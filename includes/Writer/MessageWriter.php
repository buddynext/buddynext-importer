<?php
/**
 * Writes private messages into the WPMediaVerse DM engine THROUGH ITS SERVICE
 * (MessagingService). BuddyNext is only the DM UI - the data lives in mvs_*
 * tables - so this writer targets MediaVerse, not BuddyNext.
 *
 * A source thread becomes one conversation (1:1 or group), created lazily on its
 * first message and recorded in the id-map so re-runs never duplicate. Because
 * the service stamps every send with the current time, the writer copies the
 * source send time onto the row afterwards (timestamp-only) - the ONE place it
 * touches an mvs_* table directly, since the service exposes no time setter.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the private-messages domain (WPMediaVerse DM).
 */
final class MessageWriter {

	/**
	 * Source key, used for id-map scoping.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * MediaVerse messaging service (lazily built), or null when unavailable.
	 *
	 * @var object|null
	 */
	private ?object $service = null;

	/**
	 * Construct the writer for a given source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Whether the WPMediaVerse DM engine is present. The messages domain is
	 * skipped entirely when it is not - there is nowhere to write.
	 */
	public static function available(): bool {
		return class_exists( '\WPMediaVerse\Messaging\MessagingService' );
	}

	/**
	 * Resolve the MediaVerse MessagingService.
	 *
	 * @return object MessagingService.
	 */
	private function service(): object {
		if ( null === $this->service ) {
			$this->service = new \WPMediaVerse\Messaging\MessagingService();
		}
		return $this->service;
	}

	/**
	 * Import one message. Ensures the thread's conversation exists first, then
	 * sends the message and copies the source send time onto the row. Idempotent
	 * via the id-map.
	 *
	 * @param array<string,mixed> $message      Source message: source_id, thread_id, sender_id, content, sent_at.
	 * @param array<int,int>      $participants Distinct participant user ids for the thread.
	 * @return bool Whether a message was written.
	 */
	public function import_message( array $message, array $participants ): bool {
		$source_id = (int) $message['source_id'];
		if ( IdMap::has( $this->source, 'message', $source_id ) ) {
			return false;
		}

		$sender  = (int) $message['sender_id'];
		$content = (string) $message['content'];
		$sent_at = (string) $message['sent_at'];
		if ( $sender <= 0 || '' === trim( $content ) ) {
			return false;
		}

		$conversation_id = $this->ensure_conversation( (int) $message['thread_id'], $sender, $participants, $sent_at );
		if ( $conversation_id <= 0 ) {
			return false;
		}

		$message_id = 0;
		ImportMode::run(
			function () use ( $conversation_id, $sender, $content, &$message_id ): void {
				// Clear MediaVerse's 5-second same-content duplicate guard so two
				// genuinely repeated history messages ("ok", "thanks") both land.
				delete_transient( 'mvs_dm_dup_' . $sender );

				$result     = $this->service()->send_message( $conversation_id, $sender, array( 'content' => $content ) );
				$message_id = (int) ( $result['message_id'] ?? 0 );
			}
		);

		if ( $message_id <= 0 ) {
			return false;
		}

		$this->backdate_message( $message_id, $conversation_id, $sent_at );
		IdMap::set( $this->source, 'message', $source_id, $message_id );

		return true;
	}

	/**
	 * Return the conversation id for a thread, creating it on first use.
	 *
	 * @param int            $thread_id    Source thread id.
	 * @param int            $sender       Sender of the message triggering creation.
	 * @param array<int,int> $participants Distinct participant user ids.
	 * @param string         $created_at   Source send time of the first message.
	 * @return int Conversation id, or 0 when the thread cannot form a conversation.
	 */
	private function ensure_conversation( int $thread_id, int $sender, array $participants, string $created_at ): int {
		$existing = IdMap::get( $this->source, 'message_thread', $thread_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$participants = array_values( array_unique( array_filter( array_map( 'intval', $participants ) ) ) );
		if ( count( $participants ) < 2 ) {
			return 0; // A conversation needs at least two people.
		}

		$conversation_id = 0;
		ImportMode::run(
			function () use ( $participants, $sender, &$conversation_id ): void {
				if ( 2 === count( $participants ) ) {
					$result          = $this->service()->find_or_create_conversation( $participants[0], $participants[1] );
					$conversation_id = (int) ( $result['conversation_id'] ?? 0 );
				} else {
					$creator         = in_array( $sender, $participants, true ) ? $sender : $participants[0];
					$conversation_id = (int) $this->service()->create_group_conversation( $creator, $participants );
				}
			}
		);

		if ( $conversation_id <= 0 ) {
			return 0;
		}

		$this->backdate_conversation( $conversation_id, $created_at );
		IdMap::set( $this->source, 'message_thread', $thread_id, $conversation_id );

		return $conversation_id;
	}

	/**
	 * Copy the source send time onto the inserted message row and advance the
	 * conversation's activity time. Timestamp-only writes: the service stamps
	 * NOW and exposes no setter, so this is the single direct mvs_* touch.
	 *
	 * @param int    $message_id      Inserted message id.
	 * @param int    $conversation_id Conversation id.
	 * @param string $sent_at         Source send time (Y-m-d H:i:s).
	 */
	private function backdate_message( int $message_id, int $conversation_id, string $sent_at ): void {
		if ( '' === $sent_at ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'mvs_messages', array( 'created_at' => $sent_at ), array( 'id' => $message_id ) );
		// Messages are processed in ascending id (send-time) order per thread, so
		// the last write leaves the conversation at its final message's time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'mvs_conversations', array( 'last_activity_at' => $sent_at ), array( 'id' => $conversation_id ) );
	}

	/**
	 * Set a newly created conversation's created + activity time to the source
	 * time of its first message.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $created_at      Source send time of the first message.
	 */
	private function backdate_conversation( int $conversation_id, string $created_at ): void {
		if ( '' === $created_at ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mvs_conversations',
			array(
				'created_at'       => $created_at,
				'last_activity_at' => $created_at,
			),
			array( 'id' => $conversation_id )
		);
	}
}
