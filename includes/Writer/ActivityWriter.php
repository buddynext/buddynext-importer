<?php
/**
 * Writes activity posts and comments into BuddyNext THROUGH ITS SERVICE API only
 * (buddynext_service( 'post_service' ) + 'comments' ). Never touches bn_* tables
 * directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the activity domain.
 */
final class ActivityWriter {

	/**
	 * Source key, used for id-map scoping.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Construct the writer for a given source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Resolve the BuddyNext PostService.
	 *
	 * @return object PostService.
	 */
	private function posts(): object {
		return buddynext_service( 'post_service' );
	}

	/**
	 * Resolve the BuddyNext CommentService.
	 *
	 * @return object CommentService.
	 */
	private function comments(): object {
		return buddynext_service( 'comments' );
	}

	/**
	 * Import one activity_update as a BuddyNext post. Idempotent via the id-map.
	 * Group activity (component=groups, item_id=group id) is posted into the
	 * mapped space; everything else is a sitewide post. The original timestamp is
	 * preserved through PostService's backdate-aware created_at.
	 *
	 * @param array<string,mixed> $activity   Source activity record.
	 * @param array<int,int>      $media_atts WP attachment ids attached to the activity.
	 * @return int BuddyNext post id (0 on failure/skip).
	 */
	public function import_post( array $activity, array $media_atts = array() ): int {
		$source_id = (int) $activity['source_id'];

		$existing = IdMap::get( $this->source, 'post', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$user_id   = (int) $activity['user_id'];
		$content   = $this->clean_content( (string) $activity['content'] );
		$media_ids = $this->ingest_media( $media_atts, $user_id );

		// A post needs either content or media.
		if ( '' === $content && empty( $media_ids ) ) {
			return 0;
		}

		$space_id = 0;
		if ( 'groups' === (string) $activity['component'] ) {
			$mapped   = IdMap::get( $this->source, 'space', (int) $activity['item_id'] );
			$space_id = null === $mapped ? 0 : $mapped;
		}

		$data = array(
			'type'       => empty( $media_ids ) ? 'text' : 'media',
			'content'    => $content,
			'space_id'   => $space_id,
			'created_at' => $this->utc( (string) $activity['date_recorded'] ),
		);

		if ( ! empty( $media_ids ) ) {
			$data['media_ids'] = $media_ids;
		}

		$result = ImportMode::run(
			fn() => $this->posts()->create( $user_id, $data )
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'post', $source_id, $bn_id );

		return $bn_id;
	}

	/**
	 * Import one activity_comment as a BuddyNext comment on its mapped post.
	 * A reply to another comment (secondary_item_id points at a comment, not the
	 * root activity) is nested under that comment. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $comment Source comment record.
	 * @return int BuddyNext comment id (0 on failure/skip).
	 */
	public function import_comment( array $comment ): int {
		$source_id = (int) $comment['source_id'];

		$existing = IdMap::get( $this->source, 'comment', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$post_id = IdMap::get( $this->source, 'post', (int) $comment['root_id'] );
		if ( null === $post_id ) {
			return 0; // Root post was not imported (skipped/system) - drop the comment.
		}

		$content = trim( (string) $comment['content'] );
		if ( '' === $content ) {
			return 0;
		}

		// A reply targets another comment; a top-level comment targets the root.
		$secondary = (int) $comment['secondary_item_id'];
		$parent_id = null;
		if ( $secondary > 0 && $secondary !== (int) $comment['root_id'] ) {
			$mapped_parent = IdMap::get( $this->source, 'comment', $secondary );
			$parent_id     = null === $mapped_parent ? null : $mapped_parent;
		}

		$result = ImportMode::run(
			fn() => $this->comments()->create( (int) $comment['user_id'], 'post', $post_id, $content, $parent_id )
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'comment', $source_id, $bn_id );

		return $bn_id;
	}

	/**
	 * Convert BuddyPress activity HTML into the plain text BuddyNext expects.
	 * BuddyNext renders post content as escaped text (not raw HTML), so block
	 * tags become line breaks and the rest is stripped + entity-decoded.
	 *
	 * @param string $html Source activity content (HTML).
	 */
	private function clean_content( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		$text = preg_replace( '#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n", $html );
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Normalize a source MySQL datetime to the UTC "Y-m-d H:i:s" PostService wants.
	 *
	 * @param string $value Source date_recorded (already UTC in BuddyPress).
	 */
	private function utc( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Ingest source WP attachments into the BuddyNext media engine (WPMediaVerse)
	 * via the MediaClient seam, returning the resulting media ids. A copy of each
	 * attachment file is handed to the upload service so the original attachment is
	 * left intact. No-op when the media engine is absent.
	 *
	 * @param array<int,int> $attachment_ids WP attachment ids.
	 * @param int            $user_id        Owner of the imported media.
	 * @return array<int,int> BuddyNext/WPMediaVerse media ids.
	 */
	private function ingest_media( array $attachment_ids, int $user_id ): array {
		if ( empty( $attachment_ids ) || ! \BuddyNext\Media\MediaClient::available() ) {
			return array();
		}

		$upload = \BuddyNext\Media\MediaClient::upload();
		if ( ! is_object( $upload ) || ! method_exists( $upload, 'handle' ) ) {
			return array();
		}

		$media_ids = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$path = get_attached_file( (int) $attachment_id );
			if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
				continue;
			}

			// Hand the upload service a copy so the original attachment file survives.
			$copy = wp_tempnam( wp_basename( $path ) );
			if ( ! $copy || ! copy( $path, $copy ) ) {
				continue;
			}

			$file = array(
				'name'     => wp_basename( $path ),
				'type'     => (string) get_post_mime_type( (int) $attachment_id ),
				'tmp_name' => $copy,
				'error'    => 0,
				'size'     => (int) filesize( $copy ),
			);

			$result   = ImportMode::run( fn() => $upload->handle( $file, $user_id ) );
			$media_id = $this->extract_media_id( $result );

			if ( $media_id > 0 ) {
				$media_ids[] = $media_id;
			}

			if ( file_exists( $copy ) ) {
				wp_delete_file( $copy );
			}
		}

		return $media_ids;
	}

	/**
	 * Pull a media id out of the upload service result (int, or array/object with
	 * an id|media_id key).
	 *
	 * @param mixed $result Upload service return value.
	 */
	private function extract_media_id( $result ): int {
		if ( is_numeric( $result ) ) {
			return (int) $result;
		}
		if ( is_array( $result ) ) {
			return (int) ( $result['id'] ?? $result['media_id'] ?? 0 );
		}
		if ( is_object( $result ) ) {
			return (int) ( $result->id ?? $result->media_id ?? 0 );
		}
		return 0;
	}
}
