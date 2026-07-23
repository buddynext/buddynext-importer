<?php
/**
 * Writes source private-message threads into WPMediaVerse's DM engine THROUGH
 * ITS SERVICE API only (the mvs messaging service). Never touches mvs_* tables.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

defined( 'ABSPATH' ) || exit;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

/**
 * Service-layer writer for the private-messages domain.
 *
 * Idempotency is IdMap-based on BOTH levels: 'dm_thread' (source thread ->
 * conversation) and 'dm_message' (source message -> mvs message), because
 * unlike follows/reactions the DM tables carry no natural unique key a re-run
 * could lean on - re-sending would duplicate every message.
 *
 * Two-participant threads become direct conversations; larger ones become
 * group conversations titled with the source subject. ImportMode lifts MVS's
 * social gates (rate limits, DM access level, the mvs_can_send_message veto)
 * for the run - the thread already existed at the source, so today's settings
 * must not silently drop history. MVS's own hard block (one participant has
 * blocked the other in MVS itself) still refuses the thread; that skip is
 * counted, not silent.
 */
final class MessageWriter {

	/**
	 * Source key.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Construct for a source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * The WPMediaVerse messaging service, or null when MVS is absent.
	 */
	public static function service(): ?object {
		if ( ! class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			return null;
		}

		$service = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );

		return is_object( $service ) ? $service : null;
	}

	/**
	 * Import one source thread and all its messages.
	 *
	 * @param array<string,mixed> $thread   Source thread record (thread_id, participants, subject, date_sent).
	 * @param array<int,array<string,mixed>> $messages Thread messages, oldest first.
	 * @return array{conversations:int,messages:int} Written counts (0/0 when skipped).
	 */
	public function import_thread( array $thread, array $messages ): array {
		$service = self::service();
		if ( null === $service ) {
			return array(
				'conversations' => 0,
				'messages'      => 0,
			);
		}

		$thread_id    = (int) $thread['thread_id'];
		$participants = array_values( array_unique( array_filter( array_map( 'intval', (array) $thread['participants'] ) ) ) );

		if ( $thread_id <= 0 || count( $participants ) < 2 ) {
			return array(
				'conversations' => 0,
				'messages'      => 0,
			);
		}

		$conv_id = IdMap::get( $this->source, 'dm_thread', $thread_id );
		$created = 0;

		if ( null === $conv_id ) {
			$conv_id = $this->create_conversation( $service, $thread, $participants );
			if ( $conv_id <= 0 ) {
				return array(
					'conversations' => 0,
					'messages'      => 0,
				);
			}
			IdMap::set( $this->source, 'dm_thread', $thread_id, $conv_id );
			$created = 1;
		}

		$written = 0;
		foreach ( $messages as $message ) {
			if ( $this->import_message( $service, (int) $conv_id, $message ) ) {
				++$written;
			}
		}

		return array(
			'conversations' => $created,
			'messages'      => $written,
		);
	}

	/**
	 * Create the target conversation for a thread.
	 *
	 * @param object              $service      MVS messaging service.
	 * @param array<string,mixed> $thread       Source thread record.
	 * @param array<int,int>      $participants Participant user ids.
	 * @return int Conversation id (0 on refusal).
	 */
	private function create_conversation( object $service, array $thread, array $participants ): int {
		$opts = array( 'created_at' => (string) ( $thread['date_sent'] ?? '' ) );

		if ( 2 === count( $participants ) ) {
			$result = ImportMode::run(
				fn() => $service->find_or_create_conversation( $participants[0], $participants[1], $opts )
			);

			return (int) ( $result['conversation_id'] ?? 0 );
		}

		$creator = (int) array_shift( $participants );
		$title   = trim( wp_strip_all_tags( (string) ( $thread['subject'] ?? '' ) ) );

		return (int) ImportMode::run(
			fn() => $service->create_group_conversation( $creator, $participants, $title, $opts )
		);
	}

	/**
	 * Import one message into its conversation.
	 *
	 * @param object              $service MVS messaging service.
	 * @param int                 $conv_id Target conversation id.
	 * @param array<string,mixed> $message Source message record.
	 * @return bool Whether the message was written.
	 */
	private function import_message( object $service, int $conv_id, array $message ): bool {
		$source_id = (int) $message['source_id'];

		if ( IdMap::has( $this->source, 'dm_message', $source_id ) ) {
			return false;
		}

		$sender  = (int) $message['sender_id'];
		$content = trim( (string) $message['content'] );
		if ( $sender <= 0 || '' === $content ) {
			return false;
		}

		// MVS's duplicate guard is a 5-second same-content transient per sender -
		// a source thread with identical consecutive messages (perfectly legal
		// history) would trip it, so clear it before each send. Public transient
		// API, no MVS internals touched.
		delete_transient( 'mvs_dm_dup_' . $sender );

		$result = ImportMode::run(
			fn() => $service->send_message(
				$conv_id,
				$sender,
				array(
					'content'    => $content,
					'created_at' => (string) ( $message['date_sent'] ?? '' ),
				)
			)
		);

		if ( empty( $result['success'] ) ) {
			return false;
		}

		IdMap::set( $this->source, 'dm_message', $source_id, (int) $result['message_id'] );

		return true;
	}
}
