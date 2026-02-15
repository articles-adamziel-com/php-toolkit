<?php

namespace WordPress\DataLiberation\WXRAttachmentExtractor;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\DataLiberation\URL\CSSURLProcessor;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\XML\XMLProcessor;
use WP_HTML_Tag_Processor;

/**
 * Streaming, single-pass WXR transformer that reads a WXR file and produces
 * a new WXR file with attachment (type=media) items generated for every media
 * URL found in post content.
 *
 * The WordPress importer only downloads media when the WXR file contains
 * explicit <item> entries with <wp:post_type>attachment</wp:post_type> and
 * <wp:attachment_url>. Many WXR exports omit these entries, causing media
 * references in post content to become broken after import.
 *
 * This utility fixes that by scanning each post's content:encoded for media
 * URLs (in <img>, <video>, <audio>, <source>, <embed> tags, CSS url(), and
 * srcset attributes) and generating the missing attachment items.
 *
 * ## Design
 *
 * - Uses XMLProcessor directly for streaming XML token iteration
 * - Passes raw input bytes through to output verbatim (generic XML pass-through)
 * - Only injects synthetic attachment <item> elements after </item> closers
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
	 * HTML tags and their attributes that contain direct media URLs.
	 */
	const MEDIA_URL_ATTRIBUTES = array(
		'AUDIO'  => array( 'src' ),
		'EMBED'  => array( 'src' ),
		'IMG'    => array( 'src' ),
		'IMAGE'  => array( 'href' ),
		'SOURCE' => array( 'src' ),
		'VIDEO'  => array( 'poster', 'src' ),
	);

	/**
	 * HTML tags and their attributes that contain URLs in sub-syntaxes
	 * (srcset descriptors, CSS url() values). '*' matches any tag.
	 */
	const MEDIA_URL_SUBSYNTAX_ATTRIBUTES = array(
		'*'      => array( 'style' ),
		'IMG'    => array( 'srcset' ),
		'SOURCE' => array( 'srcset' ),
	);

	/**
	 * XML namespace declarations used in WXR 1.2 output for synthetic items.
	 */
	const WXR_NAMESPACES = array(
		'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'wfw'     => 'http://wellformedweb.org/CommentAPI/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
		'wp'      => 'http://wordpress.org/export/1.2/',
	);

	/**
	 * All known WXR namespace URI variants (http/https × versions 1.0/1.1/1.2).
	 */
	private static $wxr_namespace_uris = array(
		'http://wordpress.org/export/1.0/',
		'https://wordpress.org/export/1.0/',
		'http://wordpress.org/export/1.1/',
		'https://wordpress.org/export/1.1/',
		'http://wordpress.org/export/1.2/',
		'https://wordpress.org/export/1.2/',
	);

	/** @var XMLProcessor */
	private $xml;

	/** @var ByteReadStream|null */
	private $upstream;

	/** @var ByteWriteStream */
	private $output;

	/** @var URLSeenTracker */
	private $url_tracker;

	/** @var string Raw input bytes not yet written to output. */
	private $raw_buffer = '';

	/** @var int Cumulative stream offset of $raw_buffer[0]. */
	private $raw_buffer_base_offset = 0;

	/** @var int Cumulative stream offset of bytes already written to output. */
	private $output_cursor = 0;

	/** @var int Next post_id for generated attachments. */
	private $next_attachment_id;

	/** @var int Highest post_id seen in the input. */
	private $max_post_id_seen = 0;

	/** @var string Current processing state. */
	private $state;

	/** @var string|null Base URL from the WXR for resolving relative URLs. */
	private $base_url = null;

	/** @var bool Whether we are inside an <item> element. */
	private $in_item = false;

	/** @var string|null Tag name whose text content we are accumulating. */
	private $accumulating_tag = null;

	/** @var string Text accumulator for the current tag. */
	private $text_buffer = '';

	/** @var string|null Current item's wp:post_id text. */
	private $current_item_post_id = null;

	/** @var string|null Current item's wp:post_type text. */
	private $current_item_post_type = null;

	/** @var string|null Current item's wp:attachment_url text. */
	private $current_item_attachment_url = null;

	/** @var string|null Current item's content:encoded text. */
	private $current_item_content = null;

	/** @var int Total bytes consumed from the upstream stream. */
	private $upstream_bytes_consumed = 0;

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
		$xml_cursor = null;
		$state_data = null;

		if ( null !== $cursor ) {
			$state_data = json_decode( $cursor, true );
			if ( ! is_array( $state_data ) ) {
				return false;
			}
			$xml_cursor = $state_data['xml_cursor'] ?? null;
		}

		$xml = XMLProcessor::create_for_streaming( '', $xml_cursor );
		if ( null === $xml ) {
			return false;
		}

		$extractor           = new self();
		$extractor->xml      = $xml;
		$extractor->upstream = $input;
		$extractor->output   = $output;

		if ( null !== $state_data ) {
			$extractor->url_tracker                 = $url_tracker ?? new InMemoryURLSeenTracker( $state_data['seen_urls'] ?? array() );
			$extractor->next_attachment_id          = $state_data['next_attachment_id'] ?? 100000;
			$extractor->max_post_id_seen            = $state_data['max_post_id_seen'] ?? 0;
			$extractor->state                       = $state_data['state'] ?? self::STATE_PROCESSING;
			$extractor->base_url                    = $state_data['base_url'] ?? null;
			$extractor->output_cursor               = $state_data['output_cursor'] ?? 0;
			$extractor->raw_buffer_base_offset      = $state_data['raw_buffer_base_offset'] ?? 0;
			$extractor->in_item                     = $state_data['in_item'] ?? false;
			$extractor->current_item_post_id        = $state_data['current_item_post_id'] ?? null;
			$extractor->current_item_post_type      = $state_data['current_item_post_type'] ?? null;
			$extractor->current_item_attachment_url = $state_data['current_item_attachment_url'] ?? null;
			$extractor->current_item_content        = $state_data['current_item_content'] ?? null;
			if ( isset( $state_data['upstream_offset'] ) ) {
				$input->seek( $state_data['upstream_offset'] );
				$extractor->upstream_bytes_consumed = $state_data['upstream_offset'];
			}
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

		// Try to advance to the next XML token.
		if ( ! $this->xml->next_token() ) {
			if ( $this->xml->is_paused_at_incomplete_input() ) {
				// Need more bytes from upstream.
				if ( $this->pull_upstream_bytes() ) {
					return true;
				}
				if ( $this->upstream && $this->upstream->reached_end_of_data() ) {
					$this->xml->input_finished();
					return true;
				}
				return false;
			}
			// Document complete or error – flush remaining bytes.
			$this->flush_raw_bytes_to_end();
			$this->state = self::STATE_FINISHED;
			return false;
		}

		// Flush raw input bytes through the end of this token to output.
		$token_end_offset = $this->xml->bytes_already_parsed + $this->xml->upstream_bytes_forgotten;
		$this->flush_raw_bytes_up_to( $token_end_offset );

		// Process the token for state tracking and attachment injection.
		$this->process_token();

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
		return $this->xml->is_paused_at_incomplete_input();
	}

	/**
	 * Serialise the current state so processing can be resumed later.
	 */
	public function get_reentrancy_cursor(): string {
		return json_encode(
			array(
				'xml_cursor'                 => $this->xml->get_reentrancy_cursor(),
				'next_attachment_id'         => $this->next_attachment_id,
				'max_post_id_seen'           => $this->max_post_id_seen,
				'state'                      => $this->state,
				'base_url'                   => $this->base_url,
				'output_cursor'              => $this->output_cursor,
				'raw_buffer_base_offset'     => $this->raw_buffer_base_offset,
				'upstream_offset'            => $this->upstream_bytes_consumed,
				'in_item'                    => $this->in_item,
				'current_item_post_id'       => $this->current_item_post_id,
				'current_item_post_type'     => $this->current_item_post_type,
				'current_item_attachment_url' => $this->current_item_attachment_url,
				'current_item_content'       => $this->current_item_content,
				'seen_urls'                  => $this->url_tracker->get_all_seen(),
			)
		);
	}

	// ─── Token processing ────────────────────────────────────────

	private function process_token() {
		$token_type = $this->xml->get_token_type();

		if ( '#tag' === $token_type ) {
			if ( $this->xml->is_tag_opener() ) {
				$this->process_tag_opener();
			} elseif ( $this->xml->is_tag_closer() ) {
				$this->process_tag_closer();
			}
		} elseif (
			null !== $this->accumulating_tag &&
			( '#text' === $token_type || '#cdata-section' === $token_type )
		) {
			$this->text_buffer .= $this->xml->get_modifiable_text();
		}
	}

	private function process_tag_opener() {
		$ns_local    = $this->xml->get_tag_namespace_and_local_name();
		$breadcrumbs = $this->xml->get_breadcrumbs();
		$depth       = count( $breadcrumbs );

		// <item> at depth 3 (rss > channel > item).
		if ( 'item' === $ns_local && 3 === $depth ) {
			$this->begin_item();
			return;
		}

		if ( $this->in_item && 4 === $depth ) {
			// Fields inside an <item>.
			if ( $this->is_wxr_tag( 'post_type', $ns_local ) ) {
				$this->accumulating_tag = 'post_type';
				$this->text_buffer      = '';
			} elseif ( $this->is_wxr_tag( 'post_id', $ns_local ) ) {
				$this->accumulating_tag = 'post_id';
				$this->text_buffer      = '';
			} elseif ( $this->is_wxr_tag( 'attachment_url', $ns_local ) ) {
				$this->accumulating_tag = 'attachment_url';
				$this->text_buffer      = '';
			} elseif ( $this->is_content_encoded( $ns_local ) ) {
				$this->accumulating_tag = 'content';
				$this->text_buffer      = '';
			}
		} elseif ( ! $this->in_item && 3 === $depth ) {
			// Base URLs outside items (channel-level).
			if ( $this->is_wxr_tag( 'base_blog_url', $ns_local ) ) {
				$this->accumulating_tag = 'base_blog_url';
				$this->text_buffer      = '';
			} elseif ( $this->is_wxr_tag( 'base_site_url', $ns_local ) ) {
				$this->accumulating_tag = 'base_site_url';
				$this->text_buffer      = '';
			}
		}
	}

	private function process_tag_closer() {
		// Detect </item>: we were inside an item, and after close the depth
		// drops to 2 (rss > channel).
		if ( $this->in_item ) {
			$breadcrumbs = $this->xml->get_breadcrumbs();
			if ( 2 === count( $breadcrumbs ) ) {
				$this->end_item();
				return;
			}
		}

		if ( null !== $this->accumulating_tag ) {
			$this->finish_accumulating();
		}
	}

	// ─── Item state management ───────────────────────────────────

	private function begin_item() {
		$this->in_item                     = true;
		$this->current_item_post_id        = null;
		$this->current_item_post_type      = null;
		$this->current_item_attachment_url = null;
		$this->current_item_content        = null;
		$this->accumulating_tag            = null;
		$this->text_buffer                 = '';
	}

	private function end_item() {
		// Finish any ongoing text accumulation.
		if ( null !== $this->accumulating_tag ) {
			$this->finish_accumulating();
		}

		// Track post IDs for attachment ID generation.
		if ( null !== $this->current_item_post_id ) {
			$id = (int) $this->current_item_post_id;
			if ( $id > $this->max_post_id_seen ) {
				$this->max_post_id_seen = $id;
			}
			if ( $id >= $this->next_attachment_id ) {
				$this->next_attachment_id = $id + 1;
			}
		}

		// If this is an existing attachment, record its URL to prevent duplicates.
		if (
			'attachment' === $this->current_item_post_type &&
			null !== $this->current_item_attachment_url &&
			'' !== $this->current_item_attachment_url
		) {
			$this->url_tracker->mark_seen( $this->current_item_attachment_url );
		}

		// Extract media URLs from content and inject attachment items.
		if ( null !== $this->current_item_content && '' !== $this->current_item_content ) {
			$urls = $this->extract_media_urls( $this->current_item_content );
			foreach ( $urls as $url ) {
				if ( ! $this->url_tracker->has_seen( $url ) ) {
					$this->url_tracker->mark_seen( $url );
					$this->write_attachment_item( $url );
				}
			}
		}

		// Reset item state.
		$this->in_item                     = false;
		$this->current_item_post_id        = null;
		$this->current_item_post_type      = null;
		$this->current_item_attachment_url = null;
		$this->current_item_content        = null;
	}

	private function finish_accumulating() {
		$tag  = $this->accumulating_tag;
		$text = $this->text_buffer;

		$this->accumulating_tag = null;
		$this->text_buffer      = '';

		switch ( $tag ) {
			case 'post_type':
				$this->current_item_post_type = trim( $text );
				break;
			case 'post_id':
				$this->current_item_post_id = trim( $text );
				break;
			case 'attachment_url':
				$this->current_item_attachment_url = trim( $text );
				break;
			case 'content':
				$this->current_item_content = $text;
				break;
			case 'base_blog_url':
				$this->base_url = trim( $text );
				break;
			case 'base_site_url':
				if ( null === $this->base_url ) {
					$this->base_url = trim( $text );
				}
				break;
		}
	}

	// ─── Namespace helpers ───────────────────────────────────────

	/**
	 * Check whether a namespace+local tag name matches any WXR namespace variant
	 * for the given local name.
	 */
	private function is_wxr_tag( string $local_name, string $ns_local ): bool {
		foreach ( self::$wxr_namespace_uris as $ns ) {
			if ( '{' . $ns . '}' . $local_name === $ns_local ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a namespace+local tag name is content:encoded.
	 */
	private function is_content_encoded( string $ns_local ): bool {
		return '{http://purl.org/rss/1.0/modules/content/}encoded' === $ns_local;
	}

	// ─── Raw byte management ─────────────────────────────────────

	/**
	 * Pull the next chunk of bytes from the upstream stream, feeding both
	 * the XMLProcessor and the raw buffer.
	 */
	private function pull_upstream_bytes(): bool {
		if ( ! $this->upstream || $this->upstream->reached_end_of_data() ) {
			return false;
		}

		$available = $this->upstream->pull( 65536 );
		if ( 0 === $available ) {
			return false;
		}

		$bytes = $this->upstream->consume( $available );
		$this->xml->append_bytes( $bytes );
		$this->raw_buffer              .= $bytes;
		$this->upstream_bytes_consumed += strlen( $bytes );

		return true;
	}

	/**
	 * Copy raw input bytes from the buffer to output up to the given
	 * cumulative stream offset, then trim the consumed portion.
	 */
	private function flush_raw_bytes_up_to( int $offset ) {
		if ( $offset <= $this->output_cursor ) {
			return;
		}

		$start_in_buffer = $this->output_cursor - $this->raw_buffer_base_offset;
		$length          = $offset - $this->output_cursor;

		if ( $length > 0 && $start_in_buffer >= 0 ) {
			$this->output->append_bytes( substr( $this->raw_buffer, $start_in_buffer, $length ) );
		}

		$this->output_cursor = $offset;

		// Trim consumed bytes from the raw buffer.
		$consumed = $this->output_cursor - $this->raw_buffer_base_offset;
		if ( $consumed > 0 ) {
			$this->raw_buffer             = substr( $this->raw_buffer, $consumed );
			$this->raw_buffer_base_offset = $this->output_cursor;
		}
	}

	/**
	 * Flush all remaining raw bytes to output.
	 */
	private function flush_raw_bytes_to_end() {
		if ( '' !== $this->raw_buffer ) {
			$this->output->append_bytes( $this->raw_buffer );
			$this->output_cursor          = $this->raw_buffer_base_offset + strlen( $this->raw_buffer );
			$this->raw_buffer             = '';
			$this->raw_buffer_base_offset = $this->output_cursor;
		}
	}

	// ─── Media URL extraction ────────────────────────────────────

	/**
	 * Extract media URLs from HTML content using data-driven tag/attribute lists.
	 *
	 * @param string $html The HTML content to scan.
	 * @return array List of absolute media URL strings.
	 */
	private function extract_media_urls( string $html ): array {
		$urls = array();
		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {
			$tag_name = strtoupper( $tags->get_tag() );

			// Direct URL attributes.
			if ( isset( self::MEDIA_URL_ATTRIBUTES[ $tag_name ] ) ) {
				foreach ( self::MEDIA_URL_ATTRIBUTES[ $tag_name ] as $attr ) {
					$value = $tags->get_attribute( $attr );
					if ( is_string( $value ) ) {
						$resolved = $this->resolve_url( $value );
						if ( null !== $resolved ) {
							$urls[] = $resolved;
						}
					}
				}
			}

			// Sub-syntax attributes (srcset, style) – merge wildcard and tag-specific.
			$subsyntax_attrs = array();
			if ( isset( self::MEDIA_URL_SUBSYNTAX_ATTRIBUTES['*'] ) ) {
				$subsyntax_attrs = self::MEDIA_URL_SUBSYNTAX_ATTRIBUTES['*'];
			}
			if ( isset( self::MEDIA_URL_SUBSYNTAX_ATTRIBUTES[ $tag_name ] ) ) {
				$subsyntax_attrs = array_unique(
					array_merge(
						$subsyntax_attrs,
						self::MEDIA_URL_SUBSYNTAX_ATTRIBUTES[ $tag_name ]
					)
				);
			}

			foreach ( $subsyntax_attrs as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				if ( 'srcset' === $attr ) {
					foreach ( $this->parse_srcset_urls( $value ) as $srcset_url ) {
						$resolved = $this->resolve_url( $srcset_url );
						if ( null !== $resolved ) {
							$urls[] = $resolved;
						}
					}
				} elseif ( 'style' === $attr ) {
					foreach ( $this->extract_urls_from_css( $value ) as $css_url ) {
						$urls[] = $css_url;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Extract URLs from a CSS style string using CSSURLProcessor.
	 *
	 * @param string $css CSS property declarations (e.g. from a style attribute).
	 * @return array List of absolute URL strings.
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
			if ( null !== $resolved ) {
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

	// ─── Attachment emission ─────────────────────────────────────

	/**
	 * Write a complete attachment <item> for the given media URL.
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
		$this->write_xml_tag( 'wp:post_parent', $this->current_item_post_id ?? '0' );
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
		$slash    = strrpos( $path, '/' );
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

	// ─── XML output helpers ──────────────────────────────────────

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
}
