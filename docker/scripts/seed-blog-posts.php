<?php
/**
 * Seed REALISTIC new_blog_post activities, with their comments.
 *
 * The playground's generated rows are synthetic - secondary_item_id 0 and a
 * primary_link pointing at /blog/ - so they cannot exercise the path that
 * matters: resolving the published post locally to build a link card without an
 * HTTP fetch. A real BuddyPress site records the post ID in secondary_item_id
 * and the permalink in primary_link, which is what this reproduces.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p     = $wpdb->prefix;
$table = $p . 'bp_activity';

$authors = get_users( array( 'fields' => 'ID', 'number' => 12 ) );
if ( empty( $authors ) ) {
	echo "  no users to author posts\n";
	return;
}

$titles = array(
	'How our community grew tenfold in a year',
	'Five things we learned running a member survey',
	'A field guide to moderating difficult threads',
	'Why we moved our forums in-house',
	'The quiet work of keeping a community kind',
	'What our most active members have in common',
);

$made     = 0;
$comments = 0;

foreach ( $titles as $i => $title ) {
	$author = (int) $authors[ $i % count( $authors ) ];

	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'A real published article on this site. ' . str_repeat( 'It has body text worth excerpting. ', 12 ),
			'post_excerpt' => 'A short, hand-written excerpt that a link card should prefer over the body.',
			'post_status'  => 'publish',
			'post_author'  => $author,
			'post_type'    => 'post',
		)
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		continue;
	}

	// A real new_blog_post: item_id is the blog, secondary_item_id is the POST,
	// primary_link is its permalink.
	$wpdb->insert(
		$table,
		array(
			'user_id'           => $author,
			'component'         => 'blogs',
			'type'              => 'new_blog_post',
			'action'            => sprintf( 'wrote a new post, %s', $title ),
			'content'           => get_the_excerpt( $post_id ),
			'primary_link'      => get_permalink( $post_id ),
			'item_id'           => get_current_blog_id(),
			'secondary_item_id' => (int) $post_id,
			'date_recorded'     => current_time( 'mysql', true ),
			'hide_sitewide'     => 0,
			'is_spam'           => 0,
		)
	);
	$root_id = (int) $wpdb->insert_id;
	++$made;

	// The comment thread that only migrates if its root does.
	$n = 2 + ( $i % 4 );
	for ( $c = 1; $c <= $n; $c++ ) {
		$wpdb->insert(
			$table,
			array(
				'user_id'           => (int) $authors[ ( $i + $c ) % count( $authors ) ],
				'component'         => 'activity',
				'type'              => 'activity_comment',
				'action'            => 'commented',
				'content'           => sprintf( 'Comment %d on "%s"', $c, $title ),
				'item_id'           => $root_id,
				'secondary_item_id' => $root_id,
				'date_recorded'     => current_time( 'mysql', true ),
				'hide_sitewide'     => 0,
				'is_spam'           => 0,
			)
		);
		++$comments;
	}
}

printf( "seeded %d new_blog_post activities (real post ids + permalinks) with %d comments\n", $made, $comments );
