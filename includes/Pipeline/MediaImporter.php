<?php
/**
 * Orchestrates the standalone-media domain import: bp_media_albums -> target
 * albums and never-posted bp_media rows -> target media, keyset-paginated.
 * Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\MediaWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Standalone-media import coordinator.
 *
 * Albums are imported before media so each photo finds the album it belongs to
 * already mapped - the same schema-before-values order the profile domain uses.
 */
final class MediaImporter {

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
	 * @var MediaWriter
	 */
	private MediaWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new MediaWriter( $source );
	}

	/**
	 * Whether the media target (WPMediaVerse) is present.
	 */
	public static function target_available(): bool {
		return MediaWriter::available();
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
	 * Import one keyset batch of albums.
	 *
	 * `albums` counts albums actually CREATED this run; `existing` counts those a
	 * previous run already made. A resumed migration must not report the same
	 * album as imported twice.
	 *
	 * @param int $after Exclusive lower-bound album id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,albums:int,existing:int}
	 */
	public function import_albums_batch( int $after, int $limit ): array {
		$rows     = $this->adapter->media_albums( $after, $limit );
		$albums   = 0;
		$existing = 0;
		$last     = $after;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) $row['source_id'] );

			$result = $this->writer->import_album( $row );

			if ( $result['created'] ) {
				++$albums;
			} elseif ( $result['id'] > 0 ) {
				++$existing;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'albums'   => $albums,
			'existing' => $existing,
		);
	}

	/**
	 * Import one keyset batch of standalone media.
	 *
	 * @param int $after Exclusive lower-bound media id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,media:int,skipped:array<string,int>}
	 */
	public function import_media_batch( int $after, int $limit ): array {
		$rows    = $this->adapter->standalone_media( $after, $limit );
		$media   = 0;
		$skipped = array();
		$last    = $after;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) $row['source_id'] );

			$reason = $this->writer->import_media( $row );

			if ( '' === $reason ) {
				++$media;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'media'   => $media,
			'skipped' => $skipped,
		);
	}
}
