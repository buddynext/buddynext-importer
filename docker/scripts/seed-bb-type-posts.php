<?php
/**
 * Create the BuddyBoss Profile Type / Group Type POSTS behind the taxonomy terms.
 *
 * BuddyBoss defines these as a CPT - `bp-member-type` and `bp-group-type` - with
 * the slug in `_bp_..._type_key` meta and the human label in
 * `_bp_..._type_label_name`. Its admin screens list the POSTS; the taxonomy only
 * carries the assignments.
 *
 * The playground registers types at runtime with bp_register_member_type(), so
 * the assignments existed but the definitions did not. Two consequences:
 *
 *   1. BuddyBoss's own Profile Types screen was empty, which is how this was
 *      spotted - the fixture did not look like a real site.
 *   2. The importer prefers the post title for a type's label and falls back to
 *      the term name, so with no posts the labels migrated as SLUGS ("team",
 *      "club") where a real site would give "Teams", "Clubs".
 *
 * The importer was reading the right things all along; the fixture was the half
 * that was wrong.
 *
 * @package BuddyNextImporter
 */

global $wpdb;

/**
 * Ensure a type post exists for a taxonomy term.
 *
 * @param string $post_type  bp-member-type | bp-group-type.
 * @param string $meta_key   _bp_member_type_key | _bp_group_type_key.
 * @param string $label_key  The label meta key.
 * @param string $slug       Type slug, as stored on the term.
 * @param string $label      Human plural label.
 * @return int Post id, or 0.
 */
$ensure = static function ( string $post_type, string $meta_key, string $label_key, string $slug, string $label ) use ( $wpdb ): int {
	$existing = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			  INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			  WHERE p.post_type = %s AND pm.meta_value = %s LIMIT 1",
			$meta_key,
			$post_type,
			$slug
		)
	);

	if ( $existing > 0 ) {
		return $existing;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => $label,
			'post_name'   => $slug,
			'post_type'   => $post_type,
			'post_status' => 'publish',
		)
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, $meta_key, $slug );
	update_post_meta( $post_id, $label_key, $label );
	update_post_meta( $post_id, str_replace( '_label_name', '_label_singular_name', $label_key ), rtrim( $label, 's' ) );

	return (int) $post_id;
};

/**
 * A readable plural label from a slug.
 *
 * @param string $slug Type slug.
 */
$labelise = static function ( string $slug ): string {
	$words = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	return substr( $words, -1 ) === 's' ? $words : $words . 's';
};

foreach ( array(
	'bp_member_type' => array( 'bp-member-type', '_bp_member_type_key', '_bp_member_type_label_name' ),
	'bp_group_type'  => array( 'bp-group-type', '_bp_group_type_key', '_bp_group_type_label_name' ),
) as $taxonomy => $spec ) {
	list( $post_type, $meta_key, $label_key ) = $spec;

	$terms = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.name, t.slug FROM {$wpdb->terms} t
			  INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			  WHERE tt.taxonomy = %s ORDER BY t.term_id",
			$taxonomy
		),
		ARRAY_A
	);

	if ( array() === (array) $terms ) {
		printf( "  %-16s no terms, nothing to define\n", $taxonomy );
		continue;
	}

	$made = 0;
	foreach ( (array) $terms as $term ) {
		$slug  = (string) $term['slug'];
		$label = $labelise( $slug );
		if ( $ensure( $post_type, $meta_key, $label_key, $slug, $label ) > 0 ) {
			++$made;
			printf( "    %-10s -> \"%s\"\n", $slug, $label );
		}
	}

	printf( "  %-16s %d definition post(s)\n", $taxonomy, $made );
}

echo "\n";
printf( "  bp-member-type posts: %d\n", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='bp-member-type' AND post_status='publish'" ) );
printf( "  bp-group-type posts : %d\n", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='bp-group-type' AND post_status='publish'" ) );
