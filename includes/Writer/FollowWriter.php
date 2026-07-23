<?php
/**
 * Writes source follows into BuddyNext THROUGH ITS SERVICE API only
 * (buddynext_service( 'follows' )). Never touches bn_* tables directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

defined( 'ABSPATH' ) || exit;

use BuddyNextImporter\Pipeline\ImportMode;

/**
 * Service-layer writer for the follows domain.
 *
 * No IdMap: bn_follows carries a UNIQUE (follower, following) key and
 * FollowService::follow() is INSERT IGNORE, so re-runs are idempotent at the
 * database - the same natural key the source row expresses.
 */
final class FollowWriter {

	/**
	 * BuddyNext follow service.
	 */
	private function service(): object {
		return buddynext_service( 'follows' );
	}

	/**
	 * Import one source follow.
	 *
	 * The target's who_can_follow privacy preference still applies (a member
	 * who has since locked follows down is honoured - the import replays
	 * history, it does not override today's choices). Denied or self-follow
	 * rows are skipped, not errors.
	 *
	 * @param array<string,mixed> $follow Source follow record.
	 * @return bool Whether a follow was written (or already existed).
	 */
	public function import_follow( array $follow ): bool {
		$follower = (int) $follow['follower_id'];
		$leader   = (int) $follow['leader_id'];

		if ( $follower <= 0 || $leader <= 0 || $follower === $leader ) {
			return false;
		}

		$date   = (string) ( $follow['date_recorded'] ?? '' );
		$result = ImportMode::run(
			// Third argument is FollowService's backdate seam; '' falls back to
			// the column default exactly like a live follow.
			fn() => $this->service()->follow( $follower, $leader, '' !== $date ? $date : null )
		);

		return true === $result;
	}
}
