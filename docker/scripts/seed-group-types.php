<?php
/**
 * Seed BuddyPress group types onto the fixture's groups.
 *
 * Group types become BuddyNext space categories, and neither the Reign demo
 * pack nor the hand-written seed carries any - so the path had no data at all
 * and a green run proved nothing about it.
 *
 * Deliberately includes a group with TWO types: a BuddyPress group may hold
 * several where a BuddyNext space has one category, and the collapse is the
 * part worth testing.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

// BuddyPress registers this taxonomy at runtime; the fixture writes the rows
// directly so the importer's reader sees exactly what a live site stores.
$types = array(
	'teams'    => 'Teams',
	'clubs'    => 'Clubs',
	'projects' => 'Projects',
);

$term_ids = array();
foreach ( $types as $slug => $name ) {
	$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE slug = %s", $slug ) );
	if ( ! $existing ) {
		$wpdb->insert( $wpdb->terms, array( 'name' => $name, 'slug' => $slug, 'term_group' => 0 ) );
		$existing = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $existing,
				'taxonomy'    => 'bp_group_type',
				'description' => $name . ' group type',
				'parent'      => 0,
				'count'       => 0,
			)
		);
	}
	$term_ids[ $slug ] = $existing;
}

$tt = array();
foreach ( $term_ids as $slug => $term_id ) {
	$tt[ $slug ] = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'bp_group_type'", $term_id )
	);
}

$groups = $wpdb->get_col( "SELECT id FROM {$p}bp_groups ORDER BY id" );
$slugs  = array_keys( $term_ids );

$assigned = 0;
foreach ( (array) $groups as $i => $group_id ) {
	$group_id = (int) $group_id;

	// Leave every fourth group untyped: a group with no type must still import,
	// with no category rather than a wrong one.
	if ( 3 === $i % 4 ) {
		continue;
	}

	$slug = $slugs[ $i % count( $slugs ) ];
	$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $group_id, 'term_taxonomy_id' => $tt[ $slug ], 'term_order' => 0 ) );
	++$assigned;

	// The first group gets a SECOND type, so the many-to-one collapse is
	// exercised rather than assumed.
	if ( 0 === $i ) {
		$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $group_id, 'term_taxonomy_id' => $tt['projects'], 'term_order' => 1 ) );
	}
}

printf( "seeded %d group types, %d group assignments\n", count( $term_ids ), $assigned );

foreach ( $wpdb->get_results(
	"SELECT t.name, COUNT(tr.object_id) n
	   FROM {$wpdb->terms} t
	   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'bp_group_type'
	   LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
	  GROUP BY t.term_id ORDER BY t.term_id",
	ARRAY_A
) as $r ) {
	printf( "  %-12s %d groups\n", (string) $r['name'], (int) $r['n'] );
}
