<?php
/**
 * Puts source times on the rows BuddyNext and WPMediaVerse stamp "now".
 *
 * INTERIM, owner decision 2026-10-30. Every time must be the moment it
 * happened at the source, but these write APIs take no date yet:
 * PostService::increment_counter() and the space activity touch (last
 * activity), SpaceMemberService::join()/request_join(), BookmarkService::
 * bookmark(), ShareService::share(), and WPMediaVerse's AlbumService::create(),
 * UploadService and AlbumService::add_items(). BuddyNext card 10459592358 adds
 * the optional time argument to each. The writers already pass the source time
 * to those calls, so when that ships this class and its callers are deleted.
 *
 * This is the ONLY place the importer writes bn_* / MediaVerse tables directly
 * (CLAUDE.md rule 5). Each method runs right after the service call that
 * created the row, touches only that row, and clears the cache the service
 * keeps for it.
 *
 * Every time in and out is UTC "Y-m-d H:i:s". A future or unparseable time is
 * ignored, the same clamp Backdate::resolve() applies.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- interim backdating, see the class doc; each write clears its cache below.

/**
 * Interim source-time writer.
 */
final class SourceDates {

	/**
	 * Run a write that may bump a post's or a space's last activity, then date
	 * any bump to the source time.
	 *
	 * BuddyNext moves last_activity_at / last_active_at to now on a comment,
	 * reaction, share, new space post or join. Whatever this write moved is set
	 * to the later of its old value and $utc - the rule BuddyNext itself follows
	 * live - so the newest source activity wins whatever order rows import in.
	 * With no $utc a moved value is put back. Values the write did not move are
	 * left alone, so this never adds a bump BuddyNext would not make.
	 *
	 * A new post or comment always marks its space active in BuddyNext, but
	 * behind a short throttle: a run writes years of comments in seconds, so the
	 * throttle drops most of them. $touches_space applies those touches anyway,
	 * for a write that returned a new id.
	 *
	 * @param int         $post_id  Post the write lands on, 0 for none (a new post).
	 * @param int         $space_id Space, 0 to take the post's own space.
	 * @param string|null $utc      Source time of the row being written.
	 * @param callable    $write    The service call.
	 * @param bool        $touches_space The write is a post or comment, which
	 *                                   always makes its space active.
	 * @return mixed The write's own result.
	 */
	public static function activity( int $post_id, int $space_id, ?string $utc, callable $write, bool $touches_space = false ) {
		$post = $post_id > 0 ? self::post_row( $post_id ) : null;
		if ( 0 === $space_id && null !== $post ) {
			$space_id = (int) $post['space_id'];
		}
		$space = $space_id > 0 ? self::space_row( $space_id ) : null;

		$result = $write();

		$utc = self::clean( $utc );

		if ( null !== $post ) {
			$after = self::post_row( $post_id );
			if ( null !== $after && $after['last_activity_at'] !== $post['last_activity_at'] ) {
				$value = null === $utc ? $post['last_activity_at'] : self::later( $post['last_activity_at'], $utc );
				self::update_post( $post_id, array( 'last_activity_at' => $value ) );
			}
		}

		if ( null !== $space ) {
			$after  = self::space_row( $space_id );
			$moved  = null !== $after && $after['last_active_at'] !== $space['last_active_at'];
			$landed = ! is_wp_error( $result ) && (int) $result > 0;
			if ( $moved || ( $touches_space && $landed && null !== $utc && null !== $after ) ) {
				$value = null === $utc ? $space['last_active_at'] : self::later( $space['last_active_at'], $utc );
				self::update_space( $space_id, $value );
			}
		}

		return $result;
	}

	/**
	 * The post a share points at: a share of a share counts against the root,
	 * as ShareService::share() flattens it.
	 *
	 * @param int $post_id BuddyNext post id.
	 */
	public static function share_root( int $post_id ): int {
		$row = self::post_row( $post_id );

		return null !== $row && (int) $row['shared_post_id'] > 0 ? (int) $row['shared_post_id'] : $post_id;
	}

