<?php
/**
 * WP-CLI surface: wp buddynext-import <subcommand>. Phase 1 ships `stats`.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\CLI;

use BuddyNextImporter\Pipeline\ActivityImporter;
use BuddyNextImporter\Pipeline\Checkpoint;
use BuddyNextImporter\Pipeline\FollowImporter;
use BuddyNextImporter\Pipeline\ForumImporter;
use BuddyNextImporter\Pipeline\FriendImporter;
use BuddyNextImporter\Pipeline\IdMap;
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
 * Migrate a BuddyPress or BuddyBoss community into BuddyNext.
 */
final class MigrateCommand {

	/**
	 * Show source community statistics (no data is moved).
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 * ---
	 * options:
	 *   - buddypress
	 *   - buddyboss
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import stats
	 *     wp buddynext-import stats --source=buddyboss
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function stats( array $args, array $assoc_args ): void {
		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$adapter = AdapterRegistry::get( $source );

		if ( null === $adapter ) {
			\WP_CLI::error( sprintf( 'Unknown source: %s', $source ) );
		}

		if ( ! $adapter->is_available() ) {
			\WP_CLI::error( sprintf( '%s data is not present on this site.', $adapter->label() ) );
		}

		\WP_CLI::log( sprintf( 'Source: %s', $adapter->label() ) );

		$rows = array();
		foreach ( $adapter->stats() as $domain => $count ) {
			$rows[] = array(
				'domain' => $domain,
				'count'  => $count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'domain', 'count' ) );
	}

	/**
	 * Import profile field groups, fields, and member values into BuddyNext.
	 *
	 * Writes only through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Users per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-profiles
	 *     wp buddynext-import migrate-profiles --source=buddyboss --batch=200
	 *
	 * @subcommand migrate-profiles
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_profiles( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ProfileImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		$schema = $importer->import_schema();
		\WP_CLI::log( sprintf( 'Schema imported: %d groups, %d fields.', $schema['groups'], $schema['fields'] ) );

		$after        = Checkpoint::get( $source, 'profile_value' );
		$total_users  = 0;
		$total_values = 0;

		do {
			$result        = $importer->import_values_batch( $after, $batch );
			$total_users  += $result['users'];
			$total_values += $result['values'];
			$after         = $result['last'];
			Checkpoint::set( $source, 'profile_value', $after );

			if ( $result['users'] > 0 ) {
				\WP_CLI::log( sprintf( '  ... %d members, %d values (last id %d)', $total_users, $total_values, $after ) );
			}
		} while ( $result['users'] === $batch );

		\WP_CLI::success(
			sprintf(
				'Profiles imported: %d groups, %d fields, %d members, %d values.',
				$schema['groups'],
				$schema['fields'],
				$total_users,
				$total_values
			)
		);
	}

	/**
	 * Import groups (as spaces) and their members into BuddyNext.
	 *
	 * Writes only through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Groups per batch. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-spaces
	 *     wp buddynext-import migrate-spaces --source=buddyboss --batch=100
	 *
	 * @subcommand migrate-spaces
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_spaces( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = SpaceImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 50;

		$after          = Checkpoint::get( $source, 'space' );
		$total_groups   = 0;
		$total_members  = 0;
		$total_existing = 0;
		$total_seen     = 0;

		do {
			$result          = $importer->import_batch( $after, $batch );
			$total_groups   += $result['groups'];
			$total_members  += $result['members'];
			$total_existing += $result['existing'];
			$total_seen     += $result['fetched'];
			$after           = $result['last'];
			Checkpoint::set( $source, 'space', $after );

			if ( $result['groups'] > 0 ) {
				\WP_CLI::log( sprintf( '  ... %d spaces, %d members (last group id %d)', $total_groups, $total_members, $after ) );
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'space', $total_seen, $total_groups + $total_existing );

		\WP_CLI::success( sprintf( 'Spaces imported: %d spaces, %d members.', $total_groups, $total_members ) );

		$this->report_existing( $total_existing, 'spaces' );
	}

	/**
	 * Import activity posts and comments into BuddyNext.
	 *
	 * Imports only real posts (activity_update); system rows and spam are skipped.
	 * Run migrate-spaces first so group posts land in their space. Writes only
	 * through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Rows per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-activity
	 *     wp buddynext-import migrate-activity --batch=200
	 *
	 * @subcommand migrate-activity
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_activity( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ActivityImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		// Posts first, so comments can resolve their root post.
		$after          = Checkpoint::get( $source, 'post' );
		$posts          = 0;
		$posts_existing = 0;
		$posts_seen     = 0;
		do {
			$result          = $importer->import_posts_batch( $after, $batch );
			$posts          += $result['posts'];
			$posts_existing += $result['existing'];
			$posts_seen     += $result['fetched'];
			$after           = $result['last'];
			Checkpoint::set( $source, 'post', $after );
		} while ( $result['fetched'] === $batch );
		$this->settle_checkpoint( $source, 'post', $posts_seen, $posts + $posts_existing );
		\WP_CLI::log( sprintf( '%d posts imported.', $posts ) );

		$after             = Checkpoint::get( $source, 'comment' );
		$comments          = 0;
		$comments_existing = 0;
		$comments_seen     = 0;
		do {
			$result             = $importer->import_comments_batch( $after, $batch );
			$comments          += $result['comments'];
			$comments_existing += $result['existing'];
			$comments_seen     += $result['fetched'];
			$after              = $result['last'];
			Checkpoint::set( $source, 'comment', $after );
		} while ( $result['fetched'] === $batch );
		$this->settle_checkpoint( $source, 'comment', $comments_seen, $comments + $comments_existing );

		\WP_CLI::success( sprintf( 'Activity imported: %d posts, %d comments.', $posts, $comments ) );

		$this->report_existing( $posts_existing, 'posts' );
		$this->report_existing( $comments_existing, 'comments' );
	}

	/**
	 * Import friendships into BuddyNext as connections.
	 *
	 * Confirmed friendships become accepted connections; pending ones become
	 * connection requests. Writes only through the BuddyNext service API.
	 * Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Friendships per batch. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-friends
	 *
	 * @subcommand migrate-friends
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_friends( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = FriendImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 200;

		$after   = Checkpoint::get( $source, 'connection' );
		$total   = 0;
		$seen    = 0;
		$skipped = array();
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['connections'];
			$seen  += $result['fetched'];
			$after  = $result['last'];
			Checkpoint::set( $source, 'connection', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'connection', $seen, $total + array_sum( $skipped ) );

		\WP_CLI::success( sprintf( 'Friendships imported: %d of %d connections.', $total, $seen ) );

		$this->report_skips( $skipped, $seen, $total, 'connections' );
	}

	/**
	 * Import user follows into BuddyNext.
	 *
	 * Reads bp_follow (BuddyBoss / the BuddyPress Follow plugin). Writes only
	 * through the BuddyNext service API; bn_follows' unique key makes re-runs
	 * idempotent. Follow dates are preserved when the source table has them.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Follows per batch. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-follows
	 *
	 * @subcommand migrate-follows
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_follows( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = FollowImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 200;

		$after   = Checkpoint::get( $source, 'follow' );
		$total   = 0;
		$seen    = 0;
		$skipped = array();
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['follows'];
			$seen  += $result['fetched'];
			$after  = $result['last'];
			Checkpoint::set( $source, 'follow', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'follow', $seen, $total + array_sum( $skipped ) );

		\WP_CLI::success( sprintf( 'Follows imported: %d of %d follows.', $total, $seen ) );

		$this->report_skips( $skipped, $seen, $total, 'follows' );
	}

	/**
	 * Import activity likes/favorites into BuddyNext as reactions.
	 *
	 * Reads BuddyBoss's bb_user_reactions when present (per-like dates), else
	 * BuddyPress core favorites from usermeta (no dates). Requires the activity
	 * import to have run first - a like maps through the activity id-map, and
	 * likes on unimported activities are dropped with them. Writes only through
	 * the BuddyNext service API; bn_reactions' unique key makes re-runs
	 * idempotent.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Reactions (or users, for usermeta favorites) per batch. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-reactions
	 *
	 * @subcommand migrate-reactions
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_reactions( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ReactionImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 200;

		// Batches are non-uniform (the usermeta fallback keysets by user and
		// emits whole users), so loop until a batch comes back empty.
		$after   = Checkpoint::get( $source, 'reaction' );
		$total   = 0;
		$seen    = 0;
		$skipped = array();
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['reactions'];
			$seen  += $result['fetched'];
			$after  = $result['last'];
			Checkpoint::set( $source, 'reaction', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] > 0 );

		$this->settle_checkpoint( $source, 'reaction', $seen, $total + array_sum( $skipped ) );

		\WP_CLI::success( sprintf( 'Reactions imported: %d of %d likes.', $total, $seen ) );

		$this->report_skips( $skipped, $seen, $total, 'likes' );
	}

	/**
	 * Import private-message threads into WPMediaVerse's DM engine.
	 *
	 * Requires WPMediaVerse active on the destination. Two-party threads become
	 * direct conversations, larger ones group conversations titled with the
	 * source subject; message and thread dates are preserved. Writes only
	 * through the mvs messaging service. Idempotent via the id-map on both the
	 * thread and message level.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Threads per batch. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-messages
	 *
	 * @subcommand migrate-messages
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_messages( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		if ( ! MessageImporter::target_available() ) {
			\WP_CLI::error( 'WPMediaVerse must be active to import private messages (they live in its DM engine).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = MessageImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 50;

		$after           = Checkpoint::get( $source, 'dm_thread' );
		$conversations   = 0;
		$merged          = 0;
		$messages        = 0;
		$source_messages = 0;
		$skipped         = array();

		do {
			$result           = $importer->import_batch( $after, $batch );
			$conversations   += $result['conversations'];
			$merged          += $result['merged'];
			$messages        += $result['messages'];
			$source_messages += $result['source_messages'];
			$after            = $result['last'];
			Checkpoint::set( $source, 'dm_thread', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'dm_thread', $source_messages, $messages + array_sum( $skipped ) );

		\WP_CLI::success(
			sprintf(
				'Messages imported: %d conversations, %d of %d source messages.',
				$conversations,
				$messages,
				$source_messages
			)
		);

		if ( $merged > 0 ) {
			\WP_CLI::log(
				sprintf(
					'  %d source threads were folded into an existing conversation: the source keeps one thread per subject, BuddyNext keeps one conversation per pair of members. Every message is preserved, in date order.',
					$merged
				)
			);
		}

		$this->report_skips( $skipped, $source_messages, $messages, 'messages' );
	}

	/**
	 * Report every source row that did not reach the target, by reason.
	 *
	 * A migration that quietly writes fewer rows than the source holds is the
	 * worst failure mode this tool has: the operator deletes the old community
	 * believing everything moved. So the shortfall is always printed, always
	 * broken down, and raises a warning rather than hiding inside a success line.
	 *
	 * Reasons that are NOT data loss are reported as plain notes, never folded
	 * into the shortfall: `already_imported` is a resumed run finding its own
	 * rows, and `multiple_source_types` is a source row the target's single-type
	 * model intentionally collapses. Counting those as "did not reach the
	 * target" would cry wolf on a clean migration.
	 *
	 * @param array<string,int> $skipped Skip reason -> count.
	 * @param int               $source  Source row count.
	 * @param int               $written Rows written this run.
	 * @param string            $label   Domain label for the message.
	 */
	private function report_skips( array $skipped, int $source, int $written, string $label ): void {
		$notes = array(
			'already_imported'      => '%1$d %2$s were already imported by an earlier run.',
			'multiple_source_types' => '%1$d extra source member types were dropped: a BuddyNext member holds one type.',
			'target_already_set'    => '%1$d %2$s were left alone: that member or space already had one in BuddyNext, and an import never replaces a picture somebody chose.',
			// A like whose activity was spam/skipped goes with it - a correct,
			// expected reduction, not a shortfall to warn about.
			'activity_not_imported' => '%1$d %2$s were on activities that did not migrate (spam/skipped), so they were dropped with them.',
			// A relationship one party has BLOCKED is not re-created - the block is
			// a current safety decision ImportMode deliberately leaves in force.
			// With the privacy preference lifted for the run, these "not allowed"
			// codes can only come from a block, so they read as one.
			'blocked'               => '%1$d %2$s were not re-created because one member blocks the other.',
			'follow_not_allowed'    => '%1$d %2$s were not re-created because one member blocks the other.',
			'connect_not_allowed'   => '%1$d %2$s were not re-created because one member blocks the other.',
			// Malformed source rows that can never be a relationship - a member
			// cannot follow or connect to themselves. Reported, not alarmed.
			'self_follow'           => '%1$d self-referential %2$s in the source were skipped (a member cannot follow themselves).',
			'self_connection'       => '%1$d self-referential %2$s in the source were skipped (a member cannot connect to themselves).',
		);

		foreach ( $notes as $reason => $wording ) {
			$count = 0;

			// Match the bare reason AND its per-kind variants ("avatar_already_imported",
			// "cover_already_imported"), so a clean re-run of the images domain
			// reports one note instead of two phantom losses.
			foreach ( array_keys( $skipped ) as $key ) {
				if ( $key === $reason || str_ends_with( (string) $key, '_' . $reason ) ) {
					$count += (int) $skipped[ $key ];
					unset( $skipped[ $key ] );
				}
			}

			if ( $count > 0 ) {
				\WP_CLI::log( '  ' . sprintf( $wording, $count, $label ) );
			}
		}

		if ( empty( $skipped ) ) {
			return;
		}

		\WP_CLI::warning(
			sprintf(
				'%d source %s did not reach the target (%d of %d written). Breakdown:',
				array_sum( $skipped ),
				$label,
				$written,
				$source
			)
		);

		arsort( $skipped );
		foreach ( $skipped as $reason => $count ) {
			\WP_CLI::log( sprintf( '  - %s: %d', $reason, $count ) );
		}
	}

