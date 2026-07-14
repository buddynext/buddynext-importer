<?php
/**
 * Owner-driven profile field mapping.
 *
 * The source platform's field set is arbitrary - the owner named those groups
 * and fields, and a fresh BuddyNext install carries only its system fields. So
 * the importer cannot guess a one-true mapping. Instead it builds a suggested
 * plan (each source field -> an existing BuddyNext field, or "create new"; each
 * source group -> an existing group, or "create new"), lets the owner edit it,
 * and applies it by PRE-SEEDING the id-map before the schema import runs. Fields
 * mapped to an existing BuddyNext field reuse it (so real bios land in the
 * canonical `bio` field the UI reads); everything else is created as today.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Mapping;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Source\SourceAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Builds, stores, and applies the source -> BuddyNext profile field mapping.
 */
final class FieldMap {

	/**
	 * Sentinel target meaning "create a new BuddyNext field/group for this".
	 */
	public const NEW = '__new__';

	/**
	 * Option name; the stored map is keyed by source key.
	 */
	private const OPTION = 'buddynext_importer_field_map';

	/**
	 * Canonical BuddyNext field_key => normalized source-name synonyms. Drives
	 * the suggested default so the common fields land on the right canonical
	 * field the 1.0.8 profile header / directory / search read.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const SYNONYMS = array(
		'bio'        => array( 'bio', 'aboutme', 'about', 'biography', 'description', 'summary', 'tellusaboutyourself', 'wordpressbiography', 'aboutyou' ),
		'headline'   => array( 'headline', 'tagline', 'title', 'oneliner', 'motto', 'status' ),
		'location'   => array( 'location', 'city', 'town', 'place', 'country', 'region', 'address', 'wherearyoubased', 'where' ),
		'interests'  => array( 'interests', 'interest', 'hobbies', 'likes', 'passions' ),
		'website'    => array( 'website', 'web', 'url', 'site', 'homepage', 'blog' ),
		'birth_date' => array( 'birthdate', 'birthday', 'dob', 'dateofbirth', 'born' ),
		'pronouns'   => array( 'pronouns', 'pronoun' ),
	);

	/**
	 * BuddyNext field types whose values are a controlled vocabulary (category or
	 * member-type ids/slugs), not free text. Auto-mapping a source field onto one
	 * would silently drop values that are not part of that vocabulary, so the
	 * suggestion defaults such fields to "create new" and preserves the source
	 * values as a normal field. The owner can still map them by hand.
	 *
	 * @var array<int,string>
	 */
	private const CONTROLLED_TYPES = array( 'category_multiselect', 'member_type_multiselect', 'member_type' );

