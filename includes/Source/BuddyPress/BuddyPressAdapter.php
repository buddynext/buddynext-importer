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
	 * Source activity types that become BuddyNext posts.
	 *
	 * `activity_update` is a member's own post. `new_blog_post` is BuddyPress
	 * announcing a published article - real content with a URL, which maps to a
	 * BuddyNext `article` post and brings its comment thread with it.
	 * `rtmedia_update` is a member posting photos or video through rtMedia, the
	 * media plugin plain BuddyPress sites use: content in exactly the way a
	 * status update is, and the reason a whole community's photos used to stay
	 * behind. Its attachments already ride along through
	 * activity_media_for(), which has always read rt_rtm_media - the media had
	 * a way in the entire time, and nothing ever fetched the activity holding it.
	 *
	 * Everything else BuddyPress records (joined_group, friendship_created,
	 * new_member, updated_profile) is a system notice rather than content, and
	 * BuddyNext has nothing to import it into.
	 *
	 * `new_blog_comment` is EXCLUDED ON PURPOSE, and it is the one omission here
	 * that looks like an oversight. A comment on an article is a reply, not a
	 * post: BuddyNext attaches it to the article's existing card
	 * (ActivityWriter::import_blog_comments(), sourced from wp_comments, which
	 * is authoritative and complete where the activity rows are neither).
	 * Adding it to this list would give every blog comment its own card in the
	 * feed AND leave it duplicated as a reply underneath the article.
	 *
	 * Read by the posts query, by the `activities` stat, and by the comment-root
	 * check - so those three cannot drift apart, which is exactly how comments
	 * came to be counted against a root set nothing imported.
	 *
	 * @var array<int,string>
	 */
	private const IMPORTED_ACTIVITY_TYPES = array( 'activity_update', 'new_blog_post', 'rtmedia_update' );

	/**
	 * Taxonomy that carries member-type assignments on the user object. Set by
	 * bp_set_member_type() in both BuddyPress and BuddyBoss.
	 */
	protected const MEMBER_TYPE_TAXONOMY = 'bp_member_type';

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
			'users'                        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'profile_fields'               => $this->table_count( 'bp_xprofile_fields', 'parent_id = 0' ),
			'profile_values'               => $this->table_count( 'bp_xprofile_data' ),
			'groups'                       => $this->table_count( 'bp_groups' ),
			// Every row: group_members() reads the whole table, pending memberships
			// included. Counting only confirmed ones understated the source, so a
			// genuine shortfall looked like a surplus.
			'group_members'                => $this->table_count( 'bp_groups_members' ),
			'activities'                   => $this->table_count( 'bp_activity', "type IN ('" . implode( "','", self::IMPORTED_ACTIVITY_TYPES ) . "') AND is_spam = 0" ),
			// is_spam = 0 to match activity_comments() and the orphan-comment
			// health check, both of which exclude spam. The 'activities' line
			// above always did; this one did not, so every spam comment on the
			// source was counted as an expected import that could never arrive.
			'activity_comments'            => $this->table_count( 'bp_activity', "type = 'activity_comment' AND is_spam = 0" ),
			// Every row, not just the confirmed ones: friendships() reads the whole
			// table and a pending request migrates as a pending connection. Counting
			// only confirmed rows here reported fewer in the source than the import
			// wrote, which reads as the importer inventing connections.
			'friendships'                  => $this->table_count( 'bp_friends' ),
			'follows'                      => $this->table_count( 'bp_follow', $this->follow_type_where() ),
			// bbPress forums on a plain BuddyPress source. These readers and the
			// ForumImporter have always existed here, but stats() carried no
			// forum key at all - so on BP + bbPress the coverage banner and the
			// verify screen showed nothing for content that was actively being
			// written, and no shortfall in it could ever surface. 0 when bbPress
			// is absent, which is the honest answer there.
			'forums'                       => $this->forum_post_count( 'forum' ),
			'forum_topics'                 => $this->forum_post_count( 'topic' ),
			'forum_replies'                => $this->forum_post_count( 'reply' ),
			'group_types'                  => count( $this->group_types() ),
			// What CAN migrate, as distinct from what exists: a comment on a root
			// the posts pass does not import has nowhere to attach.
			'activity_comments_importable' => $this->importable_comment_count(),
			'reactions'                    => $this->table_exists( 'bb_user_reactions' )
				? $this->table_count( 'bb_user_reactions', "item_type = 'activity'" )
				: $this->favorites_count(),
			'message_threads'              => $this->message_thread_count(),
			// rtMedia activity media (photos/videos/audio) on a BuddyPress source.
			// BuddyBoss overrides activity_media differently (bp_media); this is
			// the plain-BP path, 0 when rtMedia is absent.
			'activity_media'               => $this->rtmedia_activity_count(),
			'member_types'                 => count( $this->member_types() ),
			'member_type_users'            => $this->member_type_assignment_count(),
			'member_images'                => $this->image_owner_count( 'avatars', 'members' ),
			'group_images'                 => $this->image_owner_count( 'group-avatars', 'groups' ),
		);
	}

	/**
	 * Count member-type assignments (users carrying a bp_member_type term).
	 */
	protected function member_type_assignment_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s",
				self::MEMBER_TYPE_TAXONOMY
			)
		);
	}

	/**
	 * Count usermeta-favorites rows (the reactions fallback source).
	 */
	private function favorites_count(): int {
		global $wpdb;

		// Each row is one USER's serialized list of favourited activity ids, so
		// COUNT(*) counted people rather than likes - a member with forty
		// favourites read as one. Unserialize and total the ids, because that is
		// what ReactionImporter goes on to import.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'bp_favorite_activities' AND meta_value NOT IN ( '', 'a:0:{}' )" );

		$total = 0;
		foreach ( (array) $rows as $raw ) {
			$ids = maybe_unserialize( (string) $raw );
			if ( is_array( $ids ) ) {
				$total += count( $ids );
			}
		}

		return $total;
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
	 * A user's per-field visibility choices, from the serialized usermeta map
	 * BuddyPress and BuddyBoss both write (`bp_xprofile_visibility_levels`:
	 * field id => level). This is the MEMBER's own privacy choice per field and
	 * is stored separately from the field's admin default in bp_xprofile_fields,
	 * which is why importing the schema alone silently resets every member's
	 * "Only Me" / "My Connections" field back to the field default.
	 *
	 * @param int $user_id User id.
	 * @return array<int,string> Map of source field id to source visibility level.
	 */
	public function profile_visibility_levels( int $user_id ): array {
		$raw = get_user_meta( $user_id, 'bp_xprofile_visibility_levels', true );

		if ( ! is_array( $raw ) ) {
			$raw = maybe_unserialize( (string) $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$levels = array();
		foreach ( $raw as $field_id => $level ) {
			$field_id = (int) $field_id;
			$level    = sanitize_key( (string) $level );

			if ( $field_id > 0 && '' !== $level ) {
				$levels[ $field_id ] = $level;
			}
		}

		return $levels;
	}

	/**
	 * Member types defined on the source.
	 *
	 * Both BuddyPress and BuddyBoss store the ASSIGNMENT as a term in the
	 * `bp_member_type` taxonomy on the user object (bp_set_member_type() ->
	 * bp_set_object_terms()), so the taxonomy terms are the authoritative
	 * vocabulary — a type registered in code but never assigned has no member to
	 * migrate anyway. BuddyBoss additionally keeps a `bp-member-type` post per
	 * type carrying the human labels; when that post exists its title is
	 * preferred over the raw term name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function member_types(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.slug, t.name, tt.description
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy = %s
				ORDER BY t.term_id ASC",
				self::MEMBER_TYPE_TAXONOMY
			),
			ARRAY_A
		);

		$types = array();
		foreach ( (array) $rows as $row ) {
			$slug = (string) $row['slug'];
			if ( '' === $slug ) {
				continue;
			}

			$types[] = array(
				'slug'        => $slug,
				'name'        => $this->member_type_label( $slug, (string) wp_unslash( $row['name'] ) ),
				'description' => (string) wp_unslash( (string) $row['description'] ),
			);
		}

		return $types;
	}

	/**
	 * Member-type assignments, keyset-paginated by user id.
	 *
	 * The page is a page of USERS, not of assignment rows: a user with more than
	 * one source type would otherwise have their rows split across a page
	 * boundary, and the `object_id > last` cursor would skip the remainder
	 * forever. Selecting the user ids first and then all their rows keeps every
	 * user whole inside one batch.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size (users).
	 * @return array<int,array<string,mixed>>
	 */
	public function member_type_assignments( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tr.object_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s AND tr.object_id > %d
				ORDER BY tr.object_id ASC
				LIMIT %d",
				self::MEMBER_TYPE_TAXONOMY,
				$after,
				$limit
			)
		);

		$user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id AS user_id, t.slug
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s AND tr.object_id IN ( {$placeholders} )
				ORDER BY tr.object_id ASC, t.term_id ASC",
				array_merge( array( self::MEMBER_TYPE_TAXONOMY ), $user_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$assignments = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$slug    = (string) $row['slug'];

			if ( $user_id > 0 && '' !== $slug ) {
				$assignments[] = array(
					'user_id' => $user_id,
					'slug'    => $slug,
				);
			}
		}

		return $assignments;
	}

	/**
	 * Human label for a member type, preferring BuddyBoss's `bp-member-type`
	 * post title over the taxonomy term name (BuddyBoss stores the display label
	 * on the post and leaves the term name as the raw key on some installs).
	 *
	 * @param string $slug     Member-type slug.
	 * @param string $fallback Taxonomy term name.
	 */
	private function member_type_label( string $slug, string $fallback ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$title = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.post_title
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'bp-member-type'
					AND p.post_status = 'publish'
					AND pm.meta_key = '_bp_member_type_key'
					AND pm.meta_value = %s
				LIMIT 1",
				$slug
			)
		);

		$title = trim( (string) wp_unslash( (string) $title ) );

		return '' !== $title ? $title : $fallback;
	}

	/**
	 * Member avatars and cover images, keyset-paginated by user id.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function member_images( int $after, int $limit ): array {
		return $this->images_for( 'avatars', 'members', $after, $limit );
	}

	/**
	 * Group avatars and cover images, keyset-paginated by group id.
	 *
	 * @param int $after Exclusive lower-bound group id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function group_images( int $after, int $limit ): array {
		return $this->images_for( 'group-avatars', 'groups', $after, $limit );
	}

	/**
	 * Count objects that have an avatar and/or a cover on disk.
	 *
	 * @param string $avatar_dir Avatar parent directory ('avatars'|'group-avatars').
	 * @param string $cover_dir  Cover object directory ('members'|'groups').
	 */
	protected function image_owner_count( string $avatar_dir, string $cover_dir ): int {
		return count( $this->image_owner_ids( $avatar_dir, $cover_dir ) );
	}

	/**
	 * One keyset page of avatar/cover owners, resolved to real files.
	 *
	 * @param string $avatar_dir Avatar parent directory.
	 * @param string $cover_dir  Cover object directory.
	 * @param int    $after      Exclusive lower-bound owner id.
	 * @param int    $limit      Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	protected function images_for( string $avatar_dir, string $cover_dir, int $after, int $limit ): array {
		$ids  = $this->image_owner_ids( $avatar_dir, $cover_dir );
		$page = array();

		foreach ( $ids as $id ) {
			if ( $id <= $after ) {
				continue;
			}
			if ( count( $page ) >= $limit ) {
				break;
			}

			$avatar = $this->pick_image( $this->uploads_path( $avatar_dir . '/' . $id ), 'bpfull' );
			$cover  = $this->pick_image( $this->uploads_path( 'buddypress/' . $cover_dir . '/' . $id . '/cover-image' ), '' );

			if ( '' === $avatar && '' === $cover ) {
				continue;
			}

			$page[] = array(
				'source_id' => $id,
				'avatar'    => $avatar,
				'cover'     => $cover,
			);
		}

		return $page;
	}

	/**
	 * Sorted ids of every object that has an avatar or cover directory.
	 *
	 * Cached per request: a keyset loop calls this once per batch, and the two
	 * directory listings do not change during a run.
	 *
	 * @param string $avatar_dir Avatar parent directory.
	 * @param string $cover_dir  Cover object directory.
	 * @return array<int,int>
	 */
	protected function image_owner_ids( string $avatar_dir, string $cover_dir ): array {
		static $cache = array();

		$key = $avatar_dir . '|' . $cover_dir;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$ids = array_merge(
			$this->numeric_subdirs( $this->uploads_path( $avatar_dir ) ),
			$this->numeric_subdirs( $this->uploads_path( 'buddypress/' . $cover_dir ) )
		);

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );

		$cache[ $key ] = $ids;

		return $ids;
	}

	/**
	 * Numeric subdirectory names of a directory, as ints. Empty when absent.
	 *
	 * @param string $dir Absolute directory path.
	 * @return array<int,int>
	 */
	protected function numeric_subdirs( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return array();
		}

		$ids = array();
		foreach ( $entries as $entry ) {
			if ( ctype_digit( $entry ) && is_dir( trailingslashit( $dir ) . $entry ) ) {
				$ids[] = (int) $entry;
			}
		}

		return $ids;
	}

	/**
	 * Pick the image to import from a directory: the one whose name contains
	 * $prefer (BuddyPress writes the full-size avatar as `*-bpfull.*`), else the
	 * largest image present. Empty string when there is none.
	 *
	 * @param string $dir    Absolute directory path.
	 * @param string $prefer Filename fragment to prefer.
	 */
	protected function pick_image( string $dir, string $prefer ): string {
		if ( ! is_dir( $dir ) ) {
			return '';
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return '';
		}

		$best      = '';
		$best_size = -1;

		foreach ( $entries as $entry ) {
			$path = trailingslashit( $dir ) . $entry;

			if ( ! is_file( $path ) ) {
				continue;
			}

			$ext = strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
				continue;
			}

			if ( '' !== $prefer && false !== strpos( $entry, $prefer ) ) {
				return $path;
			}

			$size = (int) filesize( $path );
			if ( $size > $best_size ) {
				$best      = $path;
				$best_size = $size;
			}
		}

		return $best;
	}

	/**
	 * Absolute path inside the uploads directory. Honours BuddyPress's own
	 * avatar-path override when the source plugin is still active, so a site
	 * with a custom BP_AVATAR_UPLOAD_PATH still resolves.
	 *
	 * @param string $relative Path relative to the uploads basedir.
	 */
	protected function uploads_path( string $relative ): string {
		$base = '';

		if ( function_exists( 'bp_core_avatar_upload_path' ) ) {
			$base = (string) bp_core_avatar_upload_path();
		}

		if ( '' === $base ) {
			$uploads = wp_upload_dir();
			$base    = (string) ( $uploads['basedir'] ?? '' );
		}

		return trailingslashit( $base ) . ltrim( $relative, '/' );
	}

	/**
	 * Media albums. BuddyPress core has no album feature; the BuddyBoss adapter
	 * overrides this.
	 *
	 * @param int $after Exclusive lower-bound album id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function media_albums( int $after, int $limit ): array {
		return array();
	}

	/**
	 * Standalone media. BuddyPress core has no media feature; the BuddyBoss
	 * adapter overrides this.
	 *
	 * @param int $after Exclusive lower-bound media id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function standalone_media( int $after, int $limit ): array {
		return array();
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

		$types = implode( "','", self::IMPORTED_ACTIVITY_TYPES );

		// Interpolated: the table name, the optional privacy column and the type
		// list are all code-controlled (a class constant), not input. Values are
		// still placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, component, type, item_id, secondary_item_id, primary_link, content, date_recorded, hide_sitewide{$privacy_col} FROM `{$table}` WHERE type IN ('{$types}') AND is_spam = 0 AND id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'         => (int) $row['id'],
				'user_id'           => (int) $row['user_id'],
				'component'         => (string) $row['component'],
				'source_type'       => (string) $row['type'],
				'item_id'           => (int) $row['item_id'],
				// For new_blog_post this is the published post's ID, which is how
				// a link card is built from local data instead of an HTTP fetch.
				'secondary_item_id' => (int) $row['secondary_item_id'],
				'primary_link'      => (string) $row['primary_link'],
				'content'           => (string) wp_unslash( $row['content'] ),
				'date_recorded'     => (string) $row['date_recorded'],
				// BuddyPress sets this on activity it deliberately keeps OUT of the
				// sitewide feed - content from hidden groups, and blog posts that
				// are private or password protected. It is the source telling us
				// this was never public.
				'hide_sitewide'     => (int) $row['hide_sitewide'],
				'privacy'           => isset( $row['privacy'] ) ? (string) $row['privacy'] : 'public',
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
	 * Comments grouped by the TYPE of the activity they hang off.
	 *
	 * The posts pass imports only `activity_update`, while the comments pass
	 * reads every `activity_comment`. A comment's item_id points at its root
	 * activity, and in BuddyPress that root can be any commentable type - a blog
	 * post activity, a forum activity, whatever an add-on registered. Those
	 * roots are never imported, so their comments have no post to attach to and
	 * are dropped.
	 *
	 * Nothing measured that, so the loss showed up only as "comments are short"
	 * long after the migration. Answered here, BEFORE a run, it is a number the
	 * owner can decide about.
	 *
	 * @return array<int,array{type:string,comments:int,importable:bool}>
	 */
	public function comment_root_types(): array {
		global $wpdb;

		if ( ! $this->table_exists( 'bp_activity' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'bp_activity';

		// Interpolated table name only, self-joined; no input in the statement.
		// A disable/enable pair rather than :ignore because the sniff reports the
		// line inside the string, which a one-line ignore does not reach.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT COALESCE( r.type, '(missing root)' ) AS root_type, COUNT(*) AS n
			   FROM `{$table}` c
			   LEFT JOIN `{$table}` r ON r.id = c.item_id AND r.is_spam = 0
			  WHERE c.type = 'activity_comment' AND c.is_spam = 0
			  GROUP BY root_type
			  ORDER BY n DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $row ) {
			$type  = (string) $row['root_type'];
			$out[] = array(
				'type'       => $type,
				'comments'   => (int) $row['n'],
				// Only a comment on an imported root can itself be imported.
				'importable' => in_array( $type, self::IMPORTED_ACTIVITY_TYPES, true ),
			);
		}

		return $out;
	}

	/**
	 * How many comments can actually migrate - those on an imported root.
	 */
	public function importable_comment_count(): int {
		$total = 0;
		foreach ( $this->comment_root_types() as $row ) {
			if ( $row['importable'] ) {
				$total += (int) $row['comments'];
			}
		}

		return $total;
	}

	/**
	 * Content this source holds that the importer cannot carry.
	 *
	 * Rule 3 says a silent shortfall is the worst bug this tool can have, and
	 * the worst version of it is content that never appears in a shortfall at
	 * all: rtMedia photos and albums are not counted by any step, so nothing
	 * compared them against anything and the migration reported success while
	 * leaving a member's whole gallery behind.
	 *
	 * Declared rather than imported. Saying "your source has 14 photos this
	 * importer does not carry" before a run is a decision the owner can act on;
	 * discovering it afterwards, with the old site already deleted, is not.
	 *
	 * @return array<int,array{reason:string,rows:int}>
	 */
	public function unsupported_content(): array {
		$out = array();

		if ( ! $this->table_exists( 'rt_rtm_media' ) ) {
			return $out;
		}

		$albums = $this->table_count( 'rt_rtm_media', "media_type = 'album'" );

		// ONLY the media no activity carries. A photo posted through rtMedia
		// rides its rtmedia_update activity into a post and does migrate, so
		// counting every rtMedia row here would warn an owner about content that
		// is sitting in their new community - the opposite failure to the one
		// this notice exists to prevent, and just as corrosive.
		$stranded = $this->table_count( 'rt_rtm_media', "media_type <> 'album' AND COALESCE( activity_id, 0 ) = 0" );

		if ( $stranded > 0 ) {
			$out[] = array(
				'reason' => __( 'rtMedia photos and videos that were never posted to activity - they live only in an album, which this importer does not read yet', 'buddynext-importer' ),
				'rows'   => $stranded,
			);
		}

		if ( $albums > 0 ) {
			$out[] = array(
				'reason' => __( 'rtMedia albums - the albums themselves are not carried, so their contents have nowhere to land', 'buddynext-importer' ),
				'rows'   => $albums,
			);
		}

		return $out;
	}

	/**
	 * Comments that COULD have migrated but whose own root post did not.
	 *
	 * The precise size of the comment shortfall, and the only number that
	 * belongs beside it: comments on a root type this importer never carries are
	 * already excluded from the expected total, so counting them here would
	 * report a reason larger than the gap it explains.
	 *
	 * Lives on the adapter because it needs IMPORTED_ACTIVITY_TYPES, and that
	 * list is deliberately in one place - a second copy in the verifier is how
	 * the count and the fetch drift apart.
	 *
	 * @param string $source Source key, for the id-map lookup.
	 */
	public function orphaned_importable_comment_count( string $source ): int {
		global $wpdb;

		$map = $wpdb->prefix . 'bni_id_map';
		if ( ! $this->table_exists( 'bp_activity' ) ) {
			return 0;
		}

		$types = "'" . implode( "','", self::IMPORTED_ACTIVITY_TYPES ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity c
				  INNER JOIN {$wpdb->prefix}bp_activity r ON r.id = c.item_id
				  WHERE c.type = 'activity_comment' AND c.is_spam = 0
				    AND r.type IN ( {$types} )
				    AND NOT EXISTS (
				        SELECT 1 FROM `{$map}` m
				         WHERE m.source = %s AND m.domain = 'post' AND m.source_id = c.item_id
				    )",
				$source
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Referential integrity of this source. {@see SourceAdapter::relationship_report()}
	 *
	 * @return array<int,array{relation:string,total:int,broken:int,fatal:bool,note:string}>
	 */
	public function relationship_report(): array {
		global $wpdb;

		$p   = $wpdb->prefix;
		$out = array();

		/**
		 * Add one check, skipping it when the table is not installed.
		 *
		 * @param string $relation Description.
		 * @param string $table    Table the relation lives on, without prefix.
		 * @param string $total    COUNT expression for participating rows.
		 * @param string $broken   COUNT expression for rows that break it.
		 * @param bool   $fatal    Whether a broken row loses data.
		 * @param string $note     Why, when expected.
		 */
		$add = function ( string $relation, string $table, string $total, string $broken, bool $fatal = true, string $note = '' ) use ( &$out, $wpdb ): void {
			if ( ! $this->table_exists( $table ) ) {
				return;
			}

			$out[] = array(
				'relation' => $relation,
				'total'    => (int) $wpdb->get_var( $total ), // phpcs:ignore WordPress.DB
				'broken'   => (int) $wpdb->get_var( $broken ), // phpcs:ignore WordPress.DB
				'fatal'    => $fatal,
				'note'     => $note,
			);
		};

		// -- profiles ---------------------------------------------------------- //

		$add(
			'profile field -> field group',
			'bp_xprofile_fields',
			"SELECT COUNT(*) FROM {$p}bp_xprofile_fields WHERE parent_id = 0",
			"SELECT COUNT(*) FROM {$p}bp_xprofile_fields f WHERE f.parent_id = 0 AND NOT EXISTS ( SELECT 1 FROM {$p}bp_xprofile_groups g WHERE g.id = f.group_id )"
		);
		$add(
			'profile value -> field',
			'bp_xprofile_data',
			"SELECT COUNT(*) FROM {$p}bp_xprofile_data",
			"SELECT COUNT(*) FROM {$p}bp_xprofile_data d WHERE NOT EXISTS ( SELECT 1 FROM {$p}bp_xprofile_fields f WHERE f.id = d.field_id )"
		);
		$add(
			'profile value -> user',
			'bp_xprofile_data',
			"SELECT COUNT(*) FROM {$p}bp_xprofile_data",
			"SELECT COUNT(*) FROM {$p}bp_xprofile_data d WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = d.user_id )"
		);

		// -- groups ------------------------------------------------------------ //

		$add(
			'group -> creator',
			'bp_groups',
			"SELECT COUNT(*) FROM {$p}bp_groups",
			"SELECT COUNT(*) FROM {$p}bp_groups g WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = g.creator_id )"
		);
		$add(
			'membership -> group',
			'bp_groups_members',
			"SELECT COUNT(*) FROM {$p}bp_groups_members",
			"SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE NOT EXISTS ( SELECT 1 FROM {$p}bp_groups g WHERE g.id = m.group_id )"
		);
		$add(
			'membership -> user',
			'bp_groups_members',
			"SELECT COUNT(*) FROM {$p}bp_groups_members",
			"SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = m.user_id )"
		);

		// -- activity ---------------------------------------------------------- //

		$add(
			'activity -> author',
			'bp_activity',
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE is_spam = 0",
			"SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.is_spam = 0 AND NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = a.user_id )"
		);
		// A group post with no resolvable group cannot be placed, and is refused
		// rather than published to the global feed - so it is a real loss.
		$add(
			'group activity -> group',
			'bp_activity',
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE component = 'groups' AND is_spam = 0",
			"SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.component = 'groups' AND a.is_spam = 0 AND NOT EXISTS ( SELECT 1 FROM {$p}bp_groups g WHERE g.id = a.item_id )"
		);
		$add(
			'comment -> root activity',
			'bp_activity',
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND is_spam = 0",
			"SELECT COUNT(*) FROM {$p}bp_activity c WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND NOT EXISTS ( SELECT 1 FROM {$p}bp_activity r WHERE r.id = c.item_id )"
		);
		$add(
			'comment root is a type we carry',
			'bp_activity',
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND is_spam = 0",
			"SELECT COUNT(*) FROM {$p}bp_activity c JOIN {$p}bp_activity r ON r.id = c.item_id WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND r.type NOT IN ( '" . implode( "','", self::IMPORTED_ACTIVITY_TYPES ) . "' )",
			false,
			'system notices have no BuddyNext equivalent'
		);

		// -- connections ------------------------------------------------------- //

		$add(
			'friendship -> both users',
			'bp_friends',
			"SELECT COUNT(*) FROM {$p}bp_friends",
			"SELECT COUNT(*) FROM {$p}bp_friends f WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = f.initiator_user_id ) OR NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u2 WHERE u2.ID = f.friend_user_id )"
		);
		$add(
			'no self-friendship',
			'bp_friends',
			"SELECT COUNT(*) FROM {$p}bp_friends",
			"SELECT COUNT(*) FROM {$p}bp_friends WHERE initiator_user_id = friend_user_id"
		);

		// -- messages ---------------------------------------------------------- //

		$add(
			'message -> sender',
			'bp_messages_messages',
			"SELECT COUNT(*) FROM {$p}bp_messages_messages",
			"SELECT COUNT(*) FROM {$p}bp_messages_messages m WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = m.sender_id )"
		);
		$add(
			'thread has two participants',
			'bp_messages_recipients',
			"SELECT COUNT(DISTINCT thread_id) FROM {$p}bp_messages_recipients",
			"SELECT COUNT(*) FROM ( SELECT thread_id FROM {$p}bp_messages_recipients GROUP BY thread_id HAVING COUNT(DISTINCT user_id) < 2 ) x",
			false,
			'a one-sided thread is refused by the DM engine'
		);

		// -- classifications --------------------------------------------------- //

		$add(
			'member type assignment -> user',
			'bp_activity',
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '" . self::MEMBER_TYPE_TAXONOMY . "'",
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '" . self::MEMBER_TYPE_TAXONOMY . "' WHERE NOT EXISTS ( SELECT 1 FROM {$wpdb->users} u WHERE u.ID = tr.object_id )"
		);
		$add(
			'group type assignment -> group',
			'bp_groups',
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_group_type'",
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_group_type' WHERE NOT EXISTS ( SELECT 1 FROM {$p}bp_groups g WHERE g.id = tr.object_id )"
		);

		return $out;
	}

	/**
	 * Group types, which become BuddyNext space categories.
	 *
	 * BuddyPress registers group types as a TAXONOMY (`bp_group_type`), so the
	 * definitions are terms and the assignments are term relationships against
	 * the group id. BuddyBoss additionally keeps a `bp-group-type` post for each
	 * one, which is where its human label lives - so the post title is preferred
	 * when present and the term name is the fallback.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function group_types(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT t.term_id, t.name, t.slug, tt.description
			   FROM {$wpdb->terms} t
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			  WHERE tt.taxonomy = 'bp_group_type'
			  ORDER BY t.term_id ASC",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$slug  = (string) $row['slug'];
			$label = (string) $row['name'];

			// BuddyBoss names the type on a post; the term slug is the join key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_title = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.post_title FROM {$wpdb->posts} p
					  INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_bp_group_type_key'
					  WHERE p.post_type = 'bp-group-type' AND p.post_status = 'publish' AND pm.meta_value = %s
					  LIMIT 1",
					$slug
				)
			);
			if ( is_string( $post_title ) && '' !== $post_title ) {
				$label = $post_title;
			}

			$out[] = array(
				'source_id'   => (int) $row['term_id'],
				'name'        => $label,
				'slug'        => $slug,
				'description' => (string) $row['description'],
			);
		}

		return $out;
	}

	/**
	 * Group-type term ids for a page of groups, keyed by group id.
	 *
	 * Batched deliberately: resolving a type per group would be one query per
	 * row inside the space loop, which is the N+1 this importer avoids
	 * everywhere else.
	 *
	 * A source group may carry SEVERAL types where a space has one category, so
	 * the order matters - the first is the one the space takes.
	 *
	 * @param array<int,int> $group_ids Source group ids.
	 * @return array<int,array<int,int>> group id => term ids, in term order.
	 */
	public function group_type_map( array $group_ids ): array {
		global $wpdb;

		$group_ids = array_values( array_unique( array_map( 'intval', $group_ids ) ) );
		if ( array() === $group_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

		// Interpolated: core table properties, plus a placeholder list built from a
		// count of ints. Every id still goes through prepare() as %d - the sniff
		// cannot see those placeholders because they arrive interpolated, hence
		// UnfinishedPrepare here too.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				  WHERE tt.taxonomy = 'bp_group_type' AND tr.object_id IN ( {$placeholders} )
				  ORDER BY tr.object_id ASC, tr.term_order ASC, tt.term_id ASC",
				...$group_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['object_id'] ][] = (int) $row['term_id'];
		}

		return $map;
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
		return $this->rtmedia_activity_attachments_for( array( $activity_id ) )[ $activity_id ] ?? array();
	}

	/**
	 * Batched activity media for a plain BuddyPress source (rtMedia).
	 *
	 * @param array<int,int> $activity_ids Source activity ids.
	 * @return array<int,array<int,int>> Activity id => attachment ids.
	 */
	public function activity_media_for( array $activity_ids ): array {
		return $this->rtmedia_activity_attachments_for( $activity_ids );
	}

	/**
	 * WP attachment ids of the rtMedia items attached to the given activities,
	 * grouped by activity id, in a single query.
	 *
	 * The rtMedia plugin ("buddypress-media") is how activity photos/videos/audio
	 * are created on a plain BuddyPress site - BuddyBoss uses bp_media instead.
	 * Each rt_rtm_media row's `media_id` column IS the WP attachment id, and
	 * `activity_id` links it to the source activity. Album rows carry no
	 * attachment and are excluded. Learned from WPMediaVerse Pro's rtMedia
	 * importer; here the attachments feed the SAME ingest path BuddyBoss media
	 * uses, so they become media on the migrated BuddyNext post rather than
	 * standalone records (BuddyPress is being replaced, not kept alongside).
	 *
	 * @param array<int,int> $activity_ids Source activity ids.
	 * @return array<int,array<int,int>> Activity id => attachment ids (row order).
	 */
	protected function rtmedia_activity_attachments_for( array $activity_ids ): array {
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

		if ( empty( $ids ) || ! $this->table_exists( 'rt_rtm_media' ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'rt_rtm_media';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT activity_id, media_id FROM `{$table}` WHERE activity_id IN ( {$placeholders} ) AND media_type <> 'album' AND media_id > 0 ORDER BY activity_id ASC, id ASC", $ids ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$aid = (int) $row['activity_id'];
			$mid = (int) $row['media_id'];
			if ( $aid > 0 && $mid > 0 ) {
				$out[ $aid ][] = $mid;
			}
		}

		return $out;
	}

	/**
	 * Count rtMedia items attached to activities (the BuddyPress activity-media
	 * source). 0 when rtMedia is not installed.
	 */
	protected function rtmedia_activity_count(): int {
		if ( ! $this->table_exists( 'rt_rtm_media' ) ) {
			return 0;
		}

		return $this->table_count( 'rt_rtm_media', "activity_id > 0 AND media_type <> 'album'" );
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
		$rows = $this->forum_posts( 'forum', self::FORUM_STATUSES['forum'], $after, $limit );

		global $wpdb;
		foreach ( $rows as &$row ) {
			// A group's forum records the owning group in `_bbp_group_ids` (a
			// serialized array — bbPress allows several, groups in practice have
			// one). 0 means a standalone site forum with no group behind it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_bbp_group_ids'", (int) $row['source_id'] ) );

			$group_ids       = $this->first_id_of( (string) $raw );
			$row['group_id'] = $group_ids;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * First positive integer in a meta value stored as either a serialized array
	 * or a CSV string. 0 when there is none.
	 *
	 * @param string $raw Stored meta value.
	 */
	protected function first_id_of( string $raw ): int {
		if ( '' === $raw ) {
			return 0;
		}

		$decoded = maybe_unserialize( $raw );
		$list    = is_array( $decoded ) ? $decoded : explode( ',', $raw );

		foreach ( $list as $value ) {
			$value = (int) $value;
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Source bbPresstopics, keyset-paginated by post id.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_topics( int $after, int $limit ): array {
		return $this->forum_posts( 'topic', self::FORUM_STATUSES['topic'], $after, $limit );
	}

	/**
	 * Source bbPressreplies, keyset-paginated by post id (with the nested reply target).
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_replies( int $after, int $limit ): array {
		$rows = $this->forum_posts( 'reply', self::FORUM_STATUSES['reply'], $after, $limit );

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
	 * Which post statuses each bbPress type is imported from.
	 *
	 * The readers below and the forum source stats both derive from this map, so
	 * the count and the fetch cannot drift. They already had: the topic count
	 * looked only at 'publish' while the reader also imports 'closed', so every
	 * closed topic was imported without ever being expected - imported exceeded
	 * expected, and a real topic shortfall could hide underneath it.
	 *
	 * bbPress encodes forum visibility in post_status, which is why the forum row
	 * carries four.
	 */
	protected const FORUM_STATUSES = array(
		'forum' => array( 'publish', 'private', 'hidden', 'public' ),
		'topic' => array( 'publish', 'closed' ),
		'reply' => array( 'publish' ),
	);

	/**
	 * Count bbPress posts of a type, over exactly the statuses its reader imports.
	 *
	 * @param string $post_type bbPress post type (forum|topic|reply).
	 */
	protected function forum_post_count( string $post_type ): int {
		global $wpdb;

		$statuses = self::FORUM_STATUSES[ $post_type ] ?? array( 'publish' );

		$status_ph = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params    = array_merge( array( $post_type ), $statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( {$status_ph} )", $params ) );
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
	 * The WHERE condition that decides which bp_follow rows are member follows.
	 *
	 * Shared by follows() and by the 'follows' source stat so the two can never
	 * disagree. They used to: the count read every row in bp_follow while the
	 * fetch excluded non-member follow types, so on BuddyBoss - where the same
	 * table also holds forum and blog subscriptions - every subscription was
	 * counted as an expected follow that could never arrive, inventing a
	 * permanent shortfall on a migration that had lost nothing.
	 *
	 * @return string Bare condition with no leading AND, or '' when the column
	 *                is absent (classic bp_follow holds member follows only).
	 */
	private function follow_type_where(): string {
		// Guard the TABLE before asking about the column. follows() has its own
		// table_exists() check above this, but stats() calls this to build a
		// count argument, which is evaluated before table_count() gets a chance
		// to guard - so on the many BuddyPress sites with no Follow plugin at
		// all, SHOW COLUMNS ran against a table that does not exist and logged a
		// database error on every stats() call.
		if ( ! $this->table_exists( 'bp_follow' ) ) {
			return '';
		}

		return $this->column_exists( 'bp_follow', 'follow_type' )
			? "( follow_type = '' OR follow_type = 'user' )"
			: '';
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

		$table        = $wpdb->prefix . 'bp_follow';
		$date_col     = $this->column_exists( 'bp_follow', 'date_recorded' ) ? ', date_recorded' : '';
		$follow_where = $this->follow_type_where();
		$type_sql     = '' !== $follow_where ? ' AND ' . $follow_where : '';

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
		// The meta_value filter MUST match favorites_count() exactly. BuddyPress
		// leaves 'a:0:{}' behind when a member un-favourites their last item, and
		// this LIMIT counts USERS while the loop below emits favourites - so a
		// page of consecutive emptied members returned zero rows. The reactions
		// step is empty_done, meaning an empty batch is read as "domain
		// finished", so every reaction above that gap was silently dropped and
		// the checkpoint recorded the truncation as a completed domain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'bp_favorite_activities' AND meta_value NOT IN ( '', 'a:0:{}' ) AND user_id > %d ORDER BY user_id ASC LIMIT %d", $after, $limit ), ARRAY_A );

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
			// Participants = recipients UNION senders. A recipient row can be gone
			// (the member deleted the thread on their side, or was removed) while
			// their messages remain, and MVS refuses any message whose sender is
			// not a participant of the conversation — reading recipients alone
			// silently drops that member's whole side of the history.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$participants = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT user_id FROM `{$rcp}` WHERE thread_id = %d
						UNION
						SELECT DISTINCT sender_id FROM `{$msg}` WHERE thread_id = %d",
						$thread_id,
						$thread_id
					)
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * Memoised for the request. This is a guard on 40-odd read paths, one of them
	 * inside the per-activity loop in activity_media_for(), so an uncached SHOW
	 * TABLES here is one query per activity carrying media - a per-row query
	 * sitting inside the batched lookup that exists to remove per-row queries.
	 * The cache is static rather than per-instance because AdapterRegistry::all()
	 * constructs fresh adapters on every call, so an instance cache would almost
	 * never be reused.
	 *
	 * Safe to cache: the source schema is fixed for the life of a migration
	 * request. Nothing creates or drops a bp_* table mid-run, and the source
	 * platform is deactivated before the import starts.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 */
	protected function table_exists( string $unprefixed ): bool {
		global $wpdb;

		static $cache = array();

		$table = $wpdb->prefix . $unprefixed;

		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$cache[ $table ] = ( $found === $table );

		return $cache[ $table ];
	}
}
