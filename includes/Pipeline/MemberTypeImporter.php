<?php
/**
 * Orchestrates the member-types domain import: the source `bp_member_type`
 * taxonomy -> BuddyNext member types + assignments, keyset-paginated by user.
 * Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\MemberTypeWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Member-types import coordinator.
 */
final class MemberTypeImporter {

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
	 * @var MemberTypeWriter
	 */
	private MemberTypeWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new MemberTypeWriter( $source );
	}

	/**
	 * Whether the member-type target is present.
	 */
	public static function target_available(): bool {
		return MemberTypeWriter::available();
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
	 * Import the type vocabulary. Runs once, before any assignment, because an
	 * assignment can only land on a type that already exists.
	 *
	 * @return array{types:int,skipped:int}
	 */
	public function import_types(): array {
		$types   = 0;
		$skipped = 0;

		foreach ( $this->adapter->member_types() as $type ) {
			if ( $this->writer->import_type( $type ) > 0 ) {
				++$types;
			} else {
				++$skipped;
			}
		}

		return array(
			'types'   => $types,
			'skipped' => $skipped,
		);
	}

	/**
	 * Import one keyset batch of member-type assignments.
	 *
	 * `fetched` counts USERS (the page unit), so the caller's "page was full"
	 * loop condition stays correct even when a user carries several source types.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size (users).
	 * @return array{last:int,fetched:int,assignments:int,skipped:array<string,int>}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows        = $this->adapter->member_type_assignments( $after, $limit );
		$assignments = 0;
		$skipped     = array();
		$last        = $after;
		$seen        = array();

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$last    = max( $last, $user_id );

			// BuddyNext member type is single and set-once. A source user with
			// several types keeps the first; the rest are reported, not silently
			// overwritten one by one until the last one wins.
			if ( isset( $seen[ $user_id ] ) ) {
				$skipped['multiple_source_types'] = ( $skipped['multiple_source_types'] ?? 0 ) + 1;
				continue;
			}
			$seen[ $user_id ] = true;

			$reason = $this->writer->import_assignment( $user_id, (string) $row['slug'] );

			if ( '' === $reason ) {
				++$assignments;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'last'        => $last,
			'fetched'     => count( $seen ),
			'assignments' => $assignments,
			'skipped'     => $skipped,
		);
	}
}
