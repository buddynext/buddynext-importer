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
			ImportMode::run(
				function () use ( $user_id, $cover ): void {
					buddynext_service( 'avatars' )->save_cover_url( $user_id, $cover['url'] );
				}
			);
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

		$placeholder = wp_tempnam( wp_basename( $path ) );
		if ( ! $placeholder ) {
			return array(
				'url'    => '',
				'reason' => 'copy_failed',
			);
		}

		/*
		 * KEEP THE SOURCE EXTENSION on the copy. wp_tempnam() always returns a
		 * `.tmp` name, and WP_Image_Editor_Imagick decides an image's format from
		 * the FILE EXTENSION, not its bytes - so a perfectly valid JPEG handed
		 * over as `.tmp` is rejected with
		 * "NoDecodeDelegateForThisImageFormat", surfacing here as
		 * `avatar_invalid_image` / `cover_invalid_image` for EVERY avatar and
		 * cover on the site (Basecamp #10135432239, the "images 0%" leg).
		 *
		 * It is environment-dependent, which is why this leg kept failing to
		 * reproduce: WP_Image_Editor_GD loads through getimagesize() and does not
		 * care about the extension, so a GD-only host migrates images fine while
		 * an Imagick host - the default wherever the extension is installed, i.e.
		 * most real hosting - loses all of them.
		 */
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$copy      = '' !== $extension ? $placeholder . '.' . $extension : $placeholder;

		if ( ! copy( $path, $copy ) ) {
			wp_delete_file( $placeholder );
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

		// The placeholder is a SECOND file whenever an extension was appended -
		// wp_tempnam() creates it on the spot to reserve the name. Deleting only
		// $copy would leak one empty file per image, per run.
		if ( $placeholder !== $copy && file_exists( $placeholder ) ) {
			wp_delete_file( $placeholder );
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
