<?php
/**
 * Activation routine: provisions the id-map table.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Core;

use BuddyNextImporter\Pipeline\IdMap;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation. Idempotent.
 */
final class Activator {

	/**
	 * Create/upgrade the id-map table and stamp the version.
	 */
	public static function activate(): void {
		IdMap::install();
		update_option( 'buddynext_importer_version', BUDDYNEXT_IMPORTER_VERSION, false );
	}
}
