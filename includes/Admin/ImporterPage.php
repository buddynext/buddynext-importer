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

		// Shortcut straight from the plugins list, the WordPress convention every
		// comparable plugin follows. The page lives under Tools, which is not
		// where someone looks immediately after activating an importer.
		if ( defined( 'BUDDYNEXT_IMPORTER_FILE' ) ) {
			add_filter(
				'plugin_action_links_' . plugin_basename( (string) constant( 'BUDDYNEXT_IMPORTER_FILE' ) ),
				array( $this, 'action_links' )
			);
		}
	}

	/**
	 * Prepend an "Import to BuddyNext" link to the plugins-list row actions.
	 *
	 * @param array<int|string,string> $links Existing action links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();

		// Gated on the same capability as the page itself, so a user who cannot
		// open it is not shown a link into a permission error.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ),
				esc_html__( 'Import to BuddyNext', 'buddynext-importer' )
			)
		);

		return $links;
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
