<?php
/**
 * What the SOURCE community holds, before any migration runs.
 *
 * Printed at the end of seeding so the fixture's own shape is on the record -
 * a reconciliation is only meaningful against a known starting point.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$q = static function ( string $sql ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
};

printf( "  users                    : %d\n", $q( "SELECT COUNT(*) FROM {$p}users" ) );
printf( "  groups                   : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_groups" ) );
printf( "  profile values (non-empty): %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_xprofile_data WHERE value <> ''" ) );
printf( "  activity_update          : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type='activity_update' AND is_spam=0" ) );
printf( "    of which in groups     : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type='activity_update' AND component='groups' AND is_spam=0" ) );
printf( "  activity_comment         : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type='activity_comment' AND is_spam=0" ) );
printf(
	"    with importable root   : %d\n",
	$q(
		"SELECT COUNT(*) FROM {$p}bp_activity c JOIN {$p}bp_activity r ON r.id = c.item_id
		  WHERE c.type='activity_comment' AND c.is_spam=0 AND r.type='activity_update' AND r.is_spam=0"
	)
);
printf( "  friendships (confirmed)  : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_friends WHERE is_confirmed=1" ) );
printf( "  message threads          : %d\n", $q( "SELECT COUNT(*) FROM {$p}bp_messages_threads" ) );

$root_types = $wpdb->get_results(
	"SELECT r.type, COUNT(*) AS n FROM {$p}bp_activity c
	   JOIN {$p}bp_activity r ON r.id = c.item_id
	  WHERE c.type='activity_comment' AND c.is_spam=0
	  GROUP BY r.type ORDER BY n DESC",
	ARRAY_A
); // phpcs:ignore WordPress.DB
echo "  comment root types:\n";
foreach ( (array) $root_types as $t ) {
	printf( "    %-22s %d\n", (string) $t['type'], (int) $t['n'] );
}
