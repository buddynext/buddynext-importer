<?php
/**
 * Rename one group onto a slug BuddyNext reserves.
 *
 * SpaceService::RESERVED_SLUGS refuses 'mine', 'managed' and 'joined', and
 * SpaceWriter::unique_slug() only loops on slug_exists() - so such a group is
 * refused a space, and every activity in it used to be republished to the
 * global feed as public. No generator would produce this on purpose, and it is
 * the case that matters most.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$group = $wpdb->get_row( "SELECT id, name FROM {$p}bp_groups WHERE status <> 'public' ORDER BY id LIMIT 1", ARRAY_A );
if ( null === $group ) {
	$group = $wpdb->get_row( "SELECT id, name FROM {$p}bp_groups ORDER BY id LIMIT 1", ARRAY_A );
}

if ( null === $group ) {
	echo "  no groups to rename\n";
	return;
}

$wpdb->update( $p . 'bp_groups', array( 'slug' => 'joined', 'status' => 'private' ), array( 'id' => (int) $group['id'] ) );
printf( "  group #%d (%s) -> slug 'joined', private (reserved-slug case)\n", (int) $group['id'], (string) $group['name'] );
