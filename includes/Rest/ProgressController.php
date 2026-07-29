<?php
/**
 * REST surface for the admin importer flow: source stats + a progress monitor.
 * Namespace buddynext-importer/v1.
 *
 * Read endpoints (stats, status) report what a source holds and how far a run
 * has got. The batched /step endpoint advances an in-browser import one keyset
 * batch at a time; the phases it accepts come from StepRegistry, so it stays in
 * step with the background runner and the CLI.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Rest;

use BuddyNextImporter\Background\BackgroundImport;
use BuddyNextImporter\Pipeline\StepRegistry;
use BuddyNextImporter\Plugin;
use BuddyNextImporter\Source\AdapterRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the importer REST routes.
 */
final class ProgressController {

	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'buddynext-importer/v1';

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'source' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/background',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_background' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'source' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/background/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_background' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_step' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'source' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'phase'  => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'profiles',
						'sanitize_callback' => 'sanitize_key',
					),
					'after'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'batch'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'stage'  => array(
						'type'              => 'string',
						'required'          => false,
						// Blank resolves to the phase's FIRST stage, which is the one a
						// client must run first (posts before comments, forums before
						// topics, albums before the media that belongs to them).
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Capability gate. Returns WP_Error(403) on failure (never false).
	 */
	public function require_admin(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'buddynext_importer_forbidden',
			__( 'You are not allowed to run the importer.', 'buddynext-importer' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /stats - source detection + per-domain counts.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$source = $request->get_param( 'source' );
		$source = is_string( $source ) && '' !== $source ? $source : AdapterRegistry::detect_active_key();

		$adapter = null === $source ? null : AdapterRegistry::get( $source );

		if ( null === $adapter || ! $adapter->is_available() ) {
			return new WP_REST_Response(
				array(
					'source'    => null,
					'label'     => null,
					'available' => false,
					'stats'     => array(),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'source'    => $adapter->key(),
				'label'     => $adapter->label(),
				'available' => true,
				'stats'     => $adapter->stats(),
			)
		);
	}

	/**
	 * GET /status - current background-import progress for the monitor.
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( ( new BackgroundImport() )->status() );
	}

	/**
	 * POST /background - start an unattended import via Action Scheduler, so the
	 * owner can close the tab. Returns the initial status envelope.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function start_background( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Plugin::buddynext_active() ) {
			return new WP_Error(
				'buddynext_importer_no_target',
				__( 'BuddyNext must be active before importing.', 'buddynext-importer' ),
				array( 'status' => 409 )
			);
		}

		$source = $request->get_param( 'source' );
		$source = is_string( $source ) && '' !== $source ? $source : AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			return new WP_Error(
				'buddynext_importer_no_source',
				__( 'No source community was found on this site.', 'buddynext-importer' ),
				array( 'status' => 404 )
			);
		}

		$runner = new BackgroundImport();
		$runner->start( $source );

		return new WP_REST_Response( $runner->status() );
	}

	/**
	 * POST /background/cancel - stop the running background import.
	 */
	public function cancel_background(): WP_REST_Response {
		$runner = new BackgroundImport();
		$runner->cancel();

		return new WP_REST_Response( $runner->status() );
	}

	/**
	 * POST /step - advance the import by one keyset batch.
	 *
	 * The admin run loop calls this repeatedly until `done` is true, so a large
	 * site imports without a request timeout. Which phases exist, in what order,
	 * and how each one runs all come from {@see StepRegistry} - the same list the
	 * background runner ticks through - so this endpoint cannot fall behind the
	 * domains the importer actually supports.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run_step( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Plugin::buddynext_active() ) {
			return new WP_Error(
				'buddynext_importer_no_target',
				__( 'BuddyNext must be active before importing.', 'buddynext-importer' ),
				array( 'status' => 409 )
			);
		}

		$phase  = (string) $request->get_param( 'phase' );
		$stage  = (string) $request->get_param( 'stage' );
		$source = $request->get_param( 'source' );
		$source = is_string( $source ) && '' !== $source ? $source : AdapterRegistry::detect_active_key();
		$after  = (int) $request->get_param( 'after' );
		$batch  = max( 1, min( 200, (int) $request->get_param( 'batch' ) ) );

		if ( null === $source ) {
			return new WP_Error(
				'buddynext_importer_no_source',
				__( 'No source community was found on this site.', 'buddynext-importer' ),
				array( 'status' => 404 )
			);
		}

		$step = StepRegistry::find( $source, $phase, $stage );

		if ( null === $step ) {
			return new WP_Error(
				'buddynext_importer_unknown_phase',
				/* translators: %s: phase name. */
				sprintf( __( 'Unknown import phase: %s', 'buddynext-importer' ), $phase ),
				array( 'status' => 400 )
			);
		}

		if ( ! ( $step['available'] )() ) {
			return new WP_Error(
				'buddynext_importer_step_unavailable',
				sprintf(
					/* translators: %s: step label, e.g. "forums". */
					__( 'This site cannot import %s - the source reader or the target engine for that domain is not active. GET /stats lists what this site can import.', 'buddynext-importer' ),
					$step['label']
				),
				array( 'status' => 409 )
			);
		}

		$result  = ( $step['run'] )( $after, $batch );
		$fetched = (int) $result['fetched'];

		return new WP_REST_Response(
			array_merge(
				$result,
				array(
					'phase'  => $step['phase'],
					'stage'  => $step['stage'],
					'source' => $source,
					// A non-uniform keyset only proves it is finished when a batch
					// comes back empty; a uniform one is done on a short page.
					'done'   => $step['empty_done'] ? 0 === $fetched : $fetched < $batch,
				)
			)
		);
	}
}
