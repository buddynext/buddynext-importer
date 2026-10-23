<?php
/**
 * Orchestrates the blocks domain import, keyset-paginated. Shared by the CLI
 * and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\BlockWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks import coordinator.
 */
final class BlockImporter {

	/**
	 * Read adapter.
	 *
	 * @var SourceAdapter
	 */
	private SourceAdapter $adapter;

	/**
	 * Service-layer writer.
	 *
	 * @var BlockWriter
	 */
	private BlockWriter $writer;

	/**
	 * Construct the importer with its read adapter.
	 *
	 * @param SourceAdapter $adapter Read adapter for the source.
	 */
	public function __construct( SourceAdapter $adapter ) {
		$this->adapter = $adapter;
		$this->writer  = new BlockWriter();
	}

	/**
	 * Build an importer for a source key, or null when unavailable.
	 *
	 * @param string $source Source key.
	 */
	public static function for_source( string $source ): ?self {
		$adapter = AdapterRegistry::get( $source );
		if ( null === $adapter || ! $adapter->is_available() || ! function_exists( 'buddynext_service' ) ) {
			return null;
		}
		// Only sources that hold blocks show the step; BuddyPress has none.
		if ( array() === $adapter->blocks( 0, 1 ) ) {
			return null;
		}
		return new self( $adapter );
	}

	/**
	 * Import one keyset batch of blocks.
	 *
	 * @param int $after Exclusive lower-bound block id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,blocks:int,skipped:array<string,int>}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows    = $this->adapter->blocks( $after, $limit );
		$blocks  = 0;
		$skipped = array();
		$last    = $after;

		foreach ( $rows as $row ) {
			$last   = max( $last, (int) $row['source_id'] );
			$reason = $this->writer->import_block( $row );

			if ( '' === $reason ) {
				++$blocks;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'blocks'  => $blocks,
			'skipped' => $skipped,
		);
	}
}
