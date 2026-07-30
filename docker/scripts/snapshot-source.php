<?php
/**
 * Capture the SOURCE community's relationships before a migration.
 *
 * A migration must not change its source, but "must not" is not evidence. This
 * writes a baseline the post-migration state can be diffed against, so a claim
 * about what moved is measured against a fixed reference taken beforehand
 * rather than against the source as it looks afterwards.
 *
 * It records relationships, not just totals - members per group, comments per
 * root type, memberships by confirmation - because the failures worth catching
 * have all been in the edges between objects, not the counts of them.
 *
 * Usage: wp eval-file /scripts/snapshot-source.php
 * Writes: /tmp/bi-source-baseline.json
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

/**
 * COUNT(*) for a table, tolerating one that is not installed.
 *
 * @param string $table Table without prefix.
 * @param string $where Optional WHERE clause.
 */
$count = static function ( string $table, string $where = '' ) use ( $wpdb, $p ): ?int {
	$full = $p . $table;
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
		return null;
	}
	$sql = "SELECT COUNT(*) FROM `{$full}`" . ( '' !== $where ? " WHERE {$where}" : '' );
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
};

$baseline = array(
	'captured_at' => gmdate( 'c' ),
	'totals'      => array(
		'users'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB
		'xprofile_groups'    => $count( 'bp_xprofile_groups' ),
		'xprofile_fields'    => $count( 'bp_xprofile_fields', 'parent_id = 0' ),
		'xprofile_options'   => $count( 'bp_xprofile_fields', 'parent_id > 0' ),
		'xprofile_values'    => $count( 'bp_xprofile_data', "value <> ''" ),
		'groups'             => $count( 'bp_groups' ),
		'group_members'      => $count( 'bp_groups_members' ),
		'group_members_conf' => $count( 'bp_groups_members', 'is_confirmed = 1' ),
		'activities'         => $count( 'bp_activity', "type = 'activity_update' AND is_spam = 0" ),
		'activity_comments'  => $count( 'bp_activity', "type = 'activity_comment' AND is_spam = 0" ),
		'friendships'        => $count( 'bp_friends' ),
		'friendships_conf'   => $count( 'bp_friends', 'is_confirmed = 1' ),
		'message_threads'    => null,
		'messages'           => $count( 'bp_messages_messages' ),
	),
);

if ( null !== $baseline['totals']['messages'] ) {
	$baseline['totals']['message_threads'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT thread_id) FROM {$p}bp_messages_messages" ); // phpcs:ignore WordPress.DB
}

// -- relationships, which is the part totals cannot describe ---------------- //

// Group status matters: a private or hidden group's content must never surface
// more widely after a migration than it did before.
$baseline['groups_by_status'] = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$p}bp_groups GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB

// Members per group, so a per-space comparison has something to check against.
$baseline['members_per_group'] = $wpdb->get_results(
	"SELECT g.id, g.name, g.slug, g.status,
	        (SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE m.group_id = g.id AND m.is_confirmed = 1) AS confirmed,
	        (SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE m.group_id = g.id AND m.is_confirmed = 0) AS pending,
	        (SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.component='groups' AND a.type='activity_update' AND a.is_spam=0 AND a.item_id = g.id) AS activities
	   FROM {$p}bp_groups g ORDER BY g.id", // phpcs:ignore WordPress.DB
	ARRAY_A
);

// Which activity types carry comments - the roots the importer does not carry
// are comments that can never migrate, and this is where that is decided.
$baseline['comment_roots'] = $wpdb->get_results(
	"SELECT COALESCE( r.type, '(missing root)' ) AS root_type, COUNT(*) AS n
	   FROM {$p}bp_activity c
	   LEFT JOIN {$p}bp_activity r ON r.id = c.item_id AND r.is_spam = 0
	  WHERE c.type = 'activity_comment' AND c.is_spam = 0
	  GROUP BY root_type ORDER BY n DESC", // phpcs:ignore WordPress.DB
	ARRAY_A
);

