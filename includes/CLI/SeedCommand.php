<?php
/**
 * Dev-only WP-CLI surface for the source seeder: wp buddynext-import seed <sub>.
 *
 * Registered only when the seeder is enabled (Plugin::seeder_enabled), so it is
 * never available on a customer install. Builds a repeatable test community on
 * the source platform so the migration can be run and re-run after changes.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\CLI;

use BuddyNextImporter\Dev\SourceSeeder;

defined( 'ABSPATH' ) || exit;

/**
 * Seed or reset the source-platform test community (dev fixture).
 */
final class SeedCommand {

	/**
	 * Seed a BuddyPress dating community (groups, fields, members).
	 *
	 * ## OPTIONS
	 *
	 * [--members=<n>]
	 * : How many fixture members to create (1-5). Default 5.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import seed bp
	 *     wp buddynext-import seed bp --members=3
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function bp( array $args, array $assoc_args ): void {
		$members = isset( $assoc_args['members'] ) ? (int) $assoc_args['members'] : 5;

		try {
			$counts = SourceSeeder::seed_buddypress( $members );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::success(
			sprintf(
				'Seeded BuddyPress: %d groups, %d fields, %d members, %d messages.',
				(int) $counts['groups'],
				(int) $counts['fields'],
				(int) $counts['members'],
				(int) ( $counts['messages'] ?? 0 )
			)
		);
	}

	/**
	 * Remove everything the seeder created (marked members + its field groups).
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import seed reset
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args (unused).
	 */
	public function reset( array $args, array $assoc_args ): void {
		$counts = SourceSeeder::reset_buddypress();
		\WP_CLI::success(
			sprintf(
				'Removed %d seeded members and %d field groups.',
				(int) $counts['members'],
				(int) $counts['groups']
			)
		);
	}
}
