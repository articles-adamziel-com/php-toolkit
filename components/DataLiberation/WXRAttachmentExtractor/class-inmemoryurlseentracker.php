<?php

namespace WordPress\DataLiberation\WXRAttachmentExtractor;

/**
 * In-memory URL deduplication tracker using a PHP array.
 *
 * Suitable for WXR files where the number of unique image URLs
 * fits comfortably in memory (most real-world exports).
 */
class InMemoryURLSeenTracker implements URLSeenTracker {

	/**
	 * @var array<string, true> Set of seen URLs keyed by URL string.
	 */
	private $seen = array();

	/**
	 * Construct from an optional array of previously-seen URLs (for cursor restore).
	 *
	 * @param array $seen_urls List of URL strings already seen.
	 */
	public function __construct( array $seen_urls = array() ) {
		foreach ( $seen_urls as $url ) {
			$this->seen[ $url ] = true;
		}
	}

	public function has_seen( string $url ): bool {
		return isset( $this->seen[ $url ] );
	}

	public function mark_seen( string $url ): void {
		$this->seen[ $url ] = true;
	}

	public function get_all_seen(): array {
		return array_keys( $this->seen );
	}
}
