<?php
/**
 * Seed comments whose ROOT is not an activity_update.
 *
 * The posts pass imports only `activity_update`, so a comment on any other root
 * has no post to attach to and cannot migrate. Neither fixture contained one,
 * so the path had no data and a green run said nothing about it - which is
 * exactly how the shortfall reached a bug report instead of a decision.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p     = $wpdb->prefix;
$table = $p . 'bp_activity';

$user = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );

// Two roots of types the importer does not carry, each with comments on it.
$roots = array(
	'new_blog_post' => 3,
	'new_member'    => 2,
);

$made = 0;
foreach ( $roots as $type => $comments ) {
	$wpdb->insert(
		$table,
		array(
			'user_id'       => $user,
			'component'     => 'activity',
			'type'          => $type,
			'action'        => $type . ' root',
			'content'       => 'root activity of type ' . $type,
			'item_id'       => 0,
			'date_recorded' => current_time( 'mysql', true ),
			'hide_sitewide' => 0,
			'is_spam'       => 0,
		)
	);
	$root_id = (int) $wpdb->insert_id;

	for ( $i = 1; $i <= $comments; $i++ ) {
		$wpdb->insert(
			$table,
			array(
				'user_id'           => $user,
				'component'         => 'activity',
				'type'              => 'activity_comment',
				'action'            => 'comment',
				'content'           => 'comment ' . $i . ' on a ' . $type . ' root',
				'item_id'           => $root_id,
				'secondary_item_id' => $root_id,
				'date_recorded'     => current_time( 'mysql', true ),
				'hide_sitewide'     => 0,
				'is_spam'           => 0,
			)
		);
		++$made;
	}
}

printf( "seeded %d comments on non-importable roots\n", $made );
