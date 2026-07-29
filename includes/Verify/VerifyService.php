<?php
/**
 * Verify a migration: coverage, per-domain totals, and spot-checks on objects.
 *
 * The importer can only ever report what it WROTE. That number cannot describe
 * what it declined to write, and it says nothing at all about whether a row
 * landed in the right place - a migration can reconcile perfectly on every
 * total while each post sits in the wrong space, in front of the wrong people.
 *
 * So this asks three different questions, because no one of them is sufficient:
 *
 *   1. COVERAGE  - what can this source migrate at all? Content whose parent is
 *                  never imported has nowhere to go, and saying so before a run
 *                  is the difference between a decision and a bug report.
 *   2. TOTALS    - source counted independently against what landed, per domain.
 *   3. OBJECTS   - randomly sampled rows walked end to end: the space a post
 *                  belongs to, the comments and reactions hanging off it, the
 *                  members of a space. Totals cannot see placement; this can.
 *
 * Sampling is random on purpose. A fixed sample only ever proves the rows it
 * names, and the failures that matter here were all in the tail - one group
 * whose slug collided, one comment whose root was the wrong type.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Verify;

use BuddyNextImporter\Pipeline\ImportLedger;
use BuddyNextImporter\Pipeline\StepRegistry;
use BuddyNextImporter\Source\AdapterRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a verification report for a completed (or pending) migration.
 */
final class VerifyService {

	/**
	 * Build the whole report.
	 *
	 * @param string $source  Source key.
	 * @param int    $samples How many objects of each kind to spot-check.
	 * @return array<string,mixed>
	 */
	public function report( string $source, int $samples = 5 ): array {
		$adapter = AdapterRegistry::get( $source );
		if ( null === $adapter || ! $adapter->is_available() ) {
			return array(
				'source'    => $source,
				'available' => false,
			);
		}

		return array(
			'source'    => $source,
			'available' => true,
			'coverage'  => $this->coverage( $adapter ),
			'domains'   => $this->domains( $source, $adapter ),
			'samples'   => array(
				'spaces'     => $this->sample_spaces( $source, $samples ),
				'activities' => $this->sample_activities( $source, $samples ),
			),
		);
	}

	/**
	 * Content the migration cannot carry, and why.
	 *
	 * @param object $adapter Source adapter.
	 * @return array<string,mixed>
	 */
	private function coverage( object $adapter ): array {
		$blocked = array();
		$total   = 0;

		if ( method_exists( $adapter, 'comment_root_types' ) ) {
			foreach ( $adapter->comment_root_types() as $row ) {
				if ( ! $row['importable'] ) {
					$blocked[] = array(
						'reason' => sprintf(
							/* translators: %s: source activity type. */
							__( 'comments on a %s activity - a system notice the importer does not carry, so there is no post to attach them to', 'buddynext-importer' ),
							(string) $row['type']
						),
						'rows'   => (int) $row['comments'],
					);
					$total += (int) $row['comments'];
				}
			}
		}

		return array(
			'blocked_rows' => $total,
			'reasons'      => $blocked,
		);
	}

	/**
	 * Per-domain source count against what was imported.
	 *
	 * @param string $source  Source key.
	 * @param object $adapter Source adapter.
	 * @return array<int,array<string,mixed>>
	 */
	private function domains( string $source, object $adapter ): array {
		$stats  = $adapter->stats();
		$ledger = ImportLedger::for_source( $source );
		$rows   = array();

		foreach ( StepRegistry::steps( $source ) as $step ) {
			$domain = (string) $step['domain'];
			$stat   = (string) ( $step['stat'] ?? '' );

			$expected = null;
			foreach ( explode( ',', $stat ) as $key ) {
				$key = trim( $key );
				if ( '' !== $key && isset( $stats[ $key ] ) ) {
					$expected = (int) $expected + (int) $stats[ $key ];
				}
			}

			$rows[] = array(
				'label'     => (string) $step['label'],
				'domain'    => $domain,
				'expected'  => $expected,
				'imported'  => (int) ( $ledger[ $domain ] ?? 0 ),
				'available' => (bool) ( $step['available'] )(),
			);
		}

		return $rows;
	}

