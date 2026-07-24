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
	 * Ingest one WP attachment, returning the resulting media id (0 on failure).
	 *
	 * A copy of the file is handed to the upload service so the original
	 * attachment survives the import intact.
	 *
	 * @param int $attachment_id WP attachment id.
	 * @param int $user_id       Owner of the imported media.
	 */
	public function ingest( int $attachment_id, int $user_id ): int {
		if ( $attachment_id <= 0 || ! self::available() ) {
			return 0;
		}

		$existing = IdMap::get( $this->source, self::DOMAIN, $attachment_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return 0;
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
		$result   = ImportMode::run( fn() => $upload->handle( $file, $user_id ) );
		$media_id = self::extract_media_id( $result );

		if ( $media_id > 0 ) {
			IdMap::set( $this->source, self::DOMAIN, $attachment_id, $media_id );
		}

		if ( file_exists( $copy ) ) {
			wp_delete_file( $copy );
		}

		return $media_id;
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
