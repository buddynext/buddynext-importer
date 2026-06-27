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
	 * @param array<string,mixed> $activity Source activity record.
	 * @return int BuddyNext post id (0 on failure/skip).
	 */
	public function import_post( array $activity ): int {
		$source_id = (int) $activity['source_id'];

		$existing = IdMap::get( $this->source, 'post', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$content = trim( (string) $activity['content'] );
		if ( '' === $content ) {
			return 0;
		}

		$space_id = 0;
		if ( 'groups' === (string) $activity['component'] ) {
			$mapped   = IdMap::get( $this->source, 'space', (int) $activity['item_id'] );
			$space_id = null === $mapped ? 0 : $mapped;
		}

		$data = array(
			'type'       => 'text',
			'content'    => $content,
			'space_id'   => $space_id,
			'created_at' => $this->utc( (string) $activity['date_recorded'] ),
		);

		$result = ImportMode::run(
			fn() => $this->posts()->create( (int) $activity['user_id'], $data )
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
	 * Normalize a source MySQL datetime to the UTC "Y-m-d H:i:s" PostService wants.
	 *
	 * @param string $value Source date_recorded (already UTC in BuddyPress).
	 */
	private function utc( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
