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

		// The source hid this from its own sitewide feed - a hidden group's post,
		// or a blog post that is not publicly readable. BuddyPress core carries no
		// privacy column, so without this the importer would default it to public
		// and the migration itself would publish what the source kept back.
		//
		// Landing IN a space is not that: a BuddyNext space carries its own
		// privacy, and a hidden group maps to a `secret` space, which is exactly
		// the "visible only inside its own context" the source meant. So withheld
		// content keeps its place when it has one, and is only dropped when it
		// would otherwise land in the global feed with nothing to protect it.
		if ( ! empty( $activity['hide_sitewide'] ) && $space_id <= 0 ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		// A blog-post announcement is an article, not a status update: it has a
		// URL, a headline and usually a featured image, and BuddyNext has a
		// dedicated `article` type that renders exactly that - a cover card in
		// the feed and its own Explore tile. Bringing it across is also what
		// lets its comment thread come with it: those comments have no other
		// parent to attach to.
		$is_blog_post = 'new_blog_post' === (string) ( $activity['source_type'] ?? 'activity_update' );

		$data = array(
			'type'       => $is_blog_post ? self::blog_post_type() : ( empty( $media_ids ) ? 'text' : 'media' ),
			'content'    => $content,
			'space_id'   => $space_id,
			'privacy'    => PrivacyMap::post_privacy( (string) ( $activity['privacy'] ?? 'public' ) ),
			'created_at' => $this->utc( (string) $activity['date_recorded'] ),
		);

		if ( ! empty( $media_ids ) ) {
			$data['media_ids'] = $media_ids;
		}

		if ( $is_blog_post ) {
			// hide_sitewide reflects the post as it was when BuddyPress recorded
			// the activity. A post made private, password protected or unpublished
			// SINCE then still carries a public flag, so the live post decides.
			// The card would otherwise expose its title, excerpt and image.
			if ( ! $this->blog_post_is_public( $activity ) ) {
				return array(
					'id'      => 0,
					'created' => false,
				);
			}

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

		// clean_content(), the same as import_post() - not a bare trim().
		//
		// BuddyNext renders stored content as escaped text, so raw source HTML does
		// not render as markup, it renders AS ITSELF: an imported comment showed the
		// member `<span class="atwho-inserted"><a class="bp-suggestions-mention" ...>`
		// as literal visible text. That applied to every HTML comment in the source,
		// not only ones containing a mention - links, bold and emoji-image spans all
		// arrived as tag soup. Posts were never affected because they always went
		// through clean_content(); comments were the one writer that did not.
		$content = $this->clean_content( (string) $comment['content'] );
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
	 * Whether a blog post is still safe to surface in a feed.
	 *
	 * A card carries the post's title, excerpt and featured image, so it must
	 * only be built for a post anyone could read. A post that has since been
	 * made private, given a password, or taken out of `publish` is none of those
	 * - and the activity row recorded when it WAS public cannot know that.
	 *
	 * A post that no longer exists is allowed through: the card then falls back
	 * to the activity's own text and its recorded link, which is what the source
	 * showed publicly at the time and reveals nothing new.
	 *
	 * @param array<string,mixed> $activity Source activity row.
	 */
	private function blog_post_is_public( array $activity ): bool {
		$post_id = (int) ( $activity['secondary_item_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		if ( 'publish' !== (string) $post->post_status ) {
			return false;
		}

		return '' === (string) $post->post_password;
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
	/**
	 * The card type BuddyNext uses for a published post.
	 *
	 * Taken FROM BuddyNext rather than hardcoded, and it falls back: BuddyNext
	 * gained a dedicated `article` type (with its own feed card and Explore
	 * tile) alongside Feed\BlogPostListener. Against a BuddyNext that predates
	 * it, `article` is not in PostService::ALLOWED_TYPES, create() refuses the
	 * row, and the activity would be dropped in silence - content loss, which
	 * is the one failure this importer must never introduce. `link` is what
	 * every already-migrated site carries and still renders correctly.
	 *
	 * @return string
	 */
	private static function blog_post_type(): string {
		return class_exists( \BuddyNext\Feed\BlogPostListener::class )
			? \BuddyNext\Feed\BlogPostListener::TYPE
			: 'link';
	}

	/**
	 * The link_meta key BuddyNext stores a card's source post id under.
	 *
	 * @return string
	 */
	private static function source_post_meta_key(): string {
		return class_exists( \BuddyNext\Feed\BlogPostListener::class )
			? \BuddyNext\Feed\BlogPostListener::META_POST_ID
			: 'source_post_id';
	}

	/**
	 * Build the card payload for a blog-post activity from the local post.
	 *
	 * Never returns empty: PostService::create() fetches Open Graph data over
	 * HTTP whenever link_url is set and link_meta is not, on the write path,
	 * without consulting the link-preview toggle. These are the site's own
	 * posts, so the title, excerpt and featured image are already in the
	 * database and are better than any scrape.
	 *
	 * @param array<string,mixed> $activity Source activity record.
	 * @return array<string,mixed> link_meta payload.
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
			// Stamp the source post id the same way BuddyNext stamps its own
			// article cards. Without it an imported card is invisible to
			// BuddyNext: it would announce a SECOND card the first time that
			// post is re-published, and comments would not sync between the
			// post and its card. With it, a migrated card behaves exactly like
			// one BuddyNext published itself.
			$meta[ self::source_post_meta_key() ] = (int) $post->ID;

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

		// Before the tags go: a mention only survives stripping as its anchor TEXT,
		// and that text is the source platform's handle, not this site's.
		$html = $this->rewrite_mentions( $html );

		$text = preg_replace( '#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n", $html );
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Rewrite a source mention so it points at the right member on THIS site.
	 *
	 * BuddyNext stores a mention as plain text `@handle` and linkifies it at render
	 * time ({@see buddynext_format_content()}), resolving the handle through
	 * `PageRouter::member_handle()`. So an imported mention has to arrive as the
	 * handle THIS site knows the member by.
	 *
	 * BuddyBoss stores the target as a template tag in the href, with its own
	 * display handle as the anchor text:
	 *
	 *   <span class="atwho-inserted"><a class="bp-suggestions-mention"
	 *     href="{{mention_user_id_7}}">@bb-luna</a></span>
	 *
	 * The `{{mention_user_id_7}}` is substituted by BuddyBoss's JS on the frontend;
	 * nothing outside BuddyBoss knows the convention. Two separate failures came
	 * from that, and the second is the one worth understanding:
	 *
	 * 1. Comments kept the raw HTML (import_comment() skipped this method), and
	 *    BuddyNext escapes stored content on render - so the member saw the literal
	 *    `<span class="atwho-inserted"><a ... href="{{mention_user_id_7}}">` as
	 *    visible text, not a broken link.
	 *
	 * 2. Posts looked FINE and were not. Stripping the tags leaves `@bb-luna`, which
	 *    BuddyNext happily linkifies - to `/members/bb-luna/`. But `bb-luna` is the
	 *    handle the SOURCE displayed; this site resolves the member by
	 *    `bn_profile_slug ?: user_nicename`, which is frequently something else.
	 *    Verified on buddynext.local: source text `@admin-guy`, actual handle for
	 *    that user id `varundubey`. The mention rendered as a confident link to a
	 *    profile that does not exist. A wrong link that looks right survives review
	 *    far longer than tag soup does.
	 *
	 * So the user id in the tag is the authority, never the anchor text. The anchor
	 * text is kept only as a fallback for a member who no longer exists, because
	 * dropping it would leave a hole mid-sentence.
	 *
	 * BuddyPress needs nothing here: it stores a real profile URL or bare `@handle`,
	 * both of which strip to the correct plain text. The early return means this
	 * costs one strpos() for those sources.
	 *
	 * @param string $html Source content (HTML), before tags are stripped.
	 * @return string Content with every mention rewritten to this site's handle.
	 */
	private function rewrite_mentions( string $html ): string {
		if ( false === strpos( $html, 'mention_user_id_' ) ) {
			return $html;
		}

		// The whole anchor collapses to the mention text, so the wrapper markup
		// cannot leave a stray `@` or an empty link behind once tags are stripped.
		$html = (string) preg_replace_callback(
			'#<a\b[^>]*href=(["\'])\s*\{\{mention_user_id_(\d+)\}\}\s*\1[^>]*>(.*?)</a>#is',
			fn( array $m ): string => $this->mention_text( (int) $m[2], (string) $m[3] ),
			$html
		);

		// A tag that was not inside an anchor (BuddyBoss also writes them bare in
		// some stored bodies). Nothing sensible can be recovered as a fallback here,
		// so an unresolvable id leaves no text rather than a raw template tag.
		return (string) preg_replace_callback(
			'/\{\{mention_user_id_(\d+)\}\}/',
			fn( array $m ): string => $this->mention_text( (int) $m[1], '' ),
			$html
		);
	}

	/**
	 * The plain-text mention for a source user id, as this site writes handles.
	 *
	 * @param int    $user_id  User id taken from the source's mention tag.
	 * @param string $fallback Source anchor text, used only when the member is gone.
	 * @return string `@handle`, the fallback text, or '' when neither is usable.
	 */
	private function mention_text( int $user_id, string $fallback ): string {
		if ( class_exists( '\BuddyNext\Core\PageRouter' ) ) {
			$handle = \BuddyNext\Core\PageRouter::member_handle( $user_id );
			if ( '' !== $handle ) {
				return '@' . $handle;
			}
		}

		// Deleted member, or Free somehow absent. Keep whatever the source showed so
		// the sentence still reads; it just will not resolve to a profile.
		return trim( wp_strip_all_tags( $fallback ) );
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
