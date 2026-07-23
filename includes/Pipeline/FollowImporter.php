<?php
/**
 * Orchestrates the follows domain import: bp_follow -> bn_follows,
 * keyset-paginated. Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\FollowWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Follows import coordinator.
 */
final class FollowImporter {

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
	 * @var FollowWriter
	 */
	private FollowWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new FollowWriter();
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
	 * Import one keyset batch of follows.
	 *
	 * @param int $after Exclusive lower-bound follow id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,follows:int}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows    = $this->adapter->follows( $after, $limit );
		$follows = 0;
		$last    = $after;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) $row['source_id'] );
			if ( $this->writer->import_follow( $row ) ) {
				++$follows;
			}
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'follows' => $follows,
		);
	}
}
