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
	public function import_batch( int $after, int $limit ): array {
		$groups   = $this->adapter->groups( $after, $limit );
		$done     = 0;
		$existing = 0;
		$members  = 0;
		$last     = $after;

		foreach ( $groups as $group ) {
			$last     = (int) $group['source_id'];
			$bn_space = $this->writer->import_space( $group );

			if ( 0 === $bn_space['id'] ) {
				continue;
			}

			if ( $bn_space['created'] ) {
				++$done;
			} else {
				++$existing;
			}

			$members += $this->import_members( (int) $group['source_id'], $bn_space['id'], (int) $group['creator_id'] );
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
	 * @param int $source_group_id Source group id.
	 * @param int $bn_space_id     Mapped space id.
	 * @param int $owner_id        Space owner.
	 */
	private function import_members( int $source_group_id, int $bn_space_id, int $owner_id ): int {
		$written = 0;
		$after   = 0;

		do {
			$rows    = $this->adapter->group_members( $source_group_id, $after, self::MEMBER_BATCH );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				if ( $this->writer->import_member( $bn_space_id, $owner_id, $row ) ) {
					++$written;
				}
				$after = (int) $row['row_id'];
			}
		} while ( self::MEMBER_BATCH === $fetched );

		return $written;
	}
}
