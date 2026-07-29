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
use BuddyNextImporter\Source\PrivacyMap;

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
	 * Reports whether the post was CREATED, not merely resolved, so a resumed run
	 * does not report rows as imported when the id-map simply already had them.
	 *
	 * @param array<string,mixed> $activity   Source activity record.
	 * @param array<int,int>      $media_atts WP attachment ids attached to the activity.
	 * @return array{id:int,created:bool} BuddyNext post id (0 on failure/skip).
	 */
	public function import_post( array $activity, array $media_atts = array() ): array {
		$source_id = (int) $activity['source_id'];

		$existing = IdMap::get( $this->source, 'post', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$user_id   = (int) $activity['user_id'];
		$content   = $this->clean_content( (string) $activity['content'] );
		$media_ids = $this->ingest_media( $media_atts, $user_id );

		// A post needs either content or media.
		if ( '' === $content && empty( $media_ids ) ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$space_id = 0;
		if ( 'groups' === (string) $activity['component'] ) {
			$mapped = IdMap::get( $this->source, 'space', (int) $activity['item_id'] );

			// The group did not become a space, so this post has nowhere to go.
			// It must NOT fall through to space_id 0: that is the global feed,
			// and BuddyPress core carries no privacy column, so the post would be
			// republished as a public, site-wide one. A private or hidden group's
			// content would change audience during the migration, and no row
			// count would show it - the totals still reconcile. Skipping loses a
			// post; publishing it discloses one.
			if ( null === $mapped ) {
				return array(
					'id'      => 0,
					'created' => false,
				);
			}

			$space_id = $mapped;
		}

		// A blog-post announcement is a link card, not a status update: it has a
		// URL, a title and usually an image, and BuddyNext has a `link` type for
		// exactly that. Bringing it across is also what lets its comment thread
		// come with it - those comments have no other parent to attach to.
		$is_blog_post = 'new_blog_post' === (string) ( $activity['source_type'] ?? 'activity_update' );

		$data = array(
			'type'       => $is_blog_post ? 'link' : ( empty( $media_ids ) ? 'text' : 'media' ),
			'content'    => $content,
			'space_id'   => $space_id,
			'privacy'    => PrivacyMap::post_privacy( (string) ( $activity['privacy'] ?? 'public' ) ),
			'created_at' => $this->utc( (string) $activity['date_recorded'] ),
		);

		if ( ! empty( $media_ids ) ) {
			$data['media_ids'] = $media_ids;
		}

		if ( $is_blog_post ) {
			$data['link_url'] = $this->blog_post_url( $activity );

			// link_meta is built here, NEVER left empty. PostService::create()
			// fetches Open Graph data over HTTP whenever link_url is set and
			// link_meta is not - and it does so on the write path, without
			// consulting the buddynext_enable_link_preview toggle. One outbound
			// request per blog post would make a large migration crawl and hand
			// it a new way to fail: a slow host, a dead URL, no outbound network.
			//
			// It is also unnecessary. These are the site's own posts, so the
			// title, excerpt and featured image are in the database already, and
			// they are better than anything a scrape would return.
			$data['link_meta'] = $this->link_meta_for( $activity );
		}

		$result = ImportMode::run(
			fn() => $this->posts()->create( $user_id, $data )
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'post', $source_id, $bn_id );

		return array(
			'id'      => $bn_id,
			'created' => true,
		);
	}

	/**
	 * Import one activity_comment as a BuddyNext comment on its mapped post.
	 * A reply to another comment (secondary_item_id points at a comment, not the
	 * root activity) is nested under that comment. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $comment Source comment record.
	 * @return array{id:int,created:bool} BuddyNext comment id (0 on failure/skip).
	 */
	public function import_comment( array $comment ): array {
		$source_id = (int) $comment['source_id'];

		$existing = IdMap::get( $this->source, 'comment', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$post_id = IdMap::get( $this->source, 'post', (int) $comment['root_id'] );
		if ( null === $post_id ) {
			// Root post was not imported (skipped/system) - drop the comment.
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$content = trim( (string) $comment['content'] );
		if ( '' === $content ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		// A reply targets another comment; a top-level comment targets the root.
		$secondary = (int) $comment['secondary_item_id'];
		$parent_id = null;
		if ( $secondary > 0 && $secondary !== (int) $comment['root_id'] ) {
			$mapped_parent = IdMap::get( $this->source, 'comment', $secondary );
			$parent_id     = null === $mapped_parent ? null : $mapped_parent;
		}

		$result = ImportMode::run(
			// Sixth argument is CommentService's backdate seam - the comment
			// keeps its source date_recorded instead of the migration run time.
			fn() => $this->comments()->create( (int) $comment['user_id'], 'post', $post_id, $content, $parent_id, $this->utc( (string) $comment['date_recorded'] ) )
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'comment', $source_id, $bn_id );

		return array(
			'id'      => $bn_id,
			'created' => true,
		);
	}

	/**
	 * The URL a blog-post card should point at.
	 *
	 * BuddyPress stores whatever the permalink was when the activity was
	 * recorded, which is often the unpretty `?p=123` form - it still resolves,
	 * but it is not what anyone wants to see on a card, and it will not survive
	 * being copied anywhere. When the post is still here its CURRENT permalink
	 * is both prettier and more correct, so prefer it and keep the recorded link
	 * as the fallback for a post that has since gone.
	 *
	 * @param array<string,mixed> $activity Source activity row.
	 */
	private function blog_post_url( array $activity ): string {
		$post_id = (int) ( $activity['secondary_item_id'] ?? 0 );

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return (string) ( $activity['primary_link'] ?? '' );
	}

	/**
	 * Build a link card for a blog-post announcement from LOCAL data.
	 *
	 * BuddyPress records the published post's id in `secondary_item_id`, so on
	 * the site being migrated the post itself is right there - no HTTP needed.
	 * When it cannot be resolved (deleted since, or an activity from another
	 * site in a network) the activity's own text stands in, and a minimal card
	 * is still returned: an EMPTY link_meta is what triggers the network fetch
	 * this is here to avoid.
	 *
	 * @param array<string,mixed> $activity Source activity row.
	 * @return array{title:string,description:string,thumbnail:string}
	 */
	private function link_meta_for( array $activity ): array {
		$meta = array(
			'title'       => '',
			'description' => '',
			'thumbnail'   => '',
		);

		$post_id = (int) ( $activity['secondary_item_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			$meta['title']       = (string) get_the_title( $post );
			$excerpt             = has_excerpt( $post ) ? (string) $post->post_excerpt : (string) $post->post_content;
			$meta['description'] = wp_trim_words( wp_strip_all_tags( $excerpt ), 40, '' );

			$thumbnail = get_the_post_thumbnail_url( $post, 'medium_large' );
			if ( is_string( $thumbnail ) && '' !== $thumbnail ) {
				$meta['thumbnail'] = $thumbnail;
			}

			return $meta;
		}

		// No local post: fall back to what the activity itself carries.
		$meta['title']       = trim( wp_strip_all_tags( (string) ( $activity['content'] ?? '' ) ) );
		$meta['description'] = '';

		if ( '' === $meta['title'] ) {
			$meta['title'] = (string) ( $activity['primary_link'] ?? '' );
		}

		$meta['title'] = wp_trim_words( $meta['title'], 20, '' );

		return $meta;
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
	 * Ingest source WP attachments into the BuddyNext media engine, returning the
	 * resulting media ids. Delegates to the shared MediaIngest so activity photos
	 * and standalone album photos share one implementation AND one id-map domain -
	 * an attachment reachable from both never uploads twice.
	 *
	 * @param array<int,int> $attachment_ids WP attachment ids.
	 * @param int            $user_id        Owner of the imported media.
	 * @return array<int,int> BuddyNext/WPMediaVerse media ids.
	 */
	private function ingest_media( array $attachment_ids, int $user_id ): array {
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		return ( new MediaIngest( $this->source ) )->ingest_many( $attachment_ids, $user_id );
	}
}
