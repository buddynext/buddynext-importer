<?php
/**
 * Put BuddyBoss mention placeholders on COMMENTS, not just on posts.
 *
 * BuddyBoss stores a mention as {{mention_user_id_N}} in the href with its own
 * display handle as the anchor text, and it does that on comments exactly as it
 * does on posts. The comment path was fixed separately from the post path, and
 * no fixture had a mention on a comment - so the fix had nothing to prove it.
 *
 * The anchor text is deliberately wrong for this site: only the id identifies the
 * member, and trusting the text produced a confident link to a profile that does
 * not exist here.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$targets = get_users( array( 'fields' => 'ID', 'number' => 8 ) );
if ( count( $targets ) < 2 ) {
	echo "  need more members\n";
	return;
}

// Comments whose root is importable, so the comment itself migrates.
$comments = $wpdb->get_results(
	"SELECT c.id FROM {$p}bp_activity c
	   JOIN {$p}bp_activity r ON r.id = c.item_id
	  WHERE c.type = 'activity_comment' AND c.is_spam = 0
	    AND r.type IN ( 'activity_update', 'new_blog_post' ) AND r.is_spam = 0
	  ORDER BY c.id DESC LIMIT 24",
	ARRAY_A
);

$anchored = 0;
$bare     = 0;

foreach ( $comments as $i => $row ) {
	$target = (int) $targets[ $i % count( $targets ) ];

	// Alternate the two shapes BuddyBoss actually writes.
	if ( 0 === $i % 2 ) {
		$content = sprintf(
			'Good point <a href="{{mention_user_id_%d}}" class="bp-suggestions-mention">@not-this-sites-handle</a> - agreed.',
			$target
		);
		++$anchored;
	} else {
		$content = sprintf( 'Worth a look {{mention_user_id_%d}} when you get a moment.', $target );
		++$bare;
	}

	$wpdb->update( $p . 'bp_activity', array( 'content' => $content ), array( 'id' => (int) $row['id'] ) );
}

printf( "  %d anchored and %d bare mention(s) placed on comments\n", $anchored, $bare );
printf(
	"  source comments now carrying a placeholder: %d\n",
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND content LIKE '%mention_user_id_%'" )
);
