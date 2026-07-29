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
	 * The BuddyNext category id for a group, via the first of its group types
	 * that has been imported.
	 *
	 * @param array<int,int> $type_ids Source group-type term ids, in term order.
	 */
	private function resolve_category( array $type_ids ): int {
		foreach ( $type_ids as $term_id ) {
			$mapped = IdMap::get( $this->source, 'space_category', (int) $term_id );
			if ( null !== $mapped ) {
				return (int) $mapped;
			}
		}

		return 0;
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
	 * Import one source group type as a BuddyNext space category.
	 *
	 * Idempotent via the id-map, and tolerant of a category that already exists
	 * under the same slug - a re-run adopts it rather than failing on the
	 * slug_conflict the service returns.
	 *
	 * @param array<string,mixed> $type Source group type.
	 * @return array{id:int,created:bool}
	 */
	public function import_category( array $type ): array {
		$source_id = (int) $type['source_id'];

		$existing = IdMap::get( $this->source, 'space_category', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$service = $this->categories();
		if ( null === $service ) {
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$slug = sanitize_title( (string) $type['slug'] );

		$result = ImportMode::run(
			fn() => $service->create(
				array(
					'name'        => (string) $type['name'],
					'slug'        => $slug,
					'description' => (string) ( $type['description'] ?? '' ),
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			// A category with this slug is already there (an earlier run, or the
			// owner made it by hand). Adopt it: the mapping is what matters, and
			// creating "team-2" alongside "team" would split the directory.
			$found = $this->find_category_by_slug( $slug );
			if ( $found > 0 ) {
				IdMap::set( $this->source, 'space_category', $source_id, $found );

				return array(
					'id'      => $found,
					'created' => false,
				);
			}

			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'space_category', $source_id, $bn_id );

		return array(
			'id'      => $bn_id,
			'created' => true,
		);
	}

	/**
	 * Resolve the BuddyNext space category service, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function categories(): ?object {
		// Not a container binding: BuddyNext constructs this service directly
		// wherever it uses it (see DemoDataService), and the container THROWS on
		// an unregistered key rather than returning null - so asking it for one
		// would fatal the import rather than skip the domain.
		if ( ! class_exists( '\\BuddyNext\\Spaces\\SpaceCategoryService' ) ) {
			return null;
		}

		static $service = null;
		if ( null === $service ) {
			$service = new \BuddyNext\Spaces\SpaceCategoryService();
		}

		return method_exists( $service, 'create' ) ? $service : null;
	}

	/**
	 * Find an existing space category id by slug.
	 *
	 * @param string $slug Category slug.
	 */
	private function find_category_by_slug( string $slug ): int {
		$service = $this->categories();
		if ( null === $service || ! method_exists( $service, 'get_all' ) ) {
			return 0;
		}

		foreach ( (array) $service->get_all() as $category ) {
			if ( is_array( $category ) && sanitize_title( (string) ( $category['slug'] ?? '' ) ) === $slug ) {
				return (int) ( $category['id'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Import one source group as a BuddyNext space. Idempotent via the id-map.
	 *
	 * Reports whether the space was CREATED, not merely resolved: a resumed run
	 * finds every space in the id-map, and counting those as imported would tell
	 * the operator rows moved when nothing did.
	 *
	 * @param array<string,mixed> $group Source group record.
	 * @return array{id:int,created:bool} BuddyNext space id (0 on failure).
	 */
	public function import_space( array $group ): array {
		$source_id = (int) $group['source_id'];

		$existing = IdMap::get( $this->source, 'space', $source_id );
		if ( null !== $existing ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}

		$owner_id = (int) $group['creator_id'];
		$type     = self::STATUS_MAP[ (string) $group['status'] ] ?? 'open';

		$data = array(
			'name'        => (string) $group['name'],
			'slug'        => $this->unique_slug( (string) $group['slug'], (string) $group['name'] ),
			'description' => (string) $group['description'],
			'type'        => $type,
			// SpaceService's backdate seam (BuddyNext Core\Backdate validates
			// and clamps) - the space keeps the source group's date_created
			// instead of the migration run time.
			'created_at'  => (string) ( $group['date_created'] ?? '' ),
		);

		// A source group may hold SEVERAL types; a space has one category. The
		// first in term order wins and the rest are reported as a skip rather
		// than dropped silently - losing a group's classification without saying
		// so is how a migrated directory quietly stops filtering.
		$type_ids    = array_map( 'intval', (array) ( $group['type_ids'] ?? array() ) );
		$category_id = $this->resolve_category( $type_ids );
		if ( $category_id > 0 ) {
			$data['category_id'] = $category_id;
		}

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
			return array(
				'id'      => 0,
				'created' => false,
			);
		}

		$bn_id = (int) $result;
		IdMap::set( $this->source, 'space', $source_id, $bn_id );

		return array(
			'id'      => $bn_id,
			'created' => true,
		);
	}

	/**
	 * Import one group membership row into a mapped space. The space owner (the
	 * group creator) is auto-added by create(), so the caller skips that row.
	 *
	 * @param int                 $bn_space_id Mapped BuddyNext space id.
	 * @param int                 $owner_id    Space owner (acts for role changes).
	 * @param array<string,mixed> $member      Source membership row.
	 * @return bool Whether a membership row was CREATED by this call.
	 */
	public function import_member( int $bn_space_id, int $owner_id, array $member ): bool {
		$user_id = (int) $member['user_id'];

		if ( $user_id <= 0 || $user_id === $owner_id ) {
			return false;
		}

		// Membership rows carry no id-map entry - the space itself is the mapped
		// object - so the target's own membership status is what tells a re-run
		// this member already landed. Without this every re-run re-counted the
		// whole roster as newly imported.
		if ( null !== $this->members()->get_status( $bn_space_id, $user_id ) ) {
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
