<?php
/**
 * Activation routine: provisions the id-map and resume-checkpoint tables.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Core;

use BuddyNextImporter\Pipeline\Checkpoint;
use BuddyNextImporter\Pipeline\IdMap;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation. Idempotent.
 */
final class Activator {

	/**
	 * Create/upgrade the id-map + checkpoint tables and stamp the version.
	 */
	public static function activate(): void {
		IdMap::install();
		Checkpoint::install();
		update_option( 'buddynext_importer_version', BUDDYNEXT_IMPORTER_VERSION, false );
	}
}
