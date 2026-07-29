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
use BuddyNextImporter\Pipeline\StepRegistry;
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
				'started_at' => time(),
				'updated_at' => time(),
			),
			false
		);

		$this->schedule();
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

		$steps = $this->steps( (string) $job['source'] );
		$total = count( $steps );
		$step  = min( (int) ( $job['step'] ?? 0 ), $total );
		$state = (string) ( $job['state'] ?? 'idle' );

		return array(
			'state'     => $state,
			'phase'     => $step < $total ? $steps[ $step ]['label'] : null,
			'done'      => $step,
			'total'     => $total,
			'percent'   => 'complete' === $state ? 100 : ( $total > 0 ? (int) round( $step / $total * 100 ) : 0 ),
			'scheduled' => $this->is_scheduled(),
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
			return;
		}

		$source = (string) $job['source'];
		$steps  = $this->steps( $source );
		$total  = count( $steps );
		$index  = (int) ( $job['step'] ?? 0 );

		$deadline = microtime( true ) + self::TIME_BUDGET;

		while ( $index < $total ) {
			$step = $steps[ $index ];

			// Skip a domain whose target engine (Jetonomy / WPMediaVerse / ...)
			// or source reader is not available on this site.
			if ( ! ( $step['available'] )() ) {
				++$index;
				continue;
			}

			$cursor = Checkpoint::get( $source, $step['domain'] );
			$result = ( $step['run'] )( $cursor, self::BATCH );
			Checkpoint::set( $source, $step['domain'], (int) $result['last'] );

			$fetched   = (int) $result['fetched'];
			$step_done = $step['empty_done'] ? ( 0 === $fetched ) : ( $fetched < self::BATCH );
			if ( $step_done ) {
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
	 * @param string $source Source key.
	 * @return array<int,array{phase:string,stage:?string,label:string,domain:string,empty_done:bool,available:callable,run:callable}>
	 */
	private function steps( string $source ): array {
		return StepRegistry::steps( $source );
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
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( self::HOOK, array(), self::GROUP );
		}

		return (bool) wp_next_scheduled( self::HOOK );
	}
}
