<?php
/**
 * BuddyPress read adapter. Reads the bp_* tables directly (they are the source,
 * not BuddyNext data) and is the base for the BuddyBoss adapter.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source\BuddyPress;

use BuddyNextImporter\Source\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 1: identity + availability + stats. The data-read methods land per
 * build phase (profiles, groups, activity...).
 */
class BuddyPressAdapter implements SourceAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'buddypress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'BuddyPress', 'buddynext-importer' );
	}

	/**
	 * Available when any core BuddyPress table exists.
	 */
	public function is_available(): bool {
		return $this->table_exists( 'bp_xprofile_fields' )
			|| $this->table_exists( 'bp_activity' )
			|| $this->table_exists( 'bp_groups' )
			|| $this->table_exists( 'bp_friends' );
	}

	/**
	 * Per-domain counts read from the bp_* tables. Each count is table-guarded so
	 * a disabled component (e.g. friends) reports 0 rather than erroring.
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		global $wpdb;

		return array(
			'users'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'profile_fields'    => $this->table_count( 'bp_xprofile_fields', 'parent_id = 0' ),
			'profile_values'    => $this->table_count( 'bp_xprofile_data' ),
			'groups'            => $this->table_count( 'bp_groups' ),
			'group_members'     => $this->table_count( 'bp_groups_members', 'is_confirmed = 1' ),
			'activities'        => $this->table_count( 'bp_activity', "type = 'activity_update'" ),
			'activity_comments' => $this->table_count( 'bp_activity', "type = 'activity_comment'" ),
			'friendships'       => $this->table_count( 'bp_friends', 'is_confirmed = 1' ),
			'follows'           => $this->table_count( 'bp_follow' ),
			'reactions'         => $this->table_exists( 'bb_user_reactions' )
				? $this->table_count( 'bb_user_reactions', "item_type = 'activity'" )
				: $this->favorites_count(),
			'message_threads'   => $this->message_thread_count(),
		);
	}

	/**
	 * Count usermeta-favorites rows (the reactions fallback source).
	 */
	private function favorites_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'bp_favorite_activities' AND meta_value NOT IN ( '', 'a:0:{}' )" );
	}

	/**
	 * Count distinct private-message threads.
	 */
	private function message_thread_count(): int {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_messages_messages' ) ) {
			return 0;
		}

		$msg = $wpdb->prefix . 'bp_messages_messages';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT thread_id) FROM `{$msg}`" );
	}

	/**
	 * Source profile field groups (xprofile groups), ordered.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_groups(): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_xprofile_groups' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_xprofile_groups';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, name, description, group_order FROM `{$table}` ORDER BY group_order ASC, id ASC", ARRAY_A );

		$groups = array();
		foreach ( (array) $rows as $row ) {
			$groups[] = array(
				'source_id'   => (int) $row['id'],
				'name'        => (string) wp_unslash( $row['name'] ),
				'description' => (string) wp_unslash( $row['description'] ),
				'sort_order'  => (int) $row['group_order'],
			);
		}

		return $groups;
	}

	/**
	 * Source profile fields (parent fields only), each with its options list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_fields(): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_xprofile_fields' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_xprofile_fields';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, group_id, name, type, is_required, field_order FROM `{$table}` WHERE parent_id = 0 ORDER BY group_id ASC, field_order ASC", ARRAY_A );

		$fields = array();
		foreach ( (array) $rows as $row ) {
			$fields[] = array(
				'source_id'   => (int) $row['id'],
				'group_id'    => (int) $row['group_id'],
				'name'        => (string) wp_unslash( $row['name'] ),
				'type'        => (string) $row['type'],
				'is_required' => (int) $row['is_required'],
				'sort_order'  => (int) $row['field_order'],
				'visibility'  => $this->field_visibility( (int) $row['id'] ),
				'options'     => $this->field_options( (int) $row['id'] ),
			);
		}

		return $fields;
	}

	/**
	 * Option labels for a choice field (its child rows), ordered.
	 *
	 * @param int $field_id Parent field id.
	 * @return array<int,string>
	 */
	public function field_options( int $field_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bp_xprofile_fields';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT name FROM `{$table}` WHERE parent_id = %d ORDER BY option_order ASC, id ASC", $field_id ) );

		return array_map(
			static fn( $value ): string => (string) wp_unslash( $value ),
			(array) $rows
		);
	}

	/**
	 * User ids that have profile values, keyset-paginated by user id.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array<int,int>
	 */
	public function profile_value_user_ids( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_xprofile_data' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_xprofile_data';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM `{$table}` WHERE user_id > %d ORDER BY user_id ASC LIMIT %d", $after, $limit ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * A user's stored profile values, joined to field type + name.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_values( int $user_id ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_xprofile_data' ) ) {
			return array();
		}

		$data   = $wpdb->prefix . 'bp_xprofile_data';
		$fields = $wpdb->prefix . 'bp_xprofile_fields';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.field_id, f.name, f.type, d.value FROM `{$data}` d JOIN `{$fields}` f ON f.id = d.field_id WHERE d.user_id = %d", $user_id ), ARRAY_A );

		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[] = array(
				'field_id' => (int) $row['field_id'],
				'name'     => (string) wp_unslash( $row['name'] ),
				'type'     => (string) $row['type'],
				'value'    => (string) wp_unslash( $row['value'] ),
			);
		}

		return $values;
	}

	/**
	 * Source groups, keyset-paginated by group id.
	 *
	 * @param int $after Exclusive lower-bound group id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function groups( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_groups' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_groups';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, creator_id, name, slug, description, status, parent_id, date_created FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$groups = array();
		foreach ( (array) $rows as $row ) {
			$groups[] = array(
				'source_id'    => (int) $row['id'],
				'creator_id'   => (int) $row['creator_id'],
				'name'         => (string) wp_unslash( $row['name'] ),
				'slug'         => (string) $row['slug'],
				'description'  => (string) wp_unslash( $row['description'] ),
				'status'       => (string) $row['status'],
				'parent_id'    => (int) $row['parent_id'],
				'date_created' => (string) $row['date_created'],
			);
		}

		return $groups;
	}

	/**
	 * A group's members, keyset-paginated by membership row id.
	 *
	 * @param int $group_id Source group id.
	 * @param int $after    Exclusive lower-bound membership row id.
	 * @param int $limit    Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function group_members( int $group_id, int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_groups_members' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_groups_members';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, is_admin, is_mod, is_confirmed, is_banned FROM `{$table}` WHERE group_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $group_id, $after, $limit ), ARRAY_A );

		$members = array();
		foreach ( (array) $rows as $row ) {
			$members[] = array(
				'row_id'       => (int) $row['id'],
				'user_id'      => (int) $row['user_id'],
				'is_admin'     => (int) $row['is_admin'],
				'is_mod'       => (int) $row['is_mod'],
				'is_confirmed' => (int) $row['is_confirmed'],
				'is_banned'    => (int) $row['is_banned'],
			);
		}

		return $members;
	}

	/**
	 * Real activity posts (activity_update, non-spam), keyset-paginated by id.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function activities( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_activity' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_activity';

		// BuddyBoss adds a per-activity privacy column; BuddyPress core has none.
		$privacy_col = $this->column_exists( 'bp_activity', 'privacy' ) ? ', privacy' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, component, item_id, content, date_recorded{$privacy_col} FROM `{$table}` WHERE type = 'activity_update' AND is_spam = 0 AND id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'     => (int) $row['id'],
				'user_id'       => (int) $row['user_id'],
				'component'     => (string) $row['component'],
				'item_id'       => (int) $row['item_id'],
				'content'       => (string) wp_unslash( $row['content'] ),
				'date_recorded' => (string) $row['date_recorded'],
				'privacy'       => isset( $row['privacy'] ) ? (string) $row['privacy'] : 'public',
			);
		}

		return $out;
	}

	/**
	 * Activity comments (activity_comment, non-spam), keyset-paginated by id.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function activity_comments( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_activity' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_activity';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, item_id, secondary_item_id, content, date_recorded FROM `{$table}` WHERE type = 'activity_comment' AND is_spam = 0 AND id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'         => (int) $row['id'],
				'user_id'           => (int) $row['user_id'],
				'root_id'           => (int) $row['item_id'],
				'secondary_item_id' => (int) $row['secondary_item_id'],
				'content'           => (string) wp_unslash( $row['content'] ),
				'date_recorded'     => (string) $row['date_recorded'],
			);
		}

		return $out;
	}

	/**
	 * Friendships, keyset-paginated by friendship id.
	 *
	 * @param int $after Exclusive lower-bound friendship id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function friendships( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_friends' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_friends';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, initiator_user_id, friend_user_id, is_confirmed, date_created FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['id'],
				'initiator_id' => (int) $row['initiator_user_id'],
				'friend_id'    => (int) $row['friend_user_id'],
				'is_confirmed' => (int) $row['is_confirmed'],
				'date_created' => (string) $row['date_created'],
			);
		}

		return $out;
	}

	/**
	 * BuddyPress core has no activity media.
	 *
	 * @param int $activity_id Source activity id.
	 * @return array<int,int>
	 */
	public function activity_media( int $activity_id ): array {
		return array();
	}

	/**
	 * Source bbPressforums, keyset-paginated by post id.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forums( int $after, int $limit ): array {
		// bbPress forums carry their visibility in post_status.
		return $this->forum_posts( 'forum', array( 'publish', 'private', 'hidden', 'public' ), $after, $limit );
	}

	/**
	 * Source bbPresstopics, keyset-paginated by post id.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_topics( int $after, int $limit ): array {
		return $this->forum_posts( 'topic', array( 'publish', 'closed' ), $after, $limit );
	}

	/**
	 * Source bbPressreplies, keyset-paginated by post id (with the nested reply target).
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_replies( int $after, int $limit ): array {
		$rows = $this->forum_posts( 'reply', array( 'publish' ), $after, $limit );

		global $wpdb;
		foreach ( $rows as &$row ) {
			// _bbp_reply_to holds the parent reply id for a threaded reply (0 = top-level).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$reply_to        = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_bbp_reply_to'", (int) $row['source_id'] ) );
			$row['reply_to'] = (int) $reply_to;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Shared bbPress post reader (forum|topic|reply), keyset-paginated by id.
	 *
	 * @param string            $post_type bbPress post type.
	 * @param array<int,string> $statuses  Accepted post statuses (bbPress encodes
	 *                                     forum visibility in post_status).
	 * @param int               $after     Exclusive lower-bound post id.
	 * @param int               $limit     Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	private function forum_posts( string $post_type, array $statuses, int $after, int $limit ): array {
		global $wpdb;

		$status_ph = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params    = array_merge( array( $post_type ), $statuses, array( $after, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_author, post_parent, post_status, post_title, post_content, post_name, post_date_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( {$status_ph} ) AND ID > %d ORDER BY ID ASC LIMIT %d", $params ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'   => (int) $row['ID'],
				'author_id'   => (int) $row['post_author'],
				'parent_id'   => (int) $row['post_parent'],
				'status'      => (string) $row['post_status'],
				'title'       => (string) wp_unslash( $row['post_title'] ),
				'content'     => (string) wp_unslash( $row['post_content'] ),
				'slug'        => (string) $row['post_name'],
				'created_gmt' => (string) $row['post_date_gmt'],
			);
		}

		return $out;
	}

	/**
	 * Count rows of a prefixed table, guarded against the table not existing.
	 *
	 * The table name is a hard-coded literal (never user input) and the optional
	 * WHERE clause is likewise a literal, so the interpolation is safe.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 * @param string $where      Optional literal WHERE clause (no user input).
	 */
	protected function table_count( string $unprefixed, string $where = '' ): int {
		global $wpdb;

		if ( ! $this->table_exists( $unprefixed ) ) {
			return 0;
		}

		$table     = $wpdb->prefix . $unprefixed;
		$condition = '' !== $where ? " WHERE {$where}" : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`{$condition}" );
	}

	/**
	 * A profile field's default visibility, read from the xprofile field meta.
	 *
	 * @param int $field_id Source field id.
	 */
	protected function field_visibility( int $field_id ): string {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_xprofile_meta' ) ) {
			return 'public';
		}

		$table = $wpdb->prefix . 'bp_xprofile_meta';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM `{$table}` WHERE object_type = 'field' AND object_id = %d AND meta_key = 'default_visibility'", $field_id ) );

		return is_string( $value ) && '' !== $value ? $value : 'public';
	}

	/**
	 * Whether a prefixed table has a given column.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 * @param string $column     Column name.
	 */
	protected function column_exists( string $unprefixed, string $column ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $unprefixed;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
	}

	/**
	 * User follows from bp_follow (BuddyBoss / the classic BuddyPress Follow
	 * plugin - same table name in both).
	 *
	 * Column drift is handled dynamically: classic bp_follow has no date and no
	 * follow_type; BuddyBoss adds follow_type (blank for member follows). When
	 * follow_type exists, non-member follows (e.g. forum subscriptions stored in
	 * the same table) are excluded.
	 *
	 * @param int $after Exclusive lower-bound follow id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function follows( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_follow' ) ) {
			return array();
		}

		$table    = $wpdb->prefix . 'bp_follow';
		$date_col = $this->column_exists( 'bp_follow', 'date_recorded' ) ? ', date_recorded' : '';
		$type_sql = $this->column_exists( 'bp_follow', 'follow_type' ) ? " AND ( follow_type = '' OR follow_type = 'user' )" : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, leader_id, follower_id{$date_col} FROM `{$table}` WHERE id > %d{$type_sql} ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'     => (int) $row['id'],
				'follower_id'   => (int) $row['follower_id'],
				'leader_id'     => (int) $row['leader_id'],
				'date_recorded' => (string) ( $row['date_recorded'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Activity reactions/likes.
	 *
	 * Prefers BuddyBoss's bb_user_reactions table (per-row dates); falls back to
	 * BuddyPress core favorites in usermeta bp_favorite_activities (a serialized
	 * array of activity ids per user - no dates). The fallback keysets by USER id
	 * and emits every favorite a batch's users hold, so a user's favorites are
	 * never split across batches.
	 *
	 * @param int $after Exclusive lower-bound keyset id (reaction row id, or user id in the fallback).
	 * @param int $limit Batch size (rows, or users in the fallback).
	 * @return array<int,array<string,mixed>>
	 */
	public function reactions( int $after, int $limit ): array {
		global $wpdb;

		if ( $this->table_exists( 'bb_user_reactions' ) ) {
			$table = $wpdb->prefix . 'bb_user_reactions';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, item_id, date_created FROM `{$table}` WHERE item_type = 'activity' AND id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );

			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'source_id'    => (int) $row['id'],
					'user_id'      => (int) $row['user_id'],
					'activity_id'  => (int) $row['item_id'],
					'date_created' => (string) $row['date_created'],
				);
			}
			return $out;
		}

		// Fallback: BuddyPress core favorites (usermeta, no dates).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'bp_favorite_activities' AND user_id > %d ORDER BY user_id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $users as $row ) {
			$favorites = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $favorites ) ) {
				continue;
			}
			foreach ( $favorites as $activity_id ) {
				$out[] = array(
					'source_id'    => (int) $row['user_id'],
					'user_id'      => (int) $row['user_id'],
					'activity_id'  => (int) $activity_id,
					'date_created' => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Private-message threads from bp_messages_messages / bp_messages_recipients.
	 *
	 * @param int $after Exclusive lower-bound thread id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function message_threads( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_messages_messages' ) || ! $this->table_exists( 'bp_messages_recipients' ) ) {
			return array();
		}

		$msg = $wpdb->prefix . 'bp_messages_messages';
		$rcp = $wpdb->prefix . 'bp_messages_recipients';

		// One row per thread: the FIRST message carries the subject + start date.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$threads = $wpdb->get_results( $wpdb->prepare( "SELECT thread_id, MIN(id) AS first_id FROM `{$msg}` WHERE thread_id > %d GROUP BY thread_id ORDER BY thread_id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $threads as $thread ) {
			$thread_id = (int) $thread['thread_id'];

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$first = $wpdb->get_row( $wpdb->prepare( "SELECT subject, date_sent FROM `{$msg}` WHERE id = %d", (int) $thread['first_id'] ), ARRAY_A );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$participants = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM `{$rcp}` WHERE thread_id = %d", $thread_id ) ) );

			$out[] = array(
				'thread_id'    => $thread_id,
				'participants' => $participants,
				'subject'      => (string) ( $first['subject'] ?? '' ),
				'date_sent'    => (string) ( $first['date_sent'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Every message in one thread, oldest first.
	 *
	 * @param int $thread_id Source thread id.
	 * @return array<int,array<string,mixed>>
	 */
	public function thread_messages( int $thread_id ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_messages_messages' ) ) {
			return array();
		}

		$msg = $wpdb->prefix . 'bp_messages_messages';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, sender_id, message, date_sent FROM `{$msg}` WHERE thread_id = %d ORDER BY id ASC", $thread_id ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id' => (int) $row['id'],
				'sender_id' => (int) $row['sender_id'],
				'content'   => (string) $row['message'],
				'date_sent' => (string) $row['date_sent'],
			);
		}
		return $out;
	}

	/**
	 * Whether a prefixed table exists.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 */
	protected function table_exists( string $unprefixed ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $unprefixed;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}
}
