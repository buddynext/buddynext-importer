<?php
/**
 * WP-CLI surface: wp buddynext-import <subcommand>. Phase 1 ships `stats`.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\CLI;

use BuddyNextImporter\Pipeline\ActivityImporter;
use BuddyNextImporter\Pipeline\ProfileImporter;
use BuddyNextImporter\Pipeline\SpaceImporter;
use BuddyNextImporter\Plugin;
use BuddyNextImporter\Source\AdapterRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate a BuddyPress or BuddyBoss community into BuddyNext.
 */
final class MigrateCommand {

	/**
	 * Show source community statistics (no data is moved).
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 * ---
	 * options:
	 *   - buddypress
	 *   - buddyboss
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import stats
	 *     wp buddynext-import stats --source=buddyboss
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$adapter = AdapterRegistry::get( $source );

		if ( null === $adapter ) {
			\WP_CLI::error( sprintf( 'Unknown source: %s', $source ) );
		}

		if ( ! $adapter->is_available() ) {
			\WP_CLI::error( sprintf( '%s data is not present on this site.', $adapter->label() ) );
		}

		\WP_CLI::log( sprintf( 'Source: %s', $adapter->label() ) );

		$rows = array();
		foreach ( $adapter->stats() as $domain => $count ) {
			$rows[] = array(
				'domain' => $domain,
				'count'  => $count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'domain', 'count' ) );
	}

	/**
	 * Import profile field groups, fields, and member values into BuddyNext.
	 *
	 * Writes only through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Users per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-profiles
	 *     wp buddynext-import migrate-profiles --source=buddyboss --batch=200
	 *
	 * @subcommand migrate-profiles
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_profiles( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ProfileImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		$schema = $importer->import_schema();
		\WP_CLI::log( sprintf( 'Schema imported: %d groups, %d fields.', $schema['groups'], $schema['fields'] ) );

		$after        = 0;
		$total_users  = 0;
		$total_values = 0;

		do {
			$result        = $importer->import_values_batch( $after, $batch );
			$total_users  += $result['users'];
			$total_values += $result['values'];
			$after         = $result['last'];

			if ( $result['users'] > 0 ) {
				\WP_CLI::log( sprintf( '  ... %d members, %d values (last id %d)', $total_users, $total_values, $after ) );
			}
		} while ( $result['users'] === $batch );

		\WP_CLI::success(
			sprintf(
				'Profiles imported: %d groups, %d fields, %d members, %d values.',
				$schema['groups'],
				$schema['fields'],
				$total_users,
				$total_values
			)
		);
	}

	/**
	 * Import groups (as spaces) and their members into BuddyNext.
	 *
	 * Writes only through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Groups per batch. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-spaces
	 *     wp buddynext-import migrate-spaces --source=buddyboss --batch=100
	 *
	 * @subcommand migrate-spaces
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_spaces( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = SpaceImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 50;

		$after         = 0;
		$total_groups  = 0;
		$total_members = 0;

		do {
			$result         = $importer->import_batch( $after, $batch );
			$total_groups  += $result['groups'];
			$total_members += $result['members'];
			$after          = $result['last'];

			if ( $result['groups'] > 0 ) {
				\WP_CLI::log( sprintf( '  ... %d spaces, %d members (last group id %d)', $total_groups, $total_members, $after ) );
			}
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Spaces imported: %d spaces, %d members.', $total_groups, $total_members ) );
	}

	/**
	 * Import activity posts and comments into BuddyNext.
	 *
	 * Imports only real posts (activity_update); system rows and spam are skipped.
	 * Run migrate-spaces first so group posts land in their space. Writes only
	 * through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Rows per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-activity
	 *     wp buddynext-import migrate-activity --batch=200
	 *
	 * @subcommand migrate-activity
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_activity( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ActivityImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		// Posts first, so comments can resolve their root post.
		$after = 0;
		$posts = 0;
		do {
			$result = $importer->import_posts_batch( $after, $batch );
			$posts += $result['posts'];
			$after  = $result['last'];
		} while ( $result['fetched'] === $batch );
		\WP_CLI::log( sprintf( '%d posts imported.', $posts ) );

		$after    = 0;
		$comments = 0;
		do {
			$result    = $importer->import_comments_batch( $after, $batch );
			$comments += $result['comments'];
			$after     = $result['last'];
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Activity imported: %d posts, %d comments.', $posts, $comments ) );
	}
}
