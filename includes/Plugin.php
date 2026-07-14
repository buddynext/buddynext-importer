<?php
/**
 * Plugin bootstrap. Wires the two run surfaces (WP-CLI + admin/REST).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter;

use BuddyNextImporter\Admin\ImporterPage;
use BuddyNextImporter\CLI\MapCommand;
use BuddyNextImporter\CLI\MigrateCommand;
use BuddyNextImporter\CLI\SeedCommand;
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

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'buddynext-import', new MigrateCommand() );
			\WP_CLI::add_command( 'buddynext-import map', new MapCommand() );

			// Dev-only test fixture. Registered only when explicitly enabled, so
			// the seeder is never available on a customer install.
			if ( self::seeder_enabled() ) {
				\WP_CLI::add_command( 'buddynext-import seed', new SeedCommand() );
			}
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

	/**
	 * Whether the dev source seeder is enabled. Off by default; a site opts in
	 * with the BUDDYNEXT_IMPORTER_SEEDER constant or the matching filter, so the
	 * fixture never appears on a customer install.
	 */
	private static function seeder_enabled(): bool {
		$enabled = defined( 'BUDDYNEXT_IMPORTER_SEEDER' ) && BUDDYNEXT_IMPORTER_SEEDER;
		return (bool) apply_filters( 'buddynext_importer_enable_seeder', $enabled );
	}
}
