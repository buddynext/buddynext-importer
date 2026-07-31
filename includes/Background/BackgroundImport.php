<?php
/**
 * Server-side background import: runs the whole migration from Action Scheduler
 * so a site owner can start it in the admin and close the tab.
 *
 * The admin page can already run an import from the browser, one /step call per
 * batch - but that keeps the tab open for the whole run. On a 10k-member site
 * that is not realistic, so this ticks through the SAME domains server-side.
 *
 * Each tick processes as many keyset batches as fit in a short time budget, then
 * reschedules itself only while work remains (never a recurring idle poll). All
 * progress lives in the per-domain resume checkpoint {@see Checkpoint}, so a tick
 * always continues where the last one stopped, and the id-map keeps every write
 * idempotent - a duplicated or retried tick can never double-import a row.
 *
 * Action Scheduler ships inside BuddyNext (which must be active to import at
 * all), so it is always available here; WP-Cron is kept as a defensive fallback.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Background;

use BuddyNextImporter\Pipeline\Checkpoint;
use BuddyNextImporter\Pipeline\DomainSelection;
use BuddyNextImporter\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Drives an unattended import via Action Scheduler (WP-Cron fallback).
 */
final class BackgroundImport {

	/**
	 * The recurring-per-run action hook this listens on.
	 */
	private const HOOK = 'buddynext_importer_bg_tick';

	/**
	 * Supervisor hook. Runs on its own recurring schedule while a job is live.
	 */
	private const WATCHDOG_HOOK = 'buddynext_importer_bg_watchdog';

	/**
	 * How often the supervisor looks in, in seconds.
	 */
	private const WATCHDOG_INTERVAL = 300;

	/**
	 * How quiet a running job has to be before it counts as stalled.
	 *
	 * Generous on purpose: a tick that is legitimately mid-batch on a slow host
	 * has not written updated_at yet, and reviving underneath it would run two
	 * ticks over the same domain. The id-map makes that harmless but wasteful.
	 */
	private const STALL_AFTER = 600;

	/**
	 * How many times to revive a job that is making no progress before calling it
	 * failed. A tick killed by a transient OOM succeeds on retry; one that dies on
	 * the same row every time never will, and saying "running" forever is a lie.
	 */
	private const MAX_REVIVALS = 3;

	/**
	 * Action Scheduler group, so the actions are easy to find and cancel.
	 */
	private const GROUP = 'buddynext-importer';

	/**
	 * Option holding the current job (source + step cursor + state).
	 */
	private const JOB_OPTION = 'buddynext_importer_bg_job';

	/**
	 * Rows per keyset batch.
	 */
	private const BATCH = 100;

	/**
	 * Soft wall-clock budget per tick, in seconds. A tick keeps processing
	 * batches until this is exceeded, then hands off to the next scheduled tick,
	 * so no single request runs long enough to time out.
	 */
	private const TIME_BUDGET = 15.0;

	/**
	 * Hook the tick handler. Safe to call on every request; the handler no-ops
	 * unless a job is actually running.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( self::WATCHDOG_HOOK, array( $this, 'watchdog' ) );
	}

	/**
	 * Start (or restart) a background import for a source and schedule the first
	 * tick. Idempotent per source: the id-map + checkpoint make a restart resume.
	 *
	 * @param string $source Source key (buddypress|buddyboss).
	 */
	public function start( string $source ): void {
		update_option(
			self::JOB_OPTION,
			array(
				'source'     => $source,
				'state'      => 'running',
				'step'       => 0,
				// The chosen domains are snapshotted here, not read live on every
				// tick: the job tracks its position by STEP INDEX, so a selection
				// changed mid-run would renumber the list underneath a resume and
				// silently move the cursor onto a different domain.
				'domains'    => DomainSelection::get( $source ),
				// Domains that read rows they could not account for, carried
				// across ticks so the cursor can be settled when the domain ends.
				'gaps'       => array(),
				'started_at' => time(),
				'updated_at' => time(),
			),
			false
		);

		DomainSelection::record_run( DomainSelection::get( $source ) );

		$this->schedule();
		$this->schedule_watchdog();
	}

