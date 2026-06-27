<?php
/**
 * Orchestrates the friendships domain import: friendships -> connections,
 * keyset-paginated. Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ConnectionWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Friendships import coordinator.
 */
final class FriendImporter {

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
	 * @var ConnectionWriter
	 */
	private ConnectionWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ConnectionWriter( $source );
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
	 * Import one keyset batch of friendships.
	 *
	 * @param int $after Exclusive lower-bound friendship id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,connections:int}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows        = $this->adapter->friendships( $after, $limit );
		$connections = 0;
		$last        = $after;

		foreach ( $rows as $row ) {
			$last = (int) $row['source_id'];
			if ( $this->writer->import_friendship( $row ) ) {
				++$connections;
			}
		}

		return array(
			'last'        => $last,
			'fetched'     => count( $rows ),
			'connections' => $connections,
		);
	}
}