	/**
	 * Import bbPress forums, topics, and replies into Jetonomy.
	 *
	 * Requires Jetonomy active on the destination. Writes only through Jetonomy's
	 * Journey API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Rows per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-forums
	 *
	 * @subcommand migrate-forums
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_forums( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import.' );
		}

		if ( ! ForumImporter::target_available() ) {
			\WP_CLI::error( 'Jetonomy must be active to import forums (it is the discussion target engine).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ForumImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		$forums = $this->run_loop( fn( $after ) => $importer->import_forums_batch( $after, $batch ), 'forums', $batch, $source, 'forum_space' );
		\WP_CLI::log( sprintf( '%d forums imported.', $forums['done'] ) );

		$topics = $this->run_loop( fn( $after ) => $importer->import_topics_batch( $after, $batch ), 'topics', $batch, $source, 'forum_post' );
		\WP_CLI::log( sprintf( '%d topics imported.', $topics['done'] ) );

		$replies = $this->run_loop( fn( $after ) => $importer->import_replies_batch( $after, $batch ), 'replies', $batch, $source, 'forum_reply' );

		\WP_CLI::success(
			sprintf(
				'Forums imported: %d forums, %d topics, %d replies.',
				$forums['done'],
				$topics['done'],
				$replies['done']
			)
		);

		$this->report_existing( $forums['existing'], 'forums' );
		$this->report_existing( $topics['existing'], 'topics' );
		$this->report_existing( $replies['existing'], 'replies' );

		$this->report_skips( $forums['skipped'], $forums['seen'], $forums['done'], 'forums' );
		$this->report_skips( $topics['skipped'], $topics['seen'], $topics['done'], 'topics' );
		$this->report_skips( $replies['skipped'], $replies['seen'], $replies['done'], 'replies' );
	}

	/**
	 * Import member types and their member assignments into BuddyNext.
	 *
	 * Source member types live in the `bp_member_type` taxonomy on the user
	 * object (both BuddyPress and BuddyBoss), NOT in xprofile data, so they are
	 * a domain of their own rather than part of the profile import.
	 *
	 * Writes only through the BuddyNext service API. Idempotent and resumable.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Members per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-member-types
	 *
	 * @subcommand migrate-member-types
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_member_types( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		if ( ! MemberTypeImporter::target_available() ) {
			\WP_CLI::error( "BuddyNext's member-type service is unavailable." );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = MemberTypeImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		$vocabulary = $importer->import_types();
		\WP_CLI::log( sprintf( '%d member types available in BuddyNext.', $vocabulary['types'] ) );

		if ( $vocabulary['skipped'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d source member types could not be created.', $vocabulary['skipped'] ) );
		}

		$after       = Checkpoint::get( $source, 'member_type_user' );
		$assignments = 0;
		$members     = 0;
		$skipped     = array();

		do {
			$result       = $importer->import_batch( $after, $batch );
			$assignments += $result['assignments'];
			$members     += $result['fetched'];
			$after        = $result['last'];
			Checkpoint::set( $source, 'member_type_user', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'member_type_user', $members, $assignments + array_sum( $skipped ) );

		\WP_CLI::success(
			sprintf(
				'Member types imported: %d types, %d of %d members typed.',
				$vocabulary['types'],
				$assignments,
				$members
			)
		);

		$this->report_skips( $skipped, $members, $assignments, 'member-type assignments' );
	}

	/**
	 * Import media albums and standalone (never-posted) media into BuddyNext.
	 *
	 * Photos attached to an activity are imported with their post by
	 * migrate-activity. This covers what that cannot reach: albums and library
	 * items the member uploaded but never posted. Requires WPMediaVerse.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Rows per batch. Default 50 (each row uploads a file).
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-media
	 *     wp buddynext-import migrate-media --source=buddyboss --batch=25
	 *
	 * @subcommand migrate-media
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_media( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		if ( ! MediaImporter::target_available() ) {
			\WP_CLI::error( 'WPMediaVerse must be active to import media (it is the media engine).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = MediaImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		// Each row copies and re-uploads a real file, so the default batch is
		// smaller than the row-only domains.
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 50;

		$after           = Checkpoint::get( $source, 'media_album' );
		$albums          = 0;
		$albums_existing = 0;
		$albums_seen     = 0;

		do {
			$result           = $importer->import_albums_batch( $after, $batch );
			$albums          += $result['albums'];
			$albums_existing += $result['existing'];
			$albums_seen     += $result['fetched'];
			$after            = $result['last'];
			Checkpoint::set( $source, 'media_album', $after );
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'media_album', $albums_seen, $albums + $albums_existing );

		\WP_CLI::log( sprintf( '%d albums imported.', $albums ) );

		if ( $albums_existing > 0 ) {
			\WP_CLI::log( sprintf( '  %d albums were already imported by an earlier run.', $albums_existing ) );
		}

		$after        = Checkpoint::get( $source, 'standalone_media' );
		$media        = 0;
		$source_media = 0;
		$skipped      = array();

		do {
			$result        = $importer->import_media_batch( $after, $batch );
			$media        += $result['media'];
			$source_media += $result['fetched'];
			$after         = $result['last'];
			Checkpoint::set( $source, 'standalone_media', $after );

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, 'standalone_media', $source_media, $media + array_sum( $skipped ) );

		\WP_CLI::success(
			sprintf(
				'Media imported: %d albums, %d of %d standalone media.',
				$albums,
				$media,
				$source_media
			)
		);

		$this->report_skips( $skipped, $source_media, $media, 'media' );
	}

	/**
	 * Import member and group avatars and cover images into BuddyNext.
	 *
	 * These live purely on disk in the source (BuddyPress stores no row pointing
	 * at an avatar), so no table-driven import can reach them. Each file is
	 * pushed through BuddyNext's own image pipeline, producing the same resized
	 * WebP variation set a real upload would.
	 *
	 * Run migrate-spaces first so group images find their space.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Owners per batch. Default 50 (each one re-encodes up to two images).
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-images
	 *     wp buddynext-import migrate-images --source=buddyboss --batch=25
	 *
	 * @subcommand migrate-images
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_images( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		if ( ! ImageImporter::target_available() ) {
			\WP_CLI::error( "BuddyNext's image pipeline is unavailable." );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$importer = ImageImporter::for_source( $source );
		if ( null === $importer ) {
			\WP_CLI::error( sprintf( 'Source %s is not available on this site.', $source ) );
		}

		// Each owner re-encodes up to two images into several variations, so the
		// default batch is smaller than the row-only domains.
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 50;

		$members = $this->image_loop( fn( $after ) => $importer->import_members_batch( $after, $batch ), 'members', $batch, $source, 'member_image' );
		\WP_CLI::log( sprintf( '%d members got their avatar and/or cover.', $members['done'] ) );

		$spaces = $this->image_loop( fn( $after ) => $importer->import_groups_batch( $after, $batch ), 'spaces', $batch, $source, 'group_image' );

		\WP_CLI::success(
			sprintf(
				'Images imported: %d members, %d spaces.',
				$members['done'],
				$spaces['done']
			)
		);

		$this->report_skips( $members['skipped'], $members['seen'], $members['done'], 'member images' );
		$this->report_skips( $spaces['skipped'], $spaces['seen'], $spaces['done'], 'space images' );
	}

	/**
	 * Drive an image batch loop to completion, collecting counts and skips.
	 *
	 * @param callable $batch_fn   Receives the cursor, returns a batch result.
	 * @param string   $key        The success-count key in the batch result.
	 * @param int      $batch      Batch size.
	 * @param string   $source     Source key, for the resume checkpoint.
	 * @param string   $checkpoint Domain key under which the cursor is stored.
	 * @return array{done:int,seen:int,skipped:array<string,int>}
	 */
	private function image_loop( callable $batch_fn, string $key, int $batch, string $source, string $checkpoint ): array {
		$after   = Checkpoint::get( $source, $checkpoint );
		$done    = 0;
		$seen    = 0;
		$skipped = array();

		do {
			$result = $batch_fn( $after );
			$done  += (int) ( $result[ $key ] ?? 0 );
			$seen  += (int) $result['fetched'];
			$after  = (int) $result['last'];

			foreach ( (array) ( $result['skipped'] ?? array() ) as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}

			Checkpoint::set( $source, $checkpoint, $after );
		} while ( (int) $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, $checkpoint, $seen, $done + array_sum( $skipped ) );

		return array(
			'done'    => $done,
			'seen'    => $seen,
			'skipped' => $skipped,
		);
	}

