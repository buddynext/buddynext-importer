<?php
/**
 * Writes source member blocks into BuddyNext THROUGH ITS SERVICE API only
 * (buddynext_service( 'blocks' )). Never touches bn_* tables directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

defined( 'ABSPATH' ) || exit;

use BuddyNextImporter\Pipeline\ImportMode;

/**
 * Service-layer writer for the blocks domain.
 *
 * No IdMap: a block is keyed by (blocker, blocked) and BlockService::block()
 * upserts on that key, so a re-run writes nothing new.
 */
final class BlockWriter {

	/**
	 * BuddyNext block service.
	 */
	private function service(): object {
		return buddynext_service( 'blocks' );
	}

	/**
	 * Import one source block.
	 *
	 * @param array<string,mixed> $block Source block record.
	 * @return string Empty string when written, else the skip reason.
	 */
	public function import_block( array $block ): string {
		$blocker = (int) $block['blocker_id'];
		$blocked = (int) $block['blocked_id'];

		if ( $blocker <= 0 || $blocked <= 0 ) {
			return 'invalid_row';
		}

		if ( $blocker === $blocked ) {
			return 'self_block';
		}

		if ( ! get_userdata( $blocker ) || ! get_userdata( $blocked ) ) {
			return 'user_missing';
		}

		if ( $this->service()->has_blocked( $blocker, $blocked ) ) {
			return 'already_imported';
		}

		$result = ImportMode::run( fn() => $this->service()->block( $blocker, $blocked ) );

		if ( is_wp_error( $result ) ) {
			return sanitize_key( (string) $result->get_error_code() );
		}

		return true === $result ? '' : 'block_refused';
	}
}
