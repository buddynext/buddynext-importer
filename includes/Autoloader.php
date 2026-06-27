<?php
/**
 * Hand-written PSR-4 autoloader. The plugin ships deps-complete and never runs
 * Composer at runtime (same convention as BuddyNext core).
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the BuddyNextImporter\ namespace to the includes/ directory.
 */
final class Autoloader {

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	private static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$path     = BUDDYNEXT_IMPORTER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
