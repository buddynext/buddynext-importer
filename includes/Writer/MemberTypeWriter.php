<?php
/**
 * Writes source member types and their user assignments into BuddyNext THROUGH
 * ITS SERVICE API only (buddynext_service( 'member_types' )). It never touches
 * bn_member_types / bn_member_type_assignments directly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the member-types domain.
 *
 * Member types are the community's public classification of a member (Student,
 * Teacher, VIP...). They are NOT profile-field data at the source either: both
 * BuddyPress and BuddyBoss store the assignment as a `bp_member_type` term on
 * the user object, which is why importing xprofile alone leaves every member
 * untyped.
 *
 * A BuddyNext member type is single and set-once per member, so a source user
 * carrying several types keeps the first and the rest are reported as skips
 * rather than silently overwriting each other.
 */
final class MemberTypeWriter {

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
	 * Whether BuddyNext's member-type service is reachable.
	 */
	public static function available(): bool {
		return null !== self::service();
	}

	/**
	 * Resolve the BuddyNext MemberTypeService via the DI container.
	 */
	private static function service(): ?object {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return null;
		}

		$service = buddynext_service( 'member_types' );

		return is_object( $service ) && method_exists( $service, 'assign_type' ) ? $service : null;
	}

	/**
	 * Import one source member type. Idempotent: an existing BuddyNext type with
	 * the same slug is adopted rather than duplicated, so re-running never forks
	 * the vocabulary and a site that already defined "Student" keeps its own
	 * colour, icon, and description.
	 *
	 * @param array<string,mixed> $type Source type record (slug, name, description).
	 * @return int BuddyNext member-type id (0 on failure).
	 */
	public function import_type( array $type ): int {
		$service = self::service();
		if ( null === $service ) {
			return 0;
		}

		$slug = sanitize_key( (string) $type['slug'] );
		if ( '' === $slug ) {
			return 0;
		}

		$existing = $service->get_by_slug( $slug );
		if ( is_array( $existing ) && isset( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		$name = trim( (string) ( $type['name'] ?? '' ) );

		$created = ImportMode::run(
			fn() => $service->create(
				array(
					'slug'        => $slug,
					'name'        => '' !== $name ? $name : $slug,
					'description' => (string) ( $type['description'] ?? '' ),
				)
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Assign one source user their member type.
	 *
	 * @param int    $user_id Target user id (same-site migration: ids are shared).
	 * @param string $slug    Source member-type slug.
	 * @return string Empty string when assigned, otherwise the skip reason.
	 */
	public function import_assignment( int $user_id, string $slug ): string {
		$service = self::service();
		if ( null === $service ) {
			return 'no_target';
		}

		$slug = sanitize_key( $slug );
		if ( $user_id <= 0 || '' === $slug ) {
			return 'invalid_row';
		}

		if ( IdMap::has( $this->source, 'member_type_user', $user_id ) ) {
			return 'already_imported';
		}

		if ( ! get_userdata( $user_id ) ) {
			return 'user_missing';
		}

		$type = $service->get_by_slug( $slug );
		if ( ! is_array( $type ) || empty( $type['id'] ) ) {
			return 'type_not_imported';
		}

		$type_id = (int) $type['id'];

		$assigned = ImportMode::run( fn() => $service->assign_type( $user_id, $type_id ) );

		if ( is_wp_error( $assigned ) ) {
			return sanitize_key( (string) $assigned->get_error_code() );
		}

		if ( true !== $assigned ) {
			return 'assign_refused';
		}

		IdMap::set( $this->source, 'member_type_user', $user_id, $type_id );

		return '';
	}
}
