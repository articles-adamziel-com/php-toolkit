<?php

use PHPUnit\Framework\TestCase;
use WordPress\ByteStream\MemoryPipe;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\WXRAttachmentExtractor\InMemoryURLSeenTracker;
use WordPress\DataLiberation\WXRAttachmentExtractor\WXRAttachmentExtractor;
use WordPress\XML\XMLProcessor;

class WXRAttachmentExtractorTest extends TestCase {

	/**
	 * All known WXR namespace URI variants for use in test helpers.
	 */
	private static $wxr_namespace_uris = array(
		'http://wordpress.org/export/1.0/',
		'https://wordpress.org/export/1.0/',
		'http://wordpress.org/export/1.1/',
		'https://wordpress.org/export/1.1/',
		'http://wordpress.org/export/1.2/',
		'https://wordpress.org/export/1.2/',
	);

	/**
	 * Helper: run the extractor on a WXR string and return the output XML.
	 */
	private function run_extractor( string $wxr_input ): string {
		$input  = new MemoryPipe( $wxr_input );
		$output = new MemoryPipe();
		$input->close_writing();

		$extractor = WXRAttachmentExtractor::create( $input, $output );
		$this->assertNotFalse( $extractor, 'Failed to create extractor' );

		$iterations = 0;
		while ( $extractor->next_step() ) {
			++$iterations;
			$this->assertLessThan( 10000, $iterations, 'Infinite loop detected' );
		}
		$this->assertTrue( $extractor->is_finished(), 'Extractor should be finished' );

		$output->close_writing();
		return $output->consume_all();
	}

	/**
	 * Helper: create a minimal WXR document wrapping the given items XML.
	 */
	private function wrap_wxr( string $channel_content, string $ns_version = '1.2' ): string {
		return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
			. '<rss version="2.0"'
			. ' xmlns:excerpt="http://wordpress.org/export/' . $ns_version . '/excerpt/"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/' . $ns_version . '/"'
			. ">\n<channel>\n"
			. $channel_content
			. "</channel>\n</rss>\n";
	}

	/**
	 * Helper: build a minimal <item> with content:encoded.
	 */
	private function make_item( int $id, string $content, string $post_type = 'post', string $title = 'Test Post' ): string {
		return '<item>'
			. '<title>' . htmlspecialchars( $title, ENT_XML1 ) . '</title>'
			. '<wp:post_id>' . $id . '</wp:post_id>'
			. '<content:encoded><![CDATA[' . $content . ']]></content:encoded>'
			. '<wp:post_type>' . $post_type . '</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '</item>';
	}

