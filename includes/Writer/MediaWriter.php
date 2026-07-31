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

		// A BuddyBoss album with a group_id belongs to that group, not to the
		// member who happened to create it. BuddyNext gained space-owned albums
		// for exactly this, so the association is carried instead of dropped -
		// otherwise a club's shared album arrives as one member's private one.
		$this->attach_to_space( $album_id, (int) ( $album['group_id'] ?? 0 ) );

		return array(
			'id'      => $album_id,
			'created' => true,
		);
	}

	/**
	 * Hand a migrated album to the space its source group became.
	 *
	 * Through BuddyNext's own seam rather than by writing the meta key here:
	 * the association carries a privacy rule with it (a space album's audience
	 * is the space's), and that belongs to BuddyNext to decide, not to a
	 * migration tool to reproduce.
	 *
	 * Silent when the group never became a space - the album still exists and
	 * still holds its photos, it simply belongs to its creator. Losing the
	 * association is better than attaching content to the wrong space.
	 *
	 * @param int $album_id        Target album id.
	 * @param int $source_group_id Source group id (0 for a personal album).
	 * @return void
	 */
	private function attach_to_space( int $album_id, int $source_group_id ): void {
		if ( $album_id <= 0 || $source_group_id <= 0 ) {
			return;
		}

		if ( ! class_exists( '\\BuddyNext\\Media\\Galleries' )
			|| ! method_exists( '\\BuddyNext\\Media\\Galleries', 'assign_album_to_space' ) ) {
			return;
		}

		$space_id = IdMap::get( $this->source, 'space', $source_group_id );
		if ( null === $space_id || $space_id <= 0 ) {
			return;
		}

		ImportMode::run( fn() => \BuddyNext\Media\Galleries::assign_album_to_space( $album_id, (int) $space_id ) );
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

		// No album handling here any more. This domain is now loose library items
		// only - nothing with an album_id reaches it - so the linked_from_activity
		// note this used to return is gone with the overlap that caused it.
		return '';
	}

	/**
	 * Import one album photo: bring the file in, then file it in its album.
	 *
	 * Separate from import_media() because the two answer different questions.
	 * A loose library item asks "is this media in BuddyNext yet"; an album photo
	 * asks "is this album membership recorded yet", and in BuddyBoss the answer
	 * is usually that the file itself already arrived with its activity. Counting
	 * that as a fresh write would report more media than the source holds.
	 *
	 * @param array<string,mixed> $media Source album-media row.
	 * @return string Empty when filed, otherwise the skip reason.
	 */
	public function import_album_media( array $media ): string {
		$source_id     = (int) $media['source_id'];
		$attachment_id = (int) $media['attachment_id'];
		$user_id       = (int) $media['user_id'];

		if ( IdMap::has( $this->source, 'album_media', $source_id ) ) {
			return 'already_imported';
		}

		if ( $attachment_id <= 0 ) {
			return 'no_attachment';
		}

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}

		$album_id = IdMap::get( $this->source, 'media_album', (int) $media['album_id'] );
		if ( null === $album_id || $album_id <= 0 ) {
			// The album never landed, so there is nowhere to file this. Named
			// rather than silent: an album that failed takes its whole contents
			// with it, and that is worth seeing.
			return 'album_not_imported';
		}

		$media_id = $this->ingest->ingest( $attachment_id, $user_id );
		if ( $media_id <= 0 ) {
			return 'file_missing_or_upload_refused';
		}

		IdMap::set( $this->source, 'album_media', $source_id, $media_id );

		$this->place_in_album( (int) $media['album_id'], $media_id );

		return '';
	}

	/**
	 * Restore one album's running order.
	 *
	 * Album position is assigned as photos are added, and the import adds them
	 * in media-id order so the pass stays resumable - which is rarely the order
	 * the member arranged. Their arrangement lives in bp_media.menu_order and
	 * nowhere else, so it is applied afterwards, once the contents are in.
	 *
	 * @param int            $source_album_id Source album id.
	 * @param array<int,int> $source_order    Source media ids, menu_order first.
	 * @return bool Whether an order was applied.
	 */
	public function apply_album_order( int $source_album_id, array $source_order ): bool {
		$album_id = IdMap::get( $this->source, 'media_album', $source_album_id );
		if ( null === $album_id || $album_id <= 0 || array() === $source_order ) {
			return false;
		}

		$service = self::albums();
		if ( null === $service || ! method_exists( $service, 'reorder' ) ) {
			return false;
		}

		// Map the source order onto target ids, dropping anything that did not
		// make it - reorder() positions by array index, so a gap would simply
		// close up rather than corrupt the sequence.
		$ordered = array();
		foreach ( $source_order as $source_media_id ) {
			foreach ( array( 'album_media', 'standalone_media' ) as $domain ) {
				$mapped = IdMap::get( $this->source, $domain, (int) $source_media_id );
				if ( null !== $mapped && $mapped > 0 ) {
					$ordered[] = (int) $mapped;
					break;
				}
			}
		}

		if ( array() === $ordered ) {
			return false;
		}

		ImportMode::run( fn() => $service->reorder( (int) $album_id, $ordered ) );

		return true;
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
