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

		// Activity media in scope: photos (bp_media) + videos (bp_video on older
		// installs; folded into bp_media on 2.x, so it is not double-counted).
		$stats['activity_media'] = $this->table_count( 'bp_media', 'COALESCE( activity_id, 0 ) <> 0' )
			+ ( $this->table_exists( 'bp_video' ) ? $this->table_count( 'bp_video' ) : 0 );

		// Standalone library/album media - never posted to an activity.
		$stats['media_albums']     = $this->table_count( 'bp_media_albums' );
		$stats['standalone_media'] = $this->table_count( 'bp_media', "COALESCE( activity_id, 0 ) = 0 AND COALESCE( message_id, 0 ) = 0 AND status = 'published'" );

		// Forums (bbPress) -> Jetonomy, only meaningful when forums exist.
		$stats['forum_topics']  = $this->post_type_count( 'topic' );
		$stats['forum_replies'] = $this->post_type_count( 'reply' );

		return $stats;
	}

	/**
	 * Attachment ids of the photos + videos attached to a BuddyBoss activity.
	 * Reads the bp_media_ids / bp_video_ids activity meta, then resolves each
	 * media/video row to its WP attachment.
	 *
	 * @param int $activity_id Source activity id.
	 * @return array<int,int>
	 */
	public function activity_media( int $activity_id ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_activity_meta' ) ) {
			return array();
		}

		$attachments = array();

		// Older BuddyBoss keeps videos in their own bp_video table; from 2.x the
		// video component points at bp_media too (class-bp-video-component.php
		// sets table_name to bp_media) and bp_video no longer exists. Resolving
		// bp_video_ids against a missing table silently skipped every video on a
		// modern source, so fall back to bp_media when bp_video is absent.
		$sources = array(
			'bp_media_ids' => 'bp_media',
			'bp_video_ids' => $this->table_exists( 'bp_video' ) ? 'bp_video' : 'bp_media',
		);

		foreach ( $sources as $meta_key => $unprefixed ) {
			if ( ! $this->table_exists( $unprefixed ) ) {
				continue;
			}

			$meta_table = $wpdb->prefix . 'bp_activity_meta';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM `{$meta_table}` WHERE activity_id = %d AND meta_key = %s", $activity_id, $meta_key ) );

			$row_ids = $this->parse_id_list( (string) $raw );
			if ( empty( $row_ids ) ) {
				continue;
			}

			$table        = $wpdb->prefix . $unprefixed;
			$placeholders = implode( ', ', array_fill( 0, count( $row_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$table}` WHERE id IN ( {$placeholders} )", $row_ids ) );

			foreach ( $found as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				if ( $attachment_id > 0 ) {
					$attachments[] = $attachment_id;
				}
			}
		}

		return array_values( array_unique( $attachments ) );
	}

	/**
	 * Media albums from bp_media_albums.
	 *
	 * @param int $after Exclusive lower-bound album id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function media_albums( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_media_albums' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_media_albums';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, group_id, title, privacy, date_created FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$albums = array();
		foreach ( (array) $rows as $row ) {
			$albums[] = array(
				'source_id'    => (int) $row['id'],
				'user_id'      => (int) $row['user_id'],
				'group_id'     => (int) $row['group_id'],
				'title'        => (string) wp_unslash( (string) $row['title'] ),
				'privacy'      => (string) $row['privacy'],
				'date_created' => (string) $row['date_created'],
			);
		}

		return $albums;
	}

	/**
	 * Standalone media: bp_media rows that are not attached to an activity and
	 * are not a DM attachment. Activity-attached media rides its post instead
	 * (activity_media()), and DM attachments belong to the messages domain, so
	 * both are excluded here rather than imported twice.
	 *
	 * @param int $after Exclusive lower-bound media id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function standalone_media( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_media' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_media';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, user_id, title, description, album_id, group_id, privacy, type, date_created
				FROM `{$table}`
				WHERE id > %d
					AND COALESCE( activity_id, 0 ) = 0
					AND COALESCE( message_id, 0 ) = 0
					AND status = 'published'
				ORDER BY id ASC
				LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$media = array();
		foreach ( (array) $rows as $row ) {
			$media[] = array(
				'source_id'     => (int) $row['id'],
				'attachment_id' => (int) $row['attachment_id'],
				'user_id'       => (int) $row['user_id'],
				'title'         => (string) wp_unslash( (string) $row['title'] ),
				'description'   => (string) wp_unslash( (string) $row['description'] ),
				'album_id'      => (int) $row['album_id'],
				'group_id'      => (int) $row['group_id'],
				'privacy'       => (string) $row['privacy'],
				'type'          => (string) $row['type'],
				'date_created'  => (string) $row['date_created'],
			);
		}

		return $media;
	}

	/**
	 * Parse a media-id list stored as either a serialized array or a CSV string.
	 *
	 * @param string $raw Stored meta value.
	 * @return array<int,int>
	 */
	private function parse_id_list( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		$decoded = maybe_unserialize( $raw );
		$list    = is_array( $decoded ) ? $decoded : explode( ',', $raw );

		return array_values( array_filter( array_map( 'intval', $list ) ) );
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
