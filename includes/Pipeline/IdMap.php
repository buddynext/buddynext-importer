<?php
/**
 * Id-map: persists source-record-id -> BuddyNext-id pairs so an import is
 * idempotent, resumable, and able to resolve relationships (a comment finds its
 * parent post's BuddyNext id; a group activity finds its space).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Thin data-access wrapper over the {prefix}bni_id_map table.
 */
final class IdMap {

	/**
	 * Unprefixed table name.
	 */
	private const TABLE = 'bni_id_map';

	/**
	 * Fully-qualified, prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the table. Idempotent (dbDelta).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(32) NOT NULL,
			domain VARCHAR(32) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			bn_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_domain_source (source, domain, source_id),
			KEY source_domain_bn (source, domain, bn_id)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Record (or update) a mapping.
	 *
	 * @param string $source    Source key (buddypress|buddyboss).
	 * @param string $domain    Domain (profile_field|space|post|comment|connection...).
	 * @param int    $source_id The id in the source system.
	 * @param int    $bn_id     The created BuddyNext id.
	 */
	public static function set( string $source, string $domain, int $source_id, int $bn_id ): void {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source, domain, source_id, bn_id, created_at)
				VALUES (%s, %s, %d, %d, %s)
				ON DUPLICATE KEY UPDATE bn_id = VALUES(bn_id)",
				$source,
				$domain,
				$source_id,
				$bn_id,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable
	}

	/**
	 * Look up a previously-imported BuddyNext id.
	 *
	 * @param string $source    Source key.
	 * @param string $domain    Domain.
	 * @param int    $source_id Source id.
	 * @return int|null The BuddyNext id, or null if not yet imported.
	 */
	public static function get( string $source, string $domain, int $source_id ): ?int {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT bn_id FROM {$table} WHERE source = %s AND domain = %s AND source_id = %d",
				$source,
				$domain,
				$source_id
			)
		);
		// phpcs:enable

		return null === $value ? null : (int) $value;
	}

	/**
	 * Whether a source record has already been imported.
	 *
	 * @param string $source    Source key.
	 * @param string $domain    Domain.
	 * @param int    $source_id Source id.
	 */
	public static function has( string $source, string $domain, int $source_id ): bool {
		return null !== self::get( $source, $domain, $source_id );
	}

	/**
	 * Count imported rows for a source + domain (drives the progress monitor).
	 *
	 * @param string $source Source key.
	 * @param string $domain Domain.
	 */
	public static function count( string $source, string $domain ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source = %s AND domain = %s",
				$source,
				$domain
			)
		);
		// phpcs:enable
	}
}
