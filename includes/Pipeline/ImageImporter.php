<?php
/**
 * Orchestrates the avatars/covers domain import: on-disk member and group
 * images -> BuddyNext's image pipeline, keyset-paginated. Shared by the CLI and
 * REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ImageWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Avatars/covers import coordinator.
 *
 * Group images require their space to be migrated first (migrate-spaces), since
 * the source group id only reaches a BuddyNext space through the id-map.
 */
final class ImageImporter {

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
	 * @var ImageWriter
	 */
	private ImageWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ImageWriter( $source );
	}

	/**
	 * Whether BuddyNext's image pipeline is present.
	 */
	public static function target_available(): bool {
		return ImageWriter::available();
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
	 * Import one keyset batch of member avatars/covers.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,members:int,skipped:array<string,int>}
	 */
	public function import_members_batch( int $after, int $limit ): array {
		$rows    = $this->adapter->member_images( $after, $limit );
		$members = 0;
		$skipped = array();
		$last    = $after;

		foreach ( $rows as $row ) {
			$last = max( $last, (int) $row['source_id'] );

			$result = $this->writer->import_member_images( $row );

			if ( empty( $result ) ) {
				++$members;
			}

			foreach ( $result as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'members' => $members,
			'skipped' => $skipped,
		);
	}

	/**
	 * Import one keyset batch of group avatars/covers.
	 *
	 * @param int $after Exclusive lower-bound group id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,spaces:int,skipped:array<string,int>}
	 */
	public function import_groups_batch( int $after, int $limit ): array {
		$rows    = $this->adapter->group_images( $after, $limit );
		$spaces  = 0;
		$skipped = array();
		$last    = $after;

		foreach ( $rows as $row ) {
			$source_id = (int) $row['source_id'];
			$last      = max( $last, $source_id );

			$space_id = IdMap::get( $this->source, 'space', $source_id );

			if ( null === $space_id ) {
				// The group never migrated (run migrate-spaces first), so there
				// is nothing to attach the image to. Counted, never silent.
				$skipped['space_not_imported'] = ( $skipped['space_not_imported'] ?? 0 ) + 1;
				continue;
			}

			$result = $this->writer->import_space_images( (int) $space_id, $this->owner_of( (int) $space_id ), $row );

			if ( empty( $result ) ) {
				++$spaces;
			}

			foreach ( $result as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'spaces'  => $spaces,
			'skipped' => $skipped,
		);
	}

	/**
	 * The space's owner, who holds manage-space rights for the update call.
	 *
	 * @param int $space_id BuddyNext space id.
	 */
	private function owner_of( int $space_id ): int {
		$space = buddynext_service( 'spaces' )->get( $space_id );

		return is_array( $space ) ? (int) ( $space['owner_id'] ?? 0 ) : 0;
	}
}