	/**
	 * Cancel a running job and drop any pending tick.
	 */
	public function cancel(): void {
		$job = get_option( self::JOB_OPTION );
		if ( is_array( $job ) ) {
			$job['state']      = 'cancelled';
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
		}

		$this->unschedule();
		$this->unschedule_watchdog();
	}

	/**
	 * Current progress for the admin monitor.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$job = get_option( self::JOB_OPTION );

		if ( ! is_array( $job ) || empty( $job['source'] ) ) {
			return array(
				'state'     => 'idle',
				'phase'     => null,
				'done'      => 0,
				'total'     => 0,
				'percent'   => 0,
				'scheduled' => false,
			);
		}

		$steps = $this->steps( (string) $job['source'], self::job_domains( $job ) );
		$total = count( $steps );
		$step  = min( (int) ( $job['step'] ?? 0 ), $total );
		$state = (string) ( $job['state'] ?? 'idle' );

		$scheduled = $this->is_scheduled();

		return array(
			'state'     => $state,
			'phase'     => $step < $total ? $steps[ $step ]['label'] : null,
			'done'      => $step,
			'total'     => $total,
			'percent'   => 'complete' === $state ? 100 : ( $total > 0 ? (int) round( $step / $total * 100 ) : 0 ),
			'scheduled' => $scheduled,
			// Running, but with no tick queued and no heartbeat for a while. The
			// supervisor will restart it; saying so beats a progress bar that
			// simply stops moving with no explanation.
			'stalled'   => 'running' === $state
				&& ! $scheduled
				&& ( time() - (int) ( $job['updated_at'] ?? 0 ) ) >= self::STALL_AFTER,
			'revivals'  => (int) ( $job['revivals'] ?? 0 ),
		);
	}

	/**
	 * Action Scheduler / WP-Cron entry point. Processes batches until the time
	 * budget is spent or the job is done, then reschedules if work remains.
	 */
	public function tick(): void {
		$job = get_option( self::JOB_OPTION );

		if ( ! is_array( $job ) || 'running' !== ( $job['state'] ?? '' ) ) {
			return;
		}

		// The write phases go through BuddyNext services; if it went away between
		// ticks, stop cleanly and let the owner restart once it is back.
		if ( ! Plugin::buddynext_active() ) {
			$job['state']      = 'error';
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
			$this->unschedule_watchdog();
			return;
		}

		$source = (string) $job['source'];
		$steps  = $this->steps( $source, self::job_domains( $job ) );
		$total  = count( $steps );
		$index  = (int) ( $job['step'] ?? 0 );
		// A job started before this key existed carries no gap map; treat it as
		// empty rather than letting the first gap write into a non-array.
		$job['gaps'] = is_array( $job['gaps'] ?? null ) ? $job['gaps'] : array();

		/**
		 * Filter the wall-clock budget for a single background tick, in seconds.
		 *
		 * The default keeps a tick well inside any sane max_execution_time, but a
		 * constrained host may need it lower - and a lower budget is also the only
		 * way to exercise the hand-off between ticks on a community small enough
		 * to finish in one, which is how a stalled reschedule went unnoticed.
		 *
		 * @since 1.0.0
		 *
		 * @param float  $budget Seconds. Clamped to at least 1.
		 * @param string $source Source key.
		 */
		$budget   = (float) apply_filters( 'buddynext_importer_tick_budget', self::TIME_BUDGET, $source );
		$deadline = microtime( true ) + max( 1.0, $budget );

		/**
		 * Filter the rows processed per keyset batch in a background tick.
		 *
		 * The time budget above bounds how many BATCHES a tick runs, not how much
		 * work one batch is, and a batch is not uniformly cheap: an images batch
		 * re-encodes up to two pictures per member, a media batch copies and
		 * sideloads files. On a constrained host the default can outlast
		 * max_execution_time inside a single batch, which the budget cannot
		 * prevent because it is only checked between them. This is the knob that
		 * can, and it had no filter at all.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $batch  Rows per batch. Clamped to 1-500.
		 * @param string $source Source key.
		 */
		$batch = (int) apply_filters( 'buddynext_importer_tick_batch', self::BATCH, $source );
		$batch = max( 1, min( 500, $batch ) );

		while ( $index < $total ) {
			$step = $steps[ $index ];

			// Skip a domain whose target engine (Jetonomy / WPMediaVerse / ...)
			// or source reader is not available on this site.
			if ( ! ( $step['available'] )() ) {
				++$index;
				continue;
			}

			$cursor = Checkpoint::get( $source, $step['domain'] );
			$result = ( $step['run'] )( $cursor, $batch );
			Checkpoint::set( $source, $step['domain'], (int) $result['last'] );

			// Remember that this domain left rows behind, but do NOT act on it
			// yet. The cursor may only ever skip rows that are already handled,
			// so a gap has to drop the cursor - and dropping it here, per batch,
			// would restart a domain containing one permanently-failing row on
			// every tick and never finish. The CLI settles once at the END of a
			// domain for exactly this reason; this mirrors it across ticks.
			if ( ! empty( $result['gap'] ) ) {
				$job['gaps'][ $step['domain'] ] = true;
			}

			$fetched   = (int) $result['fetched'];
			$step_done = $step['empty_done'] ? ( 0 === $fetched ) : ( $fetched < $batch );
			if ( $step_done ) {
				// Domain finished. If anything went unaccounted for along the way,
				// clear the cursor so the NEXT run re-scans from the start and the
				// id-map refills the hole. A stale cursor costs a re-scan; keeping
				// it here would cost the rows, silently.
				if ( ! empty( $job['gaps'][ $step['domain'] ] ) ) {
					Checkpoint::clear( $source, $step['domain'] );
					unset( $job['gaps'][ $step['domain'] ] );
				}
				++$index;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$job['step']       = $index;
		$job['updated_at'] = time();

		if ( $index >= $total ) {
			$job['state'] = 'complete';
			update_option( self::JOB_OPTION, $job, false );
			$this->unschedule_watchdog();
			return;
		}

		update_option( self::JOB_OPTION, $job, false );
		$this->schedule();
	}

	/**
	 * The ordered list of import steps, read from the shared registry so this
	 * runner, the REST endpoint and the admin page can never disagree about what
	 * a full migration contains.
	 *
	 * @param string        $source  Source key.
	 * @param string[]|null $domains Chosen phases, or null for the stored choice.
	 * @return array<int,array{phase:string,stage:?string,label:string,domain:string,empty_done:bool,available:callable,run:callable}>
	 */
	private function steps( string $source, ?array $domains = null ): array {
		return DomainSelection::steps( $source, $domains );
	}

	/**
	 * The domain selection a job was started with.
	 *
	 * Null for a job created before selections existed, which correctly falls
	 * back to whatever is currently selected - for such a job that is "all of
	 * them", which is what it was already running.
	 *
	 * @param array<string,mixed> $job Stored job.
	 * @return string[]|null
	 */
	private static function job_domains( array $job ): ?array {
		return is_array( $job['domains'] ?? null ) ? array_map( 'strval', $job['domains'] ) : null;
	}

	/**
	 * Supervisor: notice a job that has stopped, and restart it.
	 *
	 * A tick that dies mid-flight - OOM, max_execution_time, a fatal in a
	 * partner plugin - never reaches the schedule() call at the end of tick(),
	 * so nothing queues a replacement. Action Scheduler records the action as
	 * failed and moves on. The job option keeps saying "running" forever, and
	 * the admin screen keeps showing a progress bar that will never move.
	 *
	 * That is the worst shape a large migration can fail in, because the
	 * background runner exists precisely so the owner can close the tab: nobody
	 * is watching when it happens. status() already returned `scheduled` and
	 * tick() already wrote `updated_at`; neither was read by anything. This
	 * reads both.
	 *
	 * Reviving is safe by construction - the checkpoint says where to resume and
	 * the id-map makes every write idempotent, so a re-tick can only redo work,
	 * never duplicate it.
	 */
	public function watchdog(): void {
		$job = get_option( self::JOB_OPTION );

		if ( ! is_array( $job ) || 'running' !== ( $job['state'] ?? '' ) ) {
			$this->unschedule_watchdog();
			return;
		}

		// A tick is queued: the job is fine, whatever its last heartbeat says.
		if ( $this->is_scheduled() ) {
			return;
		}

		// Recently alive, so a tick is probably in progress right now. Reviving
		// underneath it would put two ticks on the same domain.
		if ( time() - (int) ( $job['updated_at'] ?? 0 ) < self::STALL_AFTER ) {
			return;
		}

		// Stalled. Decide between "retry" and "this is not going to work", by
		// asking whether the last revival actually bought us any progress.
		$progress = $this->progress_fingerprint( $job );
		$revivals = (int) ( $job['revivals'] ?? 0 );

		if ( (string) ( $job['revived_at_progress'] ?? '' ) !== $progress ) {
			// It moved since we last stepped in, so whatever killed the tick was
			// transient. Start the count again.
			$revivals = 0;
		} elseif ( $revivals >= self::MAX_REVIVALS ) {
			// Same position, repeatedly. Something kills this tick every time and
			// another restart will not change that. Say so rather than leave a
			// progress bar turning forever.
			$job['state']      = 'error';
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
			$this->unschedule_watchdog();
			return;
		}

		$job['revivals']            = $revivals + 1;
		$job['revived_at_progress'] = $progress;
		$job['updated_at']          = time();
		update_option( self::JOB_OPTION, $job, false );

		$this->schedule();
	}

	/**
	 * Where the job currently stands, as a comparable string.
	 *
	 * Step index alone is not enough: a domain with a million rows sits on one
	 * index for a long time while its cursor climbs steadily, and that is
	 * healthy progress. The cursor is what proves work is happening.
	 *
	 * @param array<string,mixed> $job Stored job.
	 */
	private function progress_fingerprint( array $job ): string {
		$source = (string) ( $job['source'] ?? '' );
		$index  = (int) ( $job['step'] ?? 0 );
		$steps  = $this->steps( $source, self::job_domains( $job ) );
		$cursor = isset( $steps[ $index ] )
			? Checkpoint::get( $source, (string) $steps[ $index ]['domain'] )
			: 0;

		return $index . ':' . $cursor;
	}

	/**
	 * Start the supervisor, if it is not already running.
	 */
	private function schedule_watchdog(): void {
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::WATCHDOG_HOOK, array(), self::GROUP ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action(
				time() + self::WATCHDOG_INTERVAL,
				self::WATCHDOG_INTERVAL,
				self::WATCHDOG_HOOK,
				array(),
				self::GROUP
			);
			return;
		}

		if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_event( time() + self::WATCHDOG_INTERVAL, 'hourly', self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Stop the supervisor. Called whenever a job stops being live, so a finished
	 * migration leaves no recurring action behind.
	 */
	private function unschedule_watchdog(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::WATCHDOG_HOOK, array(), self::GROUP );
		}

		$next = wp_next_scheduled( self::WATCHDOG_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Schedule the next tick, preferring Action Scheduler. Never queues a second
	 * pending tick (that would be an idle poll); one in flight is enough.
	 */
	private function schedule(): void {
		if ( $this->is_scheduled() ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
			return;
		}

		wp_schedule_single_event( time() + 5, self::HOOK );
	}

	/**
	 * Drop any pending tick from both schedulers.
	 */
	private function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}

		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
	}

	/**
	 * Whether a tick is already queued (either scheduler).
	 */
	private function is_scheduled(): bool {
		// PENDING only, deliberately. as_has_scheduled_action() also counts
		// IN-PROGRESS actions, and the caller that matters most is tick() itself
		// asking "is another tick queued?" while it IS the in-progress action - so
		// it saw itself, decided one was already scheduled, and queued nothing.
		//
		// The import then stalled at whatever step the time budget ran out on:
		// one tick's worth of work, then silence, with the job left saying
		// "running" forever. On a community small enough to finish inside a single
		// tick it looked like it worked; on anything larger it stopped partway,
		// which is precisely the case the background runner exists for.
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);

			return is_array( $pending ) && array() !== $pending;
		}

		return (bool) wp_next_scheduled( self::HOOK );
	}
}
