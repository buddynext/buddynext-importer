<?php
/**
 * The one definition of what each skip reason MEANS in words.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Reason code to human sentence, shared by every surface that reports a skip.
 *
 * This used to be a private array inside MigrateCommand::report_skips(), which
 * meant only the CLI could say what a reason meant. The admin screen received the
 * same reason CODES over REST and rendered them by replacing underscores with
 * spaces, so a site owner read "17 forbidden" where a developer running the CLI
 * read "17 posts were refused because their author is not a member of the space
 * they belong to".
 *
 * That is the wrong way round. The admin screen is the LESS technical audience
 * and it is the surface an owner reads before deleting their old community, so
 * it is the one that most needs the sentence. Rule 3 - a silent shortfall is the
 * worst bug this tool can have - is not satisfied by a reason nobody can read.
 *
 * Keeping the wording here rather than in either caller means the two cannot
 * drift, which is the same reason StepRegistry owns the step list.
 */
final class SkipReasons {

	/**
	 * Reason code to a sprintf template taking (count, domain label).
	 *
	 * Codes not listed here are genuine shortfalls with no known explanation, and
	 * callers report them as a raw breakdown rather than inventing wording.
	 *
	 * @return array<string,string>
	 */
	public static function wording(): array {
		return array(
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'already_imported'      => __( '%1$d %2$s were already imported by an earlier run.', 'buddynext-importer' ),
			/* translators: %1$d: number of rows. */
			'multiple_source_types' => __( '%1$d extra source member types were dropped: a BuddyNext member holds one type.', 'buddynext-importer' ),
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'target_already_set'    => __( '%1$d %2$s were left alone: that member or space already had one in BuddyNext, and an import never replaces a picture somebody chose.', 'buddynext-importer' ),
			// A like whose activity was spam/skipped goes with it - a correct,
			// expected reduction, not a shortfall to warn about.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'activity_not_imported' => __( '%1$d %2$s were on activities that did not migrate (spam/skipped), so they were dropped with them.', 'buddynext-importer' ),
			// An album photo in BuddyBoss also has an activity, so it arrived with
			// that activity and this pass only added it to its album. Nothing was
			// lost and nothing was written twice - counting it as a write would
			// report more media than the source holds.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'linked_from_activity'  => __( '%1$d %2$s already arrived with their activity and were added to their album here.', 'buddynext-importer' ),
			// A relationship one party has BLOCKED is not re-created - the block is
			// a current safety decision ImportMode deliberately leaves in force.
			// With the privacy preference lifted for the run, these "not allowed"
			// codes can only come from a block, so they read as one.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'blocked'               => __( '%1$d %2$s were not re-created because one member blocks the other.', 'buddynext-importer' ),
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'follow_not_allowed'    => __( '%1$d %2$s were not re-created because one member blocks the other.', 'buddynext-importer' ),
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'connect_not_allowed'   => __( '%1$d %2$s were not re-created because one member blocks the other.', 'buddynext-importer' ),
			// Malformed source rows that can never be a relationship - a member
			// cannot follow or connect to themselves. Reported, not alarmed.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'self_follow'           => __( '%1$d self-referential %2$s in the source were skipped (a member cannot follow themselves).', 'buddynext-importer' ),
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'self_connection'       => __( '%1$d self-referential %2$s in the source were skipped (a member cannot connect to themselves).', 'buddynext-importer' ),
			// A source row with neither text nor media is not content; there is
			// nothing to create from it.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'empty_source_row'      => __( '%1$d %2$s in the source had no text and no media, so there was nothing to create.', 'buddynext-importer' ),
			// CONTENT WAS DROPPED here, and the wording says so. The group did not
			// become a space, so its posts have nowhere to go - and the one thing
			// they must NOT do is fall through to the global feed, which is how a
			// private group gets republished publicly. Named rather than silent
			// precisely because the owner has to know before deleting the source.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'space_not_imported'    => __( '%1$d %2$s belonged to a group that did not become a space, so they were dropped rather than published to the global feed.', 'buddynext-importer' ),
			// The SOURCE withheld this from its own sitewide feed and there is no
			// space to protect it here. Importing it would publish what the source
			// deliberately kept back.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'withheld_at_source'    => __( '%1$d %2$s were hidden from the sitewide feed at the source and had no space to land in, so they were not republished.', 'buddynext-importer' ),
			// The linked post is no longer publicly readable, so a card carrying
			// its title, excerpt and image would expose it.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'blog_post_not_public'  => __( '%1$d %2$s pointed at a post that is no longer public, so no card was created.', 'buddynext-importer' ),
			// The parent went, so its replies go with it - the same expected
			// reduction as activity_not_imported above.
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'post_not_imported'     => __( '%1$d %2$s were on a post that did not migrate, so they were dropped with it.', 'buddynext-importer' ),
			// PostService's "You do not have permission to post in this space":
			// the author is not a member of the space this content belongs to,
			// usually because they left the group before the migration. That is
			// BuddyNext enforcing the space's own rule, and VerifyService already
			// attributes it the same way per space, so warning about it would cry
			// wolf on every clean migration.
			//
			// Safe to read this narrowly: the only OTHER 'forbidden' PostService
			// returns is on announcements, and this importer never creates one
			// (it writes text, media and article types only).
			/* translators: 1: number of rows, 2: domain label such as "posts". */
			'forbidden'             => __( '%1$d %2$s were refused because their author is not a member of the space they belong to.', 'buddynext-importer' ),
		);
	}

	/**
	 * Fold a domain's raw skip map into readable sentences.
	 *
	 * Matches the bare reason AND its per-kind variants ("avatar_already_imported",
	 * "cover_already_imported"), so a clean re-run of the images domain reports one
	 * note instead of two phantom losses - the same matching the CLI has always
	 * done, moved here so both callers inherit it.
	 *
	 * Anything with no known wording is returned under `unexplained`, still with
	 * its count, because dropping it would be the silent shortfall this whole
	 * class exists to prevent.
	 *
	 * @param array<string,int> $skipped Skip reason to count.
	 * @param string            $label   Domain label, e.g. "posts".
	 * @return array{notes:array<int,string>,unexplained:array<string,int>}
	 */
	public static function describe( array $skipped, string $label ): array {
		$notes = array();

		foreach ( self::wording() as $reason => $template ) {
			$count = 0;

			foreach ( array_keys( $skipped ) as $key ) {
				if ( $key === $reason || str_ends_with( (string) $key, '_' . $reason ) ) {
					$count += (int) $skipped[ $key ];
					unset( $skipped[ $key ] );
				}
			}

			if ( $count > 0 ) {
				$notes[] = sprintf( $template, $count, $label );
			}
		}

		return array(
			'notes'       => $notes,
			'unexplained' => $skipped,
		);
	}
}
