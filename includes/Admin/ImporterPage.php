<?php
/**
 * Admin surface: Tools -> Import to BuddyNext. Renders the importer page and
 * enqueues its REST-driven progress monitor assets.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Admin;

use BuddyNextImporter\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Pattern C (dashboard) admin page: source stats + a progress monitor so a site
 * owner can run the migration without the CLI.
 */
final class ImporterPage {

	/**
	 * Admin page slug.
	 */
	private const SLUG = 'buddynext-importer';

	/**
	 * Hook the menu + assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the page under Tools.
	 */
	public function add_menu(): void {
		add_management_page(
			__( 'Import to BuddyNext', 'buddynext-importer' ),
			__( 'Import to BuddyNext', 'buddynext-importer' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Whether the current screen is this page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	private function is_page( string $hook_suffix ): bool {
		return 'tools_page_' . self::SLUG === $hook_suffix;
	}

	/**
	 * Enqueue the page assets (no inline script/style).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_page( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'buddynext-importer-admin',
			BUDDYNEXT_IMPORTER_URL . 'assets/css/admin-importer.css',
			array(),
			BUDDYNEXT_IMPORTER_VERSION
		);

		wp_enqueue_script(
			'buddynext-importer-admin',
			BUDDYNEXT_IMPORTER_URL . 'assets/js/admin-importer.js',
			array( 'wp-api-fetch' ),
			BUDDYNEXT_IMPORTER_VERSION,
			true
		);

		wp_localize_script(
			'buddynext-importer-admin',
			'buddynextImporter',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'buddynext-importer/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'bnActive' => Plugin::buddynext_active(),
				'i18n'     => array(
					'noSource'   => __( 'No BuddyPress or BuddyBoss data was found on this site.', 'buddynext-importer' ),
					'bnInactive' => __( 'BuddyNext is not active. Activate it before importing.', 'buddynext-importer' ),
					'loadFailed' => __( 'Could not load source statistics.', 'buddynext-importer' ),
					'importing'  => __( 'Importing', 'buddynext-importer' ),
					'complete'   => __( 'Import complete. You can now deactivate and remove this importer.', 'buddynext-importer' ),
					'runFailed'  => __( 'The import stopped on an error. It is safe to run again - it resumes where it left off.', 'buddynext-importer' ),
					'domain'     => __( 'Domain', 'buddynext-importer' ),
					'count'      => __( 'Records', 'buddynext-importer' ),
					'mapCreateNew'   => __( 'Create new field', 'buddynext-importer' ),
					'mapExisting'    => __( 'Map to existing field', 'buddynext-importer' ),
					'mapSaved'       => __( 'Mapping saved.', 'buddynext-importer' ),
					'mapSaveFailed'  => __( 'Could not save the mapping. Try again.', 'buddynext-importer' ),
					'mapLoadFailed'  => __( 'Could not load the field mapping.', 'buddynext-importer' ),
				),
			)
		);
	}

	/**
	 * Render the page from the template.
	 */
	public function render(): void {
		require BUDDYNEXT_IMPORTER_DIR . 'templates/admin/importer-page.php';
	}
}
