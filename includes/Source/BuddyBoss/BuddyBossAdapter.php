<?php
/**
 * BuddyBoss read adapter. BuddyBoss is a BuddyPress superset (same bp_* tables),
 * so it extends the BuddyPress adapter and adds the in-scope deltas:
 * activity media (photos + videos) and forums. Profiles/groups/activity/friends
 * are inherited unchanged.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source\BuddyBoss;

use BuddyNextImporter\Source\BuddyPress\BuddyPressAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * The same detection strategy FluentCommunity's migrator uses (isBuddyBoss):
 * BuddyBoss defines BP_PLATFORM_VERSION at runtime, and ships the bp_media table.
 */
class BuddyBossAdapter extends BuddyPressAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'buddyboss';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'BuddyBoss Platform', 'buddynext-importer' );
	}

	/**
	 * Available when BuddyBoss is detected (runtime constant or its media table).
	 */
	public function is_available(): bool {
		return self::is_buddyboss();
	}

	/**
	 * Detect BuddyBoss vs plain BuddyPress.
	 */
	public function is_buddyboss(): bool {
		return defined( 'BP_PLATFORM_VERSION' ) || $this->table_exists( 'bp_media' );
	}

	/**
	 * BuddyPress base counts plus the BuddyBoss-only deltas in scope.
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		$stats = parent::stats();

		// Activity media in scope: photos (bp_media) + videos (bp_video).
		$stats['activity_media'] = $this->table_count( 'bp_media' ) + $this->table_count( 'bp_video' );

		// Forums (bbPress) -> Jetonomy, only meaningful when forums exist.
		$stats['forum_topics']  = $this->post_type_count( 'topic' );
		$stats['forum_replies'] = $this->post_type_count( 'reply' );

		return $stats;
	}

	/**
	 * Count published posts of a bbPress post type.
	 *
	 * @param string $post_type Post type slug (topic|reply|forum).
	 */
	protected function post_type_count( string $post_type ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			)
		);
	}
}
