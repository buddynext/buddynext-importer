<?php
/**
 * WP-CLI surface for the profile field mapping: wp buddynext-import map <sub>.
 *
 * The admin screen is the primary way to edit the mapping; these commands let a
 * CLI-driven migration preview the suggested plan and accept it. The migration
 * reads the same saved map, so `map auto` then `migrate-profiles` maps the
 * common fields onto BuddyNext's canonical fields automatically.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\CLI;

use BuddyNextImporter\Mapping\FieldMap;
use BuddyNextImporter\Source\AdapterRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Preview and accept the source -> BuddyNext profile field mapping.
 */
final class MapCommand {

	/**
	 * Show the suggested mapping (no changes saved).
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import map preview
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function preview( array $args, array $assoc_args ): void {
		$adapter = $this->adapter( $assoc_args );
		$plan    = FieldMap::plan( $adapter );

		$rows = array();
		foreach ( $plan['fields'] as $sid => $row ) {
			$rows[] = array(
				'source field' => $row['label'],
				'type'         => $row['type'],
				'-> target'    => FieldMap::NEW === $row['target'] ? '(create new field)' : $row['target'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'source field', 'type', '-> target' ) );
	}

	/**
	 * Accept the suggested mapping and save it, so the migration uses it.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import map auto
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function auto( array $args, array $assoc_args ): void {
		$source  = $this->source_key( $assoc_args );
		$adapter = $this->adapter( $assoc_args );
		FieldMap::save_suggested( $source, $adapter );

		$map    = FieldMap::load( $source );
		$mapped = count( array_filter( $map['fields'], static fn( $t ) => FieldMap::NEW !== $t ) );
		$new    = count( $map['fields'] ) - $mapped;
		\WP_CLI::success( sprintf( 'Saved mapping for %s: %d field(s) mapped to existing, %d to create new.', $source, $mapped, $new ) );
	}

	/**
	 * Resolve the source key from args or detection.
	 *
	 * @param array<string,string> $assoc_args Associative args.
	 */
	private function source_key( array $assoc_args ): string {
		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();
		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}
		return $source;
	}

	/**
	 * Resolve the active adapter or error.
	 *
	 * @param array<string,string> $assoc_args Associative args.
	 * @return \BuddyNextImporter\Source\SourceAdapter
	 */
	private function adapter( array $assoc_args ) {
		$adapter = AdapterRegistry::get( $this->source_key( $assoc_args ) );
		if ( null === $adapter || ! $adapter->is_available() ) {
			\WP_CLI::error( 'The selected source is not available on this site.' );
		}
		return $adapter;
	}
}
