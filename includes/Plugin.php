<?php
/**
 * Plugin bootstrap. Wires the two run surfaces (WP-CLI + admin/REST).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter;

use BuddyNextImporter\Admin\ImporterPage;
use BuddyNextImporter\Background\BackgroundImport;
use BuddyNextImporter\CLI\MigrateCommand;
use BuddyNextImporter\Pipeline\ImportMode;
use BuddyNextImporter\Rest\ProgressController;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the importer once WordPress (and ideally BuddyNext) is loaded.
 */
final class Plugin {

	/**
	 * Entry point, hooked on plugins_loaded:20.
	 */
	public static function boot(): void {
		ImportMode::register();

		// The background runner listens for its Action Scheduler tick on every
		// request (it no-ops unless a job is running), so it must register
		// unconditionally - not only in wp-admin.
		( new BackgroundImport() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'buddynext-import', new MigrateCommand() );
		}

		( new ProgressController() )->register();

		if ( is_admin() ) {
			( new ImporterPage() )->register();
		}
	}

	/**
	 * Whether the destination BuddyNext plugin is active.
	 *
	 * Read-only source inspection (stats) works without it; the write phases
	 * require it because every record is created through BuddyNext services.
	 */
	public static function buddynext_active(): bool {
		return function_exists( 'buddynext_service' );
	}
}
