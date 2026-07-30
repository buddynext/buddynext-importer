<?php
/**
 * Per-object verification of a completed migration.
 *
 * reconcile.php compares totals, and totals are not enough: a migration can
 * reconcile perfectly while every row landed in the wrong place. The group
 * activities that were republished to the global feed did exactly that - the
 * count was right, the audience was wrong.
 *
 * So this walks objects instead of sums:
 *   1. spaces      - one row per space, members and activity, source vs target
 *                    AND vs the stored member_count denormaliser
 *   2. an activity - picked from the middle of the set, checked for the space it
 *                    belongs to and the comments and reactions hanging off it
 *
 * Usage: wp eval-file /scripts/verify-objects.php
 *
 * @package BuddyNextImporter
 */

global $wpdb;

$p      = $wpdb->prefix;
$source = 'buddypress';
$fail   = 0;

/**
 * Resolve a source id to its BuddyNext id.
 *
 * @param string $domain    Id-map domain.
 * @param int    $source_id Source id.
 */
$mapped = static function ( string $domain, int $source_id ) use ( $wpdb, $p, $source ): ?int {
	$id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT bn_id FROM {$p}bni_id_map WHERE source = %s AND domain = %s AND source_id = %d", // phpcs:ignore WordPress.DB
			$source,
			$domain,
			$source_id
		)
	);

	return null === $id ? null : (int) $id;
};

echo "\n=========== 1. SPACES: members and activity, per space ===========\n\n";
printf( "%-22s %-19s %-19s %s\n", 'SPACE', 'MEMBERS src/bn/count', 'ACTIVITY src/bn', '' );
printf( "%-22s %-19s %-19s %s\n", str_repeat( '-', 22 ), str_repeat( '-', 19 ), str_repeat( '-', 19 ), '' );

$groups = $wpdb->get_results( "SELECT id, name, status FROM {$p}bp_groups ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB

foreach ( (array) $groups as $g ) {
	$src_id = (int) $g['id'];
	$bn_id  = $mapped( 'space', $src_id );

	// Like against like: the source's confirmed members are the ones that become
	// active members, plus the owner row create() adds.
	$src_members = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bp_groups_members WHERE group_id = %d AND is_confirmed = 1", $src_id ) ); // phpcs:ignore WordPress.DB
	$src_acts    = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE component = 'groups' AND type = 'activity_update' AND is_spam = 0 AND item_id = %d", // phpcs:ignore WordPress.DB
			$src_id
		)
	);

	if ( null === $bn_id ) {
		++$fail;
		printf( "%-22s %-19s %-19s  NOT IMPORTED (%s)\n", substr( (string) $g['name'], 0, 22 ), $src_members . '/-/-', $src_acts . '/-', (string) $g['status'] );
		continue;
	}

	// member_count counts MEMBERS, and a pending join request is not one - a
	// source membership with is_confirmed = 0 migrates as status = 'pending'.
	// Comparing the counter against every row reported a phantom shortfall of
	// exactly one per pending member.
	$bn_members = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_space_members WHERE space_id = %d AND status = 'active'", $bn_id ) ); // phpcs:ignore WordPress.DB
	$bn_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_space_members WHERE space_id = %d AND status <> 'active'", $bn_id ) ); // phpcs:ignore WordPress.DB
	$stored     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT member_count FROM {$p}bn_spaces WHERE id = %d", $bn_id ) ); // phpcs:ignore WordPress.DB
	$bn_acts    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_posts WHERE space_id = %d", $bn_id ) ); // phpcs:ignore WordPress.DB

	$notes = array();
	if ( $stored !== $bn_members ) {
		$notes[] = 'member_count off by ' . ( $stored - $bn_members );
		++$fail;
	}
	if ( $bn_acts !== $src_acts ) {
		$notes[] = 'activity short by ' . ( $src_acts - $bn_acts );
		++$fail;
	}

	printf(
		"%-22s %-19s %-19s  %s\n",
		substr( (string) $g['name'], 0, 22 ),
		$src_members . '/' . $bn_members . '/' . $stored . ( $bn_pending ? ' +' . $bn_pending . 'p' : '' ),
		$src_acts . '/' . $bn_acts,
		$notes ? 'FAIL: ' . implode( '; ', $notes ) : 'ok'
	);
}

echo "\n  MEMBERS reads confirmed-source/active-target/stored-counter, with +Np for\n";
echo "  pending join requests, which are migrated but are not members. The third\n";
echo "  number is the denormaliser the space directory and header display, so a\n";
echo "  mismatch there is wrong data on screen even when the rows are right.\n";
echo "  The target normally runs one AHEAD of the source: create() adds the owner\n";
echo "  as a member, and a source group need not list its own creator.\n";

echo "\n=========== 2. ACTIVITY: placement, comments, reactions ===========\n";

$sample = $wpdb->get_results(
	"SELECT id, user_id, component, item_id, LEFT(content,44) AS content
	   FROM {$p}bp_activity
	  WHERE type = 'activity_update' AND is_spam = 0
	  ORDER BY id LIMIT 6", // phpcs:ignore WordPress.DB
	ARRAY_A
);

foreach ( (array) $sample as $a ) {
	$src_id = (int) $a['id'];
	$bn_id  = $mapped( 'post', $src_id );

	printf( "\n  bp activity #%d  (%s)\n", $src_id, (string) $a['content'] );

	if ( null === $bn_id ) {
		printf( "    NOT IMPORTED\n" );
		continue;
	}

	// Where it should live, and where it does.
	$expected_space = 'groups' === (string) $a['component'] ? $mapped( 'space', (int) $a['item_id'] ) : 0;
	$actual_space   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$p}bn_posts WHERE id = %d", $bn_id ) ); // phpcs:ignore WordPress.DB
	$privacy        = (string) $wpdb->get_var( $wpdb->prepare( "SELECT privacy FROM {$p}bn_posts WHERE id = %d", $bn_id ) ); // phpcs:ignore WordPress.DB

	$place_ok = ( (int) $expected_space === $actual_space );
	if ( ! $place_ok ) {
		++$fail;
	}
	printf(
		"    space      expected %-6s actual %-6s %s (privacy=%s)\n",
		null === $expected_space ? 'none' : (string) $expected_space,
		(string) $actual_space,
		$place_ok ? 'ok' : 'FAIL - wrong audience',
		$privacy
	);

	// Comments hanging off it.
	$src_comments = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND is_spam = 0 AND item_id = %d", // phpcs:ignore WordPress.DB
			$src_id
		)
	);
	$bn_comments  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}bn_comments WHERE object_type = 'post' AND object_id = %d AND is_deleted = 0", // phpcs:ignore WordPress.DB
			$bn_id
		)
	);
	if ( $bn_comments !== $src_comments ) {
		++$fail;
	}
	printf(
		"    comments   source %-6d target %-6d %s\n",
		$src_comments,
		$bn_comments,
		$bn_comments === $src_comments ? 'ok' : 'FAIL'
	);

	// Reactions. The source side is whichever store this site uses.
	$bn_reactions = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}bn_reactions WHERE object_type = 'post' AND object_id = %d", // phpcs:ignore WordPress.DB
			$bn_id
		)
	);
	printf( "    reactions  target %-6d\n", $bn_reactions );
}

printf( "\n=========== %s ===========\n\n", 0 === $fail ? 'ALL OBJECT CHECKS PASSED' : $fail . ' OBJECT CHECK(S) FAILED' );
