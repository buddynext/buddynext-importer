<?php
/**
 * REST surface for the admin importer flow: source stats + a progress monitor.
 * Namespace buddynext-importer/v1.
 *
 * Phase 1 ships the read endpoints (stats, status). The batched /step endpoint
 * that advances a large import lands with the domain writers in later phases;
 * here it returns the idle envelope so the admin UI can be wired end-to-end.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Rest;

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
	 * GET /stats — source detection + per-domain counts.
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
	 * GET /status — current import progress for the monitor.
	 *
	 * Phase 1 returns the idle envelope. Later phases populate phase/done/total
	 * from the id-map as batches run.
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'state'   => 'idle',
				'phase'   => null,
				'done'    => 0,
				'total'   => 0,
				'percent' => 0,
			)
		);
	}
}
