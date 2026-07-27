<?php
/**
 * Writes friendships into BuddyNext as connections THROUGH ITS SERVICE API only
 * (buddynext_service( 'connections' )). Never touches bn_* tables directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the connections domain. A BuddyPress friendship is
 * mutual, so a confirmed friendship becomes request + accept; a pending one
 * becomes a request only.
 */
final class ConnectionWriter {

	/**
	 * Source key, used for id-map scoping.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Construct the writer for a given source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Resolve the BuddyNext ConnectionService.
	 *
	 * @return object ConnectionService.
	 */
	private function service(): object {
		return buddynext_service( 'connections' );
	}

	/**
	 * Import one friendship. Idempotent via the id-map and a live state check.
	 *
	 * The write result is now checked: a request the target refuses (a block
	 * survives ImportMode's privacy lift) must NOT be marked in the id-map, or a
	 * re-run would never retry it, and must NOT be counted as a connection that
	 * never formed. Every non-success is reported by reason.
	 *
	 * @param array<string,mixed> $friendship Source friendship record.
	 * @return string Empty string when a connection landed, else the skip reason.
	 */
	public function import_friendship( array $friendship ): string {
		$source_id = (int) $friendship['source_id'];

		if ( IdMap::has( $this->source, 'connection', $source_id ) ) {
			return 'already_imported';
		}

		$requester = (int) $friendship['initiator_id'];
		$recipient = (int) $friendship['friend_id'];

		if ( $requester <= 0 || $recipient <= 0 ) {
			return 'invalid_row';
		}

		if ( $requester === $recipient ) {
			return 'self_connection';
		}

		$confirmed = 1 === (int) $friendship['is_confirmed'];

		// Source friendship date, forwarded through the service's backdate seam
		// (BuddyNext Core\Backdate validates and clamps it) so a migrated
		// connection keeps its history instead of the migration run time.
		$created_at = (string) ( $friendship['date_created'] ?? '' );

		$reason = ImportMode::run(
			function () use ( $requester, $recipient, $confirmed, $created_at ): string {
				$service = $this->service();

				// Already connected from an earlier run (the id-map was lost) or
				// a reciprocal source row: not a loss, just nothing to do.
				if ( $service->are_connected( $requester, $recipient ) ) {
					return '';
				}

				$sent = $service->send_request( $requester, $recipient, '', '' !== $created_at ? $created_at : null );
				if ( is_wp_error( $sent ) ) {
					return sanitize_key( (string) $sent->get_error_code() );
				}

				if ( $confirmed ) {
					$accepted = $service->accept_request( $recipient, $requester );
					if ( is_wp_error( $accepted ) ) {
						// The request landed but acceptance failed - leave it
						// pending rather than claim a full connection.
						return sanitize_key( (string) $accepted->get_error_code() );
					}
				}

				return '';
			}
		);

		if ( '' !== $reason ) {
			return $reason;
		}

		IdMap::set( $this->source, 'connection', $source_id, $recipient );

		return '';
	}
}
