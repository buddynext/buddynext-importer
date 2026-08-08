<?php
/**
 * Shared ingest of source WP attachments into the BuddyNext media engine
 * (WPMediaVerse) via the MediaClient seam.
 *
 * Both the activity domain (photos attached to a post) and the standalone media
 * domain (album photos that were never posted) hand attachments to the same
 * upload service, and both must share ONE id-map domain: an attachment that has
 * already been ingested by either path is reused rather than uploaded twice.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment -> WPMediaVerse media ingest.
 */
final class MediaIngest {

	/**
	 * Id-map domain. Shared by every caller on purpose - see the class docblock.
	 */
	private const DOMAIN = 'media';

	/**
	 * Source key, used for id-map scoping.
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
	 * Whether the media engine is present.
	 */
	public static function available(): bool {
		if ( ! class_exists( '\BuddyNext\Media\MediaClient' ) || ! \BuddyNext\Media\MediaClient::available() ) {
			return false;
		}

		$upload = \BuddyNext\Media\MediaClient::upload();

		return is_object( $upload ) && method_exists( $upload, 'handle' );
	}

	/**
	 * The media id a previous pass already ingested this attachment as, or 0.
	 *
	 * Ingesting is idempotent through the same id-map, so a caller never needs this
	 * to avoid a duplicate. It exists so a caller can tell the two cases APART:
	 * one source row can be reached by two domains (an album photo that also has an
	 * activity), and the second domain to arrive has not written any media - it has
	 * only linked what the first one wrote. Counting that as a fresh write would
	 * inflate the migration report, which this tool treats as a correctness bug in
	 * its own right.
	 *
	 * @param int $attachment_id WP attachment id.
	 * @return int Existing target media id, or 0 when this attachment is new.
	 */
	public function existing( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		return (int) ( IdMap::get( $this->source, self::DOMAIN, $attachment_id ) ?? 0 );
	}

	/**
	 * Ingest one WP attachment, returning the resulting media id (0 on failure).
	 *
	 * A copy of the file is handed to the upload service so the original
	 * attachment survives the import intact.
	 *
	 * $args passes straight through to the upload service (title, privacy,
	 * description). `privacy` matters for more than presentation: MVS treats
	 * 'dm' as a conversation SCOPE rather than a user preference, and every
	 * library, explore, moderation and webhook query excludes it. A private
	 * message attachment ingested without it lands as public media, i.e. a
	 * conversation photo published to Explore Media by the migration.
	 *
	 * @param int                 $attachment_id WP attachment id.
	 * @param int                 $user_id       Owner of the imported media.
	 * @param array<string,mixed> $args          Optional upload args (title, privacy, description).
	 */
	public function ingest( int $attachment_id, int $user_id, array $args = array() ): int {
		if ( $attachment_id <= 0 || ! self::available() ) {
			return 0;
		}

		// The id-map is keyed on the SOURCE attachment id only, so a re-use
		// returns whatever privacy the first ingest chose. That is safe here
		// because the platforms give a DM upload its own bp_media row and its own
		// WP attachment - no attachment is reachable both from a conversation and
		// from a public activity. If a future source ever shares one, this lookup
		// must become scope-aware before it hands a 'dm' caller public media.
		$existing = IdMap::get( $this->source, self::DOMAIN, $attachment_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return 0;
		}

		// wp_tempnam() lives in wp-admin/includes/file.php, which WordPress loads
		// only for admin requests. Every surface that actually runs a migration is
		// somewhere else: the REST /step endpoint the admin page drives, and the
		// Action Scheduler tick behind "Run in background". WP-CLI loads the admin
		// includes itself, which is why `wp buddynext-import migrate-all` never hit
		// this and the browser import died with a 500 on the first avatar.
		//
		// Required at point of use rather than at file load, so a request that
		// merely autoloads this class does not drag the admin file helpers in.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$copy = wp_tempnam( wp_basename( $path ) );
		if ( ! $copy || ! copy( $path, $copy ) ) {
			return 0;
		}

		$file = array(
			'name'     => wp_basename( $path ),
			'type'     => (string) get_post_mime_type( $attachment_id ),
			'tmp_name' => $copy,
			'error'    => 0,
			'size'     => (int) filesize( $copy ),
		);

		$upload   = \BuddyNext\Media\MediaClient::upload();
		$result   = ImportMode::run( fn() => $upload->handle( $file, $user_id, $args ) );
		$media_id = self::extract_media_id( $result );

		if ( $media_id > 0 ) {
			IdMap::set( $this->source, self::DOMAIN, $attachment_id, $media_id );
			$this->carry_source_poster( $media_id, $attachment_id );
		}

		if ( file_exists( $copy ) ) {
			wp_delete_file( $copy );
		}

		return $media_id;
	}

