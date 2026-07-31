<?php
/**
 * Which parts of the source community this migration is going to bring across.
 *
 * A migration used to be all or nothing. An owner who wanted their members,
 * spaces and activity but not five years of private messages had to take the
 * messages too. This is that choice - with everything selected by default, so an
 * owner who never opens the panel gets exactly what they got before.
 *
 * Two things make the choice safe rather than a new way to lose a community:
 *
 * 1. It is a filter over StepRegistry, never a second list of domains. The
 *    phases, their contents and their dependencies are all derived from the
 *    registry, so a domain added there appears here without another edit.
 * 2. Skipping is reversible while the source is still there. Every write is
 *    idempotent through bni_id_map, so a later run with more selected tops up
 *    instead of duplicating. That stops being true the moment `cleanup` drops
 *    the id-map - which is exactly why the UI says so next to the button.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The site owner's choice of which domains to migrate.
 */
final class DomainSelection {

	/**
	 * Where the choice is persisted.
	 *
	 * An option rather than browser state, because the background runner and any
	 * resumed run have to honour the same choice as the tab that started them.
	 */
	private const OPTION = 'bni_domain_selection';

	/**
	 * The ordered phases of a migration, derived from the registry.
	 *
	 * A phase is the unit the owner chooses, not an individual step. That is a
	 * deliberate simplification and it removes the sharpest edge in this feature:
	 * posts and comments are both the `activity` phase, so "comments but not
	 * posts" - which would import every comment into nothing - cannot be
	 * expressed at all. Only three dependencies survive across a phase boundary,
	 * and each is declared on its own step.
	 *
	 * @param string $source Source key.
	 * @return array<int,array{key:string,label:string,steps:string[],depends:string[],available:bool}>
	 */
	public static function phases( string $source ): array {
		$phases = array();

		foreach ( StepRegistry::steps( $source ) as $step ) {
			$key = (string) $step['phase'];

			if ( ! isset( $phases[ $key ] ) ) {
				$phases[ $key ] = array(
					'key'       => $key,
					'label'     => self::label( $key ),
					'steps'     => array(),
					'depends'   => array(),
					'available' => false,
				);
			}

			$phases[ $key ]['steps'][] = (string) $step['label'];

			// A phase requires whatever any of its steps requires, minus itself -
			// posts declaring `spaces` is a real dependency; a phase depending on
			// itself would just be noise in the UI.
			foreach ( (array) ( $step['depends'] ?? array() ) as $needs ) {
				if ( (string) $needs !== $key && ! in_array( (string) $needs, $phases[ $key ]['depends'], true ) ) {
					$phases[ $key ]['depends'][] = (string) $needs;
				}
			}

			// Available if ANY of its steps can run here. A phase whose target
			// plugin is missing stays off and says why, as it already did.
			if ( ( $step['available'] )() ) {
				$phases[ $key ]['available'] = true;
			}
		}

		return array_values( $phases );
	}

	/**
	 * Human, translatable name for a phase.
	 *
	 * Kept beside the registry rather than derived from the key, because a key is
	 * not translatable. An unknown key falls back to a readable form of itself so
	 * a newly added phase is never blank in the UI.
	 *
	 * @param string $phase Phase key.
	 */
	private static function label( string $phase ): string {
		$labels = array(
			'profiles'         => __( 'Profiles', 'buddynext-importer' ),
			'member_types'     => __( 'Member types', 'buddynext-importer' ),
			'space_categories' => __( 'Space categories', 'buddynext-importer' ),
			'spaces'           => __( 'Spaces and members', 'buddynext-importer' ),
			'activity'         => __( 'Activity', 'buddynext-importer' ),
			'friends'          => __( 'Connections', 'buddynext-importer' ),
			'follows'          => __( 'Follows', 'buddynext-importer' ),
			'reactions'        => __( 'Reactions', 'buddynext-importer' ),
			'forums'           => __( 'Forums', 'buddynext-importer' ),
			'images'           => __( 'Avatars and covers', 'buddynext-importer' ),
			'media'            => __( 'Photos and albums', 'buddynext-importer' ),
			'messages'         => __( 'Private messages', 'buddynext-importer' ),
		);

		return $labels[ $phase ] ?? ucfirst( str_replace( '_', ' ', $phase ) );
	}

