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
	 * @param array<string,mixed> $friendship Source friendship record.
	 * @return bool Whether a write occurred.
	 */
	public function import_friendship( array $friendship ): bool {
		$source_id = (int) $friendship['source_id'];

		if ( IdMap::has( $this->source, 'connection', $source_id ) ) {
			return false;
		}

		$requester = (int) $friendship['initiator_id'];
		$recipient = (int) $friendship['friend_id'];

		if ( $requester <= 0 || $recipient <= 0 || $requester === $recipient ) {
			return false;
		}

		$confirmed = 1 === (int) $friendship['is_confirmed'];

		ImportMode::run(
			function () use ( $requester, $recipient, $confirmed ): void {
				$service = $this->service();

				if ( $service->are_connected( $requester, $recipient ) ) {
					return;
				}

				$service->send_request( $requester, $recipient );

				if ( $confirmed ) {
					$service->accept_request( $recipient, $requester );
				}
			}
		);

		IdMap::set( $this->source, 'connection', $source_id, $recipient );

		return true;
	}
}
