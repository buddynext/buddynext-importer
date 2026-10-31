<?php
/**
 * Plugin bootstrap. Wires the two run surfaces (WP-CLI + admin/REST).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter;

use BuddyNextImporter\Pipeline\IdMap;

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

	/**
	 * Whether MediaVerse free is active.
	 *
	 * Photos, video, audio, and messages are stored there. Pro is not required.
	 */
	public static function mediaverse_active(): bool {
		return class_exists( '\WPMediaVerse\Core\Plugin' );
	}

	/**
	 * Why an import cannot start, or an empty string when both required plugins are active.
	 */
	public static function import_blocked_reason(): string {
		if ( ! self::buddynext_active() ) {
			return __( 'BuddyNext must be active to import (data is written through its service API).', 'buddynext-importer' );
		}

		if ( ! self::mediaverse_active() ) {
			return __( 'WPMediaVerse must be active to import. Install and activate the free plugin. Photos, video, audio, and messages are stored there.', 'buddynext-importer' );
		}

		if ( IdMap::was_dropped() ) {
			return __( 'Cleanup already ran on this site, so the importer no longer knows what it wrote. Another run would duplicate the whole community. Restore your backup first. To run anyway: wp option delete buddynext_importer_cleaned_up', 'buddynext-importer' );
		}

		return '';
	}
}
