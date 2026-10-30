<?php
/**
 * PeepSo read adapter. Emits the same record shape the BuddyPress writers
 * already accept. It does not write anything.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source\PeepSo;

use BuddyNextImporter\Source\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a PeepSo community from the local database.
 */
final class PeepSoAdapter implements SourceAdapter {

	/**
	 * Activity modules whose root rows become posts.
	 *
	 * 1 status, 5 video and audio (the file is attached when it is a real upload),
	 * 8 group post, 9 page post (a page is imported as a space, which is the
	 * place its posts and members can still be used), 30 poll, 50 file post (the
	 * text comes, the document does not), 111 post
	 * background (the text comes, the preset does not), 6661 journal card.
	 */
	private const POST_MODULES = array( 1, 5, 8, 9, 30, 50, 111, 6661 );

	/**
	 * Video ids are small integers and would collide with attachment ids if a
	 * later pass ingested both into the same map. Offset them out of that range.
	 */
	private const VIDEO_MAP_OFFSET = 8000000000000000000;

	/**
	 * BuddyNext's reaction set.
	 */
	private const BN_REACTIONS = array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );

	/**
	 * act_external_id => activity row. Built once per request.
	 *
	 * @var array<int,array<string,int>>|null
	 */
	private ?array $by_external = null;

	/**
	 * basename => absolute path, thumbs excluded.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $files = null;

	/**
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $fields = null;

	/**
	 * [ member id, peepso_users row or null ] for the last member read.
	 *
	 * @var array{0:int,1:array<string,mixed>|null}|null
	 */
	private ?array $user_row_cache = null;

	public function key(): string {
		return 'peepso';
	}

	public function label(): string {
		return 'PeepSo';
	}

	public function is_available(): bool {
		return $this->table_exists( 'peepso_activities' );
	}

	public function stats(): array {
		return array(
			'users'                        => $this->count_sql( "SELECT COUNT(*) FROM {$this->users()} WHERE usr_role NOT IN ('ban','register')" ),
			'profile_fields'               => count( $this->profile_fields() ),
			'profile_values'               => $this->profile_value_count(),
			'groups'                       => $this->count_posts( 'peepso-group' ) + $this->count_posts( 'peepso-page' ),
			'group_members'                => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_group_members' )} WHERE gm_user_status <> 'member_owner'" ) + $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_page_members' )} WHERE pm_user_status <> 'member_owner'" ),
			'activities'                   => $this->activity_count( false ),
			'activity_comments'            => $this->activity_count( true ),
			'activity_comments_importable' => $this->importable_comment_count(),
			'friendships'                  => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_friends' )}" ) + $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_friend_requests' )}" ),
			'follows'                      => $this->follow_count(),
			'blocks'                       => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_blocks' )}" ),
			'bookmarks'                    => $this->table_exists( 'peepso_saved_posts' ) ? $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_saved_posts' )}" ) : 0,
			'forums'                       => 0,
			'forum_topics'                 => 0,
			'forum_replies'                => 0,
			'group_types'                  => count( $this->group_types() ),
			'reactions'                    => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_reactions' )}" ),
			'message_threads'              => $this->count_posts( 'peepso-message', 'post_parent = 0' ),
			'activity_media'               => $this->attached_file_count(),
			'media_albums'                 => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_photos_album' )} WHERE pho_system_album = 0" ),
			'album_media'                  => $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_photos' )} ph JOIN {$this->t( 'peepso_photos_album' )} al ON al.pho_album_id = ph.pho_album_id WHERE al.pho_system_album = 0" ),
			'standalone_media'             => 0,
			'member_types'                 => count( $this->held_vip_icons() ),
			'member_type_users'            => $this->count_sql( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->usermeta} WHERE meta_key = 'peepso_vip_user_icon' AND meta_value NOT IN ( '', 'a:0:{}' )" ),
			'member_images'                => $this->count_sql( "SELECT COUNT(*) FROM {$this->users()} WHERE usr_role NOT IN ('ban','register')" ),
			'group_images'                 => $this->count_posts( 'peepso-group' ) + $this->count_posts( 'peepso-page' ),
		);
	}

	public function profile_groups(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		return array(
			array(
				'source_id'   => 1,
				'name'        => 'Profile',
				'description' => '',
				'sort_order'  => 0,
			),
		);
	}

	public function profile_fields(): array {
		global $wpdb;

		if ( ! $this->is_available() ) {
			return array();
		}
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		// A field's definition lives in its postmeta: `class` is the field type,
		// `select_options` the slug => label choices, `validation` the rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_name, acc.meta_value AS default_acc,
				cls.meta_value AS field_class, opt.meta_value AS select_options, val.meta_value AS validation
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} acc ON acc.post_id = p.ID AND acc.meta_key = 'default_acc'
			LEFT JOIN {$wpdb->postmeta} cls ON cls.post_id = p.ID AND cls.meta_key = 'class'
			LEFT JOIN {$wpdb->postmeta} opt ON opt.post_id = p.ID AND opt.meta_key = 'select_options'
			LEFT JOIN {$wpdb->postmeta} val ON val.post_id = p.ID AND val.meta_key = 'validation'
			WHERE p.post_type = 'peepso_user_field' AND p.post_status = 'publish'
			ORDER BY p.menu_order ASC, p.ID ASC",
			ARRAY_A
		);

		$fields = array();
		$order  = 0;
		foreach ( (array) $rows as $row ) {
			$validation = maybe_unserialize( (string) $row['validation'] );
			$options    = maybe_unserialize( (string) $row['select_options'] );
			$fields[]   = array(
				'source_id'   => (int) $row['ID'],
				'group_id'    => 1,
				'name'        => (string) wp_unslash( $row['post_title'] ),
				'slug'        => (string) $row['post_name'],
				'type'        => $this->field_type( (string) $row['field_class'], (string) $row['post_name'] ),
				'is_required' => is_array( $validation ) && ! empty( $validation['required'] ) ? 1 : 0,
				'sort_order'  => $order,
				'visibility'  => $this->access_level( (int) $row['default_acc'] ),
				// slug => label, so a member's stored slug ("m") displays as its
				// label ("Male").
				'options'     => is_array( $options ) ? array_map( 'strval', $options ) : array(),
			);
			++$order;
		}

		$this->fields = $fields;

		return $fields;
	}

	public function profile_value_user_ids( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->is_available() ) {
			return array();
		}

		$table = $this->users();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT usr_id FROM `{$table}` WHERE usr_id > %d AND usr_role NOT IN ('ban','register') ORDER BY usr_id ASC LIMIT %d", $after, $limit ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * PeepSo keeps a field's value in usermeta under peepso_user_field_{slug}.
	 * The core fields First Name, Last Name and About Me reuse WordPress's own
	 * user meta, and Website is the WordPress user_url. Gender and birthdate
	 * also have a legacy column on peepso_users, read only when the meta is
	 * empty.
	 *
	 * @param int $user_id Source member id.
	 */
	public function profile_values( int $user_id ): array {
		$user   = get_userdata( $user_id );
		$legacy = $this->user_row( $user_id );

		$values = array();
		foreach ( $this->profile_fields() as $field ) {
			$slug = (string) $field['slug'];
			switch ( $slug ) {
				case 'first_name':
				case 'last_name':
				case 'description':
					$value = (string) get_user_meta( $user_id, $slug, true );
					break;
				case 'user_url':
					$value = $user instanceof \WP_User ? (string) $user->user_url : '';
					break;
				default:
					$value = get_user_meta( $user_id, 'peepso_user_field_' . $slug, true );
			}

			// Location and multi-select values are arrays, which get_user_meta()
			// has already unserialized. Re-serialize so the writer decodes them for
			// the field's type instead of the string cast printing "Array".
			$value = is_array( $value ) ? ( empty( $value ) ? '' : maybe_serialize( $value ) ) : trim( (string) $value );
			if ( '' === $value && null !== $legacy && 'gender' === $slug ) {
				$value = (string) $legacy['usr_gender'];
			}
			if ( '' === $value && null !== $legacy && 'birthdate' === $slug ) {
				$value = (string) $legacy['usr_birthdate'];
			}
			// A single choice that is not one of the field's options (PeepSo keeps
			// Gender's unset state as an empty or placeholder value) is not a value.
			if ( 'selectbox' === $field['type'] && ! empty( $field['options'] ) && ! isset( $field['options'][ $value ] ) ) {
				continue;
			}
			if ( '' === $value || '0000-00-00' === $value ) {
				continue;
			}

			$values[] = array(
				'field_id' => (int) $field['source_id'],
				'name'     => (string) $field['name'],
				'type'     => (string) $field['type'],
				'value'    => $value,
			);
		}

		return $values;
	}

	/**
	 * The member's peepso_users.usr_last_activity as a Unix time, 0 when never active.
	 *
	 * @param int $user_id Source member id.
	 */
	public function last_active( int $user_id ): int {
		$row  = $this->user_row( $user_id );
		$time = null !== $row ? strtotime( self::utc_from_local( $row['usr_last_activity'] ?? '' ) . ' UTC' ) : false;

		return false === $time ? 0 : max( 0, (int) $time );
	}

	/**
	 * A member's own choice per field, from peepso_user_field_{slug}_acc. The
	 * legacy usr_{slug}_acc column is the fallback for members PeepSo has not
	 * moved to usermeta yet.
	 *
	 * @param int $user_id Source member id.
	 */
	public function profile_visibility_levels( int $user_id ): array {
		$legacy = (array) $this->user_row( $user_id );

		$levels = array();
		foreach ( $this->profile_fields() as $field ) {
			$slug   = (string) $field['slug'];
			$stored = get_user_meta( $user_id, 'peepso_user_field_' . $slug . '_acc', true );
			if ( '' === $stored && isset( $legacy[ 'usr_' . $slug . '_acc' ] ) ) {
				$stored = $legacy[ 'usr_' . $slug . '_acc' ];
			}
			if ( '' === (string) $stored ) {
				continue;
			}
			$levels[ (int) $field['source_id'] ] = $this->access_level( (int) $stored );
		}

		return $levels;
	}

	/**
	 * PeepSo access level to the vocabulary PrivacyMap::field_visibility() reads.
	 *
	 * @param int $access PeepSo access level (10, 20, 30 or 40).
	 */
	private function access_level( int $access ): string {
		switch ( $access ) {
			case 20:
				return 'loggedin';
			case 30:
				return 'friends';
			case 40:
				return 'onlyme';
			default:
				return 'public';
		}
	}

	/**
	 * PeepSo VIP icons become member types, but only the icons a member holds.
	 * PeepSo ships eight "Verified icon" presets that most sites never hand out.
	 */
	public function member_types(): array {
		global $wpdb;

		$held = $this->held_vip_icons();
		if ( array() === $held ) {
			return array();
		}

		$in = implode( ',', array_keys( $held ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT ID, post_title, post_name, post_content FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ( 'peepso_vip', 'peepso_vip_user' ) ORDER BY menu_order ASC, ID ASC", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'slug'        => $this->vip_slug( (int) $row['ID'], (string) $row['post_name'] ),
				'name'        => (string) wp_unslash( $row['post_title'] ),
				'description' => wp_strip_all_tags( (string) wp_unslash( $row['post_content'] ) ),
			);
		}

		return $out;
	}

	/**
	 * One row per VIP icon a member holds, keyset-paginated by member.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Members per batch.
	 */
	public function member_type_assignments( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'peepso_vip_user_icon' AND user_id > %d ORDER BY user_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			foreach ( $this->vip_icon_ids( (string) $row['meta_value'] ) as $icon_id ) {
				$out[] = array(
					'user_id' => (int) $row['user_id'],
					'slug'    => $this->vip_slug( $icon_id, (string) get_post_field( 'post_name', $icon_id ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * Icon post id => number of members holding it.
	 *
	 * @return array<int,int>
	 */
	private function held_vip_icons(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'peepso_vip_user_icon'" );

		$held = array();
		foreach ( (array) $values as $value ) {
			foreach ( $this->vip_icon_ids( (string) $value ) as $icon_id ) {
				$held[ $icon_id ] = ( $held[ $icon_id ] ?? 0 ) + 1;
			}
		}

		return $held;
	}

	/**
	 * Icon ids from one stored peepso_vip_user_icon value.
	 *
	 * @param string $stored Raw usermeta value.
	 * @return array<int,int>
	 */
	private function vip_icon_ids( string $stored ): array {
		$ids = maybe_unserialize( $stored );
		$ids = is_array( $ids ) ? $ids : array( $ids );

		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && in_array( get_post_type( $id ), array( 'peepso_vip', 'peepso_vip_user' ), true ) ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Member-type slug for a VIP icon.
	 *
	 * @param int    $icon_id   Icon post id.
	 * @param string $post_name Icon post slug.
	 */
	private function vip_slug( int $icon_id, string $post_name ): string {
		$slug = sanitize_key( $post_name );
		return '' !== $slug ? $slug : 'peepso-vip-' . $icon_id;
	}

	public function groups( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->is_available() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_author, p.post_title, p.post_name, p.post_content, p.post_date_gmt,
					COALESCE( priv.meta_value, pagepriv.meta_value ) AS privacy,
					COALESCE( rules.meta_value, pagerules.meta_value ) AS rules
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} priv ON priv.post_id = p.ID AND priv.meta_key = 'peepso_group_privacy'
				LEFT JOIN {$wpdb->postmeta} pagepriv ON pagepriv.post_id = p.ID AND pagepriv.meta_key = 'peepso_page_privacy'
				LEFT JOIN {$wpdb->postmeta} rules ON rules.post_id = p.ID AND rules.meta_key = 'peepso_group_rules'
				LEFT JOIN {$wpdb->postmeta} pagerules ON pagerules.post_id = p.ID AND pagerules.meta_key = 'peepso_page_rules'
				WHERE p.post_type IN ( 'peepso-group', 'peepso-page' ) AND p.post_status = 'publish' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$groups = array();
		foreach ( (array) $rows as $row ) {
			$groups[] = array(
				'source_id'    => (int) $row['ID'],
				'creator_id'   => $this->group_owner( (int) $row['ID'], (int) $row['post_author'] ),
				'name'         => (string) wp_unslash( $row['post_title'] ),
				'slug'         => (string) $row['post_name'],
				'description'  => (string) wp_unslash( $row['post_content'] ),
				'status'       => $this->group_status( (string) $row['privacy'] ),
				'rules'        => (string) wp_unslash( (string) $row['rules'] ),
				'parent_id'    => 0,
				'date_created' => (string) $row['post_date_gmt'],
			);
		}

		return $groups;
	}

	public function group_members( int $group_id, int $after, int $limit ): array {
		global $wpdb;

		if ( 'peepso-page' === get_post_type( $group_id ) ) {
			return $this->page_members( $group_id, $after, $limit );
		}

		$table = $this->t( 'peepso_group_members' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gm_id, gm_user_id, gm_user_status, UNIX_TIMESTAMP(gm_joined) AS gm_joined FROM `{$table}` WHERE gm_group_id = %d AND gm_id > %d AND gm_user_status <> 'member_owner' ORDER BY gm_id ASC LIMIT %d",
				$group_id,
				$after,
				$limit
			),
			ARRAY_A
		);

		$members = array();
		foreach ( (array) $rows as $row ) {
			$status    = (string) $row['gm_user_status'];
			$members[] = array(
				'row_id'       => (int) $row['gm_id'],
				'user_id'      => (int) $row['gm_user_id'],
				'is_admin'     => 0,
				'is_mod'       => in_array( $status, array( 'member_moderator', 'member_manager' ), true ) ? 1 : 0,
				'is_confirmed' => in_array( $status, array( 'pending_user', 'pending_admin' ), true ) ? 0 : 1,
				'is_banned'    => 'banned' === $status ? 1 : 0,
				'date_created' => self::utc_from_unix( $row['gm_joined'] ),
			);
		}

		return $members;
	}

	/**
	 * Page members use the same roles as group members. The owner is the
	 * space creator, so they are not also written as a member.
	 *
	 * @return array<int,array<string,int|string>>
	 */
	private function page_members( int $page_id, int $after, int $limit ): array {
		global $wpdb;

		$table = $this->t( 'peepso_page_members' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_id, pm_user_id, pm_user_status, UNIX_TIMESTAMP(pm_joined) AS pm_joined FROM `{$table}` WHERE pm_page_id = %d AND pm_id > %d AND pm_user_status <> 'member_owner' ORDER BY pm_id ASC LIMIT %d",
				$page_id,
				$after,
				$limit
			),
			ARRAY_A
		);

		$members = array();
		foreach ( (array) $rows as $row ) {
			$status    = (string) $row['pm_user_status'];
			$members[] = array(
				'row_id'       => (int) $row['pm_id'],
				'user_id'      => (int) $row['pm_user_id'],
				'is_admin'     => 0,
				'is_mod'       => in_array( $status, array( 'member_moderator', 'member_manager' ), true ) ? 1 : 0,
				'is_confirmed' => in_array( $status, array( 'pending_user', 'pending_admin' ), true ) ? 0 : 1,
				'is_banned'    => 'banned' === $status ? 1 : 0,
				'date_created' => self::utc_from_unix( $row['pm_joined'] ),
			);
		}

		return $members;
	}

	public function group_types(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT ID, post_title, post_name, post_content FROM {$wpdb->posts} WHERE post_type = 'peepso-group-cat' AND post_status = 'publish' ORDER BY ID ASC", ARRAY_A );

		$types = array();
		foreach ( (array) $rows as $row ) {
			$types[] = array(
				'source_id'   => (int) $row['ID'],
				'name'        => (string) wp_unslash( $row['post_title'] ),
				'slug'        => (string) $row['post_name'],
				'description' => (string) wp_unslash( $row['post_content'] ),
			);
		}

		return $types;
	}

	public function group_type_map( array $group_ids ): array {
		global $wpdb;

		$group_ids = array_values( array_filter( array_map( 'intval', $group_ids ) ) );
		if ( array() === $group_ids ) {
			return array();
		}

		$in    = implode( ',', $group_ids );
		$table = $this->t( 'peepso_group_categories' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT gm_group_id, gm_cat_id FROM `{$table}` WHERE gm_group_id IN ({$in}) ORDER BY gc_id ASC", ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['gm_group_id'] ][] = (int) $row['gm_cat_id'];
		}

		return $map;
	}

	public function activities( int $after, int $limit ): array {
		global $wpdb;

		$modules = implode( ',', self::POST_MODULES );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.act_id, a.act_external_id, a.act_module_id, a.act_access, a.act_owner_id, a.act_repost_id,
					p.post_author, p.post_content, p.post_date_gmt,
					g.meta_value AS group_id, nsfw.meta_value AS nsfw, pin.meta_value AS pinned, pinend.meta_value AS pinned_until
				FROM {$this->t( 'peepso_activities' )} a
				JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
				LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'peepso_group_id'
				LEFT JOIN {$wpdb->postmeta} nsfw ON nsfw.post_id = p.ID AND nsfw.meta_key = 'peepso_nsfw'
				LEFT JOIN {$wpdb->postmeta} pin ON pin.post_id = p.ID AND pin.meta_key = 'peepso_pinned'
				LEFT JOIN {$wpdb->postmeta} pinend ON pinend.post_id = p.ID AND pinend.meta_key = 'peepso_pinned_until'
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'peepso_reported'
				WHERE a.act_comment_object_id = 0
					AND a.act_module_id IN ({$modules})
					AND p.post_type = 'peepso-post'
					AND p.post_status = 'publish'
					AND r.meta_id IS NULL
					AND a.act_id > %d
				ORDER BY a.act_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$post_ids = array();
		foreach ( (array) $rows as $row ) {
			$post_ids[] = (int) $row['act_external_id'];
		}
		$files = $this->files_for_posts( $post_ids );
		$links = $this->external_video_urls( $post_ids );
		$polls = $this->polls_for_posts( $post_ids );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$post_id  = (int) $row['act_external_id'];
			$module   = (int) $row['act_module_id'];
			$group_id = $this->group_for_activity( $row );
			$access   = (int) $row['act_access'];
			$content  = (string) wp_unslash( $row['post_content'] );
			$blog_id  = 0;

			if ( 6661 === $module ) {
				$decoded = json_decode( $content, true );
				$blog_id = is_array( $decoded ) ? (int) ( $decoded['post_id'] ?? 0 ) : 0;
				$title   = (string) get_post_meta( $post_id, 'peepso_human_friendly', true );
				$content = '' !== $title ? $title : '';
			}

			$poll = $polls[ $post_id ] ?? null;
			$type = 'activity_update';
			if ( 6661 === $module ) {
				$type = 'new_blog_post';
			} elseif ( is_array( $poll ) ) {
				$type = 'poll';
			}

			$out[] = array(
				'source_id'         => (int) $row['act_id'],
				'user_id'           => (int) $row['post_author'],
				'component'         => $group_id > 0 ? 'groups' : 'activity',
				'source_type'       => $type,
				'item_id'           => $group_id,
				'secondary_item_id' => $blog_id,
				'primary_link'      => $blog_id > 0 ? (string) get_permalink( $blog_id ) : '',
				'content'           => $this->rewrite_mentions( $content ),
				'date_recorded'     => (string) $row['post_date_gmt'],
				'hide_sitewide'     => ( $group_id > 0 && 2 === $this->group_privacy( $group_id ) ) ? 1 : 0,
				'privacy'           => $this->post_privacy( $access ),
				'media_paths'       => $files[ $post_id ] ?? array(),
				'link_url'          => (string) ( $links[ $post_id ]['url'] ?? '' ),
				'link_preview'      => isset( $links[ $post_id ] ) ? array_diff_key( $links[ $post_id ], array( 'url' => true ) ) : array(),
				'poll_options'      => is_array( $poll ) ? $poll['options'] : array(),
				'poll_votes'        => is_array( $poll ) ? $poll['votes'] : array(),
				'content_warning'   => ! empty( $row['nsfw'] ),
				'pinned'            => $this->is_pinned( (string) $row['pinned'], (string) $row['pinned_until'] ),
				// The original's act_id, which is also its source id in the post map.
				'repost_of'         => (int) $row['act_repost_id'],
			);
		}

		return $out;
	}

	/**
	 * PeepSo stores the pin time and an optional end time as Unix timestamps.
	 * A pin whose end time has passed is no longer on the stream.
	 *
	 * @param string $pinned peepso_pinned value.
	 * @param string $until  peepso_pinned_until value.
	 */
	private function is_pinned( string $pinned, string $until ): bool {
		if ( (int) $pinned <= 0 ) {
			return false;
		}
		return (int) $until <= 0 || (int) $until > time();
	}

	public function activity_comments( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.act_id, a.act_external_id, a.act_comment_object_id, p.post_author, p.post_content, p.post_date_gmt
				FROM {$this->t( 'peepso_activities' )} a
				JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'peepso_reported'
				WHERE a.act_comment_object_id > 0
					AND p.post_type = 'peepso-comment'
					AND p.post_status = 'publish'
					AND r.meta_id IS NULL
					AND a.act_id > %d
				ORDER BY a.act_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$place = $this->comment_place( (int) $row['act_comment_object_id'] );
			if ( $place['root_id'] <= 0 ) {
				continue;
			}

			$out[] = array(
				'source_id'         => (int) $row['act_id'],
				'user_id'           => (int) $row['post_author'],
				'root_id'           => $place['root_id'],
				'secondary_item_id' => $place['parent_id'],
				'content'           => $this->rewrite_mentions( (string) wp_unslash( $row['post_content'] ) ),
				'date_recorded'     => (string) $row['post_date_gmt'],
			);
		}

		return $out;
	}

	public function activity_media( int $activity_id ): array {
		unset( $activity_id );
		return array();
	}

	public function activity_media_for( array $activity_ids ): array {
		unset( $activity_ids );
		return array();
	}

	/**
	 * PeepSo has no WPMediaVerse activity-linkage table. Post media already
	 * rides the post through media_paths, so this stays empty and the engine
	 * media ids are never mixed with those paths.
	 *
	 * @param array<int,int> $activity_ids Source activity ids.
	 * @return array<int,array<int,int>>
	 */
	public function mvs_activity_media_for( array $activity_ids ): array {
		unset( $activity_ids );
		return array();
	}

	/**
	 * One keyset batch of PeepSo saved posts.
	 *
	 * saved_posts.post_id is the activity id: PeepSo's own saved feed joins it
	 * to act_id (activity/classes/activity.php). A LEFT JOIN keeps a row whose
	 * activity is gone, so it is reported by the writer instead of vanishing,
	 * and a page of such rows cannot end the keyset loop early.
	 *
	 * @param int $after Exclusive lower-bound saved-post id.
	 * @param int $limit Batch size.
	 * @return array<int,array{source_id:int,user_id:int,activity_id:int,date_created:string}>
	 */
	public function bookmarks( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'peepso_saved_posts' ) ) {
			return array();
		}

		$saved      = $this->t( 'peepso_saved_posts' );
		$activities = $this->t( 'peepso_activities' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS source_id, s.user_id AS user_id, a.act_id AS activity_id, s.timestamp AS date_created
				 FROM `{$saved}` s
				 LEFT JOIN `{$activities}` a ON a.act_id = s.post_id
				 WHERE s.id > %d
				 ORDER BY s.id ASC
				 LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['source_id'],
				'user_id'      => (int) $row['user_id'],
				'activity_id'  => (int) $row['activity_id'],
				// A DATETIME, not a database-filled TIMESTAMP, and PeepSo's own save
				// endpoint never sets it: on a real site it is NULL and the
				// bookmark is dated at the run. A value put there by other code
				// is taken as UTC, as PHP's date() runs under WordPress.
				'date_created' => (string) $row['date_created'],
			);
		}

		return $out;
	}

	public function friendships( int $after, int $limit ): array {
		global $wpdb;

		$friends  = $this->t( 'peepso_friends' );
		$requests = $this->t( 'peepso_friend_requests' );

		// Pending ids are offset so they never share a keyset id with an accepted row.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
					SELECT fnd_id AS source_id, fnd_user_id AS initiator_id, fnd_friend_id AS friend_id, 1 AS is_confirmed, UNIX_TIMESTAMP(fnd_created) AS created_unix, CAST(NULL AS CHAR) AS created_local
					FROM `{$friends}`
					UNION ALL
					SELECT freq_id + 1000000000, freq_user_id, freq_friend_id, 0, NULL, CAST(freq_created AS CHAR)
					FROM `{$requests}`
				) pairs
				WHERE source_id > %d
				ORDER BY source_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['source_id'],
				'initiator_id' => (int) $row['initiator_id'],
				'friend_id'    => (int) $row['friend_id'],
				'is_confirmed' => (int) $row['is_confirmed'],
				// Accepted rows carry the database's own stamp; PeepSo writes a
				// request's time itself, on the site clock.
				'date_created' => (int) $row['created_unix'] > 0 ? self::utc_from_unix( $row['created_unix'] ) : self::utc_from_local( $row['created_local'] ),
			);
		}

		return $out;
	}

	public function follows( int $after, int $limit ): array {
		global $wpdb;

		$follows  = $this->t( 'peepso_user_followers' );
		$requests = $this->t( 'peepso_friend_requests' );

		// A friend request also writes a follower row. That is not a follow.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uf_id, uf_active_user_id, uf_passive_user_id
				FROM `{$follows}` f
				WHERE f.uf_follow = 1 AND f.uf_id > %d
					AND NOT EXISTS (
						SELECT 1 FROM `{$requests}` r
						WHERE r.freq_user_id = f.uf_active_user_id AND r.freq_friend_id = f.uf_passive_user_id
					)
				ORDER BY uf_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'     => (int) $row['uf_id'],
				'follower_id'   => (int) $row['uf_active_user_id'],
				'leader_id'     => (int) $row['uf_passive_user_id'],
				'date_recorded' => '',
			);
		}

		return $out;
	}

	/**
	 * Member blocks from peepso_blocks.
	 *
	 * @param int $after Exclusive lower-bound block id.
	 * @param int $limit Batch size.
	 */
	public function blocks( int $after, int $limit ): array {
		global $wpdb;

		if ( ! $this->table_exists( 'peepso_blocks' ) ) {
			return array();
		}

		$table = $this->t( 'peepso_blocks' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT blk_id, blk_user_id, blk_blocked_id FROM `{$table}` WHERE blk_id > %d ORDER BY blk_id ASC LIMIT %d", $after, $limit ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'  => (int) $row['blk_id'],
				'blocker_id' => (int) $row['blk_user_id'],
				'blocked_id' => (int) $row['blk_blocked_id'],
			);
		}

		return $out;
	}

	public function reactions( int $after, int $limit ): array {
		global $wpdb;

		$table = $this->t( 'peepso_reactions' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.reaction_id, r.reaction_user_id, r.reaction_act_id, UNIX_TIMESTAMP(r.reaction_timestamp) AS reaction_timestamp, p.post_name AS reaction_name
				FROM `{$table}` r
				LEFT JOIN {$wpdb->posts} p ON p.ID = r.reaction_type
				WHERE r.reaction_id > %d ORDER BY r.reaction_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['reaction_id'],
				'user_id'      => (int) $row['reaction_user_id'],
				'activity_id'  => (int) $row['reaction_act_id'],
				'date_created' => self::utc_from_unix( $row['reaction_timestamp'] ),
				'emoji'        => $this->reaction_emoji( (string) $row['reaction_name'] ),
			);
		}

		return $out;
	}

	/**
	 * PeepSo reaction name to a BuddyNext reaction. Names BuddyNext has no
	 * match for (wink, crazy, speechless, grateful, celebrate, custom ones)
	 * land as a like and are declared in unsupported_content().
	 *
	 * @param string $name Reaction definition post slug.
	 */
	private function reaction_emoji( string $name ): string {
		return in_array( $name, self::BN_REACTIONS, true ) ? $name : 'like';
	}

	public function message_threads( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$roots = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_author, post_date_gmt, post_content FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = 0 AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		// A thread's members are its participants rows (recipients as the
		// fallback), not just whoever wrote in it: a message nobody answered has
		// one author but two people, and the writer needs both.
		$members = $this->thread_members( array_map( static fn( array $r ): int => (int) $r['ID'], (array) $roots ) );

		$out = array();
		foreach ( (array) $roots as $root ) {
			$thread_id = (int) $root['ID'];
			$people    = array_merge( array( (int) $root['post_author'] ), $members[ $thread_id ] ?? array() );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$authors = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = %d", $thread_id ) );
			foreach ( (array) $authors as $author ) {
				$people[] = (int) $author;
			}

			$out[] = array(
				'thread_id'    => $thread_id,
				'participants' => array_values( array_unique( array_filter( $people ) ) ),
				'subject'      => wp_trim_words( wp_strip_all_tags( (string) $root['post_content'] ), 8, '' ),
				'date_sent'    => (string) $root['post_date_gmt'],
			);
		}

		return $out;
	}

	/**
	 * Thread root id => member ids, from peepso_message_participants and
	 * peepso_message_recipients, in two queries for the whole batch.
	 *
	 * @param array<int,int> $thread_ids Root message ids.
	 * @return array<int,array<int,int>>
	 */
	private function thread_members( array $thread_ids ): array {
		global $wpdb;

		$thread_ids = array_values( array_filter( array_map( 'intval', $thread_ids ) ) );
		if ( empty( $thread_ids ) ) {
			return array();
		}

		$in      = implode( ',', $thread_ids );
		$members = array();
		$sources = array(
			array( 'peepso_message_participants', 'mpart_msg_id', 'mpart_user_id' ),
			array( 'peepso_message_recipients', 'mrec_parent_id', 'mrec_user_id' ),
		);
		foreach ( $sources as list( $table, $thread_col, $user_col ) ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			// $in is a list of ints built above; table and column names are literals.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT DISTINCT `{$thread_col}` AS thread_id, `{$user_col}` AS user_id FROM `{$this->t( $table )}` WHERE `{$thread_col}` IN ({$in})", ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$members[ (int) $row['thread_id'] ][] = (int) $row['user_id'];
			}
		}

		return $members;
	}

	public function thread_messages( int $thread_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_author, post_content, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_status = 'publish' AND (ID = %d OR post_parent = %d) ORDER BY post_date_gmt ASC, ID ASC",
				$thread_id,
				$thread_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id' => (int) $row['ID'],
				'sender_id' => (int) $row['post_author'],
				'content'   => (string) wp_unslash( $row['post_content'] ),
				'date_sent' => (string) $row['post_date_gmt'],
			);
		}

		return $out;
	}

	public function member_images( int $after, int $limit ): array {
		return $this->images( 'users', $after, $limit );
	}

	public function group_images( int $after, int $limit ): array {
		return $this->images( 'groups', $after, $limit );
	}

	public function media_albums( int $after, int $limit ): array {
		global $wpdb;

		$table = $this->t( 'peepso_photos_album' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pho_album_id, pho_owner_id, pho_album_name, pho_album_desc, pho_album_acc, UNIX_TIMESTAMP(pho_created) AS pho_created FROM `{$table}` WHERE pho_system_album = 0 AND pho_album_id > %d ORDER BY pho_album_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['pho_album_id'],
				'user_id'      => (int) $row['pho_owner_id'],
				'group_id'     => 0,
				'title'        => (string) wp_unslash( $row['pho_album_name'] ),
				'description'  => (string) wp_unslash( (string) $row['pho_album_desc'] ),
				// Same vocabulary as profile fields: 20 is site members.
				'privacy'      => $this->access_level( (int) $row['pho_album_acc'] ),
				'date_created' => self::utc_from_unix( $row['pho_created'] ),
			);
		}

		return $out;
	}

	public function album_media( int $after, int $limit ): array {
		return $this->photo_rows( $after, $limit, true );
	}

	public function standalone_media( int $after, int $limit ): array {
		unset( $after, $limit );
		// Stream photos ride their post via media_paths. A second copy here
		// would put the same file in the library twice.
		return array();
	}

	public function album_media_order( int $source_album_id ): array {
		global $wpdb;

		$table = $this->t( 'peepso_photos' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT pho_id FROM `{$table}` WHERE pho_album_id = %d ORDER BY pho_created ASC, pho_id ASC", $source_album_id ) );

		return array_map( 'intval', (array) $ids );
	}

	public function forums( int $after, int $limit ): array {
		unset( $after, $limit );
		return array();
	}

	public function forum_topics( int $after, int $limit ): array {
		unset( $after, $limit );
		return array();
	}

	public function forum_replies( int $after, int $limit ): array {
		unset( $after, $limit );
		return array();
	}

	public function comment_root_types(): array {
		$importable   = 0;
		$not          = 0;
		foreach ( $this->comment_rows() as $row ) {
			$place = $this->comment_place( (int) $row['act_comment_object_id'] );
			if ( $place['root_id'] > 0 && $this->root_is_imported( $place['root_id'] ) ) {
				++$importable;
			} else {
				++$not;
			}
		}

		return array(
			array(
				'type'       => 'post',
				'comments'   => $importable,
				'importable' => true,
			),
			array(
				'type'       => 'other',
				'comments'   => $not,
				'importable' => false,
			),
		);
	}

	public function importable_comment_count(): int {
		$total = 0;
		foreach ( $this->comment_root_types() as $row ) {
			if ( $row['importable'] ) {
				$total += (int) $row['comments'];
			}
		}
		return $total;
	}

	public function unsupported_content(): array {
		$out = array();

		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_activities' )} WHERE act_module_id = 50 AND act_comment_object_id = 0" ), 'PeepSo file attachments. The post text imports. The file itself does not: the media engine refuses documents, same as BuddyBoss files.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_activities' )} WHERE act_module_id = 111 AND act_comment_object_id = 0" ), 'Post background presets. The words import as a status. BuddyNext has no background field.' );
		$this->declare( $out, $this->count_posts( 'peepso-story' ), 'PeepSo stories.' );
		$this->declare( $out, $this->count_posts( 'peepso-post', "post_status = 'future'" ), 'Scheduled posts. They are not published during the import.' );
		$this->declare( $out, $this->count_posts( 'peepso-post', "post_status = 'pending'" ), 'Posts waiting for approval. They were never published at the source, so they are not published here.' );
		$this->declare( $out, $this->reported_count(), 'Reported posts and comments. They are left out, the same way spam is left out.' );

		global $wpdb;
		$this->declare(
			$out,
			$this->count_sql(
				"SELECT COUNT(*) FROM {$this->t( 'peepso_group_followers' )} f
				LEFT JOIN {$this->t( 'peepso_group_members' )} m ON m.gm_group_id = f.gf_group_id AND m.gm_user_id = f.gf_user_id AND m.gm_user_status LIKE 'member%'
				WHERE m.gm_id IS NULL"
			) + $this->count_sql(
				"SELECT COUNT(*) FROM {$this->t( 'peepso_page_followers' )} f
				LEFT JOIN {$this->t( 'peepso_page_members' )} m ON m.pm_page_id = f.pf_page_id AND m.pm_user_id = f.pf_user_id AND m.pm_user_status LIKE 'member%'
				WHERE m.pm_id IS NULL"
			),
			'Group and page followers who are not members. BuddyNext has no way to follow a space without joining it.'
		);
		$this->declare(
			$out,
			$this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_group_followers' )} WHERE gf_follow = 0 OR gf_notify = 0 OR gf_email = 0" )
				+ $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_page_followers' )} WHERE pf_follow = 0 OR pf_notify = 0 OR pf_email = 0" ),
			'Members who muted a group or page, or turned off its notifications or emails. They are members in BuddyNext with the default notification settings.'
		);
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_activity_followers' )}" ), 'Post follows (the subscription that sends a member alerts about one post). BuddyNext alerts a post\'s author and the people in its thread by its own rules.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_activity_views' )}" ) + $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_activity_read' )}" ), 'Post view and read records. BuddyNext does not count views.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'peepso_last_edit'" ), 'The "edited" time on posts and comments. The edited text imports; the stamp does not.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$wpdb->postmeta} pin JOIN {$wpdb->postmeta} g ON g.post_id = pin.post_id AND g.meta_key = 'peepso_group_id' WHERE pin.meta_key = 'peepso_pinned'" ), 'Pinned group and page posts. BuddyNext pins posts on profiles only; a space features a post with an announcement.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_message_recipients' )} WHERE mrec_viewed = 0 AND mrec_deleted = 0" ), 'Unread message markers. Imported messages arrive as read.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_message_recipients' )} WHERE mrec_deleted = 1" ), 'Messages a member deleted from their own inbox. They arrive in that member\'s inbox again.' );
		$this->declare( $out, $this->count_posts( 'peepso_vip', "post_status = 'publish'" ) + $this->count_posts( 'peepso_vip_user', "post_status = 'publish'" ) - count( $this->held_vip_icons() ), 'VIP icons no member holds. Only icons a member holds become member types.' );
		$this->declare( $out, $this->unmapped_reaction_count(), 'Reactions BuddyNext has no match for (such as wink, crazy, speechless, grateful or celebrate). They import as a like.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->users()} WHERE usr_views > 0 OR usr_likes > 0" ), 'Members with profile view or profile like totals. BuddyNext does not count either.' );
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'peepso_postnotify'" ), 'Per-post notification choices made by an author. BuddyNext notifies a post\'s author and the people in its thread by its own rules.' );
		$this->declare( $out, $this->count_sql( "SELECT COALESCE( SUM( n - 1 ), 0 ) FROM ( SELECT COUNT(*) AS n FROM {$this->t( 'peepso_group_categories' )} GROUP BY gm_group_id HAVING COUNT(*) > 1 ) extra" ), 'Extra categories on a group. A BuddyNext space has one category, so each group keeps its first.' );
		$this->declare( $out, $this->custom_space_settings_count(), 'Groups and pages with settings BuddyNext has no equal for (read-only, closed to joining or invites, auto-accept, non-member posting). They import with BuddyNext\'s defaults.' );

		return $out;
	}

	/**
	 * Reactions whose type has no BuddyNext match.
	 */
	private function unmapped_reaction_count(): int {
		global $wpdb;

		$known = "'" . implode( "','", self::BN_REACTIONS ) . "'";
		return $this->count_sql(
			"SELECT COUNT(*) FROM {$this->t( 'peepso_reactions' )} r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.reaction_type
			WHERE p.post_name IS NULL OR p.post_name NOT IN ({$known})"
		);
	}

	/**
	 * Spaces whose join, invite, read-only or posting settings differ from
	 * PeepSo's defaults.
	 */
	private function custom_space_settings_count(): int {
		global $wpdb;

		return $this->count_sql(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			WHERE ( meta_key IN ( 'peepso_group_is_joinable', 'peepso_group_is_invitable', 'peepso_page_is_joinable', 'peepso_page_is_invitable' ) AND meta_value = '0' )
				OR ( meta_key IN ( 'peepso_group_is_readonly', 'peepso_group_is_auto_accept_join_request', 'peepso_group_is_allowed_non_member_actions', 'peepso_group_is_allowed_non_member_publish_post', 'peepso_page_is_readonly', 'peepso_page_is_auto_accept_join_request', 'peepso_page_is_allowed_non_member_actions' ) AND meta_value = '1' )"
		);
	}

	public function relationship_report(): array {
		$photos  = $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_photos' )}" );
		$missing = 0;
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( "SELECT pho_file_name FROM {$this->t( 'peepso_photos' )}" );
		foreach ( (array) $names as $name ) {
			if ( '' === $this->file_for( (string) $name ) ) {
				++$missing;
			}
		}

		return array(
			array(
				'relation' => 'Every photo row has its original file on disk',
				'total'    => $photos,
				'broken'   => $missing,
				'fatal'    => true,
				'note'     => '',
			),
		);
	}

	/**
	 * @param array<int,array{reason:string,rows:int}> $out
	 */
	private function declare( array &$out, int $rows, string $reason ): void {
		if ( $rows <= 0 ) {
			return;
		}
		$out[] = array(
			'reason' => $reason,
			'rows'   => $rows,
		);
	}

	/**
	 * @param array<int,int> $post_ids
	 * @return array<int,array<int,array{id:int,path:string,mime:string}>>
	 */
	private function files_for_posts( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$in    = implode( ',', $post_ids );
		$files = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$photos = $wpdb->get_results( "SELECT pho_id, pho_post_id, pho_file_name FROM {$this->t( 'peepso_photos' )} WHERE pho_post_id IN ({$in})", ARRAY_A );
		foreach ( (array) $photos as $photo ) {
			$path = $this->file_for( (string) $photo['pho_file_name'] );
			if ( '' === $path ) {
				continue;
			}
			$files[ (int) $photo['pho_post_id'] ][] = array(
				'id'   => (int) $photo['pho_id'],
				'path' => $path,
				'mime' => (string) wp_check_filetype( $path )['type'],
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$videos = $wpdb->get_results( "SELECT vid_id, vid_post_id, vid_url, vid_stored FROM {$this->t( 'peepso_videos' )} WHERE vid_stored = 1 AND vid_post_id IN ({$in})", ARRAY_A );
		foreach ( (array) $videos as $video ) {
			$post_id = (int) $video['vid_post_id'];
			$path    = $this->path_from_url( (string) $video['vid_url'] );
			if ( '' === $path ) {
				continue;
			}
			$files[ $post_id ][] = array(
				'id'   => self::VIDEO_MAP_OFFSET + (int) $video['vid_id'],
				'path' => $path,
				'mime' => (string) wp_check_filetype( $path )['type'],
			);
		}

		return $files;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function photo_rows( int $after, int $limit, bool $in_album ): array {
		global $wpdb;

		$album = $in_album ? 'al.pho_system_album = 0 AND ph.pho_album_id > 0' : 'ph.pho_album_id = 0';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ph.pho_id, ph.pho_album_id, ph.pho_owner_id, ph.pho_post_id, ph.pho_file_name, ph.pho_orig_name, ph.pho_acc, UNIX_TIMESTAMP(ph.pho_created) AS pho_created
				FROM {$this->t( 'peepso_photos' )} ph
				LEFT JOIN {$this->t( 'peepso_photos_album' )} al ON al.pho_album_id = ph.pho_album_id
				WHERE {$album} AND ph.pho_id > %d
				ORDER BY ph.pho_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$path = $this->file_for( (string) $row['pho_file_name'] );
			if ( '' === $path ) {
				continue;
			}
			$out[] = array(
				'source_id'     => (int) $row['pho_id'],
				'attachment_id' => 0,
				'user_id'       => (int) $row['pho_owner_id'],
				'title'         => (string) $row['pho_orig_name'],
				'description'   => '',
				'album_id'      => (int) $row['pho_album_id'],
				'group_id'      => 0,
				'privacy'       => 10 === (int) $row['pho_acc'] ? 'public' : 'onlyme',
				'type'          => 'photo',
				'date_created'  => self::utc_from_unix( $row['pho_created'] ),
				'path'          => $path,
				'mime'          => (string) wp_check_filetype( $path )['type'],
			);
		}

		return $out;
	}

	/**
	 * @return array<int,array{id:int,avatar:string,cover:string}>
	 */
	private function images( string $kind, int $after, int $limit ): array {
		global $wpdb;

		if ( 'users' === $kind ) {
			$table = $this->users();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT usr_id FROM `{$table}` WHERE usr_id > %d ORDER BY usr_id ASC LIMIT %d", $after, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'peepso-group', 'peepso-page' ) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) );
		}

		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$folder = $kind;
			if ( 'groups' === $kind && 'peepso-page' === get_post_type( $id ) ) {
				$folder = 'pages';
			}
			$dir    = WP_CONTENT_DIR . '/peepso/' . $folder . '/' . $id;
			$avatar = $this->first_file( $dir, '-avatar-full.jpg' );
			if ( '' === $avatar ) {
				$avatar = $this->first_file( $dir, '-avatar.jpg' );
			}
			$out[] = array(
				'source_id' => $id,
				'avatar'    => $avatar,
				'cover'     => $this->first_file( $dir, '-cover.jpg' ),
			);
		}

		return $out;
	}

	private function first_file( string $dir, string $suffix ): string {
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		foreach ( glob( $dir . '/*' . $suffix ) ?: array() as $path ) {
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	private function file_for( string $name ): string {
		if ( '' === $name ) {
			return '';
		}
		if ( null === $this->files ) {
			$this->files = array();
			$root        = WP_CONTENT_DIR . '/peepso';
			if ( is_dir( $root ) ) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $iterator as $file ) {
					if ( ! $file->isFile() ) {
						continue;
					}
					$path = $file->getPathname();
					if ( false !== strpos( $path, '/thumbs/' ) ) {
						continue;
					}
					$this->files[ $file->getFilename() ] = $path;
				}
			}
		}

		return $this->files[ $name ] ?? '';
	}

	private function path_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}
		$full = ABSPATH . ltrim( $path, '/' );
		return file_exists( $full ) ? $full : '';
	}

	/**
	 * Members-only is stored as private. PrivacyMap publishes every value other
	 * than onlyme, friends, adminsonly and private.
	 */
	private function post_privacy( int $access ): string {
		switch ( $access ) {
			case 40:
				return 'onlyme';
			case 30:
				return 'friends';
			default:
				// 10 public and 20 site members. BuddyNext has no members-only
				// level for a person's post, so 20 is public (owner, 2026-10-30).
				// A space post ignores this: the space's privacy decides.
				return 'public';
		}
	}

	private function group_status( string $privacy ): string {
		switch ( $privacy ) {
			case '1':
				return 'private';
			case '2':
				return 'hidden';
			default:
				return 'public';
		}
	}

	/**
	 * Group posts usually store the group as act_owner_id. A few also set
	 * peepso_group_id. The meta wins when both are present.
	 *
	 * @param array<string,mixed> $row Activity row.
	 */
	private function group_for_activity( array $row ): int {
		$meta = (int) $row['group_id'];
		if ( $meta > 0 ) {
			return $meta;
		}
		if ( ! in_array( (int) $row['act_module_id'], array( 8, 9 ), true ) ) {
			return 0;
		}
		$owner = (int) $row['act_owner_id'];
		return ( $owner > 0 && in_array( get_post_type( $owner ), array( 'peepso-group', 'peepso-page' ), true ) ) ? $owner : 0;
	}

	/**
	 * A poll is a list of choices plus who picked which. The choice key is only
	 * used to line the votes up with the labels; BuddyNext stores its own ids.
	 *
	 * @param array<int,int> $post_ids
	 * @return array<int,array{options:array<int,string>,votes:array<int,array{user_id:int,option:int}>}>
	 */
	private function polls_for_posts( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$out = array();
		$keys = array();
		foreach ( $post_ids as $post_id ) {
			$stored = get_post_meta( $post_id, 'select_options', true );
			if ( is_string( $stored ) ) {
				$stored = maybe_unserialize( $stored );
			}
			if ( ! is_array( $stored ) || count( $stored ) < 2 ) {
				continue;
			}
			$labels = array();
			$index  = array();
			$order  = 0;
			foreach ( $stored as $key => $option ) {
				$label = is_array( $option ) ? (string) ( $option['label'] ?? $option['text'] ?? '' ) : (string) $option;
				$label = trim( wp_strip_all_tags( $label ) );
				if ( '' === $label ) {
					continue;
				}
				$labels[]       = $label;
				$index[ (string) $key ] = $order;
				++$order;
			}
			if ( count( $labels ) < 2 || count( $labels ) > 5 ) {
				continue;
			}
			$out[ $post_id ] = array(
				'options' => $labels,
				'votes'   => array(),
			);
			$keys[ $post_id ] = $index;
		}

		if ( array() === $out || ! $this->table_exists( 'peepso_polls_user_answers' ) ) {
			return $out;
		}

		$in = implode( ',', array_keys( $out ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$votes = $wpdb->get_results( "SELECT pu_poll_id, pu_user_id, pu_value FROM {$this->t( 'peepso_polls_user_answers' )} WHERE pu_poll_id IN ({$in})", ARRAY_A );
		foreach ( (array) $votes as $vote ) {
			$poll_id = (int) $vote['pu_poll_id'];
			$option  = $keys[ $poll_id ][ (string) $vote['pu_value'] ] ?? null;
			if ( null === $option ) {
				continue;
			}
			$out[ $poll_id ]['votes'][] = array(
				'user_id' => (int) $vote['pu_user_id'],
				'option'  => (int) $option,
			);
		}

		return $out;
	}

	/**
	 * YouTube and other remote videos live in vid_url, not the post body.
	 *
	 * @param array<int,int> $post_ids
	 * @return array<int,string>
	 */
	private function external_video_urls( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( array() === $post_ids ) {
			return array();
		}

		$in = implode( ',', $post_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT vid_post_id, vid_url, vid_title, vid_description, vid_thumbnail FROM {$this->t( 'peepso_videos' )} WHERE vid_stored = 0 AND vid_post_id IN ({$in})", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$url = esc_url_raw( (string) $row['vid_url'] );
			if ( '' !== $url ) {
				// The preview PeepSo fetched for the link, for BuddyNext's link card.
				$out[ (int) $row['vid_post_id'] ] = array(
					'url'         => $url,
					'title'       => wp_strip_all_tags( (string) wp_unslash( (string) $row['vid_title'] ) ),
					'description' => wp_strip_all_tags( (string) wp_unslash( (string) $row['vid_description'] ) ),
					'thumbnail'   => esc_url_raw( (string) $row['vid_thumbnail'] ),
				);
			}
		}
		return $out;
	}

	private function group_privacy( int $group_id ): int {
		return (int) get_post_meta( $group_id, 'peepso_group_privacy', true );
	}

	private function group_owner( int $group_id, int $fallback ): int {
		global $wpdb;

		if ( 'peepso-page' === get_post_type( $group_id ) ) {
			$table = $this->t( 'peepso_page_members' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT pm_user_id FROM `{$table}` WHERE pm_page_id = %d AND pm_user_status = 'member_owner' LIMIT 1", $group_id ) );
			return $owner ? (int) $owner : $fallback;
		}

		$table = $this->t( 'peepso_group_members' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT gm_user_id FROM `{$table}` WHERE gm_group_id = %d AND gm_user_status = 'member_owner' LIMIT 1", $group_id ) );

		return $owner ? (int) $owner : $fallback;
	}

	/**
	 * @return array{root_id:int,parent_id:int}
	 */
	private function comment_place( int $parent_external_id ): array {
		$index   = $this->activity_index();
		$immediate = $index[ $parent_external_id ] ?? null;
		if ( null === $immediate ) {
			return array(
				'root_id'   => 0,
				'parent_id' => 0,
			);
		}

		$root    = $immediate;
		$guard   = 0;
		$current = $immediate;
		while ( $current['comment_object_id'] > 0 && $guard < 8 ) {
			$next = $index[ $current['comment_object_id'] ] ?? null;
			if ( null === $next ) {
				break;
			}
			$root    = $next;
			$current = $next;
			++$guard;
		}

		$parent_id = $immediate['comment_object_id'] > 0 ? $immediate['act_id'] : 0;

		return array(
			'root_id'   => $root['comment_object_id'] > 0 ? 0 : $root['act_id'],
			'parent_id' => $parent_id,
		);
	}

	private function root_is_imported( int $act_id ): bool {
		foreach ( $this->activity_index() as $row ) {
			if ( $row['act_id'] === $act_id ) {
				return in_array( $row['module_id'], self::POST_MODULES, true );
			}
		}
		return false;
	}

	/**
	 * @return array<int,array{act_id:int,comment_object_id:int,module_id:int}>
	 */
	private function activity_index(): array {
		if ( null !== $this->by_external ) {
			return $this->by_external;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT act_id, act_external_id, act_comment_object_id, act_module_id FROM {$this->t( 'peepso_activities' )}", ARRAY_A );

		$this->by_external = array();
		foreach ( (array) $rows as $row ) {
			$this->by_external[ (int) $row['act_external_id'] ] = array(
				'act_id'            => (int) $row['act_id'],
				'comment_object_id' => (int) $row['act_comment_object_id'],
				'module_id'         => (int) $row['act_module_id'],
			);
		}

		return $this->by_external;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function comment_rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			"SELECT a.act_id, a.act_comment_object_id
			FROM {$this->t( 'peepso_activities' )} a
			JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'peepso_reported'
			WHERE a.act_comment_object_id > 0 AND p.post_type = 'peepso-comment' AND p.post_status = 'publish' AND r.meta_id IS NULL",
			ARRAY_A
		);
	}

	private function rewrite_mentions( string $content ): string {
		return (string) preg_replace_callback(
			'/@peepso_user_(\d+)\([^)]*\)/',
			static fn( array $match ): string => '{{mention_user_id_' . (int) $match[1] . '}}',
			$content
		);
	}

	/**
	 * A PeepSo TIMESTAMP the database filled itself (DEFAULT CURRENT_TIMESTAMP),
	 * selected as UNIX_TIMESTAMP(), as a UTC "Y-m-d H:i:s". MySQL returns a raw
	 * TIMESTAMP in the connection's time zone, which is only UTC on a UTC server;
	 * the epoch is exact on every server. Empty when the source has no time.
	 *
	 * @param mixed $unix Unix time from the query.
	 */
	private static function utc_from_unix( $unix ): string {
		$unix = (int) $unix;

		return $unix > 0 ? gmdate( 'Y-m-d H:i:s', $unix ) : '';
	}

	/**
	 * A PeepSo time PeepSo wrote itself with current_time( 'mysql' ) - the site
	 * clock, not UTC - as a UTC "Y-m-d H:i:s". Empty when the source has no time.
	 *
	 * @param mixed $value Site-local datetime from the query.
	 */
	private static function utc_from_local( $value ): string {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return '';
		}

		return get_gmt_from_date( $value );
	}

	/**
	 * PeepSo field class to the source type vocabulary FieldTypeMap reads.
	 *
	 * @param string $field_class PeepSo `class` postmeta (fieldtext, fieldselectsingle...).
	 * @param string $slug        Field slug, for the core text fields PeepSo renders as text.
	 */
	private function field_type( string $field_class, string $slug ): string {
		switch ( $field_class ) {
			case 'selectsingle':
				return 'selectbox';
			case 'selectmulti':
				return 'multiselectbox';
			case 'selectbool':
				return 'checkbox_acceptance';
			case 'textdate':
				return 'datebox';
			case 'texturl':
			case 'texturlpreset':
				return 'url';
			case 'textemail':
				return 'email';
			case 'location':
				return 'location';
			case 'separator':
				return 'placeholder';
			default:
				// `text` and unknown classes. About Me is long-form text. Country
				// stays readable text: PeepSo stores no option list for it.
				return 'description' === $slug ? 'textarea' : 'textbox';
		}
	}

	private function activity_count( bool $comments ): int {
		global $wpdb;

		if ( $comments ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				"SELECT COUNT(*)
				FROM {$this->t( 'peepso_activities' )} a
				JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'peepso_reported'
				WHERE a.act_comment_object_id > 0 AND p.post_type = 'peepso-comment' AND p.post_status = 'publish' AND r.meta_id IS NULL"
			);
		}

		$modules = implode( ',', self::POST_MODULES );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$this->t( 'peepso_activities' )} a
			JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'peepso_reported'
			WHERE a.act_comment_object_id = 0
				AND a.act_module_id IN ({$modules})
				AND p.post_type = 'peepso-post'
				AND p.post_status = 'publish'
				AND r.meta_id IS NULL"
		);
	}

	private function attached_file_count(): int {
		$total = 0;
		$after = 0;
		do {
			$batch = $this->activities( $after, 100 );
			foreach ( $batch as $row ) {
				$total += count( (array) $row['media_paths'] );
				$after  = (int) $row['source_id'];
			}
		} while ( count( $batch ) === 100 );

		return $total;
	}

	private function follow_count(): int {
		$total = 0;
		$after = 0;
		do {
			$batch  = $this->follows( $after, 200 );
			$total += count( $batch );
			if ( array() !== $batch ) {
				$after = (int) $batch[ count( $batch ) - 1 ]['source_id'];
			}
		} while ( count( $batch ) === 200 );

		return $total;
	}

	private function profile_value_count(): int {
		$total = 0;
		$after = 0;
		do {
			$ids = $this->profile_value_user_ids( $after, 100 );
			foreach ( $ids as $id ) {
				$total += count( $this->profile_values( $id ) );
				$after  = $id;
			}
		} while ( count( $ids ) === 100 );

		return $total;
	}

	private function reported_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'peepso_reported' AND meta_value = '1'" );
	}

	private function count_posts( string $type, string $extra = "post_status = 'publish'" ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND {$extra}", $type ) );
	}

	private function count_sql( string $sql ): int {
		global $wpdb;

		if ( ! $this->is_available() ) {
			return 0;
		}

		// Some PeepSo tables only exist once their feature has been used. A
		// missing one counts as zero rows, not as a database error on screen.
		$quiet = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $sql );
		$wpdb->suppress_errors( $quiet );

		return $count;
	}

	/**
	 * @return array<string,string>|null
	 */
	private function user_row( int $user_id ): ?array {
		global $wpdb;

		// The profile step reads the same member's row for values, privacy and
		// last-active in turn; keep the last one rather than query it three times.
		if ( null !== $this->user_row_cache && $user_id === $this->user_row_cache[0] ) {
			return $this->user_row_cache[1];
		}

		$table = $this->users();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE usr_id = %d", $user_id ), ARRAY_A );
		$row = is_array( $row ) ? $row : null;

		$this->user_row_cache = array( $user_id, $row );

		return $row;
	}

	private function users(): string {
		return $this->t( 'peepso_users' );
	}

	private function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	private function table_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );

		return is_string( $found ) && '' !== $found;
	}
}
