<?php
/**
 * Orchestrates the reactions domain import: likes/favorites -> bn_reactions,
 * keyset-paginated. Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ReactionWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Reactions import coordinator.
 *
 * NOTE for callers: unlike the other importers, batches are NOT uniform in row
 * count. The usermeta-favorites fallback keysets by USER and emits every
 * favorite a batch's users hold, so a batch can return more (or fewer) rows
 * than $limit. Loop until fetched is 0, not until fetched < limit.
 */
final class ReactionImporter {

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
	 * @var ReactionWriter
	 */
	private ReactionWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ReactionWriter( $source );
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
	 * Import one keyset batch of reactions.
	 *
	 * @param int $after Exclusive lower-bound keyset id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,reactions:int}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows      = $this->adapter->reactions( $after, $limit );
		$reactions = 0;
		$last      = $after;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) $row['source_id'] );
			if ( $this->writer->import_reaction( $row ) ) {
				++$reactions;
			}
		}

		return array(
			'last'      => $last,
			'fetched'   => count( $rows ),
			'reactions' => $reactions,
		);
	}
}