	/**
	 * The post a comment sits on, 0 when it is not on a post.
	 *
	 * @param int $comment_id BuddyNext comment id.
	 */
	public static function comment_post( int $comment_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT object_id FROM {$wpdb->prefix}bn_comments WHERE id = %d AND object_type = 'post'", $comment_id )
		);
	}

	/**
	 * Date a membership to when the member joined at the source.
	 *
	 * @param int    $space_id BuddyNext space id.
	 * @param int    $user_id  Member.
	 * @param string $utc      Source join time.
	 */
	public static function joined( int $space_id, int $user_id, string $utc ): void {
		global $wpdb;

		$utc = self::clean( $utc );
		if ( null === $utc ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'joined_at' => $utc ),
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			)
		);

		$members = function_exists( 'buddynext_service' ) ? buddynext_service( 'space_members' ) : null;
		if ( is_object( $members ) && method_exists( $members, 'flush_user_caches' ) ) {
			$members->flush_user_caches( $space_id, array( $user_id ) );
		}
	}

	/**
	 * Date a bookmark to when the member saved the post.
	 *
	 * @param int    $user_id Member.
	 * @param int    $post_id BuddyNext post id.
	 * @param string $utc     Source save time.
	 */
	public static function bookmark( int $user_id, int $post_id, string $utc ): void {
		global $wpdb;

		$utc = self::clean( $utc );
		if ( null === $utc ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'bn_bookmarks',
			array( 'created_at' => $utc ),
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
			)
		);
		// BookmarkService's per-member list cache.
		wp_cache_delete( "bookmarks_{$user_id}", 'buddynext_bookmarks' );
	}

	/**
	 * Date a share: the share card post and its bn_shares row.
	 *
	 * @param int    $card_id Share card post id ShareService::share() returned.
	 * @param string $utc     Source repost time.
	 */
	public static function share( int $card_id, string $utc ): void {
		global $wpdb;

		$utc  = self::clean( $utc );
		$card = null === $utc ? null : self::post_row( $card_id );
		if ( null === $card ) {
			return;
		}

		self::update_post(
			$card_id,
			array(
				'created_at'       => $utc,
				'last_activity_at' => $utc,
			)
		);
		$wpdb->update(
			$wpdb->prefix . 'bn_shares',
			array( 'created_at' => $utc ),
			array(
				'user_id' => (int) $card['user_id'],
				'post_id' => (int) $card['shared_post_id'],
			)
		);
	}

	/**
	 * Date an uploaded file to its source upload time.
	 *
	 * @param int    $media_id WPMediaVerse media id.
	 * @param string $utc      Source upload time.
	 */
	public static function media( int $media_id, string $utc ): void {
		global $wpdb;

		$utc = self::clean( $utc );
		if ( null !== $utc && $media_id > 0 ) {
			$wpdb->update( $wpdb->prefix . 'mvs_media_index', array( 'created_at' => $utc ), array( 'media_id' => $media_id ) );
		}
	}

	/**
	 * Date an album (a WordPress post) to its source creation time.
	 *
	 * @param int    $album_id WPMediaVerse album post id.
	 * @param string $utc      Source created time.
	 */
	public static function album( int $album_id, string $utc ): void {
		global $wpdb;

		$utc = self::clean( $utc );
		if ( null === $utc || $album_id <= 0 ) {
			return;
		}

		// Straight to the row rather than wp_update_post(), which would fire every
		// save_post listener for a date change.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => get_date_from_gmt( $utc ),
				'post_date_gmt' => $utc,
			),
			array( 'ID' => $album_id )
		);
		clean_post_cache( $album_id );
	}

	/**
	 * Date a photo's place in an album to its source upload time.
	 *
	 * @param int    $album_id WPMediaVerse album id.
	 * @param int    $media_id WPMediaVerse media id.
	 * @param string $utc      Source time.
	 */
	public static function album_item( int $album_id, int $media_id, string $utc ): void {
		global $wpdb;

		$utc = self::clean( $utc );
		if ( null !== $utc ) {
			$wpdb->update(
				$wpdb->prefix . 'mvs_album_items',
				array( 'added_at' => $utc ),
				array(
					'album_id' => $album_id,
					'media_id' => $media_id,
				)
			);
		}
	}

	/**
	 * A valid, not-future UTC time, or null.
	 *
	 * @param string|null $utc Candidate.
	 */
	private static function clean( ?string $utc ): ?string {
		if ( null === $utc || '' === $utc ) {
			return null;
		}

		$time = strtotime( $utc . ' UTC' );
		if ( false === $time || $time > time() ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * The later of two UTC times; an empty one loses.
	 *
	 * @param string|null $a Time.
	 * @param string      $b Time.
	 */
	private static function later( ?string $a, string $b ): string {
		return ( null !== $a && $a > $b ) ? $a : $b;
	}

	/**
	 * The fields of a post this class reads.
	 *
	 * @param int $post_id BuddyNext post id.
	 * @return array<string,mixed>|null
	 */
	private static function post_row( int $post_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id, space_id, shared_post_id, last_activity_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The fields of a space this class reads.
	 *
	 * @param int $space_id BuddyNext space id.
	 * @return array<string,mixed>|null
	 */
	private static function space_row( int $space_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT slug, last_active_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Write post times and clear the post cache. updated_at follows the activity
	 * time, as PostService::increment_counter() moves it.
	 *
	 * @param int                  $post_id BuddyNext post id.
	 * @param array<string,string> $fields  Columns to set.
	 */
	private static function update_post( int $post_id, array $fields ): void {
		global $wpdb;

		if ( isset( $fields['last_activity_at'] ) ) {
			$fields['updated_at'] = $fields['last_activity_at'];
		}

		$wpdb->update( $wpdb->prefix . 'bn_posts', $fields, array( 'id' => $post_id ) );

		if ( is_callable( array( \BuddyNext\Feed\PostService::class, 'flush_cache' ) ) ) {
			\BuddyNext\Feed\PostService::flush_cache( $post_id );
		}
	}

	/**
	 * Write a space's last activity and clear the caches SpaceService keeps.
	 *
	 * @param int         $space_id BuddyNext space id.
	 * @param string|null $value    UTC time, or null for never active.
	 */
	private static function update_space( int $space_id, ?string $value ): void {
		global $wpdb;

		$row = self::space_row( $space_id );
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'last_active_at' => $value ), array( 'id' => $space_id ) );

		wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
		if ( null !== $row && '' !== (string) $row['slug'] ) {
			wp_cache_delete( 'space_slug_' . $row['slug'], 'buddynext_spaces' );
		}
		if ( is_callable( array( \BuddyNext\Spaces\SpaceService::class, 'flush_space_lists' ) ) ) {
			\BuddyNext\Spaces\SpaceService::flush_space_lists();
		}
	}
}
