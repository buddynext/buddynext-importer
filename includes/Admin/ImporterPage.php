<?php
/**
 * Admin surface: Tools -> Import to BuddyNext. Renders the importer page and
 * enqueues its REST-driven progress monitor assets.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Admin;

use BuddyNextImporter\Pipeline\StepRegistry;
use BuddyNextImporter\Plugin;
use BuddyNextImporter\Source\AdapterRegistry;

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

		$source = AdapterRegistry::detect_active_key();

		wp_localize_script(
			'buddynext-importer-admin',
			'buddynextImporter',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'buddynext-importer/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'bnActive' => Plugin::buddynext_active(),
				// The run order comes from the shared registry, already filtered to
				// what this site can actually import. The browser loop used to carry
				// its own hardcoded list, which knew 5 of the 16 domains - so "Start
				// import" reported success after migrating a third of the community.
				'steps'    => null === $source ? array() : StepRegistry::client_steps( $source ),
				'i18n'     => array(
					'noSource'           => __( 'No BuddyPress or BuddyBoss data was found on this site.', 'buddynext-importer' ),
					'bnInactive'         => __( 'BuddyNext is not active. Activate it before importing.', 'buddynext-importer' ),
					'loadFailed'         => __( 'Could not load source statistics.', 'buddynext-importer' ),
					'importing'          => __( 'Importing', 'buddynext-importer' ),
					'complete'           => __( 'Import complete. You can now deactivate and remove this importer.', 'buddynext-importer' ),
					'runFailed'          => __( 'The import stopped on an error. It is safe to run again - it resumes where it left off.', 'buddynext-importer' ),
					'domain'             => __( 'Domain', 'buddynext-importer' ),
					'shortfall'          => __( 'Fewer rows than the source holds. Some content cannot migrate - see the note below the table.', 'buddynext-importer' ),
					/* translators: 1: number of comments, 2: list of activity types with counts. */
					'commentRoots'       => __( '%1$d comment(s) will not be migrated. They sit on system notices - joining a group, making a friend - which are not content and have no BuddyNext equivalent to attach to: %2$s.', 'buddynext-importer' ),
					'count'              => __( 'Records', 'buddynext-importer' ),
					// A domain left out on purpose. Never phrased as a failure - the
					// owner made this choice and the report has to reflect it back as
					// a decision, not as content that went missing.
					'skippedByChoice'    => __( 'skipped by choice', 'buddynext-importer' ),
					'domainUnavailable'  => __( 'not available on this site', 'buddynext-importer' ),
					'verifyCoverage'     => __( 'Cannot migrate', 'buddynext-importer' ),
					'verifyRelations'    => __( 'Source relationships', 'buddynext-importer' ),
					'verifyBrokenSource' => __( 'broken in the source, these cannot migrate', 'buddynext-importer' ),
					'verifyExposure'     => __( 'Privacy', 'buddynext-importer' ),
					'verifyLeak'         => __( 'post(s) from a private or secret space are PUBLICLY SEARCHABLE', 'buddynext-importer' ),
					'verifyNoLeak'       => __( 'No private or secret space content is publicly searchable.', 'buddynext-importer' ),
					'verifySpaces'       => __( 'Spot-check: spaces', 'buddynext-importer' ),
					'verifyActivities'   => __( 'Spot-check: posts', 'buddynext-importer' ),
					'verifyPass'         => __( 'Every domain accounted for and every sampled object correct.', 'buddynext-importer' ),
					'verifyFindings'     => __( 'finding(s) to read. A shortfall is not automatically a fault - check it against the coverage note above.', 'buddynext-importer' ),
					'verifyRunning'      => __( 'Checking...', 'buddynext-importer' ),
					'verifyFailed'       => __( 'The checks could not be run.', 'buddynext-importer' ),
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
