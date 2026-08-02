<?php
/**
 * Source-vs-destination reconciliation for a completed migration.
 *
 * The importer reports what it WROTE. That number can only ever prove the rows
 * it created exist - it can say nothing about the rows it silently declined to
 * create, which is precisely how "Start import" reported success after moving a
 * third of a community. So this counts the SOURCE independently, counts the
 * DESTINATION independently, and prints the gap.
 *
 * A row here is not a failure by itself: a domain can be legitimately short (a
 * skipped system activity, a member with no avatar). It IS a failure when the
 * gap is unexplained - which is the whole point of making it visible.
 *
 * Usage: wp eval-file /scripts/reconcile.php
 *
 * @package BuddyNextImporter
 */

global $wpdb;

$p = $wpdb->prefix;

/**
 * Count rows for a query, tolerating a missing table.
 *
 * @param string $sql Full SQL statement.
 * @return int|null Null when the table does not exist.
 */
$count = static function ( string $sql ) use ( $wpdb ): ?int {
	$out = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
	if ( null === $out && '' !== $wpdb->last_error ) {
		return null;
	}
	return (int) $out;
};

/**
 * Whether a table exists, without asking MySQL a question it will complain about.
 *
 * $count() already tolerates a missing table, but only AFTER the query has failed
 * and printed "WordPress database error ... doesn't exist" into the log. Against a
 * BuddyBoss source - which has no bp_messages_threads - that error was the first
 * thing a tester saw, on a run that was otherwise fine.
 *
 * @param string $table Table name including prefix.
 */
$has_table = static function ( string $table ) use ( $wpdb ): bool {
	// phpcs:ignore WordPress.DB
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
};

/**
 * Count only when the table is there; null (rendered "n/a") when it is not.
 *
 * @param string $table Table name including prefix.
 * @param string $sql   Full SQL statement.
 */
$count_if = static function ( string $table, string $sql ) use ( $count, $has_table ): ?int {
	return $has_table( $table ) ? $count( $sql ) : null;
};

/**
 * The activity types this importer actually carries, read from the adapter.
 *
 * Hardcoding 'activity_update' here made reconcile disagree with the importer the
 * moment the importer learned a new type: new_blog_post has been carried for a
 * while and rtmedia_update was added in 1.1.1, so a CORRECT migration reported
 * "activity updates -> posts GAP -2" and, worse, a gap on the "of which
 * importable" row that this script's own README says must match EXACTLY.
 *
 * Read rather than copied, for the same reason the importer keeps that list in
 * one place: a second copy is how a count and a fetch drift apart.
 *
 * @return string[] Activity type slugs.
 */
$imported_types = static function (): array {
	$class = '\BuddyNextImporter\Source\BuddyPress\BuddyPressAdapter';
	if ( class_exists( $class ) ) {
		try {
			$ref = new ReflectionClass( $class );
			if ( $ref->hasConstant( 'IMPORTED_ACTIVITY_TYPES' ) ) {
				$types = (array) $ref->getConstant( 'IMPORTED_ACTIVITY_TYPES' );
				if ( array() !== $types ) {
					return array_map( 'strval', $types );
				}
			}
		} catch ( \Throwable $e ) {
			// Fall through to the literal below.
		}
	}

	// Only reached when the importer is not loaded, in which case nothing has
	// migrated anyway and the numbers are informational.
	return array( 'activity_update', 'new_blog_post', 'rtmedia_update' );
};

$types_sql = "'" . implode( "','", array_map( 'esc_sql', $imported_types() ) ) . "'";

$rows = array();

/**
 * Record one domain comparison.
 *
 * @param string   $domain Domain label.
 * @param int|null $src    Source count.
 * @param int|null $dst    Destination count.
 * @param string   $note   Explanation for an expected gap.
 */
$cmp = static function ( string $domain, ?int $src, ?int $dst, string $note = '' ) use ( &$rows ): void {
	$rows[] = array( $domain, $src, $dst, $note );
};

