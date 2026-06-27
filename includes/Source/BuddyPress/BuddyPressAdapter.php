<?php
/**
 * BuddyPress read adapter. Reads the bp_* tables directly (they are the source,
 * not BuddyNext data) and is the base for the BuddyBoss adapter.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source\BuddyPress;

use BuddyNextImporter\Source\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 1: identity + availability + stats. The data-read methods land per
 * build phase (profiles, groups, activity...).
 */
class BuddyPressAdapter implements SourceAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'buddypress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'BuddyPress', 'buddynext-importer' );
	}

	/**
	 * Available when the core BuddyPress tables exist (xprofile or activity).
	 */
	public function is_available(): bool {
		return $this->table_exists( 'bp_xprofile_fields' ) || $this->table_exists( 'bp_activity' );
	}

	/**
	 * Per-domain counts read from the bp_* tables. Each count is table-guarded so
	 * a disabled component (e.g. friends) reports 0 rather than erroring.
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		global $wpdb;

		return array(
			'users'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'profile_fields'    => $this->table_count( 'bp_xprofile_fields', 'parent_id = 0' ),
			'profile_values'    => $this->table_count( 'bp_xprofile_data' ),
			'groups'            => $this->table_count( 'bp_groups' ),
			'group_members'     => $this->table_count( 'bp_groups_members', 'is_confirmed = 1' ),
			'activities'        => $this->table_count( 'bp_activity', "type = 'activity_update'" ),
			'activity_comments' => $this->table_count( 'bp_activity', "type = 'activity_comment'" ),
			'friendships'       => $this->table_count( 'bp_friends', 'is_confirmed = 1' ),
		);
	}

	/**
	 * Count rows of a prefixed table, guarded against the table not existing.
	 *
	 * The table name is a hard-coded literal (never user input) and the optional
	 * WHERE clause is likewise a literal, so the interpolation is safe.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 * @param string $where      Optional literal WHERE clause (no user input).
	 */
	protected function table_count( string $unprefixed, string $where = '' ): int {
		global $wpdb;

		if ( ! $this->table_exists( $unprefixed ) ) {
			return 0;
		}

		$table     = $wpdb->prefix . $unprefixed;
		$condition = '' !== $where ? " WHERE {$where}" : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`{$condition}" );
	}

	/**
	 * Whether a prefixed table exists.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 */
	protected function table_exists( string $unprefixed ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $unprefixed;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}
}
