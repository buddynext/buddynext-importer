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

use BuddyNextImporter\Pipeline\ActivityImporter;
use BuddyNextImporter\Pipeline\ForumImporter;
use BuddyNextImporter\Pipeline\FriendImporter;
use BuddyNextImporter\Pipeline\MessageImporter;
use BuddyNextImporter\Pipeline\ProfileImporter;
use BuddyNextImporter\Pipeline\SpaceImporter;
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
						'default'           => 'posts',
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
	 * GET /status - current import progress for the monitor.
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

	/**
	 * POST /step - advance the import by one keyset batch.
	 *
	 * The admin run loop calls this repeatedly until `done` is true, so a large
	 * site imports without a request timeout. Phase 2 implements the `profiles`
	 * phase; later phases extend the switch.
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

		if ( 'profiles' === $phase ) {
			return $this->step_profiles( $source, $after, $batch );
		}

		if ( 'spaces' === $phase ) {
			return $this->step_spaces( $source, $after, $batch );
		}

		if ( 'activity' === $phase ) {
			return $this->step_activity( $source, (string) $request->get_param( 'stage' ), $after, $batch );
		}

		if ( 'friends' === $phase ) {
			return $this->step_friends( $source, $after, $batch );
		}

		if ( 'messages' === $phase ) {
			return $this->step_messages( $source, $after, $batch );
		}

		if ( 'forums' === $phase ) {
			return $this->step_forums( $source, (string) $request->get_param( 'stage' ), $after, $batch );
		}

		return new WP_Error(
			'buddynext_importer_unknown_phase',
			/* translators: %s: phase name. */
			sprintf( __( 'Unknown import phase: %s', 'buddynext-importer' ), $phase ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Advance the profiles phase by one batch.
	 *
	 * @param string $source Source key.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_profiles( string $source, int $after, int $batch ): WP_REST_Response|WP_Error {
		$importer = ProfileImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		$schema = 0 === $after ? $importer->import_schema() : array();
		$result = $importer->import_values_batch( $after, $batch );

		return new WP_REST_Response(
			array(
				'phase'  => 'profiles',
				'source' => $source,
				'schema' => $schema,
				'last'   => $result['last'],
				'users'  => $result['users'],
				'values' => $result['values'],
				'done'   => $result['users'] < $batch,
			)
		);
	}

	/**
	 * Advance the spaces phase by one batch.
	 *
	 * @param string $source Source key.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_spaces( string $source, int $after, int $batch ): WP_REST_Response|WP_Error {
		$importer = SpaceImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		$result = $importer->import_batch( $after, $batch );

		return new WP_REST_Response(
			array(
				'phase'   => 'spaces',
				'source'  => $source,
				'last'    => $result['last'],
				'groups'  => $result['groups'],
				'members' => $result['members'],
				'done'    => $result['fetched'] < $batch,
			)
		);
	}

	/**
	 * Advance the activity phase by one batch. Stage 'posts' runs first to
	 * completion, then stage 'comments' (so a comment's root post is mapped).
	 *
	 * @param string $source Source key.
	 * @param string $stage  'posts' or 'comments'.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_activity( string $source, string $stage, int $after, int $batch ): WP_REST_Response|WP_Error {
		$importer = ActivityImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		if ( 'comments' === $stage ) {
			$result = $importer->import_comments_batch( $after, $batch );

			return new WP_REST_Response(
				array(
					'phase'    => 'activity',
					'stage'    => 'comments',
					'source'   => $source,
					'last'     => $result['last'],
					'comments' => $result['comments'],
					'done'     => $result['fetched'] < $batch,
				)
			);
		}

		$result = $importer->import_posts_batch( $after, $batch );

		return new WP_REST_Response(
			array(
				'phase'  => 'activity',
				'stage'  => 'posts',
				'source' => $source,
				'last'   => $result['last'],
				'posts'  => $result['posts'],
				// Posts stage is done when the page is short; the client then runs the comments stage.
				'done'   => $result['fetched'] < $batch,
			)
		);
	}

	/**
	 * Advance the friends phase by one batch.
	 *
	 * @param string $source Source key.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_friends( string $source, int $after, int $batch ): WP_REST_Response|WP_Error {
		$importer = FriendImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		$result = $importer->import_batch( $after, $batch );

		return new WP_REST_Response(
			array(
				'phase'       => 'friends',
				'source'      => $source,
				'last'        => $result['last'],
				'connections' => $result['connections'],
				'done'        => $result['fetched'] < $batch,
			)
		);
	}

	/**
	 * Advance the private-messages phase by one batch. A no-op (immediately done)
	 * when the WPMediaVerse DM engine is not active.
	 *
	 * @param string $source Source key.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_messages( string $source, int $after, int $batch ): WP_REST_Response|WP_Error {
		$importer = MessageImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		$result = $importer->import_batch( $after, $batch );

		return new WP_REST_Response(
			array(
				'phase'    => 'messages',
				'source'   => $source,
				'last'     => $result['last'],
				'messages' => $result['messages'],
				'done'     => ! empty( $result['skipped'] ) || $result['fetched'] < $batch,
			)
		);
	}

	/**
	 * Advance the forums phase by one batch. Stages run forums -> topics ->
	 * replies (so each child resolves its parent). Requires Jetonomy.
	 *
	 * @param string $source Source key.
	 * @param string $stage  'forums' | 'topics' | 'replies'.
	 * @param int    $after  Cursor.
	 * @param int    $batch  Batch size.
	 */
	private function step_forums( string $source, string $stage, int $after, int $batch ): WP_REST_Response|WP_Error {
		if ( ! ForumImporter::target_available() ) {
			return new WP_Error(
				'buddynext_importer_no_jetonomy',
				__( 'Jetonomy must be active to import forums.', 'buddynext-importer' ),
				array( 'status' => 409 )
			);
		}

		$importer = ForumImporter::for_source( $source );
		if ( null === $importer ) {
			return $this->unavailable();
		}

		if ( 'topics' === $stage ) {
			$result = $importer->import_topics_batch( $after, $batch );
			$count  = array( 'topics' => $result['topics'] );
		} elseif ( 'replies' === $stage ) {
			$result = $importer->import_replies_batch( $after, $batch );
			$count  = array( 'replies' => $result['replies'] );
		} else {
			$stage  = 'forums';
			$result = $importer->import_forums_batch( $after, $batch );
			$count  = array( 'forums' => $result['forums'] );
		}

		return new WP_REST_Response(
			array_merge(
				array(
					'phase'  => 'forums',
					'stage'  => $stage,
					'source' => $source,
					'last'   => $result['last'],
					'done'   => $result['fetched'] < $batch,
				),
				$count
			)
		);
	}

	/**
	 * Standard "source unavailable" error.
	 */
	private function unavailable(): WP_Error {
		return new WP_Error(
			'buddynext_importer_unavailable',
			__( 'The selected source is not available on this site.', 'buddynext-importer' ),
			array( 'status' => 409 )
		);
	}
}