	/**
	 * Spot-check random spaces: members, and the stored counter the directory
	 * actually displays.
	 *
	 * @param string $source Source key.
	 * @param int    $limit  How many to check.
	 * @return array<int,array<string,mixed>>
	 */
	private function sample_spaces( string $source, int $limit ): array {
		global $wpdb;

		$map = $wpdb->prefix . 'bni_id_map';
		if ( ! $this->table_exists( $map ) || ! $this->table_exists( $wpdb->prefix . 'bn_spaces' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id, bn_id FROM `{$map}` WHERE source = %s AND domain = 'space' ORDER BY RAND() LIMIT %d",
				$source,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $pairs as $pair ) {
			$src = (int) $pair['source_id'];
			$bn  = (int) $pair['bn_id'];

			// A pending join request is migrated but is NOT a member, so the
			// comparison has to be active-against-confirmed or it invents a gap.
			$src_members = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_groups_members WHERE group_id = %d AND is_confirmed = 1", $src ) ); // phpcs:ignore WordPress.DB
			$bn_active   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND status = 'active'", $bn ) ); // phpcs:ignore WordPress.DB
			$stored      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $bn ) ); // phpcs:ignore WordPress.DB
			$name        = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $bn ) ); // phpcs:ignore WordPress.DB

			$problems = array();
			if ( $stored !== $bn_active ) {
				$problems[] = sprintf( 'member_count says %d, %d active members exist', $stored, $bn_active );
			}

			$out[] = array(
				'name'      => $name,
				'source_id' => $src,
				'bn_id'     => $bn,
				'detail'    => sprintf( '%d confirmed source members -> %d active', $src_members, $bn_active ),
				'problems'  => $problems,
			);
		}

		return $out;
	}

	/**
	 * Spot-check random activities: the space they belong to, and the comments
	 * and reactions hanging off them.
	 *
	 * @param string $source Source key.
	 * @param int    $limit  How many to check.
	 * @return array<int,array<string,mixed>>
	 */
	private function sample_activities( string $source, int $limit ): array {
		global $wpdb;

		$map = $wpdb->prefix . 'bni_id_map';
		if ( ! $this->table_exists( $map ) || ! $this->table_exists( $wpdb->prefix . 'bn_posts' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id, bn_id FROM `{$map}` WHERE source = %s AND domain = 'post' ORDER BY RAND() LIMIT %d",
				$source,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $pairs as $pair ) {
			$src = (int) $pair['source_id'];
			$bn  = (int) $pair['bn_id'];

			$row = $wpdb->get_row( $wpdb->prepare( "SELECT component, item_id, LEFT(content,50) AS content FROM {$wpdb->prefix}bp_activity WHERE id = %d", $src ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( null === $row ) {
				continue;
			}

			$problems = array();

			// Placement. A group activity whose space did not import must never
			// have been created at all - space_id 0 is the global feed, and on a
			// source with no privacy column it publishes as public.
			$expected_space = 0;
			if ( 'groups' === (string) $row['component'] ) {
				$expected_space = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT bn_id FROM `{$map}` WHERE source = %s AND domain = 'space' AND source_id = %d", // phpcs:ignore WordPress.DB
						$source,
						(int) $row['item_id']
					)
				);
			}
			$actual_space = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $bn ) ); // phpcs:ignore WordPress.DB
			if ( $expected_space !== $actual_space ) {
				$problems[] = sprintf( 'belongs in space %d but sits in %d', $expected_space, $actual_space );
			}

			// Children.
			$src_comments = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity WHERE type = 'activity_comment' AND is_spam = 0 AND item_id = %d", $src ) ); // phpcs:ignore WordPress.DB
			$bn_comments  = $this->table_exists( $wpdb->prefix . 'bn_comments' )
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE object_type = 'post' AND object_id = %d AND is_deleted = 0", $bn ) ) // phpcs:ignore WordPress.DB
				: 0;
			if ( $bn_comments !== $src_comments ) {
				$problems[] = sprintf( '%d source comments, %d imported', $src_comments, $bn_comments );
			}

			$bn_reactions = $this->table_exists( $wpdb->prefix . 'bn_reactions' )
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions WHERE object_type = 'post' AND object_id = %d", $bn ) ) // phpcs:ignore WordPress.DB
				: 0;

			$out[] = array(
				'source_id' => $src,
				'bn_id'     => $bn,
				'content'   => (string) $row['content'],
				'detail'    => sprintf( 'space %d, %d comment(s), %d reaction(s)', $actual_space, $bn_comments, $bn_reactions ),
				'problems'  => $problems,
			);
		}

		return $out;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}
}
