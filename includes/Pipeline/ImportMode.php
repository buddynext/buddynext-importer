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
	 * - jetonomy_notification_should_send: Jetonomy's mirror of the same veto
	 *   (jetonomy >= 1.8.1). One filter silences BOTH its notification rows
	 *   (Notification::create()) and its emails (Notifier::should_email()), and
	 *   makes Mentions::notify() bail before scanning imported forum content —
	 *   without it every imported topic/reply fanned out subscriber + mention
	 *   notifications and per-recipient EMAILS.
	 *
	 * WPMediaVerse (DMs) needs no filter of its own here: when BuddyNext is
	 * active (this plugin requires it), MVS's NotificationListener defers all
	 * DM notification routing to BuddyNext (mvs_buddynext_active), and that
	 * BuddyNext path is already killed by the veto above — emails and push
	 * included, since both hang off buddynext_notification_created.
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

		$veto = static function ( $should_send ) {
			return self::$active ? false : $should_send;
		};

		add_filter( 'buddynext_notification_should_send', $veto );
		add_filter( 'jetonomy_notification_should_send', $veto );

		// WPMediaVerse DM gate lifts, via MVS's OWN public filters (no MVS code
		// change). A source thread already existed - today's rate limits, DM
		// access levels, and cross-plugin can-send vetoes (BuddyNext hooks
		// mvs_can_send_message to enforce bn_blocks) must not silently drop its
		// history during a replay. MVS's internal hard-block check sits above
		// the filter and still refuses; those threads are counted as skips.
		// PHP_INT_MAX priority so the lift wins over every enforcing listener.
		add_filter(
			'mvs_can_send_message',
			static function ( $allowed ) {
				return self::$active ? true : $allowed;
			},
			PHP_INT_MAX
		);

		add_filter(
			'mvs_dm_access_level',
			static function ( $access ) {
				return self::$active ? 'everyone' : $access;
			},
			PHP_INT_MAX
		);

		$unlimited = static function ( $limit ) {
			return self::$active ? PHP_INT_MAX : $limit;
		};

		add_filter( 'mvs_dm_message_rate_limit', $unlimited, PHP_INT_MAX );
		add_filter( 'mvs_dm_convo_rate_limit', $unlimited, PHP_INT_MAX );

		// MVS caps a NEW message at 2,000 characters (MAX_MESSAGE_LENGTH) and
		// refuses anything longer outright. Source history predates that rule and
		// legitimately contains longer messages, so the cap is lifted for the
		// replay — otherwise every long message in the archive is dropped with no
		// way for the member to ever see it again.
		add_filter( 'mvs_message_max_length', $unlimited, PHP_INT_MAX );
	}
}
