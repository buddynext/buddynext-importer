<?php
/**
 * Writes bbPress forums/topics/replies into Jetonomy (BuddyNext's discussion
 * engine) THROUGH ITS JOURNEY API only. Gated on Jetonomy being active; a no-op
 * otherwise.
 *
 * Mapping: forum -> Jetonomy space (under an "Imported Forums" category),
 * topic -> discussion post, reply -> reply (threaded via _bbp_reply_to).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the forums domain (Jetonomy target).
 */
final class ForumWriter {

	private const CATEGORY_SLUG = 'imported-forums';

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
	 * Whether Jetonomy (the forum target engine) is available.
	 */
	public static function available(): bool {
		return class_exists( '\Jetonomy\CLI\Journeys\Content_Journey' )
			&& class_exists( '\Jetonomy\CLI\Journeys\Space_Journey' )
			&& class_exists( '\Jetonomy\CLI\Journeys\Taxonomy_Journey' );
	}

	/**
	 * Ensure the "Imported Forums" category exists, returning its id (0 on failure).
	 * Cached via the id-map so it is created once.
	 */
	public function ensure_category(): int {
		$existing = IdMap::get( $this->source, 'forum_category', 0 );
		if ( null !== $existing ) {
			return $existing;
		}

		$taxonomy = new \Jetonomy\CLI\Journeys\Taxonomy_Journey();

		$found = $taxonomy->get_category_by_slug( self::CATEGORY_SLUG );
		$id    = $found->is_success() ? $this->result_id( $found ) : 0;

		if ( $id <= 0 ) {
			$created = $taxonomy->create_category(
				array(
					'name' => __( 'Imported Forums', 'buddynext-importer' ),
					'slug' => self::CATEGORY_SLUG,
				)
			);
			$id      = $created->is_success() ? $this->result_id( $created ) : 0;
		}

		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_category', 0, $id );
		}

		return $id;
	}

	/**
	 * Import one bbPress forum as a Jetonomy space. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $forum       Source forum record.
	 * @param int                 $category_id Jetonomy category id.
	 * @return int Jetonomy space id (0 on failure).
	 */
	public function import_forum( array $forum, int $category_id ): int {
		$source_id = (int) $forum['source_id'];

		$existing = IdMap::get( $this->source, 'forum_space', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Space_Journey() )->create(
				array(
					'title'       => (string) $forum['title'],
					'slug'        => $this->slug( (string) $forum['slug'], (string) $forum['title'], 'forum-' . $source_id ),
					'category_id' => $category_id,
					'type'        => 'forum',
					'visibility'  => $this->visibility( (string) ( $forum['status'] ?? 'publish' ) ),
					'description' => wp_strip_all_tags( (string) $forum['content'] ),
				)
			)
		);

		$id = $result->is_success() ? $this->result_id( $result ) : 0;
		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_space', $source_id, $id );
		}

		return $id;
	}

	/**
	 * Import one bbPress topic as a Jetonomy discussion post under its forum-space.
	 *
	 * @param array<string,mixed> $topic Source topic record.
	 * @return int Jetonomy post id (0 on failure/skip).
	 */
	public function import_topic( array $topic ): int {
		$source_id = (int) $topic['source_id'];

		$existing = IdMap::get( $this->source, 'forum_post', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$space_id = IdMap::get( $this->source, 'forum_space', (int) $topic['parent_id'] );
		if ( null === $space_id ) {
			return 0; // Parent forum was not imported.
		}

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Content_Journey() )->create_post(
				array(
					'space_id'  => $space_id,
					'author_id' => $this->author( (int) $topic['author_id'] ),
					'title'     => (string) $topic['title'],
					'content'   => (string) $topic['content'],
				)
			)
		);

		$id = $result->is_success() ? $this->result_id( $result ) : 0;
		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_post', $source_id, $id );
		}

		return $id;
	}

	/**
	 * Import one bbPress reply as a Jetonomy reply on its topic-post, threaded
	 * under a parent reply when bbPress recorded one.
	 *
	 * @param array<string,mixed> $reply Source reply record.
	 * @return int Jetonomy reply id (0 on failure/skip).
	 */
	public function import_reply( array $reply ): int {
		$source_id = (int) $reply['source_id'];

		$existing = IdMap::get( $this->source, 'forum_reply', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$post_id = IdMap::get( $this->source, 'forum_post', (int) $reply['parent_id'] );
		if ( null === $post_id ) {
			return 0; // Parent topic was not imported.
		}

		$input = array(
			'post_id'   => $post_id,
			'author_id' => $this->author( (int) $reply['author_id'] ),
			'content'   => (string) $reply['content'],
		);

		$reply_to = (int) ( $reply['reply_to'] ?? 0 );
		if ( $reply_to > 0 ) {
			$parent = IdMap::get( $this->source, 'forum_reply', $reply_to );
			if ( null !== $parent ) {
				$input['parent_id'] = $parent;
			}
		}

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Content_Journey() )->create_reply( $input )
		);

		$id = $result->is_success() ? $this->result_id( $result ) : 0;
		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_reply', $source_id, $id );
		}

		return $id;
	}

	/**
	 * Pull the created id out of a Journey_Result's data payload.
	 *
	 * @param object $result Journey_Result.
	 */
	private function result_id( object $result ): int {
		if ( ! method_exists( $result, 'to_array' ) ) {
			return 0;
		}
		$data = $result->to_array();
		$data = is_array( $data ) ? ( $data['data'] ?? array() ) : array();
		if ( ! is_array( $data ) ) {
			return 0;
		}
		foreach ( array( 'id', 'space_id', 'post_id', 'reply_id', 'category_id', 'term_id' ) as $key ) {
			if ( isset( $data[ $key ] ) && (int) $data[ $key ] > 0 ) {
				return (int) $data[ $key ];
			}
		}
		return 0;
	}

	/**
	 * A non-empty slug, falling back to the title then a stable per-id slug.
	 *
	 * @param string $slug     Preferred slug.
	 * @param string $title    Title fallback.
	 * @param string $fallback Final fallback.
	 */
	private function slug( string $slug, string $title, string $fallback ): string {
		$candidate = sanitize_title( '' !== $slug ? $slug : $title );
		return '' !== $candidate ? $candidate : $fallback;
	}

	/**
	 * Resolve a usable author id, defaulting to user 1 for orphaned content.
	 *
	 * @param int $author_id Source author id.
	 */
	private function author( int $author_id ): int {
		return $author_id > 0 ? $author_id : 1;
	}

	/**
	 * Map a bbPress forum post_status to a Jetonomy space visibility
	 * (public|private|hidden).
	 *
	 * @param string $status bbPress forum post_status.
	 */
	private function visibility( string $status ): string {
		switch ( $status ) {
			case 'private':
				return 'private';
			case 'hidden':
				return 'hidden';
			default:
				// publish, public.
				return 'public';
		}
	}
}
