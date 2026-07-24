<?php
/**
 * WP-CLI surface: wp buddynext-import <subcommand>. Phase 1 ships `stats`.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\CLI;

use BuddyNextImporter\Pipeline\ActivityImporter;
use BuddyNextImporter\Pipeline\FollowImporter;
use BuddyNextImporter\Pipeline\ForumImporter;
use BuddyNextImporter\Pipeline\FriendImporter;
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

		$after        = 0;
		$total_users  = 0;
		$total_values = 0;

		do {
			$result        = $importer->import_values_batch( $after, $batch );
			$total_users  += $result['users'];
			$total_values += $result['values'];
			$after         = $result['last'];

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

		$after         = 0;
		$total_groups  = 0;
		$total_members = 0;

		do {
			$result         = $importer->import_batch( $after, $batch );
			$total_groups  += $result['groups'];
			$total_members += $result['members'];
			$after          = $result['last'];

			if ( $result['groups'] > 0 ) {
				\WP_CLI::log( sprintf( '  ... %d spaces, %d members (last group id %d)', $total_groups, $total_members, $after ) );
			}
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Spaces imported: %d spaces, %d members.', $total_groups, $total_members ) );
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
		$after = 0;
		$posts = 0;
		do {
			$result = $importer->import_posts_batch( $after, $batch );
			$posts += $result['posts'];
			$after  = $result['last'];
		} while ( $result['fetched'] === $batch );
		\WP_CLI::log( sprintf( '%d posts imported.', $posts ) );

		$after    = 0;
		$comments = 0;
		do {
			$result    = $importer->import_comments_batch( $after, $batch );
			$comments += $result['comments'];
			$after     = $result['last'];
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Activity imported: %d posts, %d comments.', $posts, $comments ) );
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

		$after = 0;
		$total = 0;
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['connections'];
			$after  = $result['last'];
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Friendships imported: %d connections.', $total ) );
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

		$after = 0;
		$total = 0;
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['follows'];
			$after  = $result['last'];
		} while ( $result['fetched'] === $batch );

		\WP_CLI::success( sprintf( 'Follows imported: %d follows.', $total ) );
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
		$after = 0;
		$total = 0;
		do {
			$result = $importer->import_batch( $after, $batch );
			$total += $result['reactions'];
			$after  = $result['last'];
		} while ( $result['fetched'] > 0 );

		\WP_CLI::success( sprintf( 'Reactions imported: %d likes.', $total ) );
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

		$after           = 0;
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

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

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
		);

		foreach ( $notes as $reason => $wording ) {
			$count = (int) ( $skipped[ $reason ] ?? 0 );
			unset( $skipped[ $reason ] );

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

		$forums = $this->run_loop( fn( $after ) => $importer->import_forums_batch( $after, $batch ), 'forums', $batch );
		\WP_CLI::log( sprintf( '%d forums imported.', $forums ) );

		$topics = $this->run_loop( fn( $after ) => $importer->import_topics_batch( $after, $batch ), 'topics', $batch );
		\WP_CLI::log( sprintf( '%d topics imported.', $topics ) );

		$replies = $this->run_loop( fn( $after ) => $importer->import_replies_batch( $after, $batch ), 'replies', $batch );

		\WP_CLI::success( sprintf( 'Forums imported: %d forums, %d topics, %d replies.', $forums, $topics, $replies ) );
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

		$after       = 0;
		$assignments = 0;
		$members     = 0;
		$skipped     = array();

		do {
			$result       = $importer->import_batch( $after, $batch );
			$assignments += $result['assignments'];
			$members     += $result['fetched'];
			$after        = $result['last'];

			foreach ( $result['skipped'] as $reason => $count ) {
				$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + (int) $count;
			}
		} while ( $result['fetched'] === $batch );

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
	 * Drive a keyset batch loop to completion, summing the named result count.
	 *
	 * @param callable $batch_fn Receives the cursor, returns a batch result.
	 * @param string   $key      The count key in the batch result to sum.
	 * @param int      $batch    Batch size (loop continues while a page is full).
	 */
	private function run_loop( callable $batch_fn, string $key, int $batch ): int {
		$after = 0;
		$total = 0;
		do {
			$result = $batch_fn( $after );
			$total += (int) ( $result[ $key ] ?? 0 );
			$after  = (int) $result['last'];
		} while ( (int) $result['fetched'] === $batch );

		return $total;
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
					'Source contains: %d users, %d groups, %d activities, %d comments, %d friendships, %d follows, %d reactions, %d message threads, %d typed members.',
					(int) ( $stats['users'] ?? 0 ),
					(int) ( $stats['groups'] ?? 0 ),
					(int) ( $stats['activities'] ?? 0 ),
					(int) ( $stats['activity_comments'] ?? 0 ),
					(int) ( $stats['friendships'] ?? 0 ),
					(int) ( $stats['follows'] ?? 0 ),
					(int) ( $stats['reactions'] ?? 0 ),
					(int) ( $stats['message_threads'] ?? 0 ),
					(int) ( $stats['member_type_users'] ?? 0 )
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

		if ( MessageImporter::target_available() ) {
			$this->migrate_messages( $args, $assoc_args );
		} else {
			\WP_CLI::log( 'Skipping private messages (WPMediaVerse is not active) - source message threads will NOT be migrated.' );
		}

		\WP_CLI::success( 'Migration complete. You can now deactivate and remove this importer.' );
	}
}