// ---------------------------------------------------------------- profiles --
$cmp(
	'profile values',
	$count( "SELECT COUNT(*) FROM {$p}bp_xprofile_data WHERE value <> ''" ),
	$count( "SELECT COUNT(*) FROM {$p}bn_profile_values WHERE value <> ''" ),
	'checkbox fields were the 0/25 loss'
);
$cmp(
	'  of which multi-value',
	$count(
		"SELECT COUNT(*) FROM {$p}bp_xprofile_data d
		   JOIN {$p}bp_xprofile_fields f ON f.id = d.field_id
		  WHERE f.type IN ( 'checkbox', 'multiselectbox' ) AND d.value <> ''"
	),
	$count(
		"SELECT COUNT(*) FROM {$p}bn_profile_values v
		   JOIN {$p}bn_profile_fields f ON f.id = v.field_id
		  WHERE f.type = 'multiselect' AND v.value <> ''"
	),
	'BP checkbox + multiselectbox -> BN multiselect'
);

// ------------------------------------------------------------------ spaces --
// Count spaces THIS IMPORT created, via the id-map - not every row in
// bn_spaces. BuddyNext seeds its own default space, so a raw COUNT(*) made a
// 4-groups-to-3-imported-spaces gap look like a clean 4 = 4.
$cmp(
	'groups -> spaces',
	$count( "SELECT COUNT(*) FROM {$p}bp_groups" ),
	$count( "SELECT COUNT(*) FROM {$p}bni_id_map WHERE domain='space'" ),
	'destination counted via the id-map, not raw bn_spaces'
);

// ---------------------------------------------------------------- activity --
$cmp(
	'carried activity -> posts',
	$count_if( "{$p}bp_activity", "SELECT COUNT(*) FROM {$p}bp_activity WHERE type IN ( {$types_sql} ) AND is_spam=0" ),
	$count( "SELECT COUNT(*) FROM {$p}bn_posts" ),
	'source counts every type the importer carries'
);

$cmp(
	'activity comments',
	$count_if( "{$p}bp_activity", "SELECT COUNT(*) FROM {$p}bp_activity WHERE type='activity_comment' AND is_spam=0" ),
	$count( "SELECT COUNT(*) FROM {$p}bn_comments" ),
	'gap = comments whose root is a type we do not carry'
);
$cmp(
	'  of which importable',
	$count_if(
		"{$p}bp_activity",
		"SELECT COUNT(*) FROM {$p}bp_activity c
		   JOIN {$p}bp_activity r ON r.id = c.item_id
		  WHERE c.type='activity_comment' AND c.is_spam=0
		    AND r.type IN ( {$types_sql} ) AND r.is_spam=0"
	),
	$count( "SELECT COUNT(*) FROM {$p}bn_comments" ),
	'gap here = comments whose ROOT POST was refused; verify names the count'
);

// -------------------------------------------------------------------- rest --
$cmp(
	'friendships -> connections',
	// Pending requests are migrated as pending connections, not dropped, so the
	// source side must count them too - filtering to is_confirmed=1 made the
	// importer look like it had invented connections.
	$count( "SELECT COUNT(*) FROM {$p}bp_friends" ),
	$count( "SELECT COUNT(*) FROM {$p}bn_connections" ),
	'includes pending requests, which migrate as pending'
);
$cmp( 'reactions', null, $count( "SELECT COUNT(*) FROM {$p}bn_reactions" ), 'source is usermeta bp_favorite_activities' );
$cmp( 'member type assignments', null, $count( "SELECT COUNT(*) FROM {$p}bn_member_type_assignments" ) );
// There is no bp_messages_threads table on EITHER platform - BuddyPress and
// BuddyBoss both keep the thread id as a column on bp_messages_messages. Asking
// for it printed a database error on BuddyBoss and, on BuddyPress, a silent
// "n/a" that looked like a source with no DMs rather than a broken query. Count
// it the way the adapter's own message_thread_count() does.
$cmp(
	'DM threads',
	$count_if( "{$p}bp_messages_messages", "SELECT COUNT(DISTINCT thread_id) FROM {$p}bp_messages_messages" ),
	$count( "SELECT COUNT(*) FROM {$p}mvs_conversations" ),
	'a gap here should equal the folded threads on the row below'
);
// The source keeps one thread per SUBJECT; BuddyNext keeps one conversation per
// SET OF PARTICIPANTS. Two source threads between the same people are therefore
// one conversation, and the difference is a fold rather than a loss - but only
// this row can prove that, so it sits directly under the count it explains.
// Verified on the BuddyPress fixture: 200 threads, 194 distinct participant sets,
// 194 conversations.
$cmp(
	'  of which distinct participant sets',
	$count_if(
		"{$p}bp_messages_recipients",
		"SELECT COUNT(DISTINCT parts) FROM (
			SELECT GROUP_CONCAT( DISTINCT user_id ORDER BY user_id ) parts
			  FROM {$p}bp_messages_recipients
			 GROUP BY thread_id
		) folded"
	),
	$count( "SELECT COUNT(*) FROM {$p}mvs_conversations" ),
	'THIS pair must match: one conversation per set of people'
);

