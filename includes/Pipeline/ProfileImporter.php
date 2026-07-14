<?php
/**
 * Orchestrates the profile domain import: schema (groups + fields) once, then
 * user values in keyset-paginated batches. Both run surfaces (CLI + REST) call
 * this, so the logic lives in one place.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

use BuddyNextImporter\Mapping\FieldMap;
use BuddyNextImporter\Source\AdapterRegistry;
use BuddyNextImporter\Source\SourceAdapter;
use BuddyNextImporter\Writer\ProfileWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Profile import coordinator.
 */
final class ProfileImporter {

	/**
	 * Source key.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Read adapter.
	 *
	 * @var SourceAdapter
	 */
	private SourceAdapter $adapter;

	/**
	 * Service-layer writer.
	 *
	 * @var ProfileWriter
	 */
	private ProfileWriter $writer;

	/**
	 * Construct the importer with a source key and its read adapter.
	 *
	 * @param string        $source  Source key.
	 * @param SourceAdapter $adapter Read adapter for that source.
	 */
	public function __construct( string $source, SourceAdapter $adapter ) {
		$this->source  = $source;
		$this->adapter = $adapter;
		$this->writer  = new ProfileWriter( $source );
	}

	/**
	 * Build an importer for a source key, or null when unavailable.
	 *
	 * @param string $source Source key.
	 */
	public static function for_source( string $source ): ?self {
		$adapter = AdapterRegistry::get( $source );
		if ( null === $adapter || ! $adapter->is_available() ) {
			return null;
		}
		return new self( $source, $adapter );
	}

	/**
	 * Import all groups and fields. Idempotent; safe to re-run.
	 *
	 * @return array{groups:int,fields:int}
	 */
	public function import_schema(): array {
		// Pre-seed the id-map from the owner's field mapping: source groups/fields
		// mapped to an existing BuddyNext record are recorded here, so the loops
		// below reuse them (real bios land in the canonical `bio` field) instead
		// of creating duplicates. Unmapped entries fall through to create-new.
		FieldMap::apply( $this->source );

		$groups = 0;
		foreach ( $this->adapter->profile_groups() as $group ) {
			$this->writer->import_group( $group );
			++$groups;
		}

		$fields = 0;
		foreach ( $this->adapter->profile_fields() as $field ) {
			$bn_group = IdMap::get( $this->source, 'profile_group', (int) $field['group_id'] );
			if ( null === $bn_group ) {
				continue;
			}
			if ( $this->writer->import_field( $field, $bn_group ) > 0 ) {
				++$fields;
			}
		}

		return array(
			'groups' => $groups,
			'fields' => $fields,
		);
	}

	/**
	 * Import one keyset batch of users' values.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array{last:int,users:int,values:int}
	 */
	public function import_values_batch( int $after, int $limit ): array {
		$user_ids = $this->adapter->profile_value_user_ids( $after, $limit );

		$users  = 0;
		$values = 0;
		$last   = $after;

		foreach ( $user_ids as $user_id ) {
			$values += $this->writer->import_user_values( $user_id, $this->adapter->profile_values( $user_id ) );
			++$users;
			$last = $user_id;
		}

		return array(
			'last'   => $last,
			'users'  => $users,
			'values' => $values,
		);
	}
}