// Profile field types, so a type that silently fails to map is visible.
$baseline['field_types'] = $wpdb->get_results(
	"SELECT f.type, COUNT(DISTINCT f.id) AS fields,
	        (SELECT COUNT(*) FROM {$p}bp_xprofile_data d WHERE d.field_id IN
	            (SELECT id FROM {$p}bp_xprofile_fields WHERE type = f.type) AND d.value <> '') AS values_stored
	   FROM {$p}bp_xprofile_fields f WHERE f.parent_id = 0 GROUP BY f.type ORDER BY fields DESC", // phpcs:ignore WordPress.DB
	ARRAY_A
);

// Taxonomy-backed classifications, both of which become BuddyNext concepts.
foreach ( array(
	'bp_group_type'  => 'group_types',
	'bp_member_type' => 'member_types',
) as $taxonomy => $key ) {
	$baseline[ $key ] = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.name, t.slug, COUNT(tr.object_id) AS assigned
			   FROM {$wpdb->terms} t
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
			   LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			  GROUP BY t.term_id ORDER BY assigned DESC", // phpcs:ignore WordPress.DB
			$taxonomy
		),
		ARRAY_A
	);
}

file_put_contents( '/tmp/bi-source-baseline.json', wp_json_encode( $baseline, JSON_PRETTY_PRINT ) );

echo "== SOURCE BASELINE ==\n";
foreach ( $baseline['totals'] as $label => $value ) {
	printf( "  %-22s %s\n", $label, null === $value ? '(no table)' : (string) $value );
}

echo "\n  groups by status:\n";
foreach ( (array) $baseline['groups_by_status'] as $row ) {
	printf( "    %-10s %d\n", (string) $row['status'], (int) $row['n'] );
}

// Which roots are importable is the ADAPTER's answer, not a copy of it. This
// script hardcoded 'activity_update' and so reported new_blog_post as
// un-migratable after the importer had started carrying it - the same drift the
// step lists had, reproduced in the tool meant to catch drift.
$importable = array();
if ( class_exists( '\BuddyNextImporter\Source\AdapterRegistry' ) ) {
	$key     = \BuddyNextImporter\Source\AdapterRegistry::detect_active_key();
	$adapter = null === $key ? null : \BuddyNextImporter\Source\AdapterRegistry::get( $key );
	if ( null !== $adapter && method_exists( $adapter, 'comment_root_types' ) ) {
		foreach ( $adapter->comment_root_types() as $r ) {
			if ( ! empty( $r['importable'] ) ) {
				$importable[] = (string) $r['type'];
			}
		}
	}
}

echo "\n  comments by root activity type:\n";
foreach ( (array) $baseline['comment_roots'] as $row ) {
	$type = (string) $row['root_type'];
	$note = '';
	if ( array() === $importable ) {
		$note = '  (importer inactive - cannot say)';
	} elseif ( ! in_array( $type, $importable, true ) ) {
		$note = '  <- cannot migrate';
	}
	printf( "    %-24s %6d %s\n", $type, (int) $row['n'], $note );
}

echo "\n  profile field types:\n";
foreach ( (array) $baseline['field_types'] as $row ) {
	printf( "    %-22s %2d field(s), %d value(s)\n", (string) $row['type'], (int) $row['fields'], (int) $row['values_stored'] );
}

foreach ( array( 'group_types', 'member_types' ) as $key ) {
	if ( ! empty( $baseline[ $key ] ) ) {
		printf( "\n  %s:\n", str_replace( '_', ' ', $key ) );
		foreach ( (array) $baseline[ $key ] as $row ) {
			printf( "    %-22s %d assigned\n", (string) $row['name'], (int) $row['assigned'] );
		}
	}
}

echo "\n  written to /tmp/bi-source-baseline.json\n";
