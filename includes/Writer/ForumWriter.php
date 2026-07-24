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
	 * Reports whether the discussion was CREATED, not merely resolved, so a
	 * resumed run does not claim to have re-imported the whole forum tree.
	 *
	 * @param array<string,mixed> $forum       Source forum record.
	 * @param int                 $category_id Jetonomy category id.
	 * @return array{id:int,created:bool} Jetonomy space id (0 on failure).
	 */
	public function import_forum( array $forum, int $category_id ): array {
		$source_id = (int) $forum['source_id'];

		$existing = IdMap::get( $this->source, 'forum_space', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		// A group's forum belongs INSIDE the space its group migrated into, not
		// beside it. Without this the topics land in a standalone "Imported
		// Forums" space and the migrated space's Discussions tab stays empty.
		$attached = $this->attach_to_space( $forum, $category_id );
		if ( $attached > 0 ) {
			IdMap::set( $this->source, 'forum_space', $source_id, $attached );

			return array(
				'id'      => $attached,
				'created' => true,
			);
		}

		$visibility = $this->visibility( (string) ( $forum['status'] ?? 'publish' ) );

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Space_Journey() )->create(
				array(
					'title'       => (string) $forum['title'],
					'slug'        => $this->slug( (string) $forum['slug'], (string) $forum['title'], 'forum-' . $source_id ),
					'category_id' => $category_id,
					'type'        => 'forum',
					'visibility'  => $visibility,
					'join_policy' => $this->join_policy( $visibility ),
					'description' => wp_strip_all_tags( (string) $forum['content'] ),
					// Jetonomy's Journey_Backdate seam - the forum keeps its
					// source creation date instead of the migration run time.
					'created_at'  => (string) ( $forum['created_gmt'] ?? '' ),
				)
			)
		);

		$id = $result->is_success() ? $this->result_id( $result ) : 0;
		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_space', $source_id, $id );
		}

		return array(
			'id'      => $id,
			'created' => $id > 0,
		);
	}

	/**
	 * Import one bbPress topic as a Jetonomy discussion post under its forum-space.
	 *
	 * @param array<string,mixed> $topic Source topic record.
	 * @return array{id:int,created:bool} Jetonomy post id (0 on failure/skip).
	 */
	public function import_topic( array $topic ): array {
		$source_id = (int) $topic['source_id'];

		$existing = IdMap::get( $this->source, 'forum_post', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$space_id = IdMap::get( $this->source, 'forum_space', (int) $topic['parent_id'] );
		if ( null === $space_id ) {
			// Parent forum was not imported.
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Content_Journey() )->create_post(
				array(
					'space_id'   => $space_id,
					'author_id'  => $this->author( (int) $topic['author_id'] ),
					'title'      => (string) $topic['title'],
					'content'    => (string) $topic['content'],
					// Journey_Backdate seam - also backdates last_reply_at.
					'created_at' => (string) ( $topic['created_gmt'] ?? '' ),
				)
			)
		);

		$id = $result->is_success() ? $this->result_id( $result ) : 0;
		if ( $id > 0 ) {
			IdMap::set( $this->source, 'forum_post', $source_id, $id );
		}

		return array(
			'id'      => $id,
			'created' => $id > 0,
		);
	}

	/**
	 * Import one bbPress reply as a Jetonomy reply on its topic-post, threaded
	 * under a parent reply when bbPress recorded one.
	 *
	 * @param array<string,mixed> $reply Source reply record.
	 * @return array{id:int,created:bool} Jetonomy reply id (0 on failure/skip).
	 */
	public function import_reply( array $reply ): array {
		$source_id = (int) $reply['source_id'];

		$existing = IdMap::get( $this->source, 'forum_reply', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$post_id = IdMap::get( $this->source, 'forum_post', (int) $reply['parent_id'] );
		if ( null === $post_id ) {
			// Parent topic was not imported.
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$input = array(
			'post_id'    => $post_id,
			'author_id'  => $this->author( (int) $reply['author_id'] ),
			'content'    => (string) $reply['content'],
			// Journey_Backdate seam - the reply's date also carries into the
			// parent topic's last_reply_at via Post::increment_reply_count().
			'created_at' => (string) ( $reply['created_gmt'] ?? '' ),
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

		return array(
			'id'      => $id,
			'created' => $id > 0,
		);
	}

	/**
	 * Resolve (or create) the Jetonomy discussion that belongs to the BuddyNext
	 * space this forum's group migrated into, and return its id.
	 *
	 * BuddyNext links a space to its discussion with the space meta
	 * `jetonomy_forum_id`, and only renders the Discussions tab when that link
	 * exists AND `discussion_enabled` is on (JetonomyBridge::space_discussion_enabled()).
	 * An imported group forum therefore has to become THAT discussion — creating
	 * a loose Jetonomy space writes the topics somewhere the group can never
	 * reach them.
	 *
	 * Returns 0 when the forum is a standalone site forum, when its group was
	 * not migrated, or when the bridge is unavailable; the caller then falls back
	 * to the standalone "Imported Forums" path so content is never dropped.
	 *
	 * @param array<string,mixed> $forum       Source forum record.
	 * @param int                 $category_id Jetonomy category id (required by the journey).
	 * @return int Jetonomy space id (0 when not group-attached).
	 */
	private function attach_to_space( array $forum, int $category_id ): int {
		$source_group_id = (int) ( $forum['group_id'] ?? 0 );
		if ( $source_group_id <= 0 || ! class_exists( '\BuddyNext\Bridges\JetonomyBridge' ) ) {
			return 0;
		}

		$bn_space_id = IdMap::get( $this->source, 'space', $source_group_id );
		if ( null === $bn_space_id || $bn_space_id <= 0 ) {
			return 0;
		}

		$bridge = new \BuddyNext\Bridges\JetonomyBridge();

		// The space -> discussion link is permanent and 1:1, so an already-linked
		// space (provisioned earlier, or a resumed run) keeps its discussion.
		$status   = $bridge->space_discussion_status( $bn_space_id );
		$forum_id = (int) ( $status['forum_id'] ?? 0 );

		if ( $forum_id <= 0 ) {
			$forum_id = $this->create_space_discussion( $forum, $bn_space_id, $category_id );
		}

		if ( $forum_id <= 0 ) {
			return 0;
		}

		update_space_meta( $bn_space_id, 'jetonomy_forum_id', $forum_id );

		// The link alone does not surface the tab — the owner-facing enabled flag
		// has to be on too, or members see a space with migrated discussions they
		// cannot open.
		$bridge->set_discussion_enabled( $bn_space_id, true );

		return $forum_id;
	}

	/**
	 * Create the Jetonomy discussion space that backs a migrated group, keeping
	 * the source forum's title, description, and creation date.
	 *
	 * @param array<string,mixed> $forum       Source forum record.
	 * @param int                 $bn_space_id BuddyNext space id the group migrated into.
	 * @param int                 $category_id Jetonomy category id.
	 * @return int Jetonomy space id (0 on failure).
	 */
	private function create_space_discussion( array $forum, int $bn_space_id, int $category_id ): int {
		$source_id  = (int) $forum['source_id'];
		$visibility = $this->visibility( (string) ( $forum['status'] ?? 'publish' ) );

		$result = ImportMode::run(
			fn() => ( new \Jetonomy\CLI\Journeys\Space_Journey() )->create(
				array(
					'title'       => (string) $forum['title'],
					'slug'        => $this->slug( (string) $forum['slug'], (string) $forum['title'], 'space-' . $bn_space_id . '-forum-' . $source_id ),
					'category_id' => $category_id,
					'type'        => 'forum',
					'visibility'  => $visibility,
					'join_policy' => $this->join_policy( $visibility ),
					'description' => wp_strip_all_tags( (string) $forum['content'] ),
					'created_at'  => (string) ( $forum['created_gmt'] ?? '' ),
				)
			)
		);

		return $result->is_success() ? $this->result_id( $result ) : 0;
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

	/**
	 * Pick a Jetonomy join policy compatible with the visibility. Jetonomy
	 * requires hidden spaces to be invite-only.
	 *
	 * @param string $visibility Jetonomy space visibility.
	 */
	private function join_policy( string $visibility ): string {
		switch ( $visibility ) {
			case 'hidden':
				return 'invite';
			case 'private':
				return 'approval';
			default:
				return 'open';
		}
	}
}
