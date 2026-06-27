<?php
/**
 * Resolves source keys to adapter instances and detects the active source.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source;

use BuddyNextImporter\Source\BuddyBoss\BuddyBossAdapter;
use BuddyNextImporter\Source\BuddyPress\BuddyPressAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Central place to add a new source adapter (v2: FluentCommunity, PeepSo, UM).
 */
final class AdapterRegistry {

	/**
	 * All registered adapters, keyed by source key.
	 *
	 * @return array<string,SourceAdapter>
	 */
	public static function all(): array {
		$adapters = array(
			'buddyboss'  => new BuddyBossAdapter(),
			'buddypress' => new BuddyPressAdapter(),
		);

		/**
		 * Filter the registered source adapters.
		 *
		 * @param array<string,SourceAdapter> $adapters Adapters keyed by source key.
		 */
		return apply_filters( 'buddynext_importer_source_adapters', $adapters );
	}

	/**
	 * Get one adapter by key.
	 *
	 * @param string $key Source key.
	 */
	public static function get( string $key ): ?SourceAdapter {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Adapters whose data is present on this site.
	 *
	 * @return array<string,SourceAdapter>
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static function ( SourceAdapter $adapter ): bool {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Best-guess active source: BuddyBoss wins over BuddyPress because it is a
	 * superset sharing the same bp_* tables.
	 */
	public static function detect_active_key(): ?string {
		$buddyboss = self::get( 'buddyboss' );
		if ( $buddyboss instanceof SourceAdapter && $buddyboss->is_available() ) {
			return 'buddyboss';
		}

		$buddypress = self::get( 'buddypress' );
		if ( $buddypress instanceof SourceAdapter && $buddypress->is_available() ) {
			return 'buddypress';
		}

		return null;
	}
}
