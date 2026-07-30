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

		// Library + album media. The predicate MUST stay in step with
		// standalone_media()'s WHERE clause: this is the "source" side of the
		// source-vs-written comparison, so a mismatch reports a phantom shortfall (or
		// hides a real one) on a migration that is actually fine. Album photos are
		// included even though they carry an activity_id - see standalone_media().
		$stats['media_albums']     = $this->table_count( 'bp_media_albums' );
		$stats['standalone_media'] = $this->table_count( 'bp_media', "COALESCE( message_id, 0 ) = 0 AND ( COALESCE( activity_id, 0 ) = 0 OR COALESCE( album_id, 0 ) > 0 ) AND status = 'published'" );

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
		return $this->activity_media_for( array( $activity_id ) )[ $activity_id ] ?? array();
	}

	/**
	 * Batched activity media for a BuddyBoss source (bp_media / bp_video).
	 *
	 * One query reads every bp_media_ids / bp_video_ids meta row for the batch,
	 * then one query per source table resolves those row ids to attachment ids -
	 * two or three queries for a whole page instead of one or two per activity.
	 *
	 * @param array<int,int> $activity_ids Source activity ids.
	 * @return array<int,array<int,int>> Activity id => attachment ids.
	 */
	public function activity_media_for( array $activity_ids ): array {
		global $wpdb;

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $activity_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $ids ) || ! $this->table_exists( 'bp_activity_meta' ) ) {
			return array();
		}

		// Older BuddyBoss keeps videos in their own bp_video table; from 2.x the
		// video component points at bp_media too (class-bp-video-component.php
		// sets table_name to bp_media) and bp_video no longer exists. Resolving
		// bp_video_ids against a missing table silently skipped every video on a
		// modern source, so fall back to bp_media when bp_video is absent.
		$sources = array(
			'bp_media_ids' => 'bp_media',
			'bp_video_ids' => $this->table_exists( 'bp_video' ) ? 'bp_video' : 'bp_media',
		);

		$meta_table   = $wpdb->prefix . 'bp_activity_meta';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// 1) Every media/video id-list for the whole batch, in one query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT activity_id, meta_key, meta_value FROM `{$meta_table}` WHERE activity_id IN ( {$placeholders} ) AND meta_key IN ( 'bp_media_ids', 'bp_video_ids' )", $ids ), ARRAY_A );

		// 2) Group each source row id by its table, remembering every activity it
		// belongs to (the same media row can be shared across activities).
		$rows_by_table = array();
		foreach ( (array) $meta_rows as $meta ) {
			$aid   = (int) $meta['activity_id'];
			$table = $sources[ (string) $meta['meta_key'] ] ?? '';
			if ( '' === $table || $aid <= 0 || ! $this->table_exists( $table ) ) {
				continue;
			}
			foreach ( $this->parse_id_list( (string) $meta['meta_value'] ) as $row_id ) {
				$rows_by_table[ $table ][ (int) $row_id ][] = $aid;
			}
		}

		// 3) Resolve each source table's row ids to attachment ids in one query,
		// then fan each attachment back out to every activity that used it.
		$out = array();
		foreach ( $rows_by_table as $unprefixed => $row_map ) {
			$row_ids = array_keys( $row_map );
			$ph      = implode( ', ', array_fill( 0, count( $row_ids ), '%d' ) );
			$table   = $wpdb->prefix . $unprefixed;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT id, attachment_id FROM `{$table}` WHERE id IN ( {$ph} )", $row_ids ), ARRAY_A );

			foreach ( (array) $found as $media ) {
				$attachment_id = (int) $media['attachment_id'];
				if ( $attachment_id <= 0 ) {
					continue;
				}
				foreach ( ( $row_map[ (int) $media['id'] ] ?? array() ) as $aid ) {
					$out[ $aid ][] = $attachment_id;
				}
			}
		}

		// De-dupe per activity (a media + video row can point at the same file).
		foreach ( $out as $aid => $attachments ) {
			$out[ $aid ] = array_values( array_unique( $attachments ) );
		}

		return $out;
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
	 * Media this pass is responsible for: bp_media rows that are not a DM
	 * attachment, and either have no activity OR belong to an album.
	 *
	 * DM attachments belong to the messages domain and are excluded outright.
	 * Activity-attached media rides its post instead (activity_media()) and used to
	 * be excluded here on the same "don't import twice" reasoning - correct for a
	 * loose photo, wrong for one in an album.
	 *
	 * In BuddyBoss, putting a photo in an album ALSO creates an activity for it, so
	 * every album photo carries an activity_id. The old `activity_id = 0` filter
	 * therefore excluded the entire contents of every album. The album itself was
	 * created (media_albums() has no such filter) and arrived empty, while its
	 * photos came in through the activity path - which attaches them to the activity
	 * post and never calls place_in_album(), because that only runs from
	 * import_media(). Album shells with the photos scattered outside them.
	 *
	 * Including album rows does NOT re-import the file: MediaIngest keys on the
	 * source attachment id through the id-map and returns the media id the activity
	 * pass already created, so this pass only adds the album membership that pass
	 * could not know about. import_media() reports those as `linked_from_activity`
	 * rather than counting them as fresh writes.
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
					AND COALESCE( message_id, 0 ) = 0
					AND ( COALESCE( activity_id, 0 ) = 0 OR COALESCE( album_id, 0 ) > 0 )
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

	/**
	 * BuddyPress's relations plus the media ones only BuddyBoss has.
	 *
	 * @return array<int,array{relation:string,total:int,broken:int,fatal:bool,note:string}>
	 */
	public function relationship_report(): array {
		global $wpdb;

		$p   = $wpdb->prefix;
		$out = parent::relationship_report();

		if ( ! $this->table_exists( 'bp_media' ) ) {
			return $out;
		}

		$out[] = array(
			'relation' => 'media -> attachment post',
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media" ), // phpcs:ignore WordPress.DB
			'broken'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media m WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} po WHERE po.ID = m.attachment_id )" ), // phpcs:ignore WordPress.DB
			'fatal'    => true,
			'note'     => '',
		);

		// An attachment row with no file behind it cannot be ingested - the
		// writer refuses it as file_missing_or_upload_refused, which is correct
		// but worth knowing BEFORE the run rather than reading as a shortfall.
		$out[] = array(
			'relation' => 'media attachment has a file',
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media" ), // phpcs:ignore WordPress.DB
			'broken'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media m WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = m.attachment_id AND pm.meta_key = '_wp_attached_file' )" ), // phpcs:ignore WordPress.DB
			'fatal'    => false,
			'note'     => 'ingestion refuses a row with no file',
		);

		if ( $this->table_exists( 'bp_media_albums' ) ) {
			$out[] = array(
				'relation' => 'album media -> album',
				'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media WHERE COALESCE( album_id, 0 ) > 0" ), // phpcs:ignore WordPress.DB
				'broken'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media m WHERE COALESCE( m.album_id, 0 ) > 0 AND NOT EXISTS ( SELECT 1 FROM {$p}bp_media_albums a WHERE a.id = m.album_id )" ), // phpcs:ignore WordPress.DB
				'fatal'    => true,
				'note'     => '',
			);
			$out[] = array(
				'relation' => 'album -> owner',
				'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media_albums" ), // phpcs:ignore WordPress.DB
				'broken'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_media_albums a WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = a.user_id )" ), // phpcs:ignore WordPress.DB
				'fatal'    => true,
				'note'     => '',
			);
		}

		return $out;
	}
}
