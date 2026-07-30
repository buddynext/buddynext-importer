<?php
/**
 * Seed the source shapes that only exist on BuddyBoss.
 *
 * CLAUDE.md records that the BuddyBoss paths have no automated coverage. These
 * are the two the newest importer commit addressed, and neither can occur on a
 * BuddyPress source:
 *
 *   1. Album media. bp_media rows carry album_id, and the adapter deliberately
 *      treats a row with activity_id = 0 as standalone so it is not imported
 *      twice - a card once asked for that filter to be deleted, which would have
 *      duplicated media rather than fixing albums.
 *   2. A mention stored as {{mention_user_id_N}} in the href, with BuddyBoss's
 *      OWN display handle as the anchor text. The id is authoritative and the
 *      text is not: trusting the text produced a confident link to a profile
 *      that does not exist on the destination.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$has = static function ( string $table ) use ( $wpdb, $p ): bool {
	$full = $p . $table;
	return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
};

if ( ! $has( 'bp_media' ) ) {
	echo "  bp_media is absent - this is not a BuddyBoss source, skipping\n";
	return;
}

$users = get_users(
	array(
		'fields' => 'ID',
		'number' => 6,
	)
);
if ( count( $users ) < 2 ) {
	echo "  need at least two users\n";
	return;
}

// -- 1. an album with photos in it ---------------------------------------- //

$owner    = (int) $users[0];
$album_id = 0;

if ( $has( 'bp_media_albums' ) ) {
	$wpdb->insert(
		$p . 'bp_media_albums',
		array(
			'user_id'      => $owner,
			'group_id'     => 0,
			'title'        => 'Trip photos',
			'privacy'      => 'public',
			'date_created' => current_time( 'mysql', true ),
		)
	);
	$album_id = (int) $wpdb->insert_id;
}

$made_media = 0;
foreach ( range( 1, 4 ) as $i ) {
	// A real attachment, so MediaIngest has something to ingest.
	$attachment = wp_insert_post(
		array(
			'post_title'     => 'BB photo ' . $i,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
			'post_author'    => $owner,
		)
	);

	if ( is_wp_error( $attachment ) || ! $attachment ) {
		continue;
	}

	$wpdb->insert(
		$p . 'bp_media',
		array(
			'blog_id'       => get_current_blog_id(),
			'attachment_id' => (int) $attachment,
			'user_id'       => $owner,
			'title'         => 'BB photo ' . $i,
			'album_id'      => $album_id,
			'group_id'      => 0,
			'activity_id'   => 0,
			'privacy'       => 'public',
			'menu_order'    => $i,
			'date_created'  => current_time( 'mysql', true ),
			'status'        => 'published',
		)
	);
	++$made_media;
}

// -- 2. a mention stored the BuddyBoss way -------------------------------- //

$target = (int) $users[1];
$author = (int) $users[2] ?? $owner;

// The anchor text is deliberately WRONG for this site, which is the whole point:
// only the id in the href identifies the member.
$html = sprintf(
	'Great point <a href="{{mention_user_id_%d}}" class="bp-suggestions-mention">@someone-elses-handle</a> - agreed.',
	$target
);

$wpdb->insert(
	$p . 'bp_activity',
	array(
		'user_id'       => $author,
		'component'     => 'activity',
		'type'          => 'activity_update',
		'action'        => 'posted an update',
		'content'       => $html,
		'item_id'       => 0,
		'date_recorded' => current_time( 'mysql', true ),
		'hide_sitewide' => 0,
		'is_spam'       => 0,
	)
);
$mention_activity = (int) $wpdb->insert_id;

// And the bare placeholder form, with no anchor around it.
$wpdb->insert(
	$p . 'bp_activity',
	array(
		'user_id'       => $author,
		'component'     => 'activity',
		'type'          => 'activity_update',
		'action'        => 'posted an update',
		'content'       => sprintf( 'Bare placeholder {{mention_user_id_%d}} in the body.', $target ),
		'item_id'       => 0,
		'date_recorded' => current_time( 'mysql', true ),
		'hide_sitewide' => 0,
		'is_spam'       => 0,
	)
);

printf( "  album #%d with %d photo(s) (activity_id = 0, so they are standalone)\n", $album_id, $made_media );
printf( "  mention activities: #%d (anchored) and #%d (bare), both targeting user %d\n", $mention_activity, (int) $wpdb->insert_id, $target );
printf( "  that user's real handle here: %s\n", class_exists( '\BuddyNext\Core\PageRouter' ) ? \BuddyNext\Core\PageRouter::member_handle( $target ) : '(BuddyNext inactive)' );
