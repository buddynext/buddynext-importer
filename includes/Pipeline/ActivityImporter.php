<?php
/**
 * Orchestrates the activity domain import: posts first, then comments (so a
 * comment's root post is already mapped). Both keyset-paginated. Shared by the
 * CLI and REST surfaces.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ActivityWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Activity import coordinator.
 */
final class ActivityImporter {

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
	 * @var ActivityWriter
	 */
	private ActivityWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ActivityWriter( $source );
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
	 * Import one keyset batch of posts.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,posts:int}
	 */
	public function import_posts_batch( int $after, int $limit ): array {
		$rows  = $this->adapter->activities( $after, $limit );
		$posts = 0;
		$last  = $after;

		foreach ( $rows as $row ) {
			$last  = (int) $row['source_id'];
			$media = $this->adapter->activity_media( $last );
			if ( $this->writer->import_post( $row, $media ) > 0 ) {
				++$posts;
			}
		}

		return array(
			'last'    => $last,
			'fetched' => count( $rows ),
			'posts'   => $posts,
		);
	}

	/**
	 * Import one keyset batch of comments.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,comments:int}
	 */
	public function import_comments_batch( int $after, int $limit ): array {
		$rows     = $this->adapter->activity_comments( $after, $limit );
		$comments = 0;
		$last     = $after;

		foreach ( $rows as $row ) {
			$last = (int) $row['source_id'];
			if ( $this->writer->import_comment( $row ) > 0 ) {
				++$comments;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'comments' => $comments,
		);
	}
}
