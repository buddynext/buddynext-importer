<?php
/**
 * Writes spaces and space members into BuddyNext THROUGH ITS SERVICE API only
 * (buddynext_service( 'spaces' ) + 'space_members' ). Never touches bn_* tables
 * directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the spaces domain.
 */
final class SpaceWriter {

	/**
	 * Source key, used for id-map scoping.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * BuddyPress group status -> BuddyNext space visibility type.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_MAP = array(
		'public'  => 'open',
		'private' => 'private',
		'hidden'  => 'secret',
	);

	/**
	 * Construct the writer for a given source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Resolve the BuddyNext SpaceService.
	 *
	 * @return object SpaceService.
	 */
	private function spaces(): object {
		return buddynext_service( 'spaces' );
	}

	/**
	 * Resolve the BuddyNext SpaceMemberService.
	 *
	 * @return object SpaceMemberService.
	 */
	private function members(): object {
		return buddynext_service( 'space_members' );
	}

	/**
	 * Import one source group as a BuddyNext space. Idempotent via the id-map.
	 * Returns the BuddyNext space id, or 0 when creation failed.
	 *
	 * @param array<string,mixed> $group Source group record.
	 * @return int BuddyNext space id (0 on failure).
	 */
	public function import_space( array $group ): int {
		$source_id = (int) $group['source_id'];

		$existing = IdMap::get( $this->source, 'space', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$owner_id = (int) $group['creator_id'];
		$type     = self::STATUS_MAP[ (string) $group['status'] ] ?? 'open';

		$data = array(
			'name'        => (string) $group['name'],
			'slug'        => $this->unique_slug( (string) $group['slug'], (string) $group['name'] ),
			'description' => (string) $group['description'],
			'type'        => $type,
		);

		// Resolve a sub-space parent that was already imported.
		$parent_source_id = (int) $group['parent_id'];
		if ( $parent_source_id > 0 ) {
			$parent_bn = IdMap::get( $this->source, 'space', $parent_source_id );
			if ( null !== $parent_bn ) {
				$data['parent_id'] = $parent_bn;
			}
		}

		$result = ImportMode::run(
			fn() => $this->spaces()->create( $owner_id, $data )
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'space', $source_id, $bn_id );

		return $bn_id;
	}

	/**
	 * Import one group membership row into a mapped space. The space owner (the
	 * group creator) is auto-added by create(), so the caller skips that row.
	 *
	 * @param int                 $bn_space_id Mapped BuddyNext space id.
	 * @param int                 $owner_id    Space owner (acts for role changes).
	 * @param array<string,mixed> $member      Source membership row.
	 * @return bool Whether a write occurred.
	 */
	public function import_member( int $bn_space_id, int $owner_id, array $member ): bool {
		$user_id = (int) $member['user_id'];

		if ( $user_id <= 0 || $user_id === $owner_id ) {
			return false;
		}

		return (bool) ImportMode::run(
			function () use ( $bn_space_id, $owner_id, $user_id, $member ): bool {
				if ( 1 === (int) $member['is_banned'] ) {
					$this->members()->ban_from_space( $bn_space_id, $user_id, $owner_id, '' );
					return true;
				}

				// Pending membership (request not yet confirmed).
				if ( 0 === (int) $member['is_confirmed'] ) {
					$this->members()->request_join( $bn_space_id, $user_id );
					return true;
				}

				// Confirmed: active member, promoted to moderator for group admins/mods.
				$this->members()->join( $bn_space_id, $user_id );

				if ( 1 === (int) $member['is_admin'] || 1 === (int) $member['is_mod'] ) {
					$this->members()->change_role( $bn_space_id, $user_id, 'moderator', $owner_id );
				}

				return true;
			}
		);
	}

	/**
	 * Produce a slug that does not collide with an existing space. create() rejects
	 * a duplicate slug, so on collision a numeric suffix is appended.
	 *
	 * @param string $slug Preferred slug.
	 * @param string $name Fallback source for the slug.
	 */
	private function unique_slug( string $slug, string $name ): string {
		$base = sanitize_title( '' !== $slug ? $slug : $name );
		if ( '' === $base ) {
			$base = 'space';
		}

		$candidate = $base;
		$suffix    = 2;
		while ( $this->slug_exists( $candidate ) ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Whether a space slug already exists, via the read API.
	 *
	 * @param string $slug Slug to test.
	 */
	private function slug_exists( string $slug ): bool {
		$service = $this->spaces();
		if ( method_exists( $service, 'get_by_slug' ) ) {
			return null !== $service->get_by_slug( $slug );
		}
		return false;
	}
}
