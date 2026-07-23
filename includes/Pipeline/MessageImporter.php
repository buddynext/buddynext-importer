<?php
/**
 * Orchestrates the private-messages domain import: bp_messages threads ->
 * WPMediaVerse conversations, keyset-paginated. Shared by CLI and REST.
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
	 * Whether the DM target (WPMediaVerse's messaging service) is present.
	 */
	public static function target_available(): bool {
		return null !== MessageWriter::service();
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
	 * Import one keyset batch of threads (each with all its messages).
	 *
	 * @param int $after Exclusive lower-bound thread id.
	 * @param int $limit Batch size (threads).
	 * @return array{last:int,fetched:int,conversations:int,messages:int}
	 */
	public function import_batch( int $after, int $limit ): array {
		$threads       = $this->adapter->message_threads( $after, $limit );
		$conversations = 0;
		$messages      = 0;
		$last          = $after;

		foreach ( $threads as $thread ) {
			$last = max( $last, (int) $thread['thread_id'] );

			$result         = $this->writer->import_thread( $thread, $this->adapter->thread_messages( (int) $thread['thread_id'] ) );
			$conversations += $result['conversations'];
			$messages      += $result['messages'];
		}

		return array(
			'last'          => $last,
			'fetched'       => count( $threads ),
			'conversations' => $conversations,
			'messages'      => $messages,
		);
	}
}