	/**
	 * Every phase key for a source, in registry order.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	public static function all_keys( string $source ): array {
		return array_column( self::phases( $source ), 'key' );
	}

	/**
	 * The phases this migration will run.
	 *
	 * Defaults to everything: an owner who never touches the panel, and every
	 * site that upgraded into this feature mid-migration, keep the previous
	 * all-or-nothing behaviour.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	public static function get( string $source ): array {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return self::all_keys( $source );
		}

		// Intersect with the registry rather than trusting what was stored: a
		// stored key for a phase that no longer exists must not resurrect it.
		$stored   = array_map( 'strval', $stored );
		$known    = self::all_keys( $source );
		$selected = array_values( array_intersect( $known, $stored ) );

		// The stored value records what the owner KEPT, so a missing key is
		// ambiguous - it means either "turned this off" or "this did not exist
		// when I chose". The offered list separates the two, and anything the
		// owner was never shown defaults to ON: they never declined it.
		$offered = get_option( self::OPTION . '_offered', null );
		$offered = is_array( $offered ) ? array_map( 'strval', $offered ) : $known;

		foreach ( $known as $key ) {
			if ( ! in_array( $key, $stored, true ) && ! in_array( $key, $offered, true ) ) {
				$selected[] = $key;
			}
		}

		return self::resolve( $selected, $source );
	}

	/**
	 * Persist a choice, after pulling in whatever it depends on.
	 *
	 * @param string[] $keys   Phases the owner selected.
	 * @param string   $source Source key.
	 * @return string[] The resolved selection actually stored.
	 */
	public static function save( array $keys, string $source ): array {
		$resolved = self::resolve( $keys, $source );

		update_option( self::OPTION, $resolved, false );
		// Remember everything that was on screen, so a phase added in a later
		// release is treated as "never asked about" rather than "declined".
		update_option( self::OPTION . '_offered', self::all_keys( $source ), false );

		return $resolved;
	}

	/**
	 * Close a selection over its dependencies.
	 *
	 * Selecting a child pulls in its parents, transitively. The alternative -
	 * letting the run proceed and drop what it cannot resolve - is the silent
	 * content loss this importer exists to avoid.
	 *
	 * @param string[] $keys   Chosen phases.
	 * @param string   $source Source key.
	 * @return string[] Selection in registry order.
	 */
	public static function resolve( array $keys, string $source ): array {
		$phases   = array_column( self::phases( $source ), null, 'key' );
		$selected = array();

		foreach ( array_map( 'strval', $keys ) as $key ) {
			self::pull_in( $key, $phases, $selected );
		}

		// Registry order, not the order they were ticked - the pipeline depends on
		// it and so does the progress display.
		return array_values( array_filter( array_keys( $phases ), static fn ( $k ): bool => in_array( $k, $selected, true ) ) );
	}

	/**
	 * Add a phase and everything it needs.
	 *
	 * @param string                     $key      Phase to add.
	 * @param array<string,array<mixed>> $phases  Phase map.
	 * @param string[]                   $selected Accumulator, by reference.
	 */
	private static function pull_in( string $key, array $phases, array &$selected ): void {
		if ( ! isset( $phases[ $key ] ) || in_array( $key, $selected, true ) ) {
			return;
		}

		$selected[] = $key;

		foreach ( (array) $phases[ $key ]['depends'] as $needs ) {
			self::pull_in( (string) $needs, $phases, $selected );
		}
	}

	/**
	 * Is this phase part of the current migration?
	 *
	 * @param string $phase  Phase key.
	 * @param string $source Source key.
	 */
	public static function is_selected( string $phase, string $source ): bool {
		return in_array( $phase, self::get( $source ), true );
	}

	/**
	 * The registry steps this migration will actually run.
	 *
	 * @param string        $source    Source key.
	 * @param string[]|null $selection Explicit selection, or null for the stored one.
	 * @return array<int,array<string,mixed>>
	 */
	public static function steps( string $source, ?array $selection = null ): array {
		$selected = null === $selection ? self::get( $source ) : self::resolve( $selection, $source );

		return array_values(
			array_filter(
				StepRegistry::steps( $source ),
				static fn ( array $step ): bool => in_array( (string) $step['phase'], $selected, true )
			)
		);
	}

	/**
	 * Phases the owner chose to leave behind.
	 *
	 * The report needs these by name: a domain skipped on purpose has to read as
	 * a decision, never as a shortfall, or every selective migration looks like a
	 * broken one.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	public static function skipped( string $source ): array {
		return array_values( array_diff( self::all_keys( $source ), self::get( $source ) ) );
	}

	/**
	 * Record what a run actually carried.
	 *
	 * The verification screen has to describe the RUN, not the checkboxes as they
	 * stand now. Those are two different things the moment anyone uses
	 * `--only` / `--skip`, which are per-invocation and deliberately do not
	 * rewrite the owner's stored choice - and they drift again if the owner
	 * re-ticks something after a run. Reading the live selection instead made a
	 * CLI migration that correctly skipped messages report them as an
	 * unexplained shortfall, which is precisely the confusion this feature has to
	 * avoid.
	 *
	 * @param string[] $domains Phases the run carried.
	 */
	public static function record_run( array $domains ): void {
		update_option( self::OPTION . '_last_run', array_values( array_map( 'strval', $domains ) ), false );
	}

	/**
	 * What the last run carried, for reporting.
	 *
	 * Falls back to the current selection, which is correct for the in-browser
	 * run loop: it drives itself from exactly that list.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	public static function last_run( string $source ): array {
		$recorded = get_option( self::OPTION . '_last_run', null );

		return is_array( $recorded ) ? array_map( 'strval', $recorded ) : self::get( $source );
	}

	/**
	 * Forget the choice, returning the site to "import everything".
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
		delete_option( self::OPTION . '_offered' );
		delete_option( self::OPTION . '_last_run' );
	}
}
