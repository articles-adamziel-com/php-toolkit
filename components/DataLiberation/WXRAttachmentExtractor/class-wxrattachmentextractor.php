<?php

namespace WordPress\DataLiberation\WXRAttachmentExtractor;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\ImportEntity;
use WordPress\DataLiberation\URL\CSSURLProcessor;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\XML\XMLProcessor;
use WP_HTML_Tag_Processor;

/**
 * Streaming, single-pass WXR transformer that reads a WXR file and produces
 * a new WXR file with attachment (type=media) items generated for every image
 * URL found in post content.
 *
 * The WordPress importer only downloads images when the WXR file contains
 * explicit <item> entries with <wp:post_type>attachment</wp:post_type> and
 * <wp:attachment_url>. Many WXR exports omit these entries, causing image
 * references in post content to become broken after import.
 *
 * This utility fixes that by scanning each post's content:encoded for image
 * URLs (in <img> tags, CSS background-image url(), srcset attributes, and
 * <video poster>) and generating the missing attachment items.
 *
 * ## Design
 *
 * - Uses WXREntityReader for streaming XML parsing (handles namespace variants)
 * - Uses WP_HTML_Tag_Processor for HTML parsing (no regex, no DOM)
 * - Uses CSSURLProcessor for CSS url() extraction
 * - Uses WPURL (WHATWG URL parser) for URL parsing and validation
 * - Writes output XML directly to a ByteWriteStream
 * - Supports cursor-based pause/resume for very large files
 * - Deduplicates URLs via the URLSeenTracker interface (in-memory or SQLite)
 *
 * ## Usage
 *
 *     $extractor = WXRAttachmentExtractor::create($input_stream, $output_stream);
 *     while ($extractor->next_step()) {
 *         // Optionally save cursor for pause/resume:
 *         // $cursor = $extractor->get_reentrancy_cursor();
 *     }
 */
class WXRAttachmentExtractor {

	const STATE_PROCESSING = 'processing';
	const STATE_FINISHED   = 'finished';

