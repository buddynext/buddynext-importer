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

use BuddyNextImporter\Pipeline\ActivityImporter;
use BuddyNextImporter\Pipeline\Checkpoint;
use BuddyNextImporter\Pipeline\FollowImporter;
use BuddyNextImporter\Pipeline\ForumImporter;
use BuddyNextImporter\Pipeline\FriendImporter;
use BuddyNextImporter\Pipeline\ImageImporter;
use BuddyNextImporter\Pipeline\MediaImporter;
use BuddyNextImporter\Pipeline\MemberTypeImporter;
use BuddyNextImporter\Pipeline\MessageImporter;
use BuddyNextImporter\Pipeline\ProfileImporter;
use BuddyNextImporter\Pipeline\ReactionImporter;
use BuddyNextImporter\Pipeline\SpaceImporter;
use BuddyNextImporter\Plugin;
use BuddyNextImporter\Source\AdapterRegistry;

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
			$result = ( $step['run'] )( $cursor );
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
	 * The ordered list of import steps, matching the CLI migrate-all sequence so
	 * relationships resolve (spaces before their activity, forums before topics
	 * before replies, and so on). Each step batches one domain.
	 *
	 * @param string $source Source key.
	 * @return array<int,array{label:string,domain:string,empty_done:bool,available:callable,run:callable}>
	 */
	private function steps( string $source ): array {
		$steps = array();

		$steps[] = array(
			'label'      => __( 'Profiles', 'buddynext-importer' ),
			'domain'     => 'profile_value',
			'empty_done' => false,
			'available'  => static function () use ( $source ) {
				return null !== ProfileImporter::for_source( $source );
			},
			'run'        => static function ( int $cursor ) use ( $source ) {
				$imp = ProfileImporter::for_source( $source );
				if ( null === $imp ) {
					return array(
						'fetched' => 0,
						'last'    => $cursor,
					);
				}
				if ( 0 === $cursor ) {
					$imp->import_schema();
				}
				$r = $imp->import_values_batch( $cursor, self::BATCH );
				return array(
					'fetched' => (int) $r['users'],
					'last'    => (int) $r['last'],
				);
			},
		);

		$steps[] = array(
			'label'      => __( 'Member types', 'buddynext-importer' ),
			'domain'     => 'member_type_user',
			'empty_done' => false,
			'available'  => static function () use ( $source ) {
				return MemberTypeImporter::target_available() && null !== MemberTypeImporter::for_source( $source );
			},
			'run'        => static function ( int $cursor ) use ( $source ) {
				$imp = MemberTypeImporter::for_source( $source );
				if ( null === $imp ) {
					return array(
						'fetched' => 0,
						'last'    => $cursor,
					);
				}
				if ( 0 === $cursor ) {
					$imp->import_types();
				}
				$r = $imp->import_batch( $cursor, self::BATCH );
				return array(
					'fetched' => (int) $r['fetched'],
					'last'    => (int) $r['last'],
				);
			},
		);

		$steps[] = $this->simple_step(
			__( 'Spaces', 'buddynext-importer' ),
			'space',
			static function () use ( $source ) {
				return null !== SpaceImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return SpaceImporter::for_source( $source )->import_batch( $cursor, self::BATCH );
			}
		);

		$steps[] = $this->simple_step(
			__( 'Activity posts', 'buddynext-importer' ),
			'post',
			static function () use ( $source ) {
				return null !== ActivityImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return ActivityImporter::for_source( $source )->import_posts_batch( $cursor, self::BATCH );
			}
		);

		$steps[] = $this->simple_step(
			__( 'Activity comments', 'buddynext-importer' ),
			'comment',
			static function () use ( $source ) {
				return null !== ActivityImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return ActivityImporter::for_source( $source )->import_comments_batch( $cursor, self::BATCH );
			}
		);

		$steps[] = $this->simple_step(
			__( 'Connections', 'buddynext-importer' ),
			'connection',
			static function () use ( $source ) {
				return null !== FriendImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return FriendImporter::for_source( $source )->import_batch( $cursor, self::BATCH );
			}
		);

		$steps[] = $this->simple_step(
			__( 'Follows', 'buddynext-importer' ),
			'follow',
			static function () use ( $source ) {
				return null !== FollowImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return FollowImporter::for_source( $source )->import_batch( $cursor, self::BATCH );
			}
		);

		// Reactions keyset is non-uniform (the usermeta fallback emits whole
		// users), so the step is done only when a batch comes back empty.
		$steps[] = array(
			'label'      => __( 'Reactions', 'buddynext-importer' ),
			'domain'     => 'reaction',
			'empty_done' => true,
			'available'  => static function () use ( $source ) {
				return null !== ReactionImporter::for_source( $source );
			},
			'run'        => static function ( int $cursor ) use ( $source ) {
				return ReactionImporter::for_source( $source )->import_batch( $cursor, self::BATCH );
			},
		);

		$forums_available = static function () use ( $source ) {
			return ForumImporter::target_available() && null !== ForumImporter::for_source( $source );
		};
		$steps[]          = $this->simple_step(
			__( 'Forums', 'buddynext-importer' ),
			'forum_space',
			$forums_available,
			static function ( int $cursor ) use ( $source ) {
				return ForumImporter::for_source( $source )->import_forums_batch( $cursor, self::BATCH );
			}
		);
		$steps[]          = $this->simple_step(
			__( 'Forum topics', 'buddynext-importer' ),
			'forum_post',
			$forums_available,
			static function ( int $cursor ) use ( $source ) {
				return ForumImporter::for_source( $source )->import_topics_batch( $cursor, self::BATCH );
			}
		);
		$steps[]          = $this->simple_step(
			__( 'Forum replies', 'buddynext-importer' ),
			'forum_reply',
			$forums_available,
			static function ( int $cursor ) use ( $source ) {
				return ForumImporter::for_source( $source )->import_replies_batch( $cursor, self::BATCH );
			}
		);

		$images_available = static function () use ( $source ) {
			return ImageImporter::target_available() && null !== ImageImporter::for_source( $source );
		};
		$steps[]          = $this->simple_step(
			__( 'Member images', 'buddynext-importer' ),
			'member_image',
			$images_available,
			static function ( int $cursor ) use ( $source ) {
				return ImageImporter::for_source( $source )->import_members_batch( $cursor, self::BATCH );
			}
		);
		$steps[]          = $this->simple_step(
			__( 'Space images', 'buddynext-importer' ),
			'group_image',
			$images_available,
			static function ( int $cursor ) use ( $source ) {
				return ImageImporter::for_source( $source )->import_groups_batch( $cursor, self::BATCH );
			}
		);

		$media_available = static function () use ( $source ) {
			return MediaImporter::target_available() && null !== MediaImporter::for_source( $source );
		};
		$steps[]         = $this->simple_step(
			__( 'Albums', 'buddynext-importer' ),
			'media_album',
			$media_available,
			static function ( int $cursor ) use ( $source ) {
				return MediaImporter::for_source( $source )->import_albums_batch( $cursor, self::BATCH );
			}
		);
		$steps[]         = $this->simple_step(
			__( 'Media', 'buddynext-importer' ),
			'standalone_media',
			$media_available,
			static function ( int $cursor ) use ( $source ) {
				return MediaImporter::for_source( $source )->import_media_batch( $cursor, self::BATCH );
			}
		);

		$steps[] = $this->simple_step(
			__( 'Messages', 'buddynext-importer' ),
			'dm_thread',
			static function () use ( $source ) {
				return MessageImporter::target_available() && null !== MessageImporter::for_source( $source );
			},
			static function ( int $cursor ) use ( $source ) {
				return MessageImporter::for_source( $source )->import_batch( $cursor, self::BATCH );
			}
		);

		return $steps;
	}

	/**
	 * Build a step whose batch method returns the standard `fetched`/`last` pair.
	 *
	 * @param string   $label     Display label.
	 * @param string   $domain    Checkpoint domain key.
	 * @param callable $available Returns whether the step can run here.
	 * @param callable $run       Receives the cursor, returns the batch result.
	 * @return array{label:string,domain:string,empty_done:bool,available:callable,run:callable}
	 */
	private function simple_step( string $label, string $domain, callable $available, callable $run ): array {
		return array(
			'label'      => $label,
			'domain'     => $domain,
			'empty_done' => false,
			'available'  => $available,
			'run'        => static function ( int $cursor ) use ( $run ) {
				$r = $run( $cursor );
				return array(
					'fetched' => (int) ( $r['fetched'] ?? 0 ),
					'last'    => (int) ( $r['last'] ?? $cursor ),
				);
			},
		);
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
