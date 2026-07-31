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
 * Every read here is a direct, uncached query, and phpcs says so ten times over.
 * Leave it that way: verification has to see what is on disk right now, and
 * routing a count through a service or an object cache would report the
 * importer's own view of what it wrote - which is the claim being checked. A
 * cached "all present" is worthless. The warnings are not annotated away because
 * a file-level phpcs:disable stops the inline ignores on the interpolated table
 * names from taking effect, trading ten advisory warnings for three errors.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Verify;

use BuddyNextImporter\Pipeline\DomainSelection;
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
			'relations' => method_exists( $adapter, 'relationship_report' ) ? $adapter->relationship_report() : array(),
			'exposure'  => $this->exposure(),
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
					$total    += (int) $row['comments'];
				}
			}
		}

		return array(
			'blocked_rows' => $total,
			'reasons'      => $blocked,
		);
	}

	/**
	 * Whether migrated content is exposed more widely than its space allows.
	 *
	 * A post in a private or secret space must not be publicly searchable. The
	 * search index carries its own visibility column, derived at index time, so
	 * it can disagree with the space - and when it does, nothing in a row count
	 * shows it. BuddyNext 1.1.1 fixed exactly that for natively-created content
	 * (schema v37); migrated content deserves the same assurance, since the
	 * importer writes thousands of rows in one pass.
	 *
	 * @return array<string,mixed>
	 */
	private function exposure(): array {
		global $wpdb;

		$index = $wpdb->prefix . 'bn_search_index';
		if ( ! $this->table_exists( $index ) ) {
			return array(
				'checked' => false,
				'leaked'  => 0,
				'rows'    => array(),
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $index is $wpdb->prefix . 'bn_search_index', not input.
		$rows = $wpdb->get_results(
			"SELECT COALESCE( s.type, '(no space)' ) AS space_type, si.visibility, COUNT(*) AS n
			   FROM `{$index}` si
			   LEFT JOIN {$wpdb->prefix}bn_spaces s ON s.id = si.space_id
			  WHERE si.object_type = 'post'
			  GROUP BY space_type, si.visibility", // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$leaked = 0;
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$restricted = in_array( (string) $row['space_type'], array( 'private', 'secret' ), true );
			$public     = 'public' === (string) $row['visibility'];
			$bad        = $restricted && $public;

			if ( $bad ) {
				$leaked += (int) $row['n'];
			}

			$out[] = array(
				'space_type' => (string) $row['space_type'],
				'visibility' => (string) $row['visibility'],
				'rows'       => (int) $row['n'],
				'leaked'     => $bad,
			);
		}

		return array(
			'checked' => true,
			'leaked'  => $leaked,
			'rows'    => $out,
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

		$selected = DomainSelection::last_run( $source );

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

			// A domain the owner chose to leave behind is a THIRD state, distinct
			// from imported and from failed. Reporting it as a shortfall would make
			// every selective migration look like a broken one, and would train the
			// owner to ignore the one screen that is supposed to tell them whether
			// it is safe to delete the source community.
			$skipped = ! in_array( (string) $step['phase'], $selected, true );

			$rows[] = array(
				'label'     => (string) $step['label'],
				'domain'    => $domain,
				'expected'  => $expected,
				'imported'  => (int) ( $ledger[ $domain ] ?? 0 ),
				'available' => (bool) ( $step['available'] )(),
				'skipped'   => $skipped,
				// Why a domain is short, when the answer is knowable from the
				// id-map rather than from guessing.
				'because'   => $skipped
					? __( 'skipped by choice', 'buddynext-importer' )
					: $this->shortfall_reason( $source, $domain, $expected, (int) ( $ledger[ $domain ] ?? 0 ), $adapter ),
			);
		}

		return $rows;
	}

	/**
	 * Explain a domain's shortfall where the id-map can prove the cause.
	 *
	 * A bare "short by 547" reads as data loss. For anything hung off a parent -
	 * a reaction, a comment - the id-map already knows whether that parent was
	 * imported, so the gap can be attributed rather than left to be feared.
	 *
	 * @param string      $source   Source key.
	 * @param string      $domain   Domain key.
	 * @param int|null    $expected Source count, when comparable.
	 * @param int         $imported Rows written.
	 * @param object|null $adapter Source adapter, for source-side explanations.
	 */
	private function shortfall_reason( string $source, string $domain, ?int $expected, int $imported, ?object $adapter = null ): string {
		if ( null === $expected || $imported >= $expected ) {
			return '';
		}

		global $wpdb;

		$map = $wpdb->prefix . 'bni_id_map';
		if ( ! $this->table_exists( $map ) ) {
			return '';
		}

		// Reactions hang off an activity. One whose activity was not imported has
		// nothing to attach to, and that is the whole of the gap on every source
		// seen so far - system notices people reacted to.
		if ( 'reaction' === $domain && $this->table_exists( $wpdb->prefix . 'bb_user_reactions' ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $map is $wpdb->prefix . 'bni_id_map', not input.
			$orphaned = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bb_user_reactions r
					  WHERE r.item_type = 'activity'
					    AND NOT EXISTS (
					        SELECT 1 FROM `{$map}` m
					         WHERE m.source = %s AND m.domain IN ( 'post', 'comment' ) AND m.source_id = r.item_id
					    )", // phpcs:ignore WordPress.DB
					$source
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $orphaned > 0 ) {
				return sprintf(
					/* translators: %d: number of reactions. */
					__( '%d were on activity that is not imported, so they have no post to attach to', 'buddynext-importer' ),
					$orphaned
				);
			}
		}

		// A comment whose root post was not imported has nowhere to attach, so it
		// is dropped with its parent - correct cascade behaviour, and by far the
		// commonest reason this domain comes up short. The gap was real and the
		// silence was the defect: verify printed a bare "short by 1" with nothing
		// pointing at the refused post, which reads as unexplained data loss.
		//
		// The adapter owns the count because it needs the imported-type list, and
		// asking it here means the reason can never be larger than the gap it
		// explains.
		if ( 'comment' === $domain && method_exists( $adapter, 'orphaned_importable_comment_count' ) ) {
			$orphaned = (int) $adapter->orphaned_importable_comment_count( $source );

			if ( $orphaned > 0 ) {
				return sprintf(
					/* translators: %d: number of comments. */
					__( '%d were on posts that did not migrate, so they were dropped with them', 'buddynext-importer' ),
					$orphaned
				);
			}
		}

		return '';
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
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $map is $wpdb->prefix . 'bni_id_map', not input.
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

			// Activity belongs in the space report, not in a global posts total.
			// A group post is refused when its author is not a member of that
			// group - BuddyNext enforcing the space's own rules - so the shortfall
			// is a fact about THIS space and is only explicable next to it.
			$src_activity = (int) $wpdb->get_var(
				$wpdb->prepare(
					// hide_sitewide is NOT excluded: content the source kept out of its
					// sitewide feed still imports into its space, where the space's own
					// privacy protects it. Filtering it out here made a space with
					// withheld activity read as 0 source posts against 6 imported.
					"SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity
					  WHERE component = 'groups' AND type = 'activity_update' AND is_spam = 0 AND item_id = %d", // phpcs:ignore WordPress.DB
					$src
				)
			);
			$bn_activity = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d", $bn ) ); // phpcs:ignore WordPress.DB

			$problems = array();
			if ( $stored !== $bn_active ) {
				$problems[] = sprintf( 'member_count says %d, %d active members exist', $stored, $bn_active );
			}

			$notes = array();
			if ( $bn_activity < $src_activity ) {
				// Attribute the gap rather than just reporting it. Posts by a
				// non-member are refused by design; anything left over is not.
				$by_outsiders = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity a
						  WHERE a.component = 'groups' AND a.type = 'activity_update' AND a.is_spam = 0
						    AND a.hide_sitewide = 0 AND a.item_id = %d
						    AND NOT EXISTS (
						        SELECT 1 FROM {$wpdb->prefix}bp_groups_members m
						         WHERE m.group_id = a.item_id AND m.user_id = a.user_id AND m.is_confirmed = 1
						    )", // phpcs:ignore WordPress.DB
						$src
					)
				);

				$missing = $src_activity - $bn_activity;
				if ( $by_outsiders >= $missing ) {
					$notes[] = sprintf( '%d post(s) refused - author is not a member', $missing );
				} else {
					// Only the part outsiders cannot account for is a defect.
					$problems[] = sprintf(
						'%d post(s) missing, only %d explained by a non-member author',
						$missing,
						$by_outsiders
					);
				}
			}

			$out[] = array(
				'name'      => $name,
				'source_id' => $src,
				'bn_id'     => $bn,
				'detail'    => sprintf(
					'%d confirmed member(s) -> %d active; %d activity -> %d%s',
					$src_members,
					$bn_active,
					$src_activity,
					$bn_activity,
					$notes ? ' (' . implode( '; ', $notes ) . ')' : ''
				),
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
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $map is $wpdb->prefix . 'bni_id_map', not input.
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
