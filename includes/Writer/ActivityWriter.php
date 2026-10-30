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
	 * A row this pass deliberately did not write, and why.
	 *
	 * Every non-write exit in this class goes through here. They used to return
	 * an identical id-0 array, so five very different outcomes - an empty source
	 * row, a group that never became a space, content the source itself withheld,
	 * a blog post that is no longer public, and an outright write failure - were
	 * indistinguishable by the time they reached a report. The largest domain in
	 * the migration could only say "N imported" and nothing about the rest.
	 *
	 * @param string $reason Reason code, matched against the note list in
	 *                       MigrateCommand::report_skips().
	 * @return array{id:int,created:bool,reason:string}
	 */
	private static function refused( string $reason ): array {
		return array(
			'id'      => 0,
			'created' => false,
			'reason'  => $reason,
		);
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
	 * @param array<string,mixed> $activity      Source activity record.
	 * @param array<int,int>      $media_atts    WP attachment ids attached to the activity (rtMedia/bp_media).
	 * @param array<int,int>      $mvs_media_ids Engine-native media ids attached via WPMediaVerse (already valid, never ingested).
	 * @return array{id:int,created:bool,reason?:string} BuddyNext post id (0 on failure/skip).
	 */
	public function import_post( array $activity, array $media_atts = array(), array $mvs_media_ids = array() ): array {
		$source_id = (int) $activity['source_id'];

		$existing = IdMap::get( $this->source, 'post', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$user_id      = (int) $activity['user_id'];
		$content      = $this->clean_content( (string) $activity['content'] );
		$is_blog_post = 'new_blog_post' === (string) ( $activity['source_type'] ?? '' );
		// PeepSo stores photos as files, not WP attachments. BuddyPress and
		// BuddyBoss never set media_paths, so their path is unchanged. A PeepSo
		// journal card carries its photos as files too; they are ingested here
		// and handed to the article card below, which otherwise only has the
		// WordPress post's featured image (PeepSo sets none).
		$paths         = array_filter( (array) ( $activity['media_paths'] ?? array() ), 'is_array' );
		$from_path     = $this->ingest_paths( $paths, $user_id );
		$files_refused = count( $paths ) - count( $from_path );
		$media_ids     = $from_path;
		if ( ! $is_blog_post ) {
			$media_ids = array_merge(
				$this->ingest_media( $media_atts, $user_id ),
				self::verified_engine_media( $mvs_media_ids ),
				$from_path
			);
		}

		// A repost becomes a BuddyNext share of the original, so the shared post
		// shows inside it. Asked before the empty-content check: a repost with
		// no words of its own is still a repost.
		$shared = $this->import_repost( $activity, $user_id, $content );
		if ( $shared > 0 ) {
			IdMap::set( $this->source, 'post', $source_id, $shared );

			return array(
				'id'            => $shared,
				'created'       => true,
				'files_refused' => $files_refused,
			);
		}

		// A post needs either content or media. A blog card does not: the post
		// it points at is the content.
		if ( '' === $content && empty( $media_ids ) && ! $is_blog_post ) {
			return self::refused( 'empty_source_row' );
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
			// A mapping of 0 is treated exactly like no mapping. The space write
			// can fail without raising a WP_Error - BuddyNext's services return
			// (int) $wpdb->insert_id, which is 0 on a failed INSERT - so the
			// id-map can hold a real entry pointing at nothing. Testing only for
			// null let that 0 through to the global-feed fallback above, which is
			// the precise disclosure this guard exists to prevent.
			if ( null === $mapped || $mapped <= 0 ) {
				return self::refused( 'space_not_imported' );
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
			return self::refused( 'withheld_at_source' );
		}

		// A published post gets BuddyNext's article card, the same one a new
		// post gets when it is published. It is not a status with the headline
		// pasted into the body and the post's photos attached underneath.
		if ( $is_blog_post ) {
			return $this->import_article( $activity, $source_id, $media_ids, $files_refused );
		}

		$source_type = (string) ( $activity['source_type'] ?? 'activity_update' );
		$poll_options = array();
		foreach ( (array) ( $activity['poll_options'] ?? array() ) as $option ) {
			$label = trim( wp_strip_all_tags( (string) $option ) );
			if ( '' !== $label ) {
				$poll_options[] = $label;
			}
		}
		$is_poll  = 'poll' === $source_type && count( $poll_options ) >= 2 && count( $poll_options ) <= 5;
		$link_url = esc_url_raw( (string) ( $activity['link_url'] ?? '' ) );
		$is_link  = ! $is_poll && '' !== $link_url && empty( $media_ids );

		if ( $is_poll ) {
			$type = 'poll';
		} elseif ( $is_link ) {
			$type = 'link';
		} elseif ( ! empty( $media_ids ) ) {
			// Photos, uploaded video and uploaded audio. BuddyNext has no
			// separate video or audio post type: the composer stores all three
			// as `photo`, and the grid plays each tile by its file. `media` is
			// a different card, the one a media-library share uses, and it
			// would paint a "Media" label over the member's own pictures.
			$type = 'photo';
		} else {
			$type = 'text';
		}

		$data = array(
			'type'       => $type,
			'content'    => $content,
			'space_id'   => $space_id,
			// A post belongs to a person or to a space. Inside a space the
			// space's own privacy decides who sees it, which is what BuddyNext
			// itself writes there (space_members); only a person's post maps the
			// source level.
			'privacy'    => $space_id > 0 ? 'space_members' : PrivacyMap::post_privacy( (string) ( $activity['privacy'] ?? 'public' ) ),
			'created_at' => $this->utc( (string) $activity['date_recorded'] ),
		);

		if ( ! empty( $media_ids ) && ! $is_poll ) {
			$data['media_ids'] = $media_ids;
		}

		if ( $is_poll ) {
			$data['options'] = $poll_options;
		}

		if ( $is_link ) {
			// The source's own link preview when it kept one (PeepSo stores the
			// fetched title, description and thumbnail); else the post's words.
			$preview = (array) ( $activity['link_preview'] ?? array() );
			$title   = (string) ( $preview['title'] ?? '' );
			if ( '' === $title ) {
				$title = wp_trim_words( wp_strip_all_tags( $content ), 12, '…' );
			}
			$data['link_url']  = $link_url;
			$data['link_meta'] = array(
				'title'       => '' !== $title ? $title : $link_url,
				'description' => (string) ( $preview['description'] ?? '' ),
				'url'         => $link_url,
			);
			if ( '' !== (string) ( $preview['thumbnail'] ?? '' ) ) {
				$data['link_meta']['thumbnail'] = (string) $preview['thumbnail'];
			}
		}

		if ( ! empty( $activity['content_warning'] ) ) {
			$data['content_warning']      = 1;
			$data['content_warning_type'] = 'nsfw';
		}

		$result = ImportMode::run(
			fn() => $this->posts()->create( $user_id, $data )
		);

		// PostService::create() returns (int) $wpdb->insert_id, which is 0 on a
		// failed INSERT rather than a WP_Error, so a falsy id has to be refused
		// here as well. Mapping a 0 would mark this row imported forever: the
		// early return at the top of this method would skip it on every later
		// run, and no count would ever show it missing.
		if ( is_wp_error( $result ) || (int) $result <= 0 ) {
			return self::refused( is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : 'write_failed' );
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'post', $source_id, $bn_id );

		if ( $is_poll ) {
			$this->record_poll_votes( $bn_id, (array) ( $activity['poll_votes'] ?? array() ) );
		}

		// BuddyNext pins on the author's own profile only, so a space post's
		// pin is declared by the source rather than written here.
		if ( ! empty( $activity['pinned'] ) && $space_id <= 0 ) {
			ImportMode::run( fn() => $this->posts()->pin( $bn_id, $user_id ) );
		}

		return array(
			'id'            => $bn_id,
			'created'       => true,
			'files_refused' => $files_refused,
		);
	}

	/**
	 * Publish a blog announcement as BuddyNext's article card.
	 *
	 * IntegrationActivity::publish() is the public seam a partner card uses.
	 * The card has no body and no attached files; the headline, excerpt and
	 * cover come from the post. Stock BuddyNext has no announce() method.
	 *
	 * @param array<string,mixed> $activity      Source activity record.
	 * @param int                 $source_id     Source activity id.
	 * @param array<int,int>      $media_ids     Photos ingested from the source card.
	 * @param int                 $files_refused Source files the media engine refused.
	 * @return array{id:int,created:bool,reason?:string,files_refused?:int}
	 */
	private function import_article( array $activity, int $source_id, array $media_ids = array(), int $files_refused = 0 ): array {
		if ( ! class_exists( \BuddyNext\Feed\BlogPostListener::class ) || ! class_exists( \BuddyNext\Feed\IntegrationActivity::class ) ) {
			return self::refused( 'article_api_unavailable' );
		}

		if ( ! $this->blog_post_is_public( $activity ) ) {
			return self::refused( 'blog_post_not_public' );
		}

		$post = get_post( (int) ( $activity['secondary_item_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return self::refused( 'blog_post_missing' );
		}

		$created_at = $this->utc( (string) $activity['date_recorded'] );
		$result     = ImportMode::run(
			fn() => $this->publish_article( $post, $created_at, self::cover_url( $media_ids ) )
		);

		// publish() returns 0 when this post already has a card. That card is
		// the one to keep, not a second activity.
		if ( 0 === $result ) {
			$result = \BuddyNext\Feed\BlogPostListener::card_id_for_post( (int) $post->ID );
		}

		if ( is_wp_error( $result ) || (int) $result <= 0 ) {
			return self::refused( is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : 'write_failed' );
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'post', $source_id, $bn_id );
		$this->import_blog_comments( (int) $post->ID, $bn_id );

		return array(
			'id'            => $bn_id,
			'created'       => true,
			'files_refused' => $files_refused,
		);
	}

	/**
	 * Write a source repost through ShareService::share(), returning the share
	 * card's post id, or 0 to fall back to a plain post: no original in the
	 * map (it was not imported), shares switched off, or the share refused.
	 * The source time goes in as share()'s optional fourth argument, the
	 * Backdate::resolve() seam BuddyNext card 10459592358 adds; until then PHP
	 * ignores it and the share is dated at the import time.
	 *
	 * @param array<string,mixed> $activity Source activity record.
	 * @param int                 $user_id  Author.
	 * @param string              $content  The repost's own words.
	 */
	private function import_repost( array $activity, int $user_id, string $content ): int {
		$original = (int) ( $activity['repost_of'] ?? 0 );
		if ( $original <= 0 ) {
			return 0;
		}

		$bn_original = (int) ( IdMap::get( $this->source, 'post', $original ) ?? 0 );
		$shares      = $bn_original > 0 ? buddynext_service( 'shares' ) : null;
		if ( ! is_object( $shares ) || ! method_exists( $shares, 'share' ) ) {
			return 0;
		}

		$created = $this->utc( (string) $activity['date_recorded'] );
		$result  = ImportMode::run( fn() => $shares->share( $user_id, $bn_original, $content, $created ) );

		return is_wp_error( $result ) ? 0 : max( 0, (int) $result );
	}

	/**
	 * The stored (unsigned) URL of the first ingested photo, for an article
	 * card's cover. A signed URL would expire inside the card's link meta.
	 *
	 * @param array<int,int> $media_ids Ingested media ids.
	 */
	private static function cover_url( array $media_ids ): string {
		$repo = class_exists( \BuddyNext\Media\MediaClient::class ) ? \BuddyNext\Media\MediaClient::repo() : null;
		if ( ! $repo || ! is_callable( array( $repo, 'get_raw' ) ) ) {
			return '';
		}
		foreach ( $media_ids as $media_id ) {
			foreach ( array( 'thumb_large', 'file_url' ) as $key ) {
				$url = (string) $repo->get_raw( (int) $media_id, $key );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * Write one article card, dated when the source announced it.
	 *
	 * IntegrationActivity::publish() takes no date, so the card is created
	 * through PostService::create(), which does, with the same type, link and
	 * link meta publish() would write. create() refuses an author whose email
	 * is unverified unless the write is a system publish; that one case falls
	 * back to publish(), and that card carries the run date.
	 *
	 * @param \WP_Post $post       Published WordPress post.
	 * @param string   $created_at UTC "Y-m-d H:i:s".
	 * @param string   $cover      Fallback cover when the post has no featured image.
	 * @return int|\WP_Error Card id, 0 when the card already exists, or an error.
	 */
	private function publish_article( \WP_Post $post, string $created_at, string $cover = '' ) {
		$existing = \BuddyNext\Feed\BlogPostListener::card_id_for_post( (int) $post->ID );
		if ( $existing > 0 ) {
			return $existing;
		}

		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40, '…' );
		}

		$author = (int) $post->post_author;
		$url    = \BuddyNext\Feed\IntegrationActivity::published_permalink( $post );
		$title  = wp_strip_all_tags( get_the_title( $post ) );
		$image  = (string) get_the_post_thumbnail_url( $post, 'large' );
		if ( '' === $image ) {
			$image = $cover;
		}
		$meta   = array(
			'image'                                        => $image,
			\BuddyNext\Feed\BlogPostListener::META_POST_ID => (int) $post->ID,
		);

		$data = array(
			'type'      => \BuddyNext\Feed\BlogPostListener::TYPE,
			'content'   => '',
			'privacy'   => 'public',
			'link_url'  => $url,
			'link_meta' => array_merge(
				array(
					'title'       => $title,
					'description' => $excerpt,
					'url'         => $url,
					'thumbnail'   => $image,
				),
				$meta
			),
		);
		if ( '' !== $created_at ) {
			$data['created_at'] = $created_at;
		}

		$result = $this->posts()->create( $author, $data );
		if ( is_wp_error( $result ) && 'email_unverified' === $result->get_error_code() ) {
			$result = \BuddyNext\Feed\IntegrationActivity::publish( $author, '', $url, $title, \BuddyNext\Feed\BlogPostListener::TYPE, $excerpt, 0, $meta );
		}

		return $result;
	}

	/**
	 * Import one activity_comment as a BuddyNext comment on its mapped post.
	 * A reply to another comment (secondary_item_id points at a comment, not the
	 * root activity) is nested under that comment. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $comment Source comment record.
	 * @return array{id:int,created:bool,reason?:string} BuddyNext comment id (0 on failure/skip).
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
		if ( null === $post_id || $post_id <= 0 ) {
			// Root post was not imported (skipped/system) - drop the comment.
			return self::refused( 'post_not_imported' );
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
			return self::refused( 'empty_source_row' );
		}

		// A reply targets another comment; a top-level comment targets the root.
		$secondary = (int) $comment['secondary_item_id'];
		$parent_id = null;
		if ( $secondary > 0 && $secondary !== (int) $comment['root_id'] ) {
			// A 0 mapping means the parent comment's write failed; treat it as
			// unmapped so the reply attaches to the post rather than to comment 0.
			$mapped_parent = IdMap::get( $this->source, 'comment', $secondary );
			$parent_id     = ( null === $mapped_parent || $mapped_parent <= 0 ) ? null : $mapped_parent;
		}

		// BuddyNext's own space guard applies: a reply by someone who is not an
		// active member of the space is refused and reported as `forbidden`.
		$result = ImportMode::run(
			fn() => $this->comments()->create( (int) $comment['user_id'], 'post', $post_id, $content, $parent_id, $this->utc( (string) $comment['date_recorded'] ) )
		);

		// CommentService::create() returns (int) $wpdb->insert_id - 0 on a failed
		// INSERT, not a WP_Error - so a falsy id must be refused before mapping.
		if ( is_wp_error( $result ) || (int) $result <= 0 ) {
			return self::refused( is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : 'write_failed' );
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

		// And before that text is harvested: drop the markup that DESCRIBES an
		// attachment. Its visible text is the filename, and the attachment itself
		// migrates properly as media on the post, so keeping it duplicates the
		// file name into the body as if the member had typed it.
		$html = $this->strip_attachment_markup( $html );

		$text = preg_replace( '#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n", $html );
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Source HTML is indented, and that indentation is TEXT once the tags are
		// gone: a pretty-printed wrapper leaves lines of tabs between the real
		// words. Collapsing runs of newlines alone does not touch them, because
		// they are not consecutive - they are newlines separated by tabs. So
		// horizontal whitespace around every break goes first, and only then do
		// blank lines collapse.
		$text = preg_replace( '/[ \t]*\R[ \t]*/', "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Remove source markup that renders an attachment, leaving only what the
	 * member actually wrote.
	 *
	 * An rtMedia source does not store a photo post as plain text with an
	 * attachment beside it. It stores a rendered widget:
	 *
	 *   <div class="rtmedia-activity-container">
	 *     <div class="rtmedia-activity-text"><span>the caption</span></div>
	 *     <ul class="rtmedia-list rtm-activity-media-list">
	 *       <li><a href="…">
	 *         <div class="rtmedia-item-thumbnail"><img alt="beach" src="…"></div>
	 *         <div class="rtmedia-item-title"><h4 title="beach">beach</h4></div>
	 *       </a></li>
	 *     </ul>
	 *   </div>
	 *
	 * Stripping that wholesale is not wrong about the tags - nothing leaks as
	 * literal markup - but the list's visible text is the FILENAME, so every
	 * migrated photo post ended up reading "the caption", a gap, then "beach".
	 * The file is not missing from the post; it is imported properly as media
	 * through activity_media_for(). Keeping its name in the body just states it
	 * twice, in the one place that looks like the member typed it.
	 *
	 * Targeting the media list specifically (rather than extracting the text node)
	 * keeps this safe on a source that has customised the wrapper: an unrecognised
	 * shape loses nothing, because the caption is still harvested by the normal
	 * strip below.
	 *
	 * @param string $html Source content (HTML).
	 * @return string The same content with attachment widgets removed.
	 */
	private function strip_attachment_markup( string $html ): string {
		if ( false === strpos( $html, 'rtmedia-list' ) ) {
			return $html;
		}

		// Non-greedy to the first closing </ul>. rtMedia nests no list inside its
		// media list, so this cannot swallow following content; if a future shape
		// ever did nest one, the worst case is that MORE attachment markup is
		// removed, never that real text is.
		$stripped = preg_replace( '#<ul[^>]*class="[^"]*rtmedia-list[^"]*"[^>]*>.*?</ul>#is', '', $html );

		return is_string( $stripped ) ? $stripped : $html;
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
	 * Filter a list of WPMediaVerse engine media ids down to ones that still
	 * exist and are published, the same guard WPMediaVerse's own activity
	 * renderer applies (ActivityMediaLinkage::render()). These ids are already
	 * valid BuddyNext media - not WP attachment ids - so unlike ingest_media()
	 * there is nothing to upload; a linkage row can simply outlive the media it
	 * pointed at (trashed, deleted), and this drops those rather than attaching
	 * a dangling id to the migrated post.
	 *
	 * Public because VerifyService applies the same rule when it checks a
	 * migrated post for its photos: a dropped dangling link is not a loss.
	 *
	 * @param array<int,int> $media_ids Candidate engine media ids.
	 * @return array<int,int> The subset that is still usable.
	 */
	public static function verified_engine_media( array $media_ids ): array {
		if ( empty( $media_ids ) ) {
			return array();
		}

		$repo = \BuddyNext\Media\MediaClient::repo();
		if ( ! $repo || ! is_callable( array( $repo, 'exists' ) ) || ! is_callable( array( $repo, 'get' ) ) ) {
			return array();
		}

		$verified = array();
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;
			if ( $media_id <= 0 || ! $repo->exists( $media_id ) ) {
				continue;
			}
			if ( 'publish' !== (string) $repo->get( $media_id, 'status' ) ) {
				continue;
			}
			$verified[] = $media_id;
		}

		return $verified;
	}

	/**
	 * The batch form of verified_engine_media(): the same rule (the media row
	 * exists and its status is `publish`) for many ids in one query.
	 *
	 * For a pass over a whole migration (verify's photo totals), where
	 * verified_engine_media()'s two lookups per id would be tens of thousands
	 * of queries. Keep the two rules in step.
	 *
	 * @param int[] $media_ids Candidate media ids.
	 * @return array<int,true> Published ids as keys.
	 */
	public static function published_engine_media( array $media_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );
		if ( array() === $ids || ! \BuddyNext\Media\MediaClient::repo() ) {
			return array();
		}

		$found = array();
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list built from the chunk size.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish' AND media_id IN ( " . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ' )', $chunk ) );
			foreach ( (array) $rows as $mid ) {
				$found[ (int) $mid ] = true;
			}
		}

		return $found;
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

	/**
	 * Ingest files the source stored on disk instead of as attachments.
	 *
	 * @param array<int,array<string,mixed>> $files   Each with id, path, mime.
	 * @param int                            $user_id Owner of the imported media.
	 * @return array<int,int> Media ids that landed.
	 */
	private function ingest_paths( array $files, int $user_id ): array {
		if ( array() === $files ) {
			return array();
		}

		$ingest = new MediaIngest( $this->source );
		$ids    = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$media_id = $ingest->ingest_path(
				(int) ( $file['id'] ?? 0 ),
				(string) ( $file['path'] ?? '' ),
				$user_id,
				(string) ( $file['mime'] ?? '' )
			);

			if ( $media_id > 0 ) {
				$ids[] = $media_id;
			}
		}

		return $ids;
	}

	/**
	 * Import the WordPress comments on a migrated blog post onto its card.
	 *
	 * A migrated article arrives with its thread stranded: the comments are in
	 * wp_comments, where they always were, but the article's feed card has none
	 * of them - so the community sees an article nobody appears to have replied
	 * to, while the post itself shows a full discussion. BuddyNext keeps the two
	 * in step for comments made from now on; this brings the existing ones over.
	 *
	 * Only comments by real members are eligible: BuddyNext attributes a comment
	 * to a member account, and a logged-out commenter has none. Pingbacks and
	 * unapproved comments stay out, matching what the live sync does.
	 *
	 * Idempotent through the id-map under its own `blog_comment` domain, keyed on
	 * the WordPress comment id, so a resumed or repeated run writes each comment
	 * once.
	 *
	 * @param int $post_id WordPress post id whose comments should be mirrored.
	 * @param int $card_id BuddyNext article card for that post.
	 * @return array{created:int,skipped:array<string,int>}
	 */
	public function import_blog_comments( int $post_id, int $card_id ): array {
		$created = 0;
		$skipped = array();

		if ( $post_id <= 0 || $card_id <= 0 ) {
			return array(
				'created' => $created,
				'skipped' => $skipped,
			);
		}

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'type'    => 'comment',
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		);

		$service = $this->comments();

		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			$wp_comment_id = (int) $comment->comment_ID;

			if ( null !== IdMap::get( $this->source, 'blog_comment', $wp_comment_id ) ) {
				$skipped['already_imported'] = ( $skipped['already_imported'] ?? 0 ) + 1;
				continue;
			}

			$author = (int) $comment->user_id;
			if ( $author <= 0 ) {
				// A guest comment. BuddyNext has no member to attribute it to,
				// and inventing one would put words in a real member's mouth.
				$skipped['guest_author'] = ( $skipped['guest_author'] ?? 0 ) + 1;
				continue;
			}

			$content = $this->clean_content( (string) $comment->comment_content );
			if ( '' === $content ) {
				$skipped['empty_content'] = ( $skipped['empty_content'] ?? 0 ) + 1;
				continue;
			}

			// Threading: a reply maps onto its parent's imported comment when
			// that parent came across, and otherwise flattens to the card rather
			// than being dropped - a reply with no visible parent still reads,
			// a missing reply does not.
			$parent_id = null;
			if ( (int) $comment->comment_parent > 0 ) {
				$mapped_parent = IdMap::get( $this->source, 'blog_comment', (int) $comment->comment_parent );
				$parent_id     = null === $mapped_parent ? null : $mapped_parent;
			}

			$result = ImportMode::run(
				fn() => $service->create( $author, 'post', $card_id, $content, $parent_id, $this->utc( (string) $comment->comment_date_gmt ) )
			);

			if ( is_wp_error( $result ) || (int) $result <= 0 ) {
				$reason             = is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : 'write_failed';
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
				continue;
			}

			$bn_comment_id = (int) $result;
			IdMap::set( $this->source, 'blog_comment', $wp_comment_id, $bn_comment_id );

			// Pair the two the way BuddyNext's own sync does, so a later edit or
			// delete on either side can find its twin.
			if ( method_exists( $service, 'set_sync_reply_id' ) ) {
				$service->set_sync_reply_id( $bn_comment_id, $wp_comment_id );
			}

			++$created;
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * Replay each source vote through PollService so the tally and the
	 * one-vote-per-member rule are the service's, not a copied counter.
	 *
	 * @param int                  $post_id BuddyNext poll id.
	 * @param array<int,mixed>     $votes   Source votes, each option an index into poll_options.
	 */
	private function record_poll_votes( int $post_id, array $votes ): void {
		if ( array() === $votes || ! class_exists( \BuddyNext\Feed\PollService::class ) ) {
			return;
		}

		$post    = $this->posts()->get( $post_id );
		$options = is_array( $post ) ? array_values( (array) ( $post['poll_options'] ?? array() ) ) : array();
		if ( array() === $options ) {
			return;
		}

		$service = new \BuddyNext\Feed\PollService();
		foreach ( $votes as $vote ) {
			if ( ! is_array( $vote ) ) {
				continue;
			}
			$index     = (int) ( $vote['option'] ?? -1 );
			$user_id   = (int) ( $vote['user_id'] ?? 0 );
			$option_id = (int) ( $options[ $index ]['id'] ?? 0 );
			if ( $user_id <= 0 || $option_id <= 0 ) {
				continue;
			}
			ImportMode::run( fn() => $service->vote( $user_id, $post_id, $option_id ) );
		}
	}
}
