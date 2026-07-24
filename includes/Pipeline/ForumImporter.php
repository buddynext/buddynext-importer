<?php
/**
 * Orchestrates the forums domain import: bbPress forums -> topics -> replies
 * into Jetonomy, in dependency order, keyset-paginated. Gated on Jetonomy.
 * Shared by the CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ForumWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Forums import coordinator.
 */
final class ForumImporter {

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
	 * @var ForumWriter
	 */
	private ForumWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ForumWriter( $source );
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
	 * Whether the forum target (Jetonomy) is available.
	 */
	public static function target_available(): bool {
		return ForumWriter::available();
	}

	/**
	 * Import one keyset batch of forums (ensuring the category first).
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,forums:int,existing:int,skipped:array<string,int>}
	 */
	public function import_forums_batch( int $after, int $limit ): array {
		$category = $this->writer->ensure_category();
		$rows     = $this->adapter->forums( $after, $limit );
		$done     = 0;
		$existing = 0;
		$skipped  = array();
		$last     = $after;

		foreach ( $rows as $row ) {
			$last = (int) $row['source_id'];

			if ( $category <= 0 ) {
				continue;
			}

			$result = $this->writer->import_forum( $row, $category );

			if ( $result['created'] ) {
				++$done;
			} elseif ( $result['id'] > 0 ) {
				++$existing;
			} elseif ( '' !== $result['reason'] ) {
				$skipped[ $result['reason'] ] = ( $skipped[ $result['reason'] ] ?? 0 ) + 1;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'forums'   => $done,
			'existing' => $existing,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Import one keyset batch of topics.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,topics:int,existing:int,skipped:array<string,int>}
	 */
	public function import_topics_batch( int $after, int $limit ): array {
		$rows     = $this->adapter->forum_topics( $after, $limit );
		$done     = 0;
		$existing = 0;
		$skipped  = array();
		$last     = $after;

		foreach ( $rows as $row ) {
			$last   = (int) $row['source_id'];
			$result = $this->writer->import_topic( $row );

			if ( $result['created'] ) {
				++$done;
			} elseif ( $result['id'] > 0 ) {
				++$existing;
			} elseif ( '' !== $result['reason'] ) {
				$skipped[ $result['reason'] ] = ( $skipped[ $result['reason'] ] ?? 0 ) + 1;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'topics'   => $done,
			'existing' => $existing,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Import one keyset batch of replies.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,replies:int,existing:int,skipped:array<string,int>}
	 */
	public function import_replies_batch( int $after, int $limit ): array {
		$rows     = $this->adapter->forum_replies( $after, $limit );
		$done     = 0;
		$existing = 0;
		$skipped  = array();
		$last     = $after;

		foreach ( $rows as $row ) {
			$last   = (int) $row['source_id'];
			$result = $this->writer->import_reply( $row );

			if ( $result['created'] ) {
				++$done;
			} elseif ( $result['id'] > 0 ) {
				++$existing;
			} elseif ( '' !== $result['reason'] ) {
				$skipped[ $result['reason'] ] = ( $skipped[ $result['reason'] ] ?? 0 ) + 1;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'replies'  => $done,
			'existing' => $existing,
			'skipped'  => $skipped,
		);
	}
}
