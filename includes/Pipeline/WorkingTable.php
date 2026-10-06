<?php
/**
 * Shared lifecycle for the importer's two working tables.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The id map and the checkpoint are created on activation and dropped by
 * `cleanup`. A migrate run after a cleanup (the importer's own documented
 * "one more delta before cutover" flow) used to write into tables that no
 * longer existed: every write failed silently, nothing was deduplicated, and
 * the next run imported everything again.
 *
 * Writes now create the table first if it is gone. Reads never create it, so
 * verify can still tell that the map is missing and say so.
 */
trait WorkingTable {

	/**
	 * Whether the table exists, cached for the request.
	 *
	 * @var bool|null
	 */
	private static ?bool $table_exists = null;

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		if ( null === self::$table_exists ) {
			global $wpdb;
			$table              = self::table();
			self::$table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$table_exists;
	}

	/**
	 * Create the table if it is missing, before a write.
	 *
	 * @return void
	 */
	private static function ensure(): void {
		if ( ! self::exists() ) {
			self::install();
			self::$table_exists = true;
		}
	}

	/**
	 * Forget the cached answer (after install() or drop()).
	 *
	 * @return void
	 */
	private static function forget_exists(): void {
		self::$table_exists = null;
	}
}
