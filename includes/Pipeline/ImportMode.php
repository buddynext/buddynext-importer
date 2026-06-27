<?php
/**
 * Import mode: a request-scoped switch that suppresses BuddyNext side effects
 * for the duration of an import run.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * While active, BuddyNext listeners (notifications, emails, webhooks, realtime
 * push, per-row recounts) should early-return for imported content so a large
 * import never fans out a notification per row. Listeners gate on
 * ImportMode::is_active() or the buddynext_is_importing filter; the bulk
 * recount runs once at the end of the import instead.
 */
final class ImportMode {

	/**
	 * Whether import mode is currently active.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Whether import mode is on.
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Turn import mode on.
	 */
	public static function activate(): void {
		if ( self::$active ) {
			return;
		}
		self::$active = true;
		do_action( 'buddynext_importer_mode_activated' );
	}

	/**
	 * Turn import mode off.
	 */
	public static function deactivate(): void {
		if ( ! self::$active ) {
			return;
		}
		self::$active = false;
		do_action( 'buddynext_importer_mode_deactivated' );
	}

	/**
	 * Run a callback with import mode active, restoring the previous state after.
	 *
	 * @param callable $callback Work to run inside import mode.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		$was_active = self::$active;
		self::activate();

		try {
			return $callback();
		} finally {
			if ( ! $was_active ) {
				self::deactivate();
			}
		}
	}

	/**
	 * Register the public bridge filter so BuddyNext (or any listener) can read
	 * the state via apply_filters( 'buddynext_is_importing', false ).
	 */
	public static function register(): void {
		add_filter(
			'buddynext_is_importing',
			static function ( $importing ) {
				return self::$active ? true : $importing;
			}
		);
	}
}
