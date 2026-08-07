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
	 * Attachments beyond the first that an mvs_messages row cannot hold.
	 *
	 * Reset per thread and folded into that thread's skip map.
	 *
	 * @var int
	 */
	private int $extra_media_dropped = 0;

	/**
	 * Construct for a source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Lazily-built media ingester, shared across a run.
	 *
	 * MediaIngest is idempotent by source attachment id through the id-map, so a
	 * photo reachable from both an activity and a DM resolves to one target
	 * media record rather than two.
	 *
	 * @var MediaIngest|null
	 */
	private ?MediaIngest $media = null;

	/**
	 * The media ingester for this source.
	 */
	private function media(): MediaIngest {
		if ( null === $this->media ) {
			$this->media = new MediaIngest( $this->source );
		}

		return $this->media;
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

		$written                   = 0;
		$skipped                   = array();
		$this->extra_media_dropped = 0;

		// MediaIngest reads each attachment's file path per message. Warm the
		// whole thread's attachments in one query first, so a thread of photo DMs
		// costs one lookup instead of one per message - the same reason activity
		// media is resolved a page at a time rather than per row.
		$attachment_ids = array();
		foreach ( $messages as $message ) {
			foreach ( (array) ( $message['media'] ?? array() ) as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				if ( $attachment_id > 0 ) {
					$attachment_ids[ $attachment_id ] = $attachment_id;
				}
			}
		}
		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( array_values( $attachment_ids ), false, true );
		}

		foreach ( $messages as $message ) {
			$reason = $this->import_message( $service, (int) $conv_id, $message );

			if ( '' === $reason ) {
				++$written;
				continue;
			}

			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		// An mvs_messages row holds one media reference, so a source DM carrying
		// several photos keeps the first and loses the rest. Counted, never
		// silent: this is a real shortfall and belongs in the report, not in a
		// code comment nobody reads after the migration is done.
		if ( $this->extra_media_dropped > 0 ) {
			$skipped['message_extra_media_dropped'] = $this->extra_media_dropped;
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

		// Attachments the source hung off this message (BuddyBoss DM photos and
		// videos). Resolved BEFORE the empty-content check, because a photo-only
		// DM is legitimate history with no text at all - refusing it as empty
		// would drop the whole message, not just its picture.
		//
		// These go through MediaIngest and ride the message as `media_id` with
		// message_type 'media_share', NOT as a bare attachment_id. That is what
		// BuddyNext actually renders: MessagesData::… builds the bubble's media
		// from the `media_share` payload, and only that branch sets an `id` -
		// the attachment_id branch omits it, so parts/dm-message.php (which
		// gates its image tile on `$bn_m_id > 0`) can never draw an attachment
		// inline and always falls through to the paperclip file link. BN's own
		// comment calls attachment_id a legacy fallback.
		//
		// Verified in the browser both ways round: attachment_id rendered as
		// "bi-comment-image" with a paperclip; media_share renders the picture.
		$media_id    = 0;
		$attachments = array_values( array_filter( array_map( 'intval', (array) ( $message['media'] ?? array() ) ) ) );
		foreach ( $attachments as $candidate ) {
			// privacy 'dm' is a conversation SCOPE, not a preference: MVS excludes
			// it from every library, explore, moderation and webhook query. Without
			// it a migrated conversation photo is published as public media - the
			// migration would leak private history into Explore Media.
			$media_id = $this->media()->ingest( $candidate, $sender, array( 'privacy' => 'dm' ) );
			if ( $media_id > 0 ) {
				break;
			}
		}

		if ( $media_id > 0 ) {
			// An mvs_messages row holds ONE media reference, so a multi-photo DM
			// keeps the first and loses the rest. Counted, never silent.
			$this->extra_media_dropped += max( 0, count( $attachments ) - 1 );
		}

		// A source message whose body is only markup MVS strips (an inline image,
		// an embed) sanitizes down to nothing and is refused as empty. It is a
		// real loss, so it is reported rather than passed off as written. A
		// message carrying media is NOT empty in that sense - MVS itself accepts
		// an attachment with no text (send_message() allows empty content when
		// attachment_id or media_id is set).
		if ( '' === $content && $media_id <= 0 ) {
			return 'empty_content';
		}

		// MVS's duplicate guard is a 5-second same-content transient per sender -
		// a source thread with identical consecutive messages (perfectly legal
		// history) would trip it, so clear it before each send. Public transient
		// API, no MVS internals touched.
		delete_transient( 'mvs_dm_dup_' . $sender );

		$payload = array(
			'content'    => $content,
			'created_at' => (string) ( $message['date_sent'] ?? '' ),
		);

		if ( $media_id > 0 ) {
			$payload['media_id']     = $media_id;
			$payload['message_type'] = 'media_share';
		}

		$result = ImportMode::run(
			fn() => $service->send_message( $conv_id, $sender, $payload )
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
