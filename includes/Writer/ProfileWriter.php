<?php
/**
 * Writes profile groups, fields, and user values into BuddyNext THROUGH ITS
 * SERVICE API only (buddynext_service( 'profiles' )). It never touches bn_*
 * tables directly: BuddyNext owns those writes, so counters, the search index,
 * and validation all run correctly.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;
use BuddyNextImporter\Source\FieldTypeMap;
use BuddyNextImporter\Source\PrivacyMap;

defined( 'ABSPATH' ) || exit;

/**
 * Service-layer writer for the profile domain.
 */
final class ProfileWriter {

	/**
	 * Source key (buddypress|buddyboss), used for id-map scoping.
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
	 * Resolve the BuddyNext ProfileService via the DI container.
	 *
	 * This is the single API entry point; all writes go through its methods.
	 *
	 * @return object The BuddyNext ProfileService.
	 */
	private function service(): object {
		return buddynext_service( 'profiles' );
	}

	/**
	 * Import one source group. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $group Source group record.
	 * @return int The BuddyNext group id.
	 */
	public function import_group( array $group ): int {
		$source_id = (int) $group['source_id'];

		$existing = IdMap::get( $this->source, 'profile_group', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$bn_id = (int) $this->service()->create_group(
			array(
				'group_key'  => 'bp_' . $source_id . '_' . sanitize_key( (string) $group['name'] ),
				'label'      => (string) $group['name'],
				'type'       => 'flat',
				'visibility' => 'public',
				'sort_order' => (int) $group['sort_order'],
			)
		);

		IdMap::set( $this->source, 'profile_group', $source_id, $bn_id );

		return $bn_id;
	}

	/**
	 * Import one source field under a mapped BuddyNext group. Skips synced /
	 * assignment types. Idempotent via the id-map.
	 *
	 * @param array<string,mixed> $field       Source field record.
	 * @param int                 $bn_group_id Mapped BuddyNext group id.
	 * @return int The BuddyNext field id, or 0 when skipped.
	 */
	public function import_field( array $field, int $bn_group_id ): int {
		$type = (string) $field['type'];
		if ( FieldTypeMap::is_skipped( $type ) ) {
			return 0;
		}

		$source_id = (int) $field['source_id'];

		$existing = IdMap::get( $this->source, 'profile_field', $source_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$data = array(
			'group_id'    => $bn_group_id,
			'field_key'   => $this->field_key( $source_id, (string) $field['name'] ),
			'label'       => (string) $field['name'],
			'type'        => FieldTypeMap::to_bn_type( $type ),
			'is_required' => (int) $field['is_required'],
			'sort_order'  => (int) $field['sort_order'],
			'visibility'  => PrivacyMap::field_visibility( (string) ( $field['visibility'] ?? 'public' ) ),
		);

		if ( FieldTypeMap::has_options( $type ) && ! empty( $field['options'] ) ) {
			$data['options'] = array_map( 'strval', (array) $field['options'] );
		}

		$bn_id = (int) $this->service()->create_field( $data );

		IdMap::set( $this->source, 'profile_field', $source_id, $bn_id );

		return $bn_id;
	}

	/**
	 * Import one user's profile values via save_profile(). Side effects are
	 * suppressed for the call. Returns the number of fields written.
	 *
	 * @param int                            $user_id User id.
	 * @param array<int,array<string,mixed>> $values  Source value rows.
	 */
	public function import_user_values( int $user_id, array $values ): int {
		$payload = array();

		foreach ( $values as $row ) {
			$type = (string) $row['type'];
			if ( FieldTypeMap::is_skipped( $type ) ) {
				continue;
			}

			$source_field_id = (int) $row['field_id'];
			// Only write values for fields we actually imported.
			if ( ! IdMap::has( $this->source, 'profile_field', $source_field_id ) ) {
				continue;
			}

			$field_key = $this->field_key( $source_field_id, (string) $row['name'] );
			$value     = $this->normalize_value( $type, (string) $row['value'] );

			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( '' === $value ) {
				continue;
			}

			$payload[ $field_key ] = $value;
		}

		if ( empty( $payload ) ) {
			return 0;
		}

		ImportMode::run(
			function () use ( $user_id, $payload ): void {
				$this->service()->save_profile( $user_id, $payload );
			}
		);

		return count( $payload );
	}

	/**
	 * Deterministic BuddyNext field key for a source field. Pure function of
	 * (source id, name) so it reconstructs identically at value-import time and
	 * stays unique even when two source fields share a name.
	 *
	 * @param int    $source_id Source field id.
	 * @param string $name      Source field name.
	 */
	private function field_key( int $source_id, string $name ): string {
		return 'bp_' . $source_id . '_' . sanitize_key( $name );
	}

	/**
	 * Convert a stored source value to the shape save_profile() expects: an array
	 * of option strings for multi-value types, a Y-m-d string for dates, the raw
	 * string otherwise.
	 *
	 * @param string $source_type Source field type.
	 * @param string $value       Stored source value.
	 * @return array<int,string>|string
	 */
	private function normalize_value( string $source_type, string $value ) {
		// BuddyBoss social-networks: an associative network => URL map -> readable lines.
		if ( 'social-networks' === $source_type ) {
			$decoded = maybe_unserialize( $value );
			if ( ! is_array( $decoded ) ) {
				return (string) $decoded;
			}
			$lines = array();
			foreach ( $decoded as $network => $url ) {
				$url = trim( (string) $url );
				if ( '' === $url ) {
					continue;
				}
				$lines[] = is_string( $network ) && '' !== $network
					? ucfirst( $network ) . ': ' . $url
					: $url;
			}
			return implode( "\n", $lines );
		}

		if ( FieldTypeMap::is_multi( $source_type ) ) {
			$decoded = maybe_unserialize( $value );
			return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array( (string) $decoded );
		}

		if ( 'date' === FieldTypeMap::to_bn_type( $source_type ) && '' !== $value ) {
			// BuddyPress datebox stores 'Y-m-d 00:00:00'.
			return substr( $value, 0, 10 );
		}

		return $value;
	}
}
