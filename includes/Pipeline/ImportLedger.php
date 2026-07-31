<?php
/**
 * Per-domain running totals for a migration, so the admin page can say what
 * actually moved.
 *
 * The CLI has always printed a line per domain. The admin page printed one
 * sentence - "Import complete" - and nothing else, so a site owner who had just
 * migrated a community could not answer "did my hundred message threads
 * arrive?" without opening the database.
 *
 * The obvious source for that summary is the id-map, but it only records the
 * nine domains that HAVE a stable target id: profile values, follows, reactions
 * and the image domains are not mapped, so an id-map-backed summary would
 * report them as zero. So the count is recorded as the work happens instead.
 *
 * Every surface - CLI, background runner, REST /step - executes a domain through
 * {@see StepRegistry}'s run wrapper, so recording there captures all of them
 * without each one remembering to.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed tally of rows written per domain, per source.
 */
final class ImportLedger {

	/**
	 * Option holding the tally.
	 */
	private const OPTION = 'buddynext_importer_ledger';

	/**
	 * Option holding the reason-coded tally of rows NOT written.
	 *
	 * Separate from the totals option so the domain => int shape every existing
	 * consumer relies on is untouched.
	 */
	private const SKIPS_OPTION = 'buddynext_importer_skips';

	/**
	 * Add rows to a domain's total.
	 *
	 * Idempotent in the sense that matters: a resumed or repeated run only adds
	 * what it actually wrote, because the id-map makes a re-import a no-op and a
	 * no-op reports a count of zero.
	 *
	 * @param string $source Source key.
	 * @param string $domain Domain key.
	 * @param int    $count  Rows written by this batch.
	 */
	public static function add( string $source, string $domain, int $count ): void {
		if ( $count <= 0 ) {
			return;
		}

		$ledger                       = self::all();
		$ledger[ $source ][ $domain ] = (int) ( $ledger[ $source ][ $domain ] ?? 0 ) + $count;

		update_option( self::OPTION, $ledger, false );
	}

	/**
	 * Record why rows were NOT written.
	 *
	 * The CLI has always printed this (report_skips) and the admin screen never
	 * did, so an owner running the migration from the page it ships with saw
	 * "412 of 500" and no account of the other 88 - the exact "N imported alone"
	 * the reason codes exist to prevent. Recorded in the same run wrapper as the
	 * totals above, so every surface contributes without remembering to.
	 *
	 * @param string            $source  Source key.
	 * @param string            $domain  Domain key.
	 * @param array<string,int> $reasons reason => rows.
	 */
	public static function add_skips( string $source, string $domain, array $reasons ): void {
		if ( array() === $reasons ) {
			return;
		}

		$skips = self::all_skips();
		foreach ( $reasons as $reason => $count ) {
			$count = (int) $count;
			if ( $count <= 0 ) {
				continue;
			}

			$key                                 = sanitize_key( (string) $reason );
			$skips[ $source ][ $domain ][ $key ] = (int) ( $skips[ $source ][ $domain ][ $key ] ?? 0 ) + $count;
		}

		update_option( self::SKIPS_OPTION, $skips, false );
	}

	/**
	 * The whole skip tally.
	 *
	 * @return array<string,array<string,array<string,int>>> source => domain => reason => rows.
	 */
	public static function all_skips(): array {
		$skips = get_option( self::SKIPS_OPTION );

		return is_array( $skips ) ? $skips : array();
	}

	/**
	 * One source's skips.
	 *
	 * @param string $source Source key.
	 * @return array<string,array<string,int>> domain => reason => rows.
	 */
	public static function skips_for_source( string $source ): array {
		$skips = self::all_skips();

		return isset( $skips[ $source ] ) && is_array( $skips[ $source ] ) ? $skips[ $source ] : array();
	}

	/**
	 * The whole tally.
	 *
	 * @return array<string,array<string,int>> source => domain => rows.
	 */
	public static function all(): array {
		$ledger = get_option( self::OPTION );

		return is_array( $ledger ) ? $ledger : array();
	}

	/**
	 * One source's tally.
	 *
	 * @param string $source Source key.
	 * @return array<string,int> domain => rows.
	 */
	public static function for_source( string $source ): array {
		$ledger = self::all();

		return isset( $ledger[ $source ] ) && is_array( $ledger[ $source ] )
			? array_map( 'intval', $ledger[ $source ] )
			: array();
	}

	/**
	 * Whether anything has been imported from a source yet.
	 *
	 * @param string $source Source key.
	 */
	public static function has_run( string $source ): bool {
		foreach ( self::for_source( $source ) as $rows ) {
			if ( $rows > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forget a source's tally, so a fresh migration reports only its own work.
	 *
	 * @param string $source Source key.
	 */
	public static function reset( string $source ): void {
		$ledger = self::all();
		unset( $ledger[ $source ] );

		update_option( self::OPTION, $ledger, false );

		$skips = self::all_skips();
		unset( $skips[ $source ] );

		update_option( self::SKIPS_OPTION, $skips, false );
	}

	/**
	 * Remove the option entirely (importer cleanup).
	 */
	public static function drop(): void {
		delete_option( self::OPTION );
		delete_option( self::SKIPS_OPTION );
	}
}
