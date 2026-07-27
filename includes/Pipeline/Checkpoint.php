<?php
/**
 * Resume checkpoint: the highest source-record id a domain has fully processed,
 * so a re-run skips the already-migrated prefix instead of re-scanning from id 0.
 *
 * The id-map ({@see IdMap}) already makes every write idempotent - re-importing a
 * mapped row is a no-op. What it does NOT save is the cost of WALKING back over
 * millions of already-mapped rows to reach the un-imported tail. On a 10k-member
 * / 1M-activity community that walk is the whole runtime of a resumed run. This
 * cursor records where each domain left off so resume is O(remaining), not O(all).
 *
 * Correctness stays with the id-map, never the cursor: the cursor only ever skips
 * a prefix whose rows are already mapped (or were deterministically skipped). If a
 * run leaves a gap behind the cursor (a transient write failure), the caller clears
 * the cursor for that domain so the next run re-scans from 0 and the id-map refills
 * the hole. A stale cursor can therefore cost a re-scan, never a lost row.
 *
 * Like the id-map this is scaffolding for a one-time migration: {@see self::drop()}
 * tears it down (via `wp buddynext-import cleanup`) once the import is verified.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Thin data-access wrapper over the {prefix}bni_checkpoint table.
 */
final class Checkpoint {

	/**
	 * Unprefixed table name.
	 */
	private const TABLE = 'bni_checkpoint';

	/**
	 * Fully-qualified, prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table. Idempotent (dbDelta).
	 *
	 * `last_id` (not `cursor`, a MySQL reserved word) holds the highest source id
	 * a (source, domain) pair has fully processed.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			source VARCHAR(32) NOT NULL,
			domain VARCHAR(48) NOT NULL,
			last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (source, domain)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * The cursor for a domain: the highest source id already processed, or 0.
	 *
	 * @param string $source Source key (buddypress|buddyboss).
	 * @param string $domain Domain key (post|comment|space|connection...).
	 */
	public static function get( string $source, string $domain ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_id FROM {$table} WHERE source = %s AND domain = %s",
				$source,
				$domain
			)
		);
		// phpcs:enable

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Advance (or set) the cursor for a domain.
	 *
	 * @param string $source  Source key.
	 * @param string $domain  Domain key.
	 * @param int    $last_id Highest source id processed so far.
	 */
	public static function set( string $source, string $domain, int $last_id ): void {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source, domain, last_id, updated_at)
				VALUES (%s, %s, %d, %s)
				ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), updated_at = VALUES(updated_at)",
				$source,
				$domain,
				$last_id,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable
	}

	/**
	 * Forget a domain's cursor so the next run re-scans it from the start.
	 *
	 * Called when a run leaves an un-imported gap behind the cursor (a transient
	 * write failure): the id-map skips what is already there and refills the gap.
	 *
	 * @param string $source Source key.
	 * @param string $domain Domain key.
	 */
	public static function clear( string $source, string $domain ): void {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source = %s AND domain = %s",
				$source,
				$domain
			)
		);
		// phpcs:enable
	}

	/**
	 * Drop the whole table. Part of the post-migration teardown.
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
