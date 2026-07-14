<?php
/**
 * REST surface for the profile field mapping. Namespace buddynext-importer/v1.
 *
 *   GET  /mapping   - the plan: each source field with its saved-or-suggested
 *                     target, plus the list of BuddyNext fields to map onto.
 *   POST /mapping   - persist the owner's chosen targets.
 *
 * The migration reads the saved map (FieldMap::apply), so the owner reviews and
 * corrects the mapping here before running the import - the step that makes
 * migrated bios land in BuddyNext's canonical fields instead of duplicates.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Rest;

use BuddyNextImporter\Mapping\FieldMap;
use BuddyNextImporter\Source\AdapterRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Read/write the source -> BuddyNext profile field mapping over REST.
 */
final class MappingController {

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
	 * Register the two mapping routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/mapping',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_mapping' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'source' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_mapping' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'source' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'fields' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Only administrators may read or change the mapping.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'buddynext_importer_forbidden',
			__( 'You do not have permission to manage the import.', 'buddynext-importer' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /mapping - plan + BuddyNext target fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_mapping( WP_REST_Request $request ) {
		$source = $this->resolve_source( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$adapter = AdapterRegistry::get( $source );
		if ( null === $adapter || ! $adapter->is_available() ) {
			return $this->unavailable();
		}

		$plan  = FieldMap::plan( $adapter );
		$saved = FieldMap::load( $source );

		$fields = array();
		foreach ( $plan['fields'] as $source_id => $row ) {
			// A saved choice wins over the suggestion; otherwise show the suggestion.
			$target             = $saved['fields'][ $source_id ] ?? $row['target'];
			$fields[]           = array(
				'source_id' => (int) $source_id,
				'label'     => (string) $row['label'],
				'type'      => (string) $row['type'],
				'target'    => (string) $target,
			);
		}

		// BuddyNext fields available as targets, grouped for a readable dropdown.
		$targets = array();
		foreach ( FieldMap::bn_fields() as $bn ) {
			$targets[] = array(
				'key'   => (string) $bn['field_key'],
				'label' => (string) $bn['label'],
				'group' => (string) $bn['group_label'],
				'type'  => (string) $bn['type'],
			);
		}

		return new WP_REST_Response(
			array(
				'source'  => $source,
				'new'     => FieldMap::NEW,
				'targets' => $targets,
				'fields'  => $fields,
			),
			200
		);
	}

	/**
	 * POST /mapping - persist the owner's targets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_mapping( WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );
		$raw    = (array) $request->get_param( 'fields' );

		// source_id => target field_key | __new__. Keys are ints, values key-safe.
		$fields = array();
		foreach ( $raw as $source_id => $target ) {
			$fields[ (int) $source_id ] = sanitize_key( (string) $target );
		}

		FieldMap::save( $source, array( 'fields' => $fields, 'groups' => array() ) );

		$mapped = count(
			array_filter( $fields, static fn( $t ) => FieldMap::NEW !== $t )
		);

		return new WP_REST_Response(
			array(
				'saved'  => true,
				'mapped' => $mapped,
				'new'    => count( $fields ) - $mapped,
			),
			200
		);
	}

	/**
	 * Resolve the source key from the request or detection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function resolve_source( WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );
		if ( '' === $source ) {
			$source = (string) AdapterRegistry::detect_active_key();
		}
		if ( '' === $source ) {
			return $this->unavailable();
		}
		return $source;
	}

	/**
	 * No supported source found.
	 */
	private function unavailable(): WP_Error {
		return new WP_Error(
			'buddynext_importer_no_source',
			__( 'No BuddyPress or BuddyBoss data was found on this site.', 'buddynext-importer' ),
			array( 'status' => 404 )
		);
	}
}
