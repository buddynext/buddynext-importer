<?php
/**
 * Writes source media albums and standalone (never-posted) media into the
 * BuddyNext media engine (WPMediaVerse) THROUGH ITS SERVICE API only - the
 * album service and the upload service. Never touches mvs_* tables.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the standalone-media domain.
 *
 * Activity-attached photos already ride their post through ActivityWriter. This
 * covers what that path can never reach: album photos and library items the
 * member uploaded but never posted. Both paths share MediaIngest, so an
 * attachment reachable from either is uploaded once and reused.
 */
final class MediaWriter {

	/**
	 * Source key, used for id-map scoping.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Shared attachment ingest.
	 *
	 * @var MediaIngest
	 */
	private MediaIngest $ingest;

	/**
	 * Construct the writer for a given source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
		$this->ingest = new MediaIngest( $source );
	}

	/**
	 * Whether the media engine and its album service are both reachable.
	 */
	public static function available(): bool {
		return MediaIngest::available() && null !== self::albums();
	}

	/**
	 * Resolve the WPMediaVerse album service via the MediaClient seam.
	 */
	private static function albums(): ?object {
		if ( ! class_exists( '\BuddyNext\Media\MediaClient' ) ) {
			return null;
		}

		$albums = \BuddyNext\Media\MediaClient::albums();

		return is_object( $albums ) && method_exists( $albums, 'create' ) && method_exists( $albums, 'add_items' )
			? $albums
			: null;
	}

	/**
	 * Import one source album. Idempotent via the id-map.
	 *
	 * Reports whether the album was actually CREATED, not merely resolved: a
	 * re-run finds every album in the id-map and must not report them as newly
	 * imported, or the run summary overstates what moved.
	 *
	 * @param array<string,mixed> $album Source album record.
	 * @return array{id:int,created:bool} Target album id (0 on failure/skip).
	 */
	public function import_album( array $album ): array {
		$source_id = (int) $album['source_id'];

		$existing = IdMap::get( $this->source, 'media_album', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$service = self::albums();
		if ( null === $service ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$author = (int) $album['user_id'];
		$title  = trim( (string) $album['title'] );

		// The album service refuses an empty title, and a source album may
		// legitimately have none - name it after its source id rather than
		// dropping the album and orphaning every photo inside it.
		if ( '' === $title ) {
			/* translators: %d: source album id. */
			$title = sprintf( __( 'Imported album %d', 'buddynext-importer' ), $source_id );
		}

		if ( $author <= 0 || ! get_userdata( $author ) ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$created = ImportMode::run(
			fn() => $service->create(
				$author,
				array(
					'title'   => $title,
					'privacy' => $this->privacy( (string) ( $album['privacy'] ?? 'public' ) ),
				)
			)
		);

		if ( is_wp_error( $created ) || (int) $created <= 0 ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$album_id = (int) $created;
		IdMap::set( $this->source, 'media_album', $source_id, $album_id );

		return array(
			'id'      => $album_id,
			'created' => true,
		);
	}

	/**
	 * Import one standalone media row: ingest its attachment, then place it in
	 * its album when the album was imported.
	 *
	 * @param array<string,mixed> $media Source media record.
	 * @return string Empty string when written, otherwise the skip reason.
	 */
	public function import_media( array $media ): string {
		$source_id     = (int) $media['source_id'];
		$attachment_id = (int) $media['attachment_id'];
		$user_id       = (int) $media['user_id'];

		if ( IdMap::has( $this->source, 'standalone_media', $source_id ) ) {
			return 'already_imported';
		}

		if ( $attachment_id <= 0 ) {
			return 'no_attachment';
		}

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}

		// The source row points at a WP attachment whose file must still exist -
		// ingest copies the real file. A pruned uploads directory is a real loss
		// and is reported rather than counted as written.
		$media_id = $this->ingest->ingest( $attachment_id, $user_id );
		if ( $media_id <= 0 ) {
			return 'file_missing_or_upload_refused';
		}

		IdMap::set( $this->source, 'standalone_media', $source_id, $media_id );

		$this->place_in_album( (int) $media['album_id'], $media_id );

		return '';
	}

	/**
	 * Add an imported media item to its imported album, when it had one.
	 *
	 * @param int $source_album_id Source album id (0 when the item is loose).
	 * @param int $media_id        Target media id.
	 */
	private function place_in_album( int $source_album_id, int $media_id ): void {
		if ( $source_album_id <= 0 ) {
			return;
		}

		$album_id = IdMap::get( $this->source, 'media_album', $source_album_id );
		if ( null === $album_id ) {
			return;
		}

		$service = self::albums();
		if ( null === $service ) {
			return;
		}

		ImportMode::run( fn() => $service->add_items( (int) $album_id, array( $media_id ) ) );
	}

	/**
	 * Map a source media privacy to a WPMediaVerse one.
	 *
	 * BuddyBoss values: public, loggedin, onlyme, friends, grouponly. MediaVerse
	 * exposes public / private, so every restricted level collapses to private
	 * rather than publishing media the member had limited.
	 *
	 * @param string $source Source privacy value.
	 */
	private function privacy( string $source ): string {
		return 'public' === $source ? 'public' : 'private';
	}
}
