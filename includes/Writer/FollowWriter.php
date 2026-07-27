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
	 * ImportMode lifts the target's who_can_follow preference for the run (the
	 * follow existed at the source - it is historical data we re-add), so the
	 * only refusals left are the ones that SHOULD stand: a real block, or the
	 * (lifted) follow cap. Whatever the service refuses is reported by its own
	 * error code rather than dropped in silence.
	 *
	 * @param array<string,mixed> $follow Source follow record.
	 * @return string Empty string when written (or already present), else the skip reason.
	 */
	public function import_follow( array $follow ): string {
		$follower = (int) $follow['follower_id'];
		$leader   = (int) $follow['leader_id'];

		if ( $follower <= 0 || $leader <= 0 ) {
			return 'invalid_row';
		}

		if ( $follower === $leader ) {
			return 'self_follow';
		}

		// Already present from an earlier run. follow() is INSERT IGNORE so a
		// re-write is harmless, but asking first lets the run report an honest
		// "already imported" instead of re-counting it as freshly written - the
		// DB is the idempotency source of truth, no id-map needed.
		if ( $this->service()->is_following( $follower, $leader ) ) {
			return 'already_imported';
		}

		$date   = (string) ( $follow['date_recorded'] ?? '' );
		$result = ImportMode::run(
			// Third argument is FollowService's backdate seam; '' falls back to
			// the column default exactly like a live follow.
			fn() => $this->service()->follow( $follower, $leader, '' !== $date ? $date : null )
		);

		// follow() returns true on insert AND on an existing row (INSERT IGNORE),
		// so a re-run reports success rather than a loss. Only a WP_Error is a
		// row that did not land.
		if ( is_wp_error( $result ) ) {
			return sanitize_key( (string) $result->get_error_code() );
		}

		return true === $result ? '' : 'follow_refused';
	}
}
