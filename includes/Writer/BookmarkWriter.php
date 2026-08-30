<?php
/**
 * Writes source activity bookmarks into bn_bookmarks via BookmarkService.
 *
 * No IdMap for the bookmark itself: bn_bookmarks is keyed PRIMARY (user_id,
 * post_id) and BookmarkService::bookmark() is INSERT IGNORE, so re-runs are
 * idempotent at the database. The activity -> post mapping DOES come from the
 * IdMap the activity import wrote; a bookmark on an activity that was never
 * imported (spam, skipped) is dropped with it and counted.
 *
 * Bookmarks are deliberately NOT folded into the reactions domain. A favourite
 * and a bookmark are different acts in both platforms, and BuddyNext keeps them
 * in different tables - importing one as the other would tell a member they had
 * liked things they had only saved.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;

defined( 'ABSPATH' ) || exit;

/**
 * Bookmark writer.
 */
final class BookmarkWriter {

	/**
	 * Source key.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Construct for a source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * BuddyNext bookmark service.
	 */
	private function service(): object {
		return buddynext_service( 'bookmarks' );
	}

	/**
	 * Import one source bookmark.
	 *
	 * @param array<string,mixed> $bookmark Source bookmark record.
	 * @return string Empty string when written (or already present), else the skip reason.
	 */
	public function import_bookmark( array $bookmark ): string {
		$user_id     = (int) ( $bookmark['user_id'] ?? 0 );
		$activity_id = (int) ( $bookmark['activity_id'] ?? 0 );

		if ( $user_id <= 0 || $activity_id <= 0 ) {
			return 'invalid_row';
		}

		// A bookmark can only point at a POST. BuddyNext's bn_bookmarks is keyed
		// (user_id, post_id) with no object_type, so unlike a reaction there is
		// nowhere for a bookmark on a COMMENT to go. Resolved as a comment purely
		// to report the honest reason: 'comment_not_bookmarkable' tells an
		// operator this is a structural limit, where 'activity_not_imported'
		// would send them looking for a missing post.
		$post_id = IdMap::get( $this->source, 'post', $activity_id );

		if ( null === $post_id ) {
			if ( null !== IdMap::get( $this->source, 'comment', $activity_id ) ) {
				return 'comment_not_bookmarkable';
			}

			return 'activity_not_imported';
		}

		if ( ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}

		// Asked before writing so a re-run reports an honest "already imported"
		// rather than counting an INSERT IGNORE no-op as freshly written. The
		// database is the idempotency source of truth - no id-map needed.
		if ( $this->service()->is_bookmarked( $user_id, (int) $post_id ) ) {
			return 'already_imported';
		}

		// bookmark() is void and takes no date. That is not a shortcut: the
		// source stores a bookmark as an activity meta row, and meta rows carry
		// no timestamp, so there IS no original date to preserve. created_at
		// takes its column default (import time), which is the only honest value
		// available. Do not add a backdate seam here expecting to fill it.
		$this->service()->bookmark( $user_id, (int) $post_id );

		return $this->service()->is_bookmarked( $user_id, (int) $post_id ) ? '' : 'bookmark_refused';
	}
}
