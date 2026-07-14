<?php
/**
 * Orchestrates the private-messages domain import: source messages -> the
 * WPMediaVerse DM engine, keyset-paginated by message id. Conversations are
 * created lazily on a thread's first message. Shared by the CLI and REST
 * surfaces. No-op when the DM engine is not installed.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\MessageWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Private-messages import coordinator.
 */
final class MessageImporter {

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
	 * @var MessageWriter
	 */
	private MessageWriter $writer;

	/**
	 * Per-thread participant cache for the life of this importer (one CLI run or
	 * one REST batch). Avoids re-querying recipients for every message in a thread.
	 *
	 * @var array<int,array<int,int>>
	 */
	private array $participants = array();

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new MessageWriter( $source );
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
	 * Import one keyset batch of messages.
	 *
	 * @param int $after Exclusive lower-bound message id.
	 * @param int $limit Batch size.
	 * @return array{last:int,fetched:int,messages:int,skipped?:bool}
	 */
	public function import_batch( int $after, int $limit ): array {
		// The DM engine owns the message tables; without it there is nowhere to
		// write, so the whole domain is skipped rather than failing the run.
		if ( ! MessageWriter::available() ) {
			return array(
				'last'     => $after,
				'fetched'  => 0,
				'messages' => 0,
				'skipped'  => true,
			);
		}

		$rows     = $this->adapter->messages( $after, $limit );
		$messages = 0;
		$last     = $after;

		foreach ( $rows as $row ) {
			$last      = (int) $row['source_id'];
			$thread_id = (int) $row['thread_id'];

			if ( ! isset( $this->participants[ $thread_id ] ) ) {
				$this->participants[ $thread_id ] = $this->adapter->thread_participants( $thread_id );
			}

			if ( $this->writer->import_message( $row, $this->participants[ $thread_id ] ) ) {
				++$messages;
			}
		}

		return array(
			'last'     => $last,
			'fetched'  => count( $rows ),
			'messages' => $messages,
		);
	}
}
