<?php
/**
 * Seed reactions that target a COMMENT rather than a top-level activity.
 *
 * A comment is an activity in the source - type activity_comment - so a reaction
 * row on one is indistinguishable from a reaction on a post: same table, same
 * item_type, just a different id. The generators only ever react to top-level
 * activity, so this path had no data and the importer's handling of it was
 * asserted rather than verified.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$table = $p . 'bb_user_reactions';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
	echo "  bb_user_reactions is absent - not a BuddyBoss source\n";
	return;
}

// Comments whose ROOT is importable, so the comment itself will be migrated and
// the reaction has a parent to attach to.
$comments = $wpdb->get_col(
	"SELECT c.id FROM {$p}bp_activity c
	   JOIN {$p}bp_activity r ON r.id = c.item_id
	  WHERE c.type = 'activity_comment' AND c.is_spam = 0
	    AND r.type IN ( 'activity_update', 'new_blog_post' ) AND r.is_spam = 0
	  ORDER BY c.id LIMIT 60"
);

if ( array() === (array) $comments ) {
	echo "  no importable comments to react to\n";
	return;
}

$members = get_users(
	array(
		'fields' => 'ID',
		'number' => 20,
	)
);
if ( count( $members ) < 2 ) {
	echo "  need more members\n";
	return;
}

// Mirror the column layout BuddyBoss uses, so the adapter reads these exactly as
// it reads the real ones.
$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
$made    = 0;

foreach ( (array) $comments as $i => $comment_id ) {
	$reactors = 1 + ( $i % 3 );

	for ( $r = 0; $r < $reactors; $r++ ) {
		$row = array(
			'user_id'   => (int) $members[ ( $i + $r ) % count( $members ) ],
			'item_id'   => (int) $comment_id,
			'item_type' => 'activity',
		);

		// Optional columns differ between BuddyBoss builds; only set what exists.
		if ( in_array( 'reaction_id', $columns, true ) ) {
			$row['reaction_id'] = (int) $wpdb->get_var( "SELECT reaction_id FROM `{$table}` LIMIT 1" );
		}
		if ( in_array( 'date_created', $columns, true ) ) {
			$row['date_created'] = current_time( 'mysql', true );
		}
		if ( in_array( 'blog_id', $columns, true ) ) {
			$row['blog_id'] = get_current_blog_id();
		}

		$wpdb->insert( $table, $row );
		if ( '' === $wpdb->last_error ) {
			++$made;
		}
	}
}

printf( "  %d reaction(s) on %d comment(s)\n", $made, count( (array) $comments ) );
printf(
	"  source reactions now target: %d post-ish activity, %d comment(s)\n",
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` r JOIN {$p}bp_activity a ON a.id = r.item_id WHERE a.type <> 'activity_comment'" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` r JOIN {$p}bp_activity a ON a.id = r.item_id WHERE a.type = 'activity_comment'" )
);