	/**
	 * Drive a keyset batch loop to completion, summing the named result count.
	 *
	 * Resumes from the domain's saved cursor and advances it after every batch, so
	 * a re-run skips the already-migrated prefix. {@see self::settle_checkpoint()}.
	 *
	 * @param callable $batch_fn   Receives the cursor, returns a batch result.
	 * @param string   $key        The count key in the batch result to sum.
	 * @param int      $batch      Batch size (loop continues while a page is full).
	 * @param string   $source     Source key, for the resume checkpoint.
	 * @param string   $checkpoint Domain key under which the cursor is stored.
	 */
	private function run_loop( callable $batch_fn, string $key, int $batch, string $source, string $checkpoint ): array {
		$after    = Checkpoint::get( $source, $checkpoint );
		$total    = 0;
		$existing = 0;
		$seen     = 0;
		$skipped  = array();

		do {
			$result    = $batch_fn( $after );
			$total    += (int) ( $result[ $key ] ?? 0 );
			$existing += (int) ( $result['existing'] ?? 0 );
			$seen     += (int) $result['fetched'];
			$after     = (int) $result['last'];

			foreach ( (array) ( $result['skipped'] ?? array() ) as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}

			Checkpoint::set( $source, $checkpoint, $after );
		} while ( (int) $result['fetched'] === $batch );

		$this->settle_checkpoint( $source, $checkpoint, $seen, $total + $existing + array_sum( $skipped ) );

		return array(
			'done'     => $total,
			'existing' => $existing,
			'seen'     => $seen,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Decide whether a completed domain's resume cursor is safe to keep.
	 *
	 * The cursor may only ever skip rows that are already handled. If a run wrote
	 * (or deterministically skipped) fewer rows than it read, a gap was left behind
	 * the cursor - a transient write failure. Keeping the cursor would hide that
	 * gap on the next run, so it is cleared: the next run re-scans from the start
	 * and the id-map refills the hole. A stale cursor costs a re-scan, never a row.
	 *
	 * @param string $source    Source key.
	 * @param string $domain    Domain key.
	 * @param int    $seen      Source rows read this run.
	 * @param int    $accounted Rows written + already-present + categorised skips.
	 */
	private function settle_checkpoint( string $source, string $domain, int $seen, int $accounted ): void {
		if ( $seen > $accounted ) {
			Checkpoint::clear( $source, $domain );
		}
	}

	/**
	 * Print the "already there" note for a domain, when there is one.
	 *
	 * Rows an earlier run already wrote are not imports. Reporting them as such
	 * is how a re-run used to claim it had moved the whole community again.
	 *
	 * @param int    $existing Rows found already present.
	 * @param string $label    Domain label.
	 */
	private function report_existing( int $existing, string $label ): void {
		if ( $existing > 0 ) {
			\WP_CLI::log( sprintf( '  %d %s were already imported by an earlier run.', $existing, $label ) );
		}
	}

	/**
	 * Run the full migration in dependency order: profiles, spaces, activity,
	 * friends. Each phase is idempotent, so re-running resumes safely.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source platform. Defaults to the detected active source.
	 *
	 * [--batch=<batch>]
	 * : Rows per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import migrate-all
	 *
	 * @subcommand migrate-all
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function migrate_all( array $args, array $assoc_args ): void {
		if ( ! Plugin::buddynext_active() ) {
			\WP_CLI::error( 'BuddyNext must be active to import (data is written through its service API).' );
		}

		$source = isset( $assoc_args['source'] )
			? sanitize_key( $assoc_args['source'] )
			: AdapterRegistry::detect_active_key();

		if ( null === $source ) {
			\WP_CLI::error( 'No BuddyPress or BuddyBoss data found on this site.' );
		}

		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

		\WP_CLI::log( sprintf( 'Migrating %s -> BuddyNext (batch %d).', $source, $batch ) );

		// Coverage report up front: what the source HOLDS, so nothing can be
		// skipped silently - an operator sees the counts this run is expected
		// to account for before a single row moves.
		$adapter = AdapterRegistry::get( $source );
		if ( null !== $adapter ) {
			$stats = $adapter->stats();
			\WP_CLI::log(
				sprintf(
					'Source contains: %d users, %d groups, %d activities, %d comments, %d friendships, %d follows, %d reactions, %d message threads, %d typed members, %d albums, %d standalone media, %d member images, %d group images.',
					(int) ( $stats['users'] ?? 0 ),
					(int) ( $stats['groups'] ?? 0 ),
					(int) ( $stats['activities'] ?? 0 ),
					(int) ( $stats['activity_comments'] ?? 0 ),
					(int) ( $stats['friendships'] ?? 0 ),
					(int) ( $stats['follows'] ?? 0 ),
					(int) ( $stats['reactions'] ?? 0 ),
					(int) ( $stats['message_threads'] ?? 0 ),
					(int) ( $stats['member_type_users'] ?? 0 ),
					(int) ( $stats['media_albums'] ?? 0 ),
					(int) ( $stats['standalone_media'] ?? 0 ),
					(int) ( $stats['member_images'] ?? 0 ),
					(int) ( $stats['group_images'] ?? 0 )
				)
			);
		}

		$this->migrate_profiles( $args, $assoc_args );
		$this->migrate_member_types( $args, $assoc_args );
		$this->migrate_spaces( $args, $assoc_args );
		$this->migrate_activity( $args, $assoc_args );
		$this->migrate_friends( $args, $assoc_args );
		$this->migrate_follows( $args, $assoc_args );
		$this->migrate_reactions( $args, $assoc_args );

		if ( ForumImporter::target_available() ) {
			$this->migrate_forums( $args, $assoc_args );
		} else {
			\WP_CLI::log( 'Skipping forums (Jetonomy is not active) - source forum content will NOT be migrated.' );
		}

		// After spaces (group images need their space) and before the media
		// domains, so a member's avatar is in place when their content lands.
		if ( ImageImporter::target_available() ) {
			$this->migrate_images( $args, $assoc_args );
		}

		if ( MediaImporter::target_available() ) {
			$this->migrate_media( $args, $assoc_args );
		} else {
			\WP_CLI::log( 'Skipping albums and standalone media (WPMediaVerse is not active) - source album photos will NOT be migrated.' );
		}

		if ( MessageImporter::target_available() ) {
			$this->migrate_messages( $args, $assoc_args );
		} else {
			\WP_CLI::log( 'Skipping private messages (WPMediaVerse is not active) - source message threads will NOT be migrated.' );
		}

		// Creating posts schedules BuddyNext's async indexing (hashtags + search)
		// on Action Scheduler. A live site drains that via WP-Cron within a minute,
		// but a CLI migration generates no web traffic to trigger it, so the
		// migrated site's hashtag feed and search stay empty until something runs
		// the queue. Run it here so the result is query-ready the moment the
		// migration finishes - no manual `wp action-scheduler run` afterwards.
		$this->run_pending_index_jobs();

		\WP_CLI::success( 'Migration complete.' );
		\WP_CLI::log( 'Verify the result, then drop the temporary mapping tables with:' );
		\WP_CLI::log( sprintf( '  wp buddynext-import cleanup --source=%s', $source ) );
		\WP_CLI::log( 'After that you can deactivate and delete this importer.' );
	}

	/**
	 * Drain the asynchronous indexing Action Scheduler jobs the import queued.
	 *
	 * Every post the import creates fires buddynext_post_created, which schedules
	 * BuddyNext's async hashtag extraction and search indexing on Action Scheduler
	 * (kept off the write path so a 100k-row import never blocks on indexing). A
	 * live site drains that queue via WP-Cron within a minute, but a CLI migration
	 * generates no web request to trigger cron - leaving the hashtag feed and
	 * search empty until something runs the queue. Run the due queue here so the
	 * migrated site is query-ready the moment migrate-all finishes.
	 */
	private function run_pending_index_jobs(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		// Count pending actions that are DUE now. Async jobs are enqueued for
		// immediate run; future-scheduled recurring actions are excluded, so the
		// loop cannot spin on a job that is not meant to run yet.
		$due_now = static function (): int {
			$ids = as_get_scheduled_actions(
				array(
					'status'       => 'pending',
					'date'         => gmdate( 'Y-m-d H:i:s' ),
					'date_compare' => '<=',
					'per_page'     => 1,
				),
				'ids'
			);
			return is_array( $ids ) ? count( $ids ) : 0;
		};

		if ( 0 === $due_now() ) {
			return;
		}

		\WP_CLI::log( 'Running queued hashtag + search indexing...' );

		// Each pass runs one Action Scheduler batch through its own cron entry
		// point. The guard stops a runaway if a job keeps rescheduling itself.
		$guard = 0;
		while ( $due_now() > 0 && $guard < 500 ) {
			do_action( 'action_scheduler_run_queue', 'BuddyNext import' );
			++$guard;
		}

		\WP_CLI::log( '  Indexing complete - hashtag feed and search are ready.' );
	}

	/**
	 * Remove the importer's temporary working tables (id-map + resume checkpoint).
	 *
	 * These two tables are scaffolding for a one-time migration: the id-map makes
	 * every write idempotent and resolves relationships, the checkpoint lets a
	 * resumed run skip what is already done. Once the migration is verified they
	 * serve no purpose on the live site, so this drops them - the standard "stage,
	 * then tear down" end of an import.
	 *
	 * Run this ONLY when the migration is complete and checked. Afterwards there is
	 * no duplicate protection: a fresh `migrate-*` run would re-create every row
	 * from scratch, because the map that remembered what was already imported is
	 * gone. That is why it is a deliberate, confirmed step and never automatic.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext-import cleanup
	 *     wp buddynext-import cleanup --yes
	 *
	 * @subcommand cleanup
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		\WP_CLI::confirm(
			'This drops the importer id-map and resume-checkpoint tables. Do this only after the migration is complete and verified: a later import run would then re-create rows from scratch, with no duplicate protection. Continue?',
			$assoc_args
		);

		IdMap::drop();
		Checkpoint::drop();

		\WP_CLI::success( 'Removed the importer working tables (id-map + checkpoint). You can now deactivate and delete this importer.' );
	}
}