	/**
	 * Carry the source platform's own video preview thumbnail across as the poster.
	 *
	 * Without this a migrated video depends on the DESTINATION server having
	 * ffmpeg: the engine frame-grabs a poster at ingest and, where the binary is
	 * missing, logs "ffmpeg poster extraction failed" and keeps the default
	 * placeholder. The same import therefore produced a video with a preview on
	 * one host and a bare player on another, which is what was reported
	 * (Basecamp #10180601509). Most shared hosts have no ffmpeg.
	 *
	 * The image already exists on the customer's disk: BuddyBoss generated it with
	 * ITS ffmpeg at upload time and records the attachment id on the video's own
	 * attachment. Carrying it beats re-deriving one - it is the frame the member
	 * chose, and it takes ffmpeg out of the import's requirements entirely.
	 *
	 * MUST HAND OVER A COPY. The engine seam stages the poster with
	 * PosterService::stage_client_frame(), which copies the file into
	 * `wpmediaverse/posters/` and then UNLINKS what it was given - correct for the
	 * upload temp file it was written for, destructive for a source file. Passing
	 * the attachment's real path deletes the customer's preview during a
	 * migration, which this importer must never do (see the same wp_tempnam copy
	 * in ingest() above, and the "original attachment survives the import intact"
	 * promise it exists to keep).
	 *
	 * Silent and best-effort: a source with no preview - BuddyPress, rtMedia,
	 * every image - simply returns, and a failure never fails the media.
	 *
	 * @param int $media_id      Engine media id just created.
	 * @param int $attachment_id Source WP attachment id.
	 * @return void
	 */
	private function carry_source_poster( int $media_id, int $attachment_id ): void {
		if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'video/' ) ) {
			return;
		}

		if ( ! is_callable( array( \BuddyNext\Media\MediaClient::class, 'apply_client_poster' ) ) ) {
			return;
		}

		$poster_id = self::source_poster_attachment( $attachment_id );
		if ( $poster_id <= 0 ) {
			return;
		}

		$poster_path = get_attached_file( $poster_id );
		if ( ! is_string( $poster_path ) || '' === $poster_path || ! file_exists( $poster_path ) ) {
			return;
		}

		$poster_copy = wp_tempnam( wp_basename( $poster_path ) );
		if ( ! $poster_copy || ! copy( $poster_path, $poster_copy ) ) {
			return;
		}

		// Consumes $poster_copy on success; clean up the case where it did not.
		\BuddyNext\Media\MediaClient::apply_client_poster( $media_id, $poster_copy );

		if ( file_exists( $poster_copy ) ) {
			wp_delete_file( $poster_copy );
		}
	}

	/**
	 * The source platform's preview-thumbnail attachment for a video, if any.
	 *
	 * Read from WordPress post meta on the video's own attachment rather than
	 * through the adapter: these are attachment meta rows, not platform tables, so
	 * they read the same whichever adapter drives the run, and a source that never
	 * wrote them returns 0.
	 *
	 * @param int $attachment_id Source video attachment id.
	 * @return int Poster attachment id, or 0.
	 */
	private static function source_poster_attachment( int $attachment_id ): int {
		$direct = (int) get_post_meta( $attachment_id, 'bp_video_preview_thumbnail_id', true );
		if ( $direct > 0 ) {
			return $direct;
		}

		// Alternate BuddyBoss shape: a set of auto-generated frames plus an
		// optional custom pick. The custom image is the member's explicit choice,
		// so it outranks the generated set.
		$thumbs = get_post_meta( $attachment_id, 'video_preview_thumbnails', true );
		if ( ! is_array( $thumbs ) ) {
			return 0;
		}

		$custom = (int) ( $thumbs['custom_image'] ?? 0 );
		if ( $custom > 0 ) {
			return $custom;
		}

		$defaults = $thumbs['default_images'] ?? array();

		return is_array( $defaults ) && ! empty( $defaults ) ? (int) reset( $defaults ) : 0;
	}

	/**
	 * Ingest a list of attachments, returning the media ids that succeeded.
	 *
	 * @param array<int,int> $attachment_ids WP attachment ids.
	 * @param int            $user_id        Owner of the imported media.
	 * @return array<int,int>
	 */
	public function ingest_many( array $attachment_ids, int $user_id ): array {
		$media_ids = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$media_id = $this->ingest( (int) $attachment_id, $user_id );
			if ( $media_id > 0 ) {
				$media_ids[] = $media_id;
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
	private static function extract_media_id( $result ): int {
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
