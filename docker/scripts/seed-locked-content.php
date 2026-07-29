<?php
/**
 * Seed activity the source deliberately kept back.
 *
 * BuddyPress sets hide_sitewide on activity that must not appear in the
 * sitewide feed, and locks a blog post by taking it out of `publish` or giving
 * it a password. Neither generator produces any of this, so the guards against
 * republishing it had no data to prove them - and the failure mode is a
 * migration that discloses content its source withheld.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p     = $wpdb->prefix;
$table = $p . 'bp_activity';

$author = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
$made   = array();

/**
 * Publish a post, record its activity, then lock the post down.
 *
 * @param string $title    Post title.
 * @param array  $override Post fields applied AFTER the activity is recorded.
 * @param int    $hide     hide_sitewide flag on the activity.
 */
$seed = static function ( string $title, array $override, int $hide ) use ( $wpdb, $table, $author, &$made ): void {
	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => 'Body of ' . $title,
			'post_excerpt' => 'Excerpt of ' . $title,
			'post_status'  => 'publish',
			'post_author'  => $author,
			'post_type'    => 'post',
		)
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	$wpdb->insert(
		$table,
		array(
			'user_id'           => $author,
			'component'         => 'blogs',
			'type'              => 'new_blog_post',
			'action'            => 'wrote a new post',
			'content'           => 'Excerpt of ' . $title,
			'primary_link'      => get_permalink( $post_id ),
			'item_id'           => get_current_blog_id(),
			'secondary_item_id' => (int) $post_id,
			'date_recorded'     => current_time( 'mysql', true ),
			'hide_sitewide'     => $hide,
			'is_spam'           => 0,
		)
	);

	// Lock it AFTER recording, which is what happens on a real site - the
	// activity keeps the public flag it was written with.
	if ( ! empty( $override ) ) {
		wp_update_post( array_merge( array( 'ID' => $post_id ), $override ) );
	}

	$made[] = array( (int) $wpdb->insert_id, $title );
};

$seed( 'LOCKED private post', array( 'post_status' => 'private' ), 0 );
$seed( 'LOCKED password protected post', array( 'post_password' => 'secret' ), 0 );
$seed( 'LOCKED reverted to draft', array( 'post_status' => 'draft' ), 0 );
$seed( 'HIDDEN by hide_sitewide', array(), 1 );

// A hidden-group status update, the other thing hide_sitewide marks.
$hidden_group = (int) $wpdb->get_var( "SELECT id FROM {$p}bp_groups WHERE status = 'hidden' ORDER BY id LIMIT 1" );
if ( $hidden_group > 0 ) {
	$wpdb->insert(
		$table,
		array(
			'user_id'       => $author,
			'component'     => 'groups',
			'type'          => 'activity_update',
			'action'        => 'posted an update',
			'content'       => 'HIDDEN GROUP UPDATE - must never reach the sitewide feed',
			'item_id'       => $hidden_group,
			'date_recorded' => current_time( 'mysql', true ),
			'hide_sitewide' => 1,
			'is_spam'       => 0,
		)
	);
	$made[] = array( (int) $wpdb->insert_id, 'hidden-group update' );
}

printf( "seeded %d activities the source keeps back:\n", count( $made ) );
foreach ( $made as $row ) {
	printf( "  activity #%-6d %s\n", $row[0], $row[1] );
}
