<?php
/**
 * Orchestrates the spaces domain import: groups -> spaces, each with its members,
 * keyset-paginated. Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\SpaceWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Spaces import coordinator.
 */
final class SpaceImporter {

	/**
	 * Inner member-batch size.
	 */
	private const MEMBER_BATCH = 200;

	/**
	 * Source key.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Read adapter.
	 *
	 * @var SourceAdapter
	 */
	private SourceAdapter $adapter;

	/**
	 * Service-layer writer.
	 *
	 * @var SpaceWriter
	 */
	private SpaceWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new SpaceWriter( $source );
	}

	/**
	 * Build an importer for a source key, or null when unavailable.
	 *
	 * @param string $source Source key.
	 */
	public static function for_source( string $source ): ?self {
		$adapter = AdapterRegistry::get( $source );
		if ( null === $adapter || ! $adapter->is_available() ) {
			return null;
		}
		return new self( $source, $adapter );
	}

	/**
	 * Import one keyset batch of groups, each with all of its members.
	 *
	 * @param int $after Exclusive lower-bound group id.
	 * @param int $limit Groups per batch.
	 * `groups` counts spaces created this run; `existing` counts those a previous
	 * run already made. Members are still walked for an existing space, because a
	 * resumed or incremental run may have new members to add to it.
	 *
	 * @return array{last:int,fetched:int,groups:int,existing:int,members:int}
	 */
	/**
	 * Import the source's group types as BuddyNext space categories.
	 *
	 * Runs BEFORE spaces: a space carries its category_id at create time, so a
	 * category that does not exist yet cannot be attached to one.
	 *
	 * Group types are a handful of rows, not a domain that needs paging, so the
	 * whole set moves in one call - the cursor is still reported so the runner
	 * can treat this like any other step.
	 *
	 * @param int $after Cursor (unused - the set is imported whole).
	 * @param int $limit Batch size (unused, but part of the step signature every
	 *                   StepRegistry run closure is called with).
	 * @return array{last:int,fetched:int,categories:int,existing:int}
	 */
	public function import_categories_batch( int $after, int $limit ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $after > 0 ) {
			// Already done in the first call; report an empty page so the step ends.
			return array(
				'last'       => $after,
				'fetched'    => 0,
				'categories' => 0,
				'existing'   => 0,
			);
		}

		$types      = $this->adapter->group_types();
		$categories = 0;
		$existing   = 0;

		foreach ( $types as $type ) {
			$result = $this->writer->import_category( $type );

			if ( $result['created'] ) {
				++$categories;
			} elseif ( $result['id'] > 0 ) {
				++$existing;
			}
		}

		return array(
			'last'       => count( $types ) > 0 ? 1 : 0,
			'fetched'    => count( $types ),
			'categories' => $categories,
			'existing'   => $existing,
		);
	}

	/**
	 * Import one page of source groups as spaces, with their members.
	 *
	 * @param int $after Keyset cursor: the highest source group id already done.
	 * @param int $limit Page size.
	 * @return array{last:int,fetched:int,spaces:int,existing:int,members:int}
	 */
	public function import_batch( int $after, int $limit ): array {
		$groups   = $this->adapter->groups( $after, $limit );
		$done     = 0;
		$existing = 0;
		$members  = 0;
		$last     = $after;

		// One query for the whole page's group types, so resolving each space's
		// category costs nothing per row.
		$type_map = $this->adapter->group_type_map(
			array_map( static fn ( array $g ): int => (int) $g['source_id'], $groups )
		);

		foreach ( $groups as $group ) {
			$last              = (int) $group['source_id'];
			$group['type_ids'] = $type_map[ (int) $group['source_id'] ] ?? array();
			$bn_space          = $this->writer->import_space( $group );

			if ( 0 === $bn_space['id'] ) {
				continue;
			}

			if ( $bn_space['created'] ) {
				++$done;
			} else {
				++$existing;
			}

			$members += $this->import_members(
				(int) $group['source_id'],
				$bn_space['id'],
				(int) $group['creator_id'],
				(bool) $bn_space['created']
			);
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $groups ),
			'groups'   => $done,
			'existing' => $existing,
			'members'  => $members,
		);
	}

	/**
	 * Import all members of one group, keyset-paginated.
	 *
	 * @param int  $source_group_id Source group id.
	 * @param int  $bn_space_id     Mapped space id.
	 * @param int  $owner_id        Space owner.
	 * @param bool $space_created   Whether the space was created by THIS run.
	 */
	private function import_members( int $source_group_id, int $bn_space_id, int $owner_id, bool $space_created = false ): int {
		$written = 0;
		$after   = 0;

		do {
			$rows    = $this->adapter->group_members( $source_group_id, $after, self::MEMBER_BATCH );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				if ( $this->writer->import_member( $bn_space_id, $owner_id, $row ) ) {
					++$written;
					$after = (int) $row['row_id'];
					continue;
				}

				// The owner's membership is real - SpaceService::create() adds it
				// with the space - but import_member() refuses the row, so nothing
				// counted it. The source stat counts every bp_groups_members row
				// INCLUDING the creator's, so each space reported one member fewer
				// than it holds: a phantom "short by N" on a migration that had
				// lost nothing, on the screen an owner reads before deleting their
				// old community.
				//
				// Counted only on the run that CREATED the space. On a re-run the
				// space already exists, every other member row returns false too,
				// and the ledger keeps the count the first run recorded.
				if ( $space_created && (int) $row['user_id'] === $owner_id ) {
					++$written;
				}

				$after = (int) $row['row_id'];
			}
		} while ( self::MEMBER_BATCH === $fetched );

		return $written;
	}
}
