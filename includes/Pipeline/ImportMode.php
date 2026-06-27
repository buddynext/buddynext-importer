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
	 * Register the suppression hooks. Called once at boot.
	 *
	 * - buddynext_is_importing: public bridge any listener can read.
	 * - buddynext_notification_should_send: BuddyNext's own veto filter on
	 *   NotificationService::create(). Returning false makes create() return 0,
	 *   which suppresses the in-app notification AND, because EmailDispatchListener
	 *   and the Pro push dispatcher hang off buddynext_notification_created (never
	 *   fired), their email + realtime fan-out too. This kills the dominant
	 *   per-recipient fan-out for every imported post, comment, join, and follow
	 *   while leaving the search index, hashtags, and cache busts intact.
	 *
	 * Outbound webhooks are not gated here: dispatch() no-ops unless the site has
	 * registered an endpoint, and even then it only schedules cron rather than
	 * making synchronous HTTP. Sites with active outbound webhooks should disable
	 * them for the duration of a large import.
	 */
	public static function register(): void {
		add_filter(
			'buddynext_is_importing',
			static function ( $importing ) {
				return self::$active ? true : $importing;
			}
		);

		add_filter(
			'buddynext_notification_should_send',
			static function ( $should_send ) {
				return self::$active ? false : $should_send;
			}
		);
	}
}
