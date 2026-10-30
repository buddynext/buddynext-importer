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
	 * Background jobs that reach a person: notification and email fan-out,
	 * push, webhooks. Not queued during an import, because they would run after
	 * it, outside import mode, and tell members about history. Search indexing,
	 * link previews and media processing are not on this list and still run.
	 *
	 * @var string[]
	 */
	private const OUTBOUND_JOBS = array(
		'buddynext_send_notification_email',
		'buddynext_retry_notification_email',
		'buddynext_async_space_post_fanout',
		'buddynext_async_space_post_emails',
		'buddynext_async_announcement_fanout',
		'buddynext_async_send_invite_email',
		'bn_onboarding_nudge_24h',
		'bn_onboarding_nudge_72h',
		'buddynext_webhook_deliver',
		'buddynext_webhook_deliver_one',
		'buddynextpro_push_send',
		'jetonomy_fanout_post_subscribers',
		'jetonomy_fanout_reply_subscribers',
		'mvs_deliver_webhook',
	);

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
	 * - pre_wp_mail, plus the Action Scheduler and WP-Cron pre-schedule filters:
	 *   email and the OUTBOUND_JOBS (fan-out, push, webhooks) are paused for the
	 *   run, so a replay of history never emails or pings a member.
	 */
	public static function register(): void {
		// Email is paused while an import runs: one WordPress filter, so no
		// plugin's mail leaves the site, whatever triggered it. false, not true:
		// the mail was not sent, and an email log should say so.
		add_filter(
			'pre_wp_mail',
			static function ( $pre ) {
				return self::$active ? false : $pre;
			},
			PHP_INT_MAX
		);

		// And the queued jobs that would reach a person later. Refusing the job
		// is safe: BuddyNext drops it, and Jetonomy falls back to its inline
		// fan-out, which the notification veto below already silences.
		$refuse_job = static function ( $pre, $hook ) {
			return self::$active && in_array( $hook, self::OUTBOUND_JOBS, true ) ? 0 : $pre;
		};
		add_filter( 'pre_as_enqueue_async_action', $refuse_job, PHP_INT_MAX, 2 );
		add_filter(
			'pre_as_schedule_single_action',
			static function ( $pre, $timestamp, $hook ) use ( $refuse_job ) {
				unset( $timestamp );
				return $refuse_job( $pre, $hook );
			},
			PHP_INT_MAX,
			3
		);
		add_filter(
			'pre_schedule_event',
			static function ( $pre, $event ) {
				return self::$active && is_object( $event ) && in_array( $event->hook ?? '', self::OUTBOUND_JOBS, true ) ? false : $pre;
			},
			PHP_INT_MAX,
			2
		);

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

		// Blog comment mirroring off, through BuddyNext's own filter. BuddyNext
		// keeps a post's comments and its article card's comments in step, which
		// is right for a live conversation and wrong for a replay of one: the
		// importer writes a post's EXISTING comments onto its card, and with
		// mirroring live every one of them would be written straight back onto
		// the post that already has them, doubling the thread.
		add_filter( 'buddynext_sync_blog_comments', $veto );

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

		// BuddyNext throttles comments to buddynext_comment_rate_limit per user
		// per two minutes (default 30). A bulk replay trips that by definition -
		// it is the same "rate limits exist to stop live abuse" case as the DM
		// limits above - and CommentService reads the option directly rather than
		// through a filter of its own, so it is lifted through WordPress's option
		// filters. The value 0 is CommentService's own documented "disabled".
		//
		// This one cost real rows before it was found: 49 comments were refused
		// mid-run as rate_limited, and because a refusal is not written to the
		// id-map they simply were not there afterwards. A second pass imported
		// every one of them, which is what proved the loss was the throttle and
		// not the data.
		$no_comment_throttle = static function ( $limit ) {
			return self::$active ? 0 : $limit;
		};

		add_filter( 'option_buddynext_comment_rate_limit', $no_comment_throttle, PHP_INT_MAX );
		add_filter( 'default_option_buddynext_comment_rate_limit', $no_comment_throttle, PHP_INT_MAX );

		// Follow + connection privacy lifts, via BuddyNext's OWN public filters.
		// A follow or friendship EXISTED at the source, so it is historical data
		// we re-add during the replay; the member's who_can_follow /
		// who_can_connect preference governs FUTURE actions, not the migration of
		// a relationship that already happened. This mirrors the DM gate lift
		// above (a thread that existed is replayed).
		//
		// A real BLOCK still refuses: PrivacyService checks bn_blocks BEFORE
		// these filters (can_follow / can_connect early-return false on a block,
		// and FollowService::follow() carries its own block guard), so lifting the
		// preference here can never re-create a relationship across a block. Rows
		// a block refuses are counted as reason-coded skips, never silent.
		$allow = static function ( $allowed ) {
			return self::$active ? true : $allowed;
		};

		add_filter( 'buddynext_can_follow', $allow, PHP_INT_MAX );
		add_filter( 'buddynext_can_connect', $allow, PHP_INT_MAX );

		// The post throttle (buddynext_post_rate_limit per author per minute,
		// SafeguardService::check_rate_limit()) is the post twin of the comment
		// throttle above. It counts bn_posts by created_at, and an imported post
		// keeps its source date, so it only bites on source activity from the
		// last minute - exactly what a delta/cutover run imports. A real cutover
		// had 137 of 210 recent posts refused as rate_limited. BuddyNext's own
		// exemption filter lifts it; the other safeguards (banned words, blocked
		// domains) still run.
		add_filter( 'buddynext_rate_limit_exempt', $allow, PHP_INT_MAX );

		// The follow cap (default 5,000) bounds a member's following set for
		// feed performance - a today's-limit guard, not a privacy choice. Source
		// history can legitimately exceed it, so it is lifted for the replay like
		// the DM rate limits; new follows after migration are capped again.
		add_filter( 'buddynext_max_following', $unlimited, PHP_INT_MAX );

		// SpacePostGuard refuses a new space post from anyone who is not an
		// active member, before its own buddynext_space_can_post filter runs.
		// A source post written by a member who is now pending, or who has
		// since left, still belongs in that space's history. The data is held
		// just before the guard and handed back just after it, only during an
		// import and only for that guard's 'forbidden' refusal of a space post.
		$held = null;
		add_filter(
			'buddynext_post_before_save',
			static function ( $data, $user_id, $post_id ) use ( &$held ) {
				unset( $user_id );
				$held = ( self::$active && null === $post_id && is_array( $data ) && (int) ( $data['space_id'] ?? 0 ) > 0 ) ? $data : null;
				return $data;
			},
			9,
			3
		);
		add_filter(
			'buddynext_post_before_save',
			static function ( $data ) use ( &$held ) {
				$restore = $held;
				$held    = null;
				if ( null !== $restore && is_wp_error( $data ) && 'forbidden' === $data->get_error_code() ) {
					return $restore;
				}
				return $data;
			},
			11
		);
	}
}