	/**
	 * Recognised image file extensions (lowercase, without dot).
	 */
	const IMAGE_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico',
		'avif', 'tiff', 'tif', 'heic', 'heif',
	);

	/**
	 * XML namespace declarations used in WXR 1.2 output.
	 */
	const WXR_NAMESPACES = array(
		'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'wfw'     => 'http://wellformedweb.org/CommentAPI/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
		'wp'      => 'http://wordpress.org/export/1.2/',
	);

	/** @var WXREntityReader */
	private $reader;

	/** @var ByteWriteStream */
	private $output;

	/** @var URLSeenTracker */
	private $url_tracker;

	/** @var int Next post_id for generated attachments. */
	private $next_attachment_id;

	/** @var int Highest post_id seen in the input. */
	private $max_post_id_seen = 0;

	/** @var string Current processing state. */
	private $state;

	/** @var bool Whether the XML header has been written to output. */
	private $header_written = false;

	/** @var array Pending image URLs to emit as attachments after closing the current item. */
	private $pending_urls = array();

	/** @var string|null Current post's ID (for post_parent on attachments). */
	private $current_post_id = null;

	/** @var bool Whether an <item> tag is currently open in the output. */
	private $item_open = false;

	/** @var string|null Base URL from the WXR for resolving relative URLs. */
	private $base_url = null;

	/**
	 * Create a new extractor.
	 *
	 * @param ByteReadStream      $input       Input WXR byte stream.
	 * @param ByteWriteStream     $output      Output WXR byte stream.
	 * @param URLSeenTracker|null $url_tracker  URL dedup tracker (defaults to in-memory).
	 * @param string|null         $cursor       Reentrancy cursor from a previous run.
	 *
	 * @return self|false
	 */
	public static function create(
		ByteReadStream $input,
		ByteWriteStream $output,
		?URLSeenTracker $url_tracker = null,
		?string $cursor = null
	) {
		$reader_cursor = null;
		$state_data    = null;

		if ( null !== $cursor ) {
			$state_data = json_decode( $cursor, true );
			if ( ! is_array( $state_data ) ) {
				return false;
			}
			$reader_cursor = $state_data['reader_cursor'] ?? null;
		}

		$reader = WXREntityReader::create( $input, $reader_cursor );
		if ( false === $reader ) {
			return false;
		}

		$extractor         = new self();
		$extractor->reader = $reader;
		$extractor->output = $output;

		if ( null !== $state_data ) {
			$extractor->url_tracker        = $url_tracker ?? new InMemoryURLSeenTracker( $state_data['seen_urls'] ?? array() );
			$extractor->next_attachment_id = $state_data['next_attachment_id'] ?? 100000;
			$extractor->max_post_id_seen   = $state_data['max_post_id_seen'] ?? 0;
			$extractor->header_written     = $state_data['header_written'] ?? false;
			$extractor->state              = $state_data['state'] ?? self::STATE_PROCESSING;
			$extractor->item_open          = $state_data['item_open'] ?? false;
			$extractor->base_url           = $state_data['base_url'] ?? null;
		} else {
			$extractor->url_tracker        = $url_tracker ?? new InMemoryURLSeenTracker();
			$extractor->next_attachment_id = 100000;
			$extractor->state              = self::STATE_PROCESSING;
		}

		return $extractor;
	}

	private function __construct() {}

	/**
	 * Process the next step.
	 *
	 * @return bool True if there is more work to do, false when finished or paused.
	 */
	public function next_step(): bool {
		if ( self::STATE_FINISHED === $this->state ) {
			return false;
		}

		if ( ! $this->header_written ) {
			$this->write_header();
			$this->header_written = true;
		}

		// Read the next entity from the input WXR.
		if ( ! $this->reader->next_entity() ) {
			if ( $this->reader->is_paused_at_incomplete_input() ) {
				return false;
			}
			// Input exhausted – flush remaining attachments and close.
			$this->close_item_and_flush_attachments();
			$this->write_footer();
			$this->state = self::STATE_FINISHED;
			return false;
		}

		$entity = $this->reader->get_entity();
		if ( false === $entity ) {
			return true;
		}

		$this->process_entity( $entity );
		return true;
	}

	/**
	 * Whether all input has been processed and all output written.
	 */
	public function is_finished(): bool {
		return self::STATE_FINISHED === $this->state;
	}

	/**
	 * Whether the extractor is paused waiting for more input bytes.
	 */
	public function is_paused_at_incomplete_input(): bool {
		return $this->reader->is_paused_at_incomplete_input();
	}

	/**
	 * Serialise the current state so processing can be resumed later.
	 */
	public function get_reentrancy_cursor(): string {
		return json_encode( array(
			'reader_cursor'      => $this->reader->get_reentrancy_cursor(),
			'next_attachment_id' => $this->next_attachment_id,
			'max_post_id_seen'   => $this->max_post_id_seen,
			'header_written'     => $this->header_written,
			'state'              => $this->state,
			'item_open'          => $this->item_open,
			'base_url'           => $this->base_url,
			'seen_urls'          => $this->url_tracker->get_all_seen(),
		) );
	}

	// ─── Entity processing ────────────────────────────────────────

	private function process_entity( ImportEntity $entity ) {
		switch ( $entity->get_type() ) {
			case 'post':
				$this->process_post( $entity );
				break;
			case 'post_meta':
				$this->write_post_meta( $entity->get_data() );
				break;
			case 'comment':
				$this->write_comment( $entity->get_data() );
				break;
			case 'user':
				$this->close_item_and_flush_attachments();
				$this->write_author( $entity->get_data() );
				break;
			case 'category':
				$this->close_item_and_flush_attachments();
				$this->write_category( $entity->get_data() );
				break;
			case 'tag':
				$this->close_item_and_flush_attachments();
				$this->write_tag( $entity->get_data() );
				break;
			case 'term':
				$this->close_item_and_flush_attachments();
				$this->write_term( $entity->get_data() );
				break;
			case 'site_option':
				$this->process_site_option( $entity->get_data() );
				break;
		}
	}

	private function process_post( ImportEntity $entity ) {
		// Close the previous item and emit its pending attachments.
		$this->close_item_and_flush_attachments();

		$data = $entity->get_data();

		// Track the highest post_id for attachment ID generation.
		if ( ! empty( $data['post_id'] ) ) {
			$id = (int) $data['post_id'];
			if ( $id > $this->max_post_id_seen ) {
				$this->max_post_id_seen = $id;
			}
			if ( $id >= $this->next_attachment_id ) {
				$this->next_attachment_id = $id + 1;
			}
		}

		$this->current_post_id = $data['post_id'] ?? null;

		// If this is already an attachment, record its URL.
		if (
			isset( $data['post_type'] ) &&
			'attachment' === $data['post_type'] &&
			! empty( $data['attachment_url'] )
		) {
			$this->url_tracker->mark_seen( $data['attachment_url'] );
		}

		// Write the <item> to output.
		$this->write_item_open( $data );
		$this->item_open = true;

		// Scan content for image URLs.
		if ( ! empty( $data['post_content'] ) ) {
			$this->extract_and_collect_image_urls( $data['post_content'] );
		}
	}

	private function process_site_option( array $data ) {
		if ( 'home' === ( $data['option_name'] ?? '' ) && ! empty( $data['option_value'] ) ) {
			$this->base_url = $data['option_value'];
			$this->write_xml_tag( 'wp:base_blog_url', $data['option_value'] );
		} elseif ( 'siteurl' === ( $data['option_name'] ?? '' ) && ! empty( $data['option_value'] ) ) {
			if ( null === $this->base_url ) {
				$this->base_url = $data['option_value'];
			}
			$this->write_xml_tag( 'wp:base_site_url', $data['option_value'] );
		} elseif ( 'blogname' === ( $data['option_name'] ?? '' ) ) {
			$this->write_xml_tag( 'title', $data['option_value'] ?? '' );
		}
	}

	// ─── Image URL extraction ─────────────────────────────────────

	/**
	 * Extract image URLs from HTML content and collect new ones as pending.
	 */
	private function extract_and_collect_image_urls( string $html ) {
		$urls = $this->extract_image_urls( $html );
		foreach ( $urls as $url ) {
			if ( ! $this->url_tracker->has_seen( $url ) ) {
				$this->url_tracker->mark_seen( $url );
				$this->pending_urls[] = $url;
			}
		}
	}

	/**
	 * Parse HTML and return all image URLs found.
	 *
	 * Sources:
	 * - <img src="...">
	 * - <img srcset="..."> (each URL)
	 * - <video poster="...">
	 * - <source src="..." type="image/...">
	 * - CSS url() in style attributes
	 *
	 * @param string $html The HTML content to scan.
	 * @return array List of absolute image URL strings.
	 */
	private function extract_image_urls( string $html ): array {
		$urls = array();
		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {
			$tag_name = strtoupper( $tags->get_tag() );

			// <img src="...">
			if ( 'IMG' === $tag_name ) {
				$src = $tags->get_attribute( 'src' );
				if ( is_string( $src ) ) {
					$resolved = $this->resolve_url( $src );
					if ( null !== $resolved ) {
						$urls[] = $resolved;
					}
				}

				// <img srcset="url 300w, url 600w, ...">
				$srcset = $tags->get_attribute( 'srcset' );
				if ( is_string( $srcset ) ) {
					$srcset_urls = $this->parse_srcset_urls( $srcset );
					foreach ( $srcset_urls as $srcset_url ) {
						$resolved = $this->resolve_url( $srcset_url );
						if ( null !== $resolved ) {
							$urls[] = $resolved;
						}
					}
				}
			}

			// <video poster="...">
			if ( 'VIDEO' === $tag_name ) {
				$poster = $tags->get_attribute( 'poster' );
				if ( is_string( $poster ) ) {
					$resolved = $this->resolve_url( $poster );
					if ( null !== $resolved ) {
						$urls[] = $resolved;
					}
				}
			}

			// <source src="..." type="image/...">
			if ( 'SOURCE' === $tag_name ) {
				$type = $tags->get_attribute( 'type' );
				$src  = $tags->get_attribute( 'src' );
				if (
					is_string( $src ) &&
					is_string( $type ) &&
					0 === strncasecmp( $type, 'image/', 6 )
				) {
					$resolved = $this->resolve_url( $src );
					if ( null !== $resolved ) {
						$urls[] = $resolved;
					}
				}

				// <source srcset="...">
				$srcset = $tags->get_attribute( 'srcset' );
				if ( is_string( $srcset ) ) {
					$srcset_urls = $this->parse_srcset_urls( $srcset );
					foreach ( $srcset_urls as $srcset_url ) {
						$resolved = $this->resolve_url( $srcset_url );
						if ( null !== $resolved ) {
							$urls[] = $resolved;
						}
					}
				}
			}

			// CSS url() in style attributes.
			$style = $tags->get_attribute( 'style' );
			if ( is_string( $style ) && strlen( $style ) > 0 ) {
				$css_urls = $this->extract_urls_from_css( $style );
				foreach ( $css_urls as $css_url ) {
					$urls[] = $css_url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Extract image URLs from a CSS style string using CSSURLProcessor.
	 *
	 * @param string $css CSS property declarations (e.g. from a style attribute).
	 * @return array List of absolute image URL strings.
	 */
	private function extract_urls_from_css( string $css ): array {
		$urls          = array();
		$css_processor = new CSSURLProcessor( $css );

		while ( $css_processor->next_url() ) {
			if ( $css_processor->is_data_uri() ) {
				continue;
			}
			$raw_url = $css_processor->get_raw_url();
			if ( false === $raw_url || '' === $raw_url ) {
				continue;
			}
			$resolved = $this->resolve_url( $raw_url );
			if ( null !== $resolved && $this->looks_like_image_url( $resolved ) ) {
				$urls[] = $resolved;
			}
		}

		return $urls;
	}

	/**
	 * Parse the srcset attribute value and return the URL from each entry.
	 *
	 * The srcset format is: "url descriptor, url descriptor, ..."
	 * where descriptor is like "300w" or "2x".
	 *
	 * @param string $srcset The raw srcset attribute value.
	 * @return array List of URL strings.
	 */
	private function parse_srcset_urls( string $srcset ): array {
		$urls    = array();
		$entries = explode( ',', $srcset );

		foreach ( $entries as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			// The URL is everything before the first whitespace.
			$space_pos = strpos( $entry, ' ' );
			if ( false !== $space_pos ) {
				$url = substr( $entry, 0, $space_pos );
			} else {
				$url = $entry;
			}
			$url = trim( $url );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Resolve a URL (possibly relative) to an absolute URL.
	 *
	 * Returns null for data: URIs, fragment-only references, and
	 * URLs that cannot be parsed.
	 *
	 * @param string $url The URL to resolve.
	 * @return string|null The absolute URL, or null if invalid.
	 */
	private function resolve_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		// Skip data: URIs.
		if ( 0 === strncasecmp( $url, 'data:', 5 ) ) {
			return null;
		}

		// Skip fragment-only references.
		if ( '#' === $url[0] ) {
			return null;
		}

		// Try parsing as absolute URL first.
		$parsed = WPURL::parse( $url );
		if ( false !== $parsed ) {
			return $parsed->href;
		}

		// Try resolving relative to the base URL.
		if ( null !== $this->base_url ) {
			$parsed = WPURL::parse( $url, $this->base_url );
			if ( false !== $parsed ) {
				return $parsed->href;
			}
		}

		return null;
	}

	/**
	 * Check whether a URL path ends with a known image file extension.
	 *
	 * Used for CSS url() values where we need to distinguish images
	 * from fonts, SVG icons used as masks, etc.
	 *
	 * @param string $url An absolute URL.
	 * @return bool
	 */
	private function looks_like_image_url( string $url ): bool {
		$parsed = WPURL::parse( $url );
		if ( false === $parsed ) {
			return false;
		}

		$path = $parsed->pathname;
		$dot  = strrpos( $path, '.' );
		if ( false === $dot ) {
			return false;
		}

		$ext = strtolower( substr( $path, $dot + 1 ) );
		return in_array( $ext, self::IMAGE_EXTENSIONS, true );
	}

	// ─── Attachment emission ──────────────────────────────────────

	/**
	 * Close the current <item> and emit any pending attachment items.
	 */
	private function close_item_and_flush_attachments() {
		if ( $this->item_open ) {
			$this->output->append_bytes( "</item>\n" );
			$this->item_open = false;
		}

		foreach ( $this->pending_urls as $url ) {
			$this->write_attachment_item( $url );
		}
		$this->pending_urls = array();
	}

	/**
	 * Write a complete attachment <item> for the given image URL.
	 */
	private function write_attachment_item( string $url ) {
		$id        = $this->next_attachment_id++;
		$post_name = $this->derive_post_name_from_url( $url );
		$title     = $post_name;

		$this->output->append_bytes( "<item>\n" );
		$this->write_xml_tag( 'title', $title );
		$this->write_xml_tag( 'guid', $url );
		$this->write_xml_tag( 'wp:post_id', (string) $id );
		$this->write_xml_tag( 'wp:post_date', '0000-00-00 00:00:00' );
		$this->write_xml_tag( 'wp:post_date_gmt', '0000-00-00 00:00:00' );
		$this->write_xml_tag( 'wp:comment_status', 'open' );
		$this->write_xml_tag( 'wp:ping_status', 'closed' );
		$this->write_xml_tag( 'wp:post_name', $post_name );
		$this->write_xml_tag( 'wp:status', 'inherit' );
		$this->write_xml_tag( 'wp:post_parent', $this->current_post_id ?? '0' );
		$this->write_xml_tag( 'wp:menu_order', '0' );
		$this->write_xml_tag( 'wp:post_type', 'attachment' );
		$this->write_xml_tag( 'wp:attachment_url', $url );
		$this->output->append_bytes( "</item>\n" );
	}

	/**
	 * Derive a slug-like post_name from a URL.
	 *
	 * For "https://example.com/uploads/my-photo.jpg" returns "my-photo".
	 *
	 * @param string $url An absolute image URL.
	 * @return string
	 */
	private function derive_post_name_from_url( string $url ): string {
		$parsed = WPURL::parse( $url );
		if ( false === $parsed ) {
			return 'attachment';
		}

		$path = $parsed->pathname;

		// Get the filename (last path segment).
		$slash = strrpos( $path, '/' );
		$filename = ( false !== $slash ) ? substr( $path, $slash + 1 ) : $path;

		// Remove the extension.
		$dot = strrpos( $filename, '.' );
		if ( false !== $dot ) {
			$filename = substr( $filename, 0, $dot );
		}

		// URL-decode.
		$filename = rawurldecode( $filename );

		if ( '' === $filename ) {
			return 'attachment';
		}

		return $filename;
	}

	// ─── XML output helpers ───────────────────────────────────────

	private function write_header() {
		$this->output->append_bytes(
			"<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n"
			. '<rss version="2.0"'
			. ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:wfw="http://wellformedweb.org/CommentAPI/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.2/"'
			. ">\n<channel>\n"
			. "<wp:wxr_version>1.2</wp:wxr_version>\n"
		);
	}

	private function write_footer() {
		$this->output->append_bytes( "</channel>\n</rss>\n" );
	}

	/**
	 * Write a single XML tag with properly encoded text content.
	 *
	 * Uses XMLProcessor internally to ensure correct encoding.
	 */
	private function write_xml_tag( string $tag_name, string $content ) {
		$xml = XMLProcessor::create_from_string(
			"<$tag_name>text</$tag_name>\n",
			null,
			'UTF-8',
			self::WXR_NAMESPACES
		);
		$xml->next_token(); // Opening tag.
		$xml->next_token(); // Text node.
		$xml->set_modifiable_text( $content );

		$this->output->append_bytes( $xml->get_updated_xml() );
	}

	/**
	 * Write an <item> opening and all its standard post fields.
	 *
	 * The fields come from WXREntityReader's post entity data.
	 */
	private function write_item_open( array $data ) {
		$this->output->append_bytes( "<item>\n" );

		$field_map = array(
			'post_title'       => 'title',
			'guid'             => 'guid',
			'post_content'     => 'content:encoded',
			'post_excerpt'     => 'excerpt:encoded',
			'post_author'      => 'dc:creator',
			'post_id'          => 'wp:post_id',
			'post_date'        => 'wp:post_date',
			'post_date_gmt'    => 'wp:post_date_gmt',
			'post_modified'    => 'wp:post_modified',
			'post_modified_gmt' => 'wp:post_modified_gmt',
			'comment_status'   => 'wp:comment_status',
			'ping_status'      => 'wp:ping_status',
			'post_name'        => 'wp:post_name',
			'post_status'      => 'wp:status',
			'post_parent'      => 'wp:post_parent',
			'menu_order'       => 'wp:menu_order',
			'post_type'        => 'wp:post_type',
			'post_password'    => 'wp:post_password',
			'is_sticky'        => 'wp:is_sticky',
			'attachment_url'   => 'wp:attachment_url',
		);

		// Write link and pubDate first (RSS standard fields).
		if ( ! empty( $data['link'] ) ) {
			$this->write_xml_tag( 'link', $data['link'] );
		}
		if ( ! empty( $data['post_published_at'] ) ) {
			$this->write_xml_tag( 'pubDate', $data['post_published_at'] );
		}

		foreach ( $field_map as $reader_key => $xml_tag ) {
			if ( isset( $data[ $reader_key ] ) && '' !== $data[ $reader_key ] ) {
				$this->write_xml_tag( $xml_tag, $data[ $reader_key ] );
			}
		}

		// Write category terms if present.
		if ( ! empty( $data['terms'] ) && is_array( $data['terms'] ) ) {
			foreach ( $data['terms'] as $term ) {
				$taxonomy = $term['taxonomy'] ?? 'category';
				$slug     = $term['slug'] ?? '';
				$name     = $term['description'] ?? '';
				$xml      = XMLProcessor::create_from_string(
					"<category domain=\"d\" nicename=\"n\">text</category>\n",
					null,
					'UTF-8',
					self::WXR_NAMESPACES
				);
				$xml->next_tag();
				$xml->set_attribute( '', 'domain', $taxonomy );
				$xml->set_attribute( '', 'nicename', $slug );
				$xml->next_token(); // to text node
				$xml->set_modifiable_text( $name );
				$this->output->append_bytes( $xml->get_updated_xml() );
			}
		}
	}

	/**
	 * Write a <wp:postmeta> element.
	 */
	private function write_post_meta( array $data ) {
		$this->output->append_bytes( "<wp:postmeta>\n" );
		if ( isset( $data['meta_key'] ) ) {
			$this->write_xml_tag( 'wp:meta_key', $data['meta_key'] );
		}
		if ( isset( $data['meta_value'] ) ) {
			$this->write_xml_tag( 'wp:meta_value', $data['meta_value'] );
		}
		$this->output->append_bytes( "</wp:postmeta>\n" );
	}

	/**
	 * Write a <wp:comment> element.
	 */
	private function write_comment( array $data ) {
		$this->output->append_bytes( "<wp:comment>\n" );
		$comment_fields = array(
			'comment_id'           => 'wp:comment_id',
			'comment_author'       => 'wp:comment_author',
			'comment_author_email' => 'wp:comment_author_email',
			'comment_author_url'   => 'wp:comment_author_url',
			'comment_author_IP'    => 'wp:comment_author_IP',
			'comment_date'         => 'wp:comment_date',
			'comment_date_gmt'     => 'wp:comment_date_gmt',
			'comment_content'      => 'wp:comment_content',
			'comment_approved'     => 'wp:comment_approved',
			'comment_type'         => 'wp:comment_type',
			'comment_parent'       => 'wp:comment_parent',
			'comment_user_id'      => 'wp:comment_user_id',
		);
		foreach ( $comment_fields as $data_key => $xml_tag ) {
			if ( isset( $data[ $data_key ] ) ) {
				$this->write_xml_tag( $xml_tag, $data[ $data_key ] );
			}
		}
		$this->output->append_bytes( "</wp:comment>\n" );
	}

	/**
	 * Write a <wp:author> element.
	 */
	private function write_author( array $data ) {
		$this->output->append_bytes( "<wp:author>\n" );
		$fields = array(
			'ID'           => 'wp:author_id',
			'user_login'   => 'wp:author_login',
			'user_email'   => 'wp:author_email',
			'display_name' => 'wp:author_display_name',
			'first_name'   => 'wp:author_first_name',
			'last_name'    => 'wp:author_last_name',
		);
		foreach ( $fields as $data_key => $xml_tag ) {
			if ( isset( $data[ $data_key ] ) ) {
				$this->write_xml_tag( $xml_tag, $data[ $data_key ] );
			}
		}
		$this->output->append_bytes( "</wp:author>\n" );
	}

	/**
	 * Write a <wp:category> element.
	 */
	private function write_category( array $data ) {
		$this->output->append_bytes( "<wp:category>\n" );
		if ( isset( $data['slug'] ) ) {
			$this->write_xml_tag( 'wp:category_nicename', $data['slug'] );
		}
		if ( isset( $data['parent'] ) ) {
			$this->write_xml_tag( 'wp:category_parent', $data['parent'] );
		}
		if ( isset( $data['name'] ) ) {
			$this->write_xml_tag( 'wp:cat_name', $data['name'] );
		}
		if ( isset( $data['description'] ) ) {
			$this->write_xml_tag( 'wp:category_description', $data['description'] );
		}
		$this->output->append_bytes( "</wp:category>\n" );
	}

	/**
	 * Write a <wp:tag> element.
	 */
	private function write_tag( array $data ) {
		$this->output->append_bytes( "<wp:tag>\n" );
		if ( isset( $data['term_id'] ) ) {
			$this->write_xml_tag( 'wp:term_id', $data['term_id'] );
		}
		if ( isset( $data['slug'] ) ) {
			$this->write_xml_tag( 'wp:tag_slug', $data['slug'] );
		}
		if ( isset( $data['name'] ) ) {
			$this->write_xml_tag( 'wp:tag_name', $data['name'] );
		}
		if ( isset( $data['description'] ) ) {
			$this->write_xml_tag( 'wp:tag_description', $data['description'] );
		}
		$this->output->append_bytes( "</wp:tag>\n" );
	}

	/**
	 * Write a <wp:term> element.
	 */
	private function write_term( array $data ) {
		$this->output->append_bytes( "<wp:term>\n" );
		if ( isset( $data['term_id'] ) ) {
			$this->write_xml_tag( 'wp:term_id', $data['term_id'] );
		}
		if ( isset( $data['taxonomy'] ) ) {
			$this->write_xml_tag( 'wp:term_taxonomy', $data['taxonomy'] );
		}
		if ( isset( $data['slug'] ) ) {
			$this->write_xml_tag( 'wp:term_slug', $data['slug'] );
		}
		if ( isset( $data['parent'] ) ) {
			$this->write_xml_tag( 'wp:term_parent', $data['parent'] );
		}
		if ( isset( $data['name'] ) ) {
			$this->write_xml_tag( 'wp:term_name', $data['name'] );
		}
		$this->output->append_bytes( "</wp:term>\n" );
	}
}
