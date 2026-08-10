<?php
/**
 * Seed a BuddyBoss source with group types, and comments on its media activity.
 *
 * Every relation the importer reads needs data, or a green migration only means
 * the populated half worked.
 *
 * MEDIA IS NOT CREATED HERE ANY MORE. Albums, photos and their activities come
 * from `wp bp playground media`, which seed-bb-only.sh runs before this script.
 * Building a source community is the generator's job; this plugin only READS
 * BuddyBoss, and keeping an uploader here meant the importer carried working
 * knowledge of a platform it never writes to - while every other consumer of the
 * playground still got a community with no media at all.
 *
 * What is left is the part that IS migration-specific:
 *
 *   bp_groups_register_group_type() / set_group_type()
 *                   -> the bp_group_type TAXONOMY rows the importer reads,
 *                      which is the path no generator has ever exercised
 *   comments on media activity
 *                   -> the comment thread hanging off an upload, which is
 *                      exactly the shape that was never migrated or checked
 *
 * The comment pass depends on the media activities the playground has already
 * created, so ORDER MATTERS: media first, this second.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$members = get_users(
	array(
		'fields' => 'ID',
		'number' => 40,
	)
);
if ( count( $members ) < 5 ) {
	echo "  need more members first\n";
	return;
}

// ----------------------------------------------------------- group types --- //

echo "\n== group types (the bp_group_type taxonomy) ==\n";

$types = array(
	'team'    => 'Teams',
	'club'    => 'Clubs',
	'course'  => 'Courses',
	'support' => 'Support',
);

foreach ( $types as $slug => $name ) {
	if ( function_exists( 'bp_groups_register_group_type' ) ) {
		bp_groups_register_group_type( $slug, array( 'labels' => array( 'name' => $name ) ) );
	}
}

$groups   = $wpdb->get_col( "SELECT id FROM {$p}bp_groups ORDER BY id" );
$slugs    = array_keys( $types );
$assigned = 0;

foreach ( (array) $groups as $i => $group_id ) {
	// Leave every fifth group untyped: a group with no type must still import,
	// with no category rather than a wrong one.
	if ( 4 === $i % 5 ) {
		continue;
	}

	$slug = $slugs[ $i % count( $slugs ) ];
	if ( function_exists( 'bp_groups_set_group_type' ) ) {
		bp_groups_set_group_type( (int) $group_id, $slug );
		++$assigned;

		// The first group gets a SECOND type, so the many-to-one collapse into a
		// single space category is exercised rather than assumed.
		if ( 0 === $i ) {
			bp_groups_set_group_type( (int) $group_id, 'course', true );
		}
	}
}

printf( "  %d type(s) registered, %d group(s) typed\n", count( $types ), $assigned );
printf(
	"  bp_group_type taxonomy rows: %d\n",
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'bp_group_type'" )
);

// -------------------------------------------------------------- comments --- //

echo "\n== comments on the media and album activity ==\n";

// The activity BuddyBoss just created for those uploads is exactly the kind that
// had no comments before, so its comment thread was never migrated or checked.
$media_activity = $wpdb->get_col(
	"SELECT id FROM {$p}bp_activity
	  WHERE component = 'media' OR type IN ( 'activity_update', 'bp_media_upload', 'bp_media_album_created' )
	  ORDER BY id DESC LIMIT 40"
);

$comments = 0;
foreach ( (array) $media_activity as $i => $root ) {
	$n = 1 + ( $i % 3 );
	for ( $c = 1; $c <= $n; $c++ ) {
		$author = (int) $members[ ( $i + $c ) % count( $members ) ];
		$wpdb->insert(
			$p . 'bp_activity',
			array(
				'user_id'           => $author,
				'component'         => 'activity',
				'type'              => 'activity_comment',
				'action'            => 'commented',
				'content'           => sprintf( 'Comment %d on activity %d', $c, (int) $root ),
				'item_id'           => (int) $root,
				'secondary_item_id' => (int) $root,
				'date_recorded'     => current_time( 'mysql', true ),
				'hide_sitewide'     => 0,
				'is_spam'           => 0,
			)
		);
		++$comments;
	}
}

printf( "  %d comment(s) across %d activity item(s)\n", $comments, count( (array) $media_activity ) );

echo "\n== source now holds ==\n";
foreach ( array(
	'bp_media'        => 'media rows',
	'bp_media_albums' => 'albums',
	'bp_groups'       => 'groups',
) as $t => $label ) {
	$full = $p . $t;
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
		printf( "  %-14s %d\n", $label, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" ) );
	}
}
printf(
	"  media WITH a file  %d\n",
	(int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$p}bp_media m JOIN {$wpdb->postmeta} pm ON pm.post_id = m.attachment_id AND pm.meta_key = '_wp_attached_file'"
	)
);
