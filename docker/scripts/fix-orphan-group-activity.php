<?php
/**
 * Place group activity that has no group.
 *
 * The playground writes component='groups' with item_id = 0 when the Groups
 * component was inactive at generation time. Those rows cannot be placed, so the
 * importer correctly refuses them - but as a fixture that means ~a quarter of the
 * importable posts are skipped for a reason that has nothing to do with the code
 * under test, and the resulting shortfall reads like a bug.
 *
 * So they are assigned to real groups, spread across public, private and hidden
 * so placement and privacy both get exercised. A couple are left unplaced on
 * purpose: an activity with no resolvable parent is a real shape, and the guard
 * that refuses it should keep having something to refuse.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$groups = $wpdb->get_col( "SELECT id FROM {$p}bp_groups ORDER BY id" );
if ( array() === (array) $groups ) {
	echo "  no groups to assign to\n";
	return;
}

$orphans = $wpdb->get_results(
	"SELECT a.id, a.user_id, a.type FROM {$p}bp_activity a
	  WHERE a.component = 'groups' AND a.is_spam = 0 AND a.item_id = 0
	  ORDER BY a.id",
	ARRAY_A
);

$total = count( (array) $orphans );
if ( 0 === $total ) {
	echo "  none to fix\n";
	return;
}

// Leave the last two unplaced, as the deliberate "no resolvable parent" case.
$keep_unplaced = 2;
$placed        = 0;
$in_group      = 0;

foreach ( (array) $orphans as $i => $row ) {
	if ( $i >= $total - $keep_unplaced ) {
		break;
	}

	$group_id = (int) $groups[ $i % count( $groups ) ];

	$wpdb->update( $p . 'bp_activity', array( 'item_id' => $group_id ), array( 'id' => (int) $row['id'] ) );
	++$placed;

	// An author who is not a member is refused by BuddyNext by design, so make
	// most of them members - otherwise the fixture only ever exercises refusal.
	$is_member = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}bp_groups_members WHERE group_id = %d AND user_id = %d",
			$group_id,
			(int) $row['user_id']
		)
	);

	if ( ! $is_member && 0 !== $i % 5 ) {
		$wpdb->insert(
			$p . 'bp_groups_members',
			array(
				'group_id'      => $group_id,
				'user_id'       => (int) $row['user_id'],
				'inviter_id'    => 0,
				'is_admin'      => 0,
				'is_mod'        => 0,
				'user_title'    => '',
				'date_modified' => current_time( 'mysql', true ),
				'comments'      => '',
				'is_confirmed'  => 1,
				'is_banned'     => 0,
				'invite_sent'   => 0,
			)
		);
		++$in_group;
	}
}

// A hidden group's activity must be marked the way BuddyBoss marks it, or the
// privacy path is never exercised on this fixture.
$hidden = (int) $wpdb->get_var( "SELECT id FROM {$p}bp_groups WHERE status = 'hidden' ORDER BY id LIMIT 1" );
if ( $hidden > 0 ) {
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$p}bp_activity SET hide_sitewide = 1 WHERE component = 'groups' AND item_id = %d",
			$hidden
		)
	);
}

printf( "  placed %d of %d orphan group activities across %d group(s)\n", $placed, $total, count( $groups ) );
printf( "  added %d membership(s) so their authors can post there\n", $in_group );
printf( "  left %d unplaced on purpose (the no-parent case)\n", $keep_unplaced );
printf( "  marked hidden-group activity hide_sitewide = 1 (group #%d)\n", $hidden );
