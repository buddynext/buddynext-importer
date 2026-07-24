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
	 * Every message that does NOT reach the target is accounted for by reason in
	 * the `skipped` map. Message loss used to be invisible here — each refusal
	 * returned a bare false — which is how a run could report success while
	 * leaving half the archive behind (Basecamp #10127726335).
	 *
	 * @param array<string,mixed>            $thread   Source thread record (thread_id, participants, subject, date_sent).
	 * @param array<int,array<string,mixed>> $messages Thread messages, oldest first.
	 * @return array{conversations:int,messages:int,skipped:array<string,int>}
	 */
	public function import_thread( array $thread, array $messages ): array {
		$service = self::service();
		if ( null === $service ) {
			return $this->outcome( 0, 0, array( 'no_target' => count( $messages ) ) );
		}

		$thread_id    = (int) $thread['thread_id'];
		$participants = array_values( array_unique( array_filter( array_map( 'intval', (array) $thread['participants'] ) ) ) );

		if ( $thread_id <= 0 || count( $participants ) < 2 ) {
			return $this->outcome( 0, 0, array( 'thread_needs_two_participants' => count( $messages ) ) );
		}

		$conv_id = IdMap::get( $this->source, 'dm_thread', $thread_id );
		$created = 0;
		$merged  = 0;

		if ( null === $conv_id ) {
			$conversation = $this->create_conversation( $service, $thread, $participants );
			$conv_id      = $conversation['id'];

			if ( $conv_id <= 0 ) {
				// MVS refused the conversation (its own hard block still applies
				// inside import mode), so the whole thread is lost, not one row.
				return $this->outcome( 0, 0, array( 'conversation_refused' => count( $messages ) ) );
			}

			IdMap::set( $this->source, 'dm_thread', $thread_id, $conv_id );

			// The source models 1:1 DMs as many subject-based threads per pair;
			// MVS models them as ONE conversation per pair. So a second source
			// thread between the same two members lands in the conversation the
			// first one opened. No message is lost — but reporting it as a new
			// conversation would overstate what was created.
			if ( $conversation['created'] ) {
				$created = 1;
			} else {
				$merged = 1;
			}
		}

		$written = 0;
		$skipped = array();

		foreach ( $messages as $message ) {
			$reason = $this->import_message( $service, (int) $conv_id, $message );

			if ( '' === $reason ) {
				++$written;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return $this->outcome( $created, $written, $skipped, $merged );
	}

	/**
	 * Shape a thread outcome.
	 *
	 * @param int               $conversations Conversations created.
	 * @param int               $messages      Messages written.
	 * @param array<string,int> $skipped       Skip reason -> count.
	 * @param int               $merged        Threads folded into an existing conversation.
	 * @return array{conversations:int,messages:int,skipped:array<string,int>,merged:int}
	 */
	private function outcome( int $conversations, int $messages, array $skipped, int $merged = 0 ): array {
		return array(
			'conversations' => $conversations,
			'messages'      => $messages,
			'skipped'       => array_filter( $skipped ),
			'merged'        => $merged,
		);
	}

	/**
	 * Create the target conversation for a thread.
	 *
	 * @param object              $service      MVS messaging service.
	 * @param array<string,mixed> $thread       Source thread record.
	 * @param array<int,int>      $participants Participant user ids.
	 * @return array{id:int,created:bool} Conversation id (0 on refusal) and whether it is new.
	 */
	private function create_conversation( object $service, array $thread, array $participants ): array {
		$opts = array( 'created_at' => (string) ( $thread['date_sent'] ?? '' ) );

		if ( 2 === count( $participants ) ) {
			$result = ImportMode::run(
				fn() => $service->find_or_create_conversation( $participants[0], $participants[1], $opts )
			);

			return array(
				'id'      => (int) ( $result['conversation_id'] ?? 0 ),
				'created' => (bool) ( $result['created'] ?? false ),
			);
		}

		$creator = (int) array_shift( $participants );
		$title   = trim( wp_strip_all_tags( (string) ( $thread['subject'] ?? '' ) ) );

		$id = (int) ImportMode::run(
			fn() => $service->create_group_conversation( $creator, $participants, $title, $opts )
		);

		return array(
			'id'      => $id,
			'created' => $id > 0,
		);
	}

	/**
	 * Import one message into its conversation.
	 *
	 * @param object              $service MVS messaging service.
	 * @param int                 $conv_id Target conversation id.
	 * @param array<string,mixed> $message Source message record.
	 * @return string Empty string when written, otherwise the skip reason.
	 */
	private function import_message( object $service, int $conv_id, array $message ): string {
		$source_id = (int) $message['source_id'];

		// Already imported by an earlier run. Counted separately from a loss:
		// nothing is missing, this row is simply already there.
		if ( IdMap::has( $this->source, 'dm_message', $source_id ) ) {
			return 'already_imported';
		}

		$sender  = (int) $message['sender_id'];
		$content = trim( (string) $message['content'] );

		if ( $sender <= 0 ) {
			return 'no_sender';
		}

		// A source message whose body is only markup MVS strips (an inline image,
		// an embed) sanitizes down to nothing and is refused as empty. It is a
		// real loss, so it is reported rather than passed off as written.
		if ( '' === $content ) {
			return 'empty_content';
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
			// MVS reports WHY it refused (not_participant, content_too_long,
			// duplicate_message, rate_limited, a moderation code...). Carrying
			// that code out is the difference between "104 messages vanished"
			// and a one-line diagnosis.
			$error = (string) ( $result['error'] ?? '' );

			return '' !== $error ? sanitize_key( $error ) : 'target_refused';
		}

		IdMap::set( $this->source, 'dm_message', $source_id, (int) $result['message_id'] );

		return '';
	}
}