echo "\n";
printf( "%-30s %10s %10s   %s\n", 'DOMAIN', 'SOURCE', 'BUDDYNEXT', 'NOTE' );
printf( "%-30s %10s %10s   %s\n", str_repeat( '-', 30 ), '------', '---------', str_repeat( '-', 40 ) );
foreach ( $rows as $r ) {
	list( $domain, $src, $dst, $note ) = $r;
	$flag                              = '';
	if ( null !== $src && null !== $dst && $src !== $dst ) {
		$flag = '  <== GAP ' . ( $dst - $src );
	}
	printf(
		"%-30s %10s %10s   %s%s\n",
		$domain,
		null === $src ? 'n/a' : (string) $src,
		null === $dst ? 'n/a' : (string) $dst,
		$note,
		$flag
	);
}

// ------------------------------------------------- the privacy check ------ //
// A group activity whose space failed to map is created at space_id = 0, which
// is the GLOBAL feed - and BuddyPress core has no privacy column, so the
// importer defaults it to public. A hidden group's post becoming a public
// sitewide post does not change any row count, so no total above can catch it.
// This is the only check here that looks at placement rather than volume.
$src_group_activities = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$p}bp_activity WHERE component='groups' AND type IN ( {$types_sql} ) AND is_spam=0"
); // phpcs:ignore WordPress.DB
$dst_spaced           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bn_posts WHERE space_id > 0" ); // phpcs:ignore WordPress.DB

// A group activity that was never created is a refusal, not a leak. Count only
// those that DID become a post and landed outside their space.
$leaked_rows = $wpdb->get_results(
	"SELECT bp.id AS src, bn.id AS dst, bn.privacy, LEFT(bn.content,50) AS content, g.name AS grp, g.status
	   FROM {$p}bp_activity bp
	   JOIN {$p}bni_id_map m ON m.domain='post' AND m.source_id = bp.id
	   JOIN {$p}bn_posts   bn ON bn.id = m.bn_id
	   LEFT JOIN {$p}bp_groups g ON g.id = bp.item_id
	  WHERE bp.component='groups' AND bp.type IN ( {$types_sql} ) AND bp.is_spam=0
	    AND bn.space_id = 0",
	ARRAY_A
); // phpcs:ignore WordPress.DB

echo "\n";
echo "PRIVACY - group activities must land IN a space, never the global feed\n";
printf( "  source group activities : %d\n", $src_group_activities );
printf( "  BN posts with space_id>0: %d\n", $dst_spaced );

printf( "  refused outright        : %d (not a leak - never created)\n", $src_group_activities - $dst_spaced - count( $leaked_rows ) );

if ( ! empty( $leaked_rows ) ) {
	printf( "  FAIL  %d group post(s) REPUBLISHED to the global feed\n", count( $leaked_rows ) );
	foreach ( $leaked_rows as $r ) {
		printf(
			"        bp#%s -> bn#%s  group=%s (%s)  privacy=%s :: %s\n",
			(string) $r['src'],
			(string) $r['dst'],
			(string) $r['grp'],
			(string) $r['status'],
			(string) $r['privacy'],
			(string) $r['content']
		);
	}
} elseif ( 0 === $src_group_activities ) {
	echo "  n/a   this source has no group activity - the check did not run\n";
} else {
	echo "  PASS  no group post landed in the global feed\n";
}

// Unmapped spaces are the upstream cause, so name them directly.
$unmapped = $wpdb->get_results(
	"SELECT g.id, g.name, g.slug, g.status FROM {$p}bp_groups g
	  WHERE NOT EXISTS (
	      SELECT 1 FROM {$p}bni_id_map m
	       WHERE m.domain = 'space' AND m.source_id = g.id
	  )",
	ARRAY_A
); // phpcs:ignore WordPress.DB

if ( ! empty( $unmapped ) ) {
	echo "\n  groups with NO space in the id-map (their activities have no parent):\n";
	foreach ( $unmapped as $g ) {
		printf( "    #%d  %-20s slug=%-16s status=%s\n", (int) $g['id'], (string) $g['name'], (string) $g['slug'], (string) $g['status'] );
	}
}

echo "\n";
