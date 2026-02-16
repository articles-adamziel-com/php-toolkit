<?php

namespace WordPress\DataLiberation\WXRAttachmentExtractor;

/**
 * Tracks which URLs have already been seen to avoid creating
 * duplicate attachment entries in the WXR output.
 *
 * Implementations can use in-memory storage for small files
 * or SQLite for very large WXR files with many URLs.
 */
interface URLSeenTracker {

	/**
	 * Check whether a URL has already been seen.
	 *
	 * @param string $url The normalized URL to check.
	 * @return bool Whether the URL was previously marked as seen.
	 */
	public function has_seen( string $url ): bool;

	/**
	 * Mark a URL as seen.
	 *
	 * @param string $url The normalized URL to record.
	 */
	public function mark_seen( string $url ): void;

	/**
	 * Return the list of all seen URLs. Used for serializing cursor state.
	 *
	 * @return array List of seen URL strings.
	 */
	public function get_all_seen(): array;
}