	/**
	 * Normalize a label for matching: lowercase alphanumerics only.
	 */
	private static function norm( string $label ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $label ) );
	}

	/**
	 * The BuddyNext target fields available to map onto, flattened.
	 *
	 * @return array<int,array{field_key:string,label:string,type:string,is_system:bool,group_label:string}>
	 */
	public static function bn_fields(): array {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return array();
		}
		$service = buddynext_service( 'profiles' );
		if ( ! is_object( $service ) || ! method_exists( $service, 'get_fields' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $service->get_fields() as $group ) {
			$glabel = (string) ( $group['label'] ?? '' );
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$key = (string) ( $field['field_key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$out[] = array(
					'field_key'   => $key,
					'label'       => (string) ( $field['label'] ?? $key ),
					'type'        => (string) ( $field['type'] ?? 'text' ),
					'is_system'   => ! empty( $field['is_system'] ),
					'group_label' => $glabel,
				);
			}
		}
		return $out;
	}

	/**
	 * The BuddyNext target groups available to map onto.
	 *
	 * @return array<int,array{id:int,label:string,is_system:bool}>
	 */
	public static function bn_groups(): array {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return array();
		}
		$service = buddynext_service( 'profiles' );
		if ( ! is_object( $service ) || ! method_exists( $service, 'get_fields' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $service->get_fields() as $group ) {
			$out[] = array(
				'id'        => (int) ( $group['id'] ?? 0 ),
				'label'     => (string) ( $group['label'] ?? '' ),
				'is_system' => ! empty( $group['is_system'] ),
			);
		}
		return $out;
	}

	/**
	 * Suggest a BuddyNext field_key for a source field name, or NEW.
	 *
	 * @param string                                                                              $name      Source field label.
	 * @param array<int,array{field_key:string,label:string,type:string,is_system:bool,group_label:string}> $bn_fields Target fields.
	 */
	public static function suggest_field( string $name, array $bn_fields ): string {
		$n = self::norm( $name );
		if ( '' === $n ) {
			return self::NEW;
		}

		// 1. Canonical synonym match.
		foreach ( self::SYNONYMS as $key => $words ) {
			if ( in_array( $n, array_filter( $words ), true ) ) {
				// Only suggest it if that canonical field exists AND is a free-value
				// type (never a controlled-vocabulary field - that would drop data).
				foreach ( $bn_fields as $bn ) {
					if ( $bn['field_key'] === $key && ! in_array( $bn['type'], self::CONTROLLED_TYPES, true ) ) {
						return $key;
					}
				}
			}
		}

		// 2. Exact normalized match against an existing field's key or label.
		foreach ( $bn_fields as $bn ) {
			if ( in_array( $bn['type'], self::CONTROLLED_TYPES, true ) ) {
				continue;
			}
			if ( self::norm( $bn['field_key'] ) === $n || self::norm( $bn['label'] ) === $n ) {
				return $bn['field_key'];
			}
		}

		return self::NEW;
	}

	/**
	 * Suggest a BuddyNext group id for a source group name, or NEW.
	 *
	 * @param array<int,array{id:int,label:string,is_system:bool}> $bn_groups Target groups.
	 */
	public static function suggest_group( string $name, array $bn_groups ): string {
		$n = self::norm( $name );
		foreach ( $bn_groups as $g ) {
			if ( '' !== $n && self::norm( $g['label'] ) === $n ) {
				return (string) $g['id'];
			}
		}
		return self::NEW;
	}

	/**
	 * Build the suggested plan for a source: groups + fields with default targets.
	 *
	 * @return array{groups:array<int,array{label:string,target:string}>,fields:array<int,array{label:string,type:string,group_id:int,target:string}>}
	 */
	public static function plan( SourceAdapter $adapter ): array {
		$bn_fields = self::bn_fields();
		$bn_groups = self::bn_groups();

		$groups = array();
		foreach ( $adapter->profile_groups() as $group ) {
			$sid            = (int) $group['source_id'];
			$groups[ $sid ] = array(
				'label'  => (string) $group['name'],
				'target' => self::suggest_group( (string) $group['name'], $bn_groups ),
			);
		}

		$fields = array();
		foreach ( $adapter->profile_fields() as $field ) {
			$sid            = (int) $field['source_id'];
			$fields[ $sid ] = array(
				'label'    => (string) $field['name'],
				'type'     => (string) $field['type'],
				'group_id' => (int) $field['group_id'],
				'target'   => self::suggest_field( (string) $field['name'], $bn_fields ),
			);
		}

		return array( 'groups' => $groups, 'fields' => $fields );
	}

	/**
	 * Load the saved map for a source (empty when none saved yet).
	 *
	 * @return array{groups:array<int,string>,fields:array<int,string>}
	 */
	public static function load( string $source ): array {
		$all = (array) get_option( self::OPTION, array() );
		$map = isset( $all[ $source ] ) ? (array) $all[ $source ] : array();
		return array(
			'groups' => (array) ( $map['groups'] ?? array() ),
			'fields' => (array) ( $map['fields'] ?? array() ),
		);
	}

	/**
	 * Persist the owner's map for a source. Values are target field_key/group_id
	 * or the NEW sentinel, keyed by source id.
	 *
	 * @param array{groups:array<int,string>,fields:array<int,string>} $map Mapping.
	 */
	public static function save( string $source, array $map ): void {
		$all            = (array) get_option( self::OPTION, array() );
		$all[ $source ] = array(
			'groups' => (array) ( $map['groups'] ?? array() ),
			'fields' => (array) ( $map['fields'] ?? array() ),
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Store the suggested plan as the saved map (accept-all-suggestions).
	 */
	public static function save_suggested( string $source, SourceAdapter $adapter ): void {
		$plan   = self::plan( $adapter );
		$groups = array();
		foreach ( $plan['groups'] as $sid => $row ) {
			$groups[ $sid ] = (string) $row['target'];
		}
		$fields = array();
		foreach ( $plan['fields'] as $sid => $row ) {
			$fields[ $sid ] = (string) $row['target'];
		}
		self::save( $source, array( 'groups' => $groups, 'fields' => $fields ) );
	}

	/**
	 * Apply the saved map by pre-seeding the id-map: any source group/field
	 * mapped to an EXISTING BuddyNext record is recorded so the schema import
	 * reuses it instead of creating a duplicate. Unmapped (NEW) entries are left
	 * for the importer to create. No-op when nothing is saved.
	 */
	public static function apply( string $source ): void {
		$map = self::load( $source );

		foreach ( $map['groups'] as $src_id => $target ) {
			if ( self::NEW === $target || '' === (string) $target ) {
				continue;
			}
			IdMap::set( $source, 'profile_group', (int) $src_id, (int) $target );
		}

		if ( empty( $map['fields'] ) ) {
			return;
		}

		// Resolve target field_key -> field id once.
		$key_to_id = array();
		foreach ( self::bn_fields_with_ids() as $row ) {
			$key_to_id[ $row['field_key'] ] = $row['field_id'];
		}

		foreach ( $map['fields'] as $src_id => $target ) {
			if ( self::NEW === $target || '' === (string) $target ) {
				continue;
			}
			if ( isset( $key_to_id[ $target ] ) ) {
				IdMap::set( $source, 'profile_field', (int) $src_id, (int) $key_to_id[ $target ] );
			}
		}
	}

	/**
	 * BuddyNext fields including their numeric id (for id-map seeding).
	 *
	 * @return array<int,array{field_id:int,field_key:string}>
	 */
	private static function bn_fields_with_ids(): array {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return array();
		}
		$service = buddynext_service( 'profiles' );
		if ( ! is_object( $service ) || ! method_exists( $service, 'get_fields' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $service->get_fields() as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$key = (string) ( $field['field_key'] ?? '' );
				$id  = (int) ( $field['id'] ?? $field['field_id'] ?? 0 );
				if ( '' !== $key && $id > 0 ) {
					$out[] = array( 'field_id' => $id, 'field_key' => $key );
				}
			}
		}
		return $out;
	}
}