	/**
	 * Helper: check whether a namespace+local name is wp:post_type in any variant.
	 */
	private function is_wxr_tag( string $local_name, string $ns_name ): bool {
		foreach ( self::$wxr_namespace_uris as $ns ) {
			if ( '{' . $ns . '}' . $local_name === $ns_name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Helper: count occurrences of attachment post_type in the output XML.
	 */
	private function count_attachment_items( string $xml ): int {
		$count     = 0;
		$processor = XMLProcessor::create_from_string( $xml );
		$in_post_type = false;
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && $processor->is_tag_opener() ) {
				$ns_name = $processor->get_tag_namespace_and_local_name();
				if ( $this->is_wxr_tag( 'post_type', $ns_name ) ) {
					$in_post_type = true;
				}
			} elseif ( $in_post_type && ( '#text' === $processor->get_token_type() || '#cdata-section' === $processor->get_token_type() ) ) {
				if ( 'attachment' === trim( $processor->get_modifiable_text() ) ) {
					++$count;
				}
				$in_post_type = false;
			} elseif ( '#tag' === $processor->get_token_type() ) {
				$in_post_type = false;
			}
		}
		return $count;
	}

	/**
	 * Helper: extract all <wp:attachment_url> values from the output.
	 */
	private function extract_attachment_urls( string $xml ): array {
		$urls      = array();
		$processor = XMLProcessor::create_from_string( $xml );
		$in_attachment_url = false;
		$text_buffer       = '';
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && $processor->is_tag_opener() ) {
				$ns_name = $processor->get_tag_namespace_and_local_name();
				if ( $this->is_wxr_tag( 'attachment_url', $ns_name ) ) {
					$in_attachment_url = true;
					$text_buffer       = '';
				}
			} elseif ( $in_attachment_url && ( '#text' === $processor->get_token_type() || '#cdata-section' === $processor->get_token_type() ) ) {
				$text_buffer .= $processor->get_modifiable_text();
			} elseif ( '#tag' === $processor->get_token_type() && $processor->is_tag_closer() && $in_attachment_url ) {
				$urls[]            = trim( $text_buffer );
				$in_attachment_url = false;
			}
		}
		return $urls;
	}

	// ──────────────────────────────────────────────────────────────
	// Basic functionality
	// ──────────────────────────────────────────────────────────────

	public function test_empty_wxr_produces_valid_output() {
		$input  = $this->wrap_wxr( '' );
		$output = $this->run_extractor( $input );

		$this->assertStringContainsString( '<?xml', $output );
		$this->assertStringContainsString( '<rss', $output );
		$this->assertStringContainsString( '</channel>', $output );
		$this->assertStringContainsString( '</rss>', $output );
	}

	public function test_post_with_no_images_passes_through() {
		$input = $this->wrap_wxr(
			$this->make_item( 1, '<p>Hello world, no images here.</p>' )
		);

		$output = $this->run_extractor( $input );

		$this->assertStringContainsString( 'Hello world, no images here.', $output );
		$this->assertEquals( 0, $this->count_attachment_items( $output ) );
	}

	public function test_single_img_tag_creates_attachment() {
		$input = $this->wrap_wxr(
			$this->make_item( 1, '<p><img src="https://example.com/photo.jpg" /></p>' )
		);

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/photo.jpg', $urls );
	}

	public function test_multiple_images_create_multiple_attachments() {
		$content = '<div>'
			. '<img src="https://example.com/one.png" />'
			. '<img src="https://example.com/two.gif" />'
			. '<img src="https://example.com/three.webp" />'
			. '</div>';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 3, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/one.png', $urls );
		$this->assertContains( 'https://example.com/two.gif', $urls );
		$this->assertContains( 'https://example.com/three.webp', $urls );
	}

	// ──────────────────────────────────────────────────────────────
	// Deduplication
	// ──────────────────────────────────────────────────────────────

	public function test_duplicate_urls_in_same_post_are_deduplicated() {
		$content = '<img src="https://example.com/dup.jpg" />'
			. '<img src="https://example.com/dup.jpg" />';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_duplicate_urls_across_posts_are_deduplicated() {
		$items = $this->make_item( 1, '<img src="https://example.com/shared.jpg" />' )
			. $this->make_item( 2, '<img src="https://example.com/shared.jpg" />' );
		$input = $this->wrap_wxr( $items );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_existing_attachment_url_is_not_duplicated() {
		$attachment_item = '<item>'
			. '<title>existing-image</title>'
			. '<wp:post_id>50</wp:post_id>'
			. '<wp:post_type>attachment</wp:post_type>'
			. '<wp:attachment_url>https://example.com/existing.jpg</wp:attachment_url>'
			. '<wp:status>inherit</wp:status>'
			. '</item>';
		$post = $this->make_item( 1, '<img src="https://example.com/existing.jpg" />' );
		$input = $this->wrap_wxr( $attachment_item . $post );

		$output = $this->run_extractor( $input );

		// Only the original attachment, no new one.
		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// URL extraction edge cases
	// ──────────────────────────────────────────────────────────────

	public function test_data_uri_is_ignored() {
		$content = '<img src="data:image/png;base64,iVBORw0KGgoAAAANS..." />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 0, $this->count_attachment_items( $output ) );
	}

	public function test_empty_src_is_ignored() {
		$content = '<img src="" /><img />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 0, $this->count_attachment_items( $output ) );
	}

	public function test_fragment_only_url_is_ignored() {
		$content = '<img src="#section" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 0, $this->count_attachment_items( $output ) );
	}

	public function test_relative_url_resolved_against_base_url() {
		$channel = '<wp:base_blog_url>https://myblog.com</wp:base_blog_url>'
			. $this->make_item( 1, '<img src="/uploads/photo.jpg" />' );
		$input = $this->wrap_wxr( $channel );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://myblog.com/uploads/photo.jpg', $urls );
	}

	public function test_url_with_query_string_and_fragment() {
		$content = '<img src="https://cdn.example.com/img.jpg?w=800&h=600#crop" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_url_with_encoded_characters() {
		$content = '<img src="https://example.com/photos/caf%C3%A9%20life.jpg" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// CSS background-image extraction
	// ──────────────────────────────────────────────────────────────

	public function test_css_background_image_url_is_extracted() {
		$content = '<div style="background-image: url(https://example.com/bg.jpg)">text</div>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/bg.jpg', $urls );
	}

	public function test_css_background_image_with_quotes() {
		$content = '<div style="background-image: url(&quot;https://example.com/bg2.png&quot;)">text</div>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_css_data_uri_is_ignored() {
		$content = '<div style="background-image: url(data:image/gif;base64,R0lGODlh)">text</div>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 0, $this->count_attachment_items( $output ) );
	}

	public function test_css_url_extracted_regardless_of_extension() {
		// With extension filtering removed, all CSS url() values are extracted.
		$content = '<div style="background-image: url(https://example.com/font.woff2)">text</div>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// srcset extraction
	// ──────────────────────────────────────────────────────────────

	public function test_srcset_urls_are_extracted() {
		$content = '<img src="https://example.com/photo.jpg"'
			. ' srcset="https://example.com/photo-300.jpg 300w, https://example.com/photo-600.jpg 600w" />';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		// 1 from src + 2 from srcset = 3 unique URLs
		$this->assertEquals( 3, $this->count_attachment_items( $output ) );
	}

	public function test_srcset_with_duplicate_of_src() {
		$content = '<img src="https://example.com/photo.jpg"'
			. ' srcset="https://example.com/photo.jpg 1x, https://example.com/photo-2x.jpg 2x" />';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		// photo.jpg appears in both src and srcset, so only 2 unique.
		$this->assertEquals( 2, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// video poster extraction
	// ──────────────────────────────────────────────────────────────

	public function test_video_poster_is_extracted() {
		$content = '<video poster="https://example.com/thumb.jpg" src="https://example.com/video.mp4"></video>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/thumb.jpg', $urls );
	}

	// ──────────────────────────────────────────────────────────────
	// New media tag extraction
	// ──────────────────────────────────────────────────────────────

	public function test_audio_src_is_extracted() {
		$content = '<audio src="https://example.com/podcast.mp3"></audio>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/podcast.mp3', $urls );
	}

	public function test_embed_src_is_extracted() {
		$content = '<embed src="https://example.com/animation.swf" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/animation.swf', $urls );
	}

	public function test_video_src_is_extracted() {
		$content = '<video src="https://example.com/clip.mp4"></video>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/clip.mp4', $urls );
	}

	public function test_source_src_without_type_is_extracted() {
		$content = '<video><source src="https://example.com/video.webm" /></video>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/video.webm', $urls );
	}

	// ──────────────────────────────────────────────────────────────
	// Namespace variations (the tricky part!)
	// ──────────────────────────────────────────────────────────────

	public function test_wxr_1_0_namespace() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.0/"'
			. '><channel>'
			. '<item>'
			. '<title>Old Format Post</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<img src="https://example.com/old-ns.jpg" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '</item>'
			. '</channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_wxr_1_1_namespace() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="http://wordpress.org/export/1.1/"'
			. '><channel>'
			. '<item>'
			. '<title>WXR 1.1 Post</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<img src="https://example.com/v11.png" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '</item>'
			. '</channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_https_namespace_variant() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0"'
			. ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:wp="https://wordpress.org/export/1.2/"'
			. '><channel>'
			. '<item>'
			. '<title>HTTPS NS Post</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<img src="https://example.com/https-ns.jpg" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '</item>'
			. '</channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Tricky XML formatting
	// ──────────────────────────────────────────────────────────────

	public function test_multiple_tags_on_same_line() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><item><title>Inline</title><wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<img src="https://example.com/inline.jpg" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_content_with_multiple_cdata_sections() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><item><title>Multi CDATA</title><wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<p>Part 1</p><img src="https://example.com/]]><![CDATA[multi-cdata.jpg" /></p>]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>';

		$output = $this->run_extractor( $wxr );

		// The XML processor concatenates multiple CDATA sections.
		// The resulting HTML "<img src="https://example.com/multi-cdata.jpg" />"
		// should be parsed and the image extracted.
		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_content_with_mixed_text_and_cdata() {
		// Real WXR files use CDATA for content:encoded. Entity-encoded content
		// is an edge case that the XML decoder may not fully round-trip.
		// This test verifies CDATA content mixed with plain text nodes works.
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><item><title>Mixed</title><wp:post_id>1</wp:post_id>'
			. '<content:encoded>Before <![CDATA[<img src="https://example.com/mixed.jpg" />]]> after</content:encoded>'
			. '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_content_with_html_entities_in_url() {
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><item><title>HTML Entities</title><wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<img src="https://example.com/photo.jpg?w=800&amp;h=600" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Preservation of existing entities
	// ──────────────────────────────────────────────────────────────

	public function test_authors_are_preserved() {
		$wxr = $this->wrap_wxr(
			'<wp:author>'
			. '<wp:author_id>1</wp:author_id>'
			. '<wp:author_login>admin</wp:author_login>'
			. '<wp:author_email>admin@example.com</wp:author_email>'
			. '</wp:author>'
			. $this->make_item( 1, '<p>Text</p>' )
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( 'admin@example.com', $output );
	}

	public function test_categories_are_preserved() {
		$wxr = $this->wrap_wxr(
			'<wp:category>'
			. '<wp:category_nicename>tech</wp:category_nicename>'
			. '<wp:cat_name><![CDATA[Technology]]></wp:cat_name>'
			. '</wp:category>'
			. $this->make_item( 1, '<p>Text</p>' )
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( 'Technology', $output );
	}

	public function test_tags_are_preserved() {
		$wxr = $this->wrap_wxr(
			'<wp:tag>'
			. '<wp:term_id>1</wp:term_id>'
			. '<wp:tag_slug>php</wp:tag_slug>'
			. '<wp:tag_name><![CDATA[PHP]]></wp:tag_name>'
			. '</wp:tag>'
			. $this->make_item( 1, '<p>Text</p>' )
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( 'PHP', $output );
	}

	public function test_post_meta_is_preserved() {
		$wxr = $this->wrap_wxr(
			'<item>'
			. '<title>With Meta</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<p>text</p>]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '<wp:postmeta>'
			. '<wp:meta_key>_custom_field</wp:meta_key>'
			. '<wp:meta_value><![CDATA[custom_value]]></wp:meta_value>'
			. '</wp:postmeta>'
			. '</item>'
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( '_custom_field', $output );
		$this->assertStringContainsString( 'custom_value', $output );
	}

	public function test_comments_are_preserved() {
		$wxr = $this->wrap_wxr(
			'<item>'
			. '<title>With Comment</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<p>text</p>]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '<wp:comment>'
			. '<wp:comment_id>1</wp:comment_id>'
			. '<wp:comment_author><![CDATA[Commenter]]></wp:comment_author>'
			. '<wp:comment_content><![CDATA[Great post!]]></wp:comment_content>'
			. '<wp:comment_approved>1</wp:comment_approved>'
			. '</wp:comment>'
			. '</item>'
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( 'Commenter', $output );
		$this->assertStringContainsString( 'Great post!', $output );
	}

	// ──────────────────────────────────────────────────────────────
	// Complex real-world-like scenarios
	// ──────────────────────────────────────────────────────────────

	public function test_multiple_posts_with_shared_and_unique_images() {
		$items = $this->make_item(
			1,
			'<img src="https://example.com/shared.jpg" />'
			. '<img src="https://example.com/unique-to-post1.png" />'
		)
			. $this->make_item(
				2,
				'<img src="https://example.com/shared.jpg" />'
				. '<img src="https://example.com/unique-to-post2.gif" />'
			);
		$input = $this->wrap_wxr( $items );

		$output = $this->run_extractor( $input );

		// 3 unique image URLs: shared.jpg, unique-to-post1.png, unique-to-post2.gif
		$this->assertEquals( 3, $this->count_attachment_items( $output ) );
	}

	public function test_post_with_images_in_various_html_constructs() {
		$content = ''
			. '<figure class="wp-block-image"><img src="https://example.com/figure.jpg" alt="A figure" /></figure>'
			. '<div class="gallery">'
			. '<img src="https://example.com/gallery1.png" />'
			. '<img src="https://example.com/gallery2.png" />'
			. '</div>'
			. '<p style="background-image: url(https://example.com/bg-para.jpg)">Styled paragraph</p>'
			. '<video poster="https://example.com/poster.jpg"><source src="video.mp4" /></video>';

		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/figure.jpg', $urls );
		$this->assertContains( 'https://example.com/gallery1.png', $urls );
		$this->assertContains( 'https://example.com/gallery2.png', $urls );
		$this->assertContains( 'https://example.com/bg-para.jpg', $urls );
		$this->assertContains( 'https://example.com/poster.jpg', $urls );
		$this->assertEquals( 5, $this->count_attachment_items( $output ) );
	}

	public function test_attachment_post_name_derived_from_url() {
		$content = '<img src="https://example.com/wp-content/uploads/2024/01/my-beautiful-photo.jpg" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertStringContainsString( 'my-beautiful-photo', $output );
	}

	public function test_generated_attachment_has_correct_post_type() {
		$content = '<img src="https://example.com/test.jpg" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		// The output should have exactly 1 attachment type item.
		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_generated_attachment_ids_do_not_conflict() {
		$items = $this->make_item( 99999, '<img src="https://example.com/a.jpg" />' )
			. $this->make_item( 100001, '<img src="https://example.com/b.jpg" />' );
		$input = $this->wrap_wxr( $items );

		$output = $this->run_extractor( $input );

		// All post IDs should be unique.
		$ids       = array();
		$processor = XMLProcessor::create_from_string( $output );
		$in_post_id = false;
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && $processor->is_tag_opener() ) {
				if ( $this->is_wxr_tag( 'post_id', $processor->get_tag_namespace_and_local_name() ) ) {
					$in_post_id = true;
				}
			} elseif ( $in_post_id && ( '#text' === $processor->get_token_type() || '#cdata-section' === $processor->get_token_type() ) ) {
				$ids[] = trim( $processor->get_modifiable_text() );
				$in_post_id = false;
			} elseif ( '#tag' === $processor->get_token_type() ) {
				$in_post_id = false;
			}
		}

		$this->assertEquals( count( $ids ), count( array_unique( $ids ) ), 'Post IDs should be unique' );
	}

	// ──────────────────────────────────────────────────────────────
	// Cursor-based pause/resume
	// ──────────────────────────────────────────────────────────────

	public function test_reentrancy_cursor_can_be_obtained() {
		$input  = new MemoryPipe( $this->wrap_wxr( $this->make_item( 1, '<p>text</p>' ) ) );
		$output = new MemoryPipe();
		$input->close_writing();

		$extractor = WXRAttachmentExtractor::create( $input, $output );
		$this->assertNotFalse( $extractor );

		$extractor->next_step();
		$cursor = $extractor->get_reentrancy_cursor();

		$this->assertIsString( $cursor );
		$decoded = json_decode( $cursor, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'xml_cursor', $decoded );
		$this->assertArrayHasKey( 'seen_urls', $decoded );
	}

	// ──────────────────────────────────────────────────────────────
	// URLSeenTracker abstraction
	// ──────────────────────────────────────────────────────────────

	public function test_custom_url_tracker_is_used() {
		$tracker = new InMemoryURLSeenTracker( array( 'https://example.com/pre-seen.jpg' ) );

		$content = '<img src="https://example.com/pre-seen.jpg" />'
			. '<img src="https://example.com/new.jpg" />';
		$input   = new MemoryPipe( $this->wrap_wxr( $this->make_item( 1, $content ) ) );
		$output  = new MemoryPipe();
		$input->close_writing();

		$extractor = WXRAttachmentExtractor::create( $input, $output, $tracker );
		$this->assertNotFalse( $extractor );

		while ( $extractor->next_step() ) {
		}

		$output->close_writing();
		$xml = $output->consume_all();

		// Only new.jpg should generate an attachment (pre-seen.jpg was already tracked).
		$this->assertEquals( 1, $this->count_attachment_items( $xml ) );
		$urls = $this->extract_attachment_urls( $xml );
		$this->assertContains( 'https://example.com/new.jpg', $urls );
	}

	// ──────────────────────────────────────────────────────────────
	// Output is valid WXR
	// ──────────────────────────────────────────────────────────────

	public function test_output_is_valid_xml() {
		$content = '<p>Hello <img src="https://example.com/img.jpg" /> World</p>';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );
		$output  = $this->run_extractor( $input );

		// Parse with XMLProcessor; it should not fail.
		$processor = XMLProcessor::create_from_string( $output );
		$token_count = 0;
		while ( $processor->next_token() ) {
			++$token_count;
		}
		$this->assertNull( $processor->get_last_error(), 'Output XML should be valid' );
		$this->assertGreaterThan( 0, $token_count );
	}

	public function test_output_can_be_read_by_wxr_entity_reader() {
		$content = '<img src="https://example.com/photo.jpg" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content, 'post', 'My Post' ) );
		$output  = $this->run_extractor( $input );

		// Parse output with WXREntityReader.
		$pipe = new MemoryPipe( $output );
		$pipe->close_writing();
		$reader = WXREntityReader::create( $pipe );
		$this->assertNotFalse( $reader );

		$entities = array();
		while ( $reader->next_entity() ) {
			$entity     = $reader->get_entity();
			$entities[] = array(
				'type' => $entity->get_type(),
				'data' => $entity->get_data(),
			);
		}

		// Should have the original post + the generated attachment.
		$post_types = array_column( array_column( $entities, 'data' ), 'post_type' );
		$posts      = array_filter( $entities, function ( $e ) {
			return 'post' === $e['type'] && 'post' === ( $e['data']['post_type'] ?? '' );
		} );
		$attachments = array_filter( $entities, function ( $e ) {
			return 'post' === $e['type'] && 'attachment' === ( $e['data']['post_type'] ?? '' );
		} );

		$this->assertCount( 1, $posts, 'Should have 1 regular post' );
		$this->assertCount( 1, $attachments, 'Should have 1 attachment' );

		// Verify the attachment has an attachment_url field.
		$attachment_data = array_values( $attachments )[0]['data'];
		$this->assertEquals( 'https://example.com/photo.jpg', $attachment_data['attachment_url'] ?? '' );
	}

	// ──────────────────────────────────────────────────────────────
	// Special characters and encoding
	// ──────────────────────────────────────────────────────────────

	public function test_unicode_in_post_title_is_preserved() {
		$wxr = $this->wrap_wxr(
			'<item>'
			. '<title>远征手记 — UTF-8 café</title>'
			. '<wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<p>Text</p>]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type>'
			. '<wp:status>publish</wp:status>'
			. '</item>'
		);

		$output = $this->run_extractor( $wxr );

		$this->assertStringContainsString( '远征手记', $output );
		$this->assertStringContainsString( 'café', $output );
	}

	public function test_xml_special_chars_in_url_are_handled() {
		$content = '<img src="https://example.com/image.jpg?a=1&amp;b=2" />';
		$input   = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_post_content_with_cdata_end_marker_look_alike() {
		// Content that contains ]]> which normally breaks CDATA. In real WXR exports,
		// this is handled by splitting CDATA sections or by entity-encoding.
		// This test uses two adjacent CDATA sections which is the standard approach.
		$wxr = '<?xml version="1.0" encoding="UTF-8" ?>'
			. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<channel><item><title>CDATA Tricks</title><wp:post_id>1</wp:post_id>'
			. '<content:encoded><![CDATA[<div>Code: a[b]]><![CDATA[]</div><img src="https://example.com/tricky.jpg" />]]></content:encoded>'
			. '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>';

		$output = $this->run_extractor( $wxr );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// WordPress block markup patterns
	// ──────────────────────────────────────────────────────────────

	public function test_wordpress_image_block() {
		$content = '<!-- wp:image {"id":0,"sizeSlug":"full"} -->'
			. '<figure class="wp-block-image size-full">'
			. '<img src="https://example.com/wp-content/uploads/2024/block-image.jpg" alt="" class="wp-image-0"/>'
			. '</figure>'
			. '<!-- /wp:image -->';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
		$urls = $this->extract_attachment_urls( $output );
		$this->assertContains( 'https://example.com/wp-content/uploads/2024/block-image.jpg', $urls );
	}

	public function test_wordpress_cover_block_with_background() {
		$content = '<!-- wp:cover {"url":"https://example.com/cover-bg.jpg"} -->'
			. '<div class="wp-block-cover" style="background-image:url(https://example.com/cover-bg.jpg)">'
			. '<p>Overlay text</p>'
			. '</div>'
			. '<!-- /wp:cover -->';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 1, $this->count_attachment_items( $output ) );
	}

	public function test_wordpress_gallery_block() {
		$content = '<!-- wp:gallery -->'
			. '<figure class="wp-block-gallery">'
			. '<figure class="wp-block-image"><img src="https://example.com/g1.jpg" /></figure>'
			. '<figure class="wp-block-image"><img src="https://example.com/g2.jpg" /></figure>'
			. '<figure class="wp-block-image"><img src="https://example.com/g3.jpg" /></figure>'
			. '</figure>'
			. '<!-- /wp:gallery -->';
		$input = $this->wrap_wxr( $this->make_item( 1, $content ) );

		$output = $this->run_extractor( $input );

		$this->assertEquals( 3, $this->count_attachment_items( $output ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Streaming chunk boundary handling
	// ──────────────────────────────────────────────────────────────

	public function test_large_wxr_processed_correctly() {
		// Test with a multi-post WXR that exercises many code paths.
		$items = '';
		for ( $i = 1; $i <= 20; $i++ ) {
			$items .= $this->make_item(
				$i,
				'<p>Post ' . $i . '</p><img src="https://example.com/img-' . $i . '.jpg" />'
				. '<img src="https://example.com/shared.jpg" />'
			);
		}
		$input = $this->wrap_wxr( $items );

		$output = $this->run_extractor( $input );

		// 20 unique per-post images + 1 shared = 21 attachments.
		$this->assertEquals( 21, $this->count_attachment_items( $output ) );
	}

	public function test_cursor_preserves_seen_urls_state() {
		// Verify that the reentrancy cursor captures enough state to
		// avoid re-processing URLs that were seen before the pause.
		$content = '<img src="https://example.com/seen-before-pause.jpg" />';
		$input   = new MemoryPipe( $this->wrap_wxr( $this->make_item( 1, $content ) ) );
		$output  = new MemoryPipe();
		$input->close_writing();

		$extractor = WXRAttachmentExtractor::create( $input, $output );
		$this->assertNotFalse( $extractor );

		// Process until finished to accumulate state.
		while ( $extractor->next_step() ) {
		}

		$cursor  = $extractor->get_reentrancy_cursor();
		$decoded = json_decode( $cursor, true );

		$this->assertContains(
			'https://example.com/seen-before-pause.jpg',
			$decoded['seen_urls'],
			'Cursor should contain URLs seen during processing'
		);
	}
}
