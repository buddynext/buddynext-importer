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
			'member_types'                 => 0,
			'member_type_users'            => 0,
			'member_images'                => $this->count_sql( "SELECT COUNT(*) FROM {$this->users()} WHERE usr_role NOT IN ('ban','register')" ),
			'group_images'                 => $this->count_posts( 'peepso-group' ),
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'peepso_user_field' AND post_status = 'publish' ORDER BY menu_order ASC, ID ASC", ARRAY_A );

		$fields = array();
		$order  = 0;
		foreach ( (array) $rows as $row ) {
			$type = $this->field_type( (string) $row['post_title'] );
			$fields[] = array(
				'source_id'   => (int) $row['ID'],
				'group_id'    => 1,
				'name'        => (string) wp_unslash( $row['post_title'] ),
				'type'        => $type,
				'is_required' => 0,
				'sort_order'  => $order,
				'visibility'  => 'public',
				'options'     => 'gender' === $type ? array( 'm', 'f' ) : array(),
			);
			++$order;
		}

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

	public function profile_values( int $user_id ): array {
		$by_name = array();
		foreach ( $this->profile_fields() as $field ) {
			$by_name[ (string) $field['name'] ] = $field;
		}

		$pairs = array(
			'First Name' => (string) get_user_meta( $user_id, 'first_name', true ),
			'Last Name'  => (string) get_user_meta( $user_id, 'last_name', true ),
			'About Me'   => (string) get_user_meta( $user_id, 'description', true ),
			'Website'    => (string) get_user_meta( $user_id, 'user_url', true ),
		);

		$user = $this->user_row( $user_id );
		if ( null !== $user ) {
			$gender = (string) $user['usr_gender'];
			if ( 'm' === $gender || 'f' === $gender ) {
				$pairs['Gender'] = $gender;
			}
			$born = (string) $user['usr_birthdate'];
			if ( '' !== $born && '0000-00-00' !== $born ) {
				$pairs['Birthdate'] = $born;
			}
		}

		$values = array();
		foreach ( $pairs as $name => $value ) {
			$value = trim( $value );
			if ( '' === $value || ! isset( $by_name[ $name ] ) ) {
				continue;
			}
			$field    = $by_name[ $name ];
			$values[] = array(
				'field_id' => (int) $field['source_id'],
				'name'     => $name,
				'type'     => (string) $field['type'],
				'value'    => $value,
			);
		}

		return $values;
	}

	public function profile_visibility_levels( int $user_id ): array {
		unset( $user_id );
		return array();
	}

	public function member_types(): array {
		return array();
	}

	public function member_type_assignments( int $after, int $limit ): array {
		unset( $after, $limit );
		return array();
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
					COALESCE( priv.meta_value, pagepriv.meta_value ) AS privacy
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} priv ON priv.post_id = p.ID AND priv.meta_key = 'peepso_group_privacy'
				LEFT JOIN {$wpdb->postmeta} pagepriv ON pagepriv.post_id = p.ID AND pagepriv.meta_key = 'peepso_page_privacy'
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
				"SELECT gm_id, gm_user_id, gm_user_status FROM `{$table}` WHERE gm_group_id = %d AND gm_id > %d AND gm_user_status <> 'member_owner' ORDER BY gm_id ASC LIMIT %d",
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
			);
		}

		return $members;
	}

	/**
	 * Page members use the same roles as group members. The owner is the
	 * space creator, so they are not also written as a member.
	 *
	 * @return array<int,array<string,int>>
	 */
	private function page_members( int $page_id, int $after, int $limit ): array {
		global $wpdb;

		$table = $this->t( 'peepso_page_members' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_id, pm_user_id, pm_user_status FROM `{$table}` WHERE pm_page_id = %d AND pm_id > %d AND pm_user_status <> 'member_owner' ORDER BY pm_id ASC LIMIT %d",
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
				"SELECT a.act_id, a.act_external_id, a.act_module_id, a.act_access, a.act_owner_id,
					p.post_author, p.post_content, p.post_date_gmt,
					g.meta_value AS group_id
				FROM {$this->t( 'peepso_activities' )} a
				JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
				LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'peepso_group_id'
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
				'link_url'          => $links[ $post_id ] ?? '',
				'poll_options'      => is_array( $poll ) ? $poll['options'] : array(),
				'poll_votes'        => is_array( $poll ) ? $poll['votes'] : array(),
			);
		}

		return $out;
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

	public function friendships( int $after, int $limit ): array {
		global $wpdb;

		$friends  = $this->t( 'peepso_friends' );
		$requests = $this->t( 'peepso_friend_requests' );

		// Pending ids are offset so they never share a keyset id with an accepted row.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
					SELECT fnd_id AS source_id, fnd_user_id AS initiator_id, fnd_friend_id AS friend_id, 1 AS is_confirmed, fnd_created AS date_created
					FROM `{$friends}`
					UNION ALL
					SELECT freq_id + 1000000000, freq_user_id, freq_friend_id, 0, freq_created
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
				'date_created' => (string) $row['date_created'],
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

	public function reactions( int $after, int $limit ): array {
		global $wpdb;

		$table = $this->t( 'peepso_reactions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reaction_id, reaction_user_id, reaction_act_id, reaction_timestamp FROM `{$table}` WHERE reaction_id > %d ORDER BY reaction_id ASC LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source_id'    => (int) $row['reaction_id'],
				'user_id'      => (int) $row['reaction_user_id'],
				'activity_id'  => (int) $row['reaction_act_id'],
				'date_created' => (string) $row['reaction_timestamp'],
			);
		}

		return $out;
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

		$out = array();
		foreach ( (array) $roots as $root ) {
			$thread_id = (int) $root['ID'];
			$people    = array( (int) $root['post_author'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$authors = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = %d", $thread_id ) );
			foreach ( (array) $authors as $author ) {
				$people[] = (int) $author;
			}

			$out[] = array(
				'thread_id'    => $thread_id,
				'participants' => array_values( array_unique( $people ) ),
				'subject'      => wp_trim_words( wp_strip_all_tags( (string) $root['post_content'] ), 8, '' ),
				'date_sent'    => (string) $root['post_date_gmt'],
			);
		}

		return $out;
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
				"SELECT pho_album_id, pho_owner_id, pho_album_name, pho_album_acc, pho_created FROM `{$table}` WHERE pho_system_album = 0 AND pho_album_id > %d ORDER BY pho_album_id ASC LIMIT %d",
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
				'privacy'      => 10 === (int) $row['pho_album_acc'] ? 'public' : 'onlyme',
				'date_created' => (string) $row['pho_created'],
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
		$this->declare( $out, $this->count_sql( "SELECT COUNT(*) FROM {$this->t( 'peepso_blocks' )}" ), 'Blocked members. There is no block step.' );
		$this->declare( $out, $this->count_posts( 'peepso-post', "post_status = 'future'" ), 'Scheduled posts. They are not published during the import.' );
		$this->declare( $out, $this->reported_count(), 'Reported posts and comments. They are left out, the same way spam is left out.' );

		return $out;
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
				"SELECT ph.pho_id, ph.pho_album_id, ph.pho_owner_id, ph.pho_post_id, ph.pho_file_name, ph.pho_orig_name, ph.pho_acc, ph.pho_created
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
				'date_created'  => (string) $row['pho_created'],
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
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'peepso-group' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) );
		}

		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$dir    = WP_CONTENT_DIR . '/peepso/' . $kind . '/' . $id;
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
			case 20:
				return 'private';
			default:
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
		$rows = $wpdb->get_results( "SELECT vid_post_id, vid_url FROM {$this->t( 'peepso_videos' )} WHERE vid_stored = 0 AND vid_post_id IN ({$in})", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$url = esc_url_raw( (string) $row['vid_url'] );
			if ( '' !== $url ) {
				$out[ (int) $row['vid_post_id'] ] = $url;
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

	private function field_type( string $title ): string {
		switch ( $title ) {
			case 'About Me':
				return 'textarea';
			case 'Gender':
				return 'gender';
			case 'Birthdate':
				return 'birthdate';
			case 'Website':
				return 'url';
			default:
				return 'textbox';
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return array<string,string>|null
	 */
	private function user_row( int $user_id ): ?array {
		global $wpdb;

		$table = $this->users();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT usr_gender, usr_birthdate FROM `{$table}` WHERE usr_id = %d", $user_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
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
