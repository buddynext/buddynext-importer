<?php
/**
 * Orchestrates the bookmarks domain import: source activity bookmarks ->
 * bn_bookmarks, keyset-paginated. Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\BookmarkWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Bookmarks import coordinator.
 */
final class BookmarkImporter {

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
	 * @var BookmarkWriter
	 */
	private BookmarkWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new BookmarkWriter( $source );
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
	 * Whether the target can accept bookmarks.
	 *
	 * BookmarkService is Free, so this is true on any BuddyNext install - but
	 * asked rather than assumed, because a step that writes into a service which
	 * is not there fails one row at a time instead of declaring itself
	 * unavailable up front.
	 */
	public static function target_available(): bool {
		return function_exists( 'buddynext_service' )
			&& class_exists( '\\BuddyNext\\Feed\\BookmarkService' );
	}

	/**
	 * Import one keyset batch of bookmarks.
	 *
	 * @param int $after Exclusive lower-bound source id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,bookmarks:int,skipped:array<string,int>}
	 */
	public function import_batch( int $after, int $limit ): array {
		$rows      = $this->adapter->bookmarks( $after, $limit );
		$bookmarks = 0;
		$skipped   = array();
		$last      = $after;

		foreach ( $rows as $row ) {
			$last   = max( $last, (int) $row['source_id'] );
			$reason = $this->writer->import_bookmark( $row );

			if ( '' === $reason ) {
				++$bookmarks;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'last'      => $last,
			'fetched'   => count( $rows ),
			'bookmarks' => $bookmarks,
			'skipped'   => $skipped,
		);
	}
}
