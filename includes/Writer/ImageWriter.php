<?php
/**
 * Writes source avatars and cover images into BuddyNext THROUGH ITS OWN image
 * pipeline (Media\ImageStorageService), so every import gets the same resized,
 * WebP-converted variation set a real upload produces. Never writes files into
 * BuddyNext's storage directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the avatars/covers domain.
 *
 * These are the one part of a source community that lives purely on disk -
 * BuddyPress stores no row pointing at an avatar - so they are invisible to
 * every table-driven importer and were the last thing left behind.
 *
 * The source file is copied before it is handed over: ImageStorageService
 * re-encodes from the path it is given, and the source community may still be
 * serving that exact file to its own members mid-migration.
 */
final class ImageWriter {

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
	 * Whether BuddyNext's image pipeline is reachable.
	 */
	public static function available(): bool {
		return class_exists( '\BuddyNext\Media\ImageStorageService' );
	}

	/**
	 * Import one member's avatar and cover.
	 *
	 * @param array<string,mixed> $row Source row (source_id, avatar, cover).
	 * @return array<string,int> Skip reason -> count, keyed 'avatar_*' / 'cover_*'. Empty on full success.
	 */
	public function import_member_images( array $row ): array {
		$user_id = (int) $row['source_id'];

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return array( 'user_missing' => 1 );
		}

		$skipped = array();

		$avatar = $this->store( (string) $row['avatar'], 'avatar', 'user', $user_id, 'user_avatar', (string) get_user_meta( $user_id, 'bn_avatar', true ) );
		if ( '' !== $avatar['reason'] ) {
			$skipped[ 'avatar_' . $avatar['reason'] ] = 1;
		} elseif ( '' !== $avatar['url'] ) {
			ImportMode::run(
				function () use ( $user_id, $avatar ): void {
					buddynext_service( 'avatars' )->save_avatar_url( $user_id, $avatar['url'] );
				}
			);
		}

		$cover = $this->store( (string) $row['cover'], 'cover', 'user', $user_id, 'user_cover', (string) get_user_meta( $user_id, 'buddynext_cover_url', true ) );
		if ( '' !== $cover['reason'] ) {
			$skipped[ 'cover_' . $cover['reason'] ] = 1;
		} elseif ( '' !== $cover['url'] ) {
			// BuddyNext has no cover-URL setter of its own; its admin profile
			// screen writes this same user meta after calling the storage
			// service, so the importer follows that path exactly.
			update_user_meta( $user_id, 'buddynext_cover_url', esc_url_raw( $cover['url'] ) );
		}

		return $skipped;
	}

	/**
	 * Import one space's avatar and cover. The space must already be migrated -
	 * the caller resolves the source group id through the id-map.
	 *
	 * @param int                 $space_id BuddyNext space id.
	 * @param int                 $owner_id Space owner (needs manage-space rights).
	 * @param array<string,mixed> $row      Source row (source_id, avatar, cover).
	 * @return array<string,int> Skip reason -> count. Empty on full success.
	 */
	public function import_space_images( int $space_id, int $owner_id, array $row ): array {
		if ( $space_id <= 0 ) {
			return array( 'space_not_imported' => 1 );
		}

		$skipped = array();
		$space   = buddynext_service( 'spaces' )->get( $space_id );
		$space   = is_array( $space ) ? $space : array();

		$map = array(
			'avatar' => array( 'space_avatar', 'avatar_url' ),
			'cover'  => array( 'space_cover', 'cover_image_url' ),
		);

		foreach ( $map as $kind => $config ) {
			list( $domain, $column ) = $config;

			$stored = $this->store( (string) $row[ $kind ], $kind, 'space', $space_id, $domain, (string) ( $space[ $column ] ?? '' ) );

			if ( '' !== $stored['reason'] ) {
				$skipped[ $kind . '_' . $stored['reason'] ] = 1;
				continue;
			}

			if ( '' === $stored['url'] ) {
				continue;
			}

			$result = ImportMode::run(
				fn() => buddynext_service( 'spaces' )->update( $space_id, $owner_id, array( $column => $stored['url'] ) )
			);

			if ( is_wp_error( $result ) ) {
				$skipped[ $kind . '_' . sanitize_key( (string) $result->get_error_code() ) ] = 1;
			}
		}

		return $skipped;
	}

	/**
	 * Push one source image file through BuddyNext's image pipeline.
	 *
	 * @param string $path     Absolute source file path ('' when the object has none).
	 * @param string $kind     'avatar' | 'cover'.
	 * @param string $owner    'user' | 'space'.
	 * @param int    $id       Owner id.
	 * @param string $domain   Id-map domain for idempotency.
	 * @param string $existing The image the target already has, if any.
	 * @return array{url:string,reason:string} Stored URL, or a skip reason.
	 */
	private function store( string $path, string $kind, string $owner, int $id, string $domain, string $existing ): array {
		// Nothing to do is not a failure - most members have one image, not both.
		if ( '' === $path ) {
			return array(
				'url'    => '',
				'reason' => '',
			);
		}

		if ( IdMap::has( $this->source, $domain, $id ) ) {
			return array(
				'url'    => '',
				'reason' => 'already_imported',
			);
		}

		// NEVER overwrite an image the member or space owner already has in
		// BuddyNext. ImageStorageService::store() purges the owner's folder
		// before writing, so importing over a newer avatar would destroy it with
		// no way back. On a same-site migration that is somebody's current
		// picture; the import fills gaps, it does not replace choices.
		if ( '' !== trim( $existing ) ) {
			return array(
				'url'    => '',
				'reason' => 'target_already_set',
			);
		}

		if ( ! file_exists( $path ) ) {
			return array(
				'url'    => '',
				'reason' => 'file_missing',
			);
		}

		// Copy first: the storage service re-encodes from the path it is given,
		// and the source community may still be serving this exact file.
		$copy = wp_tempnam( wp_basename( $path ) );
		if ( ! $copy || ! copy( $path, $copy ) ) {
			return array(
				'url'    => '',
				'reason' => 'copy_failed',
			);
		}

		$stored = ImportMode::run(
			fn() => ( new \BuddyNext\Media\ImageStorageService() )->store( $copy, $kind, $owner, $id )
		);

		if ( file_exists( $copy ) ) {
			wp_delete_file( $copy );
		}

		if ( is_wp_error( $stored ) ) {
			return array(
				'url'    => '',
				'reason' => sanitize_key( (string) $stored->get_error_code() ),
			);
		}

		$url = (string) $stored;
		if ( '' === $url ) {
			return array(
				'url'    => '',
				'reason' => 'store_failed',
			);
		}

		// The id-map records the OWNER id, not a file id: there is exactly one
		// avatar and one cover per owner, so this is what makes a re-run skip
		// re-encoding an image it already converted.
		IdMap::set( $this->source, $domain, $id, $id );

		return array(
			'url'    => $url,
			'reason' => '',
		);
	}
}
