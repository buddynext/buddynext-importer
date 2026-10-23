<?php
/**
 * Did BuddyBoss mention template tags resolve to THIS site's handles?
 *
 * The user id in {{mention_user_id_N}} is the authority; the source anchor text
 * is the handle the source displayed and is frequently not the handle here. So
 * this checks the resolved text against PageRouter::member_handle() per id, not
 * against whatever the source wrote.
 *
 * @package BuddyNextImporter
 */

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT id, content FROM {$wpdb->prefix}bp_activity WHERE content LIKE '%mention_user_id_%'",
	ARRAY_A
);

printf( "source rows carrying a mention tag: %d\n\n", count( $rows ) );

$checked   = 0;
$correct   = 0;
$wrong     = array();
$unmapped  = 0;
$mismatch_handle = 0;

foreach ( $rows as $row ) {
	$aid = (int) $row['id'];

	if ( ! preg_match_all( '/\{\{mention_user_id_(\d+)\}\}/', (string) $row['content'], $m ) ) {
		continue;
	}

	$post_id = \BuddyNextImporter\Pipeline\IdMap::get( 'buddyboss', 'post', $aid );
	$domain  = 'post';
	if ( null === $post_id ) {
		$post_id = \BuddyNextImporter\Pipeline\IdMap::get( 'buddyboss', 'comment', $aid );
		$domain  = 'comment';
	}
	if ( null === $post_id ) {
		++$unmapped;
		continue;
	}

	$table   = 'post' === $domain ? 'bn_posts' : 'bn_comments';
	$migrated = (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT content FROM {$wpdb->prefix}{$table} WHERE id = %d", $post_id )
	);

	foreach ( array_unique( $m[1] ) as $uid ) {
		++$checked;
		$handle = \BuddyNext\Core\PageRouter::member_handle( (int) $uid );

		if ( '' === $handle ) {
			// Deleted member: the source anchor text is kept instead.
			continue;
		}

		if ( false !== strpos( $migrated, '@' . $handle ) ) {
			++$correct;
		} else {
			$wrong[] = sprintf( '%s %d (source activity %d, user %d, expected @%s)', $domain, $post_id, $aid, $uid, $handle );
		}

		// The trap: did the SOURCE handle differ from this site's handle?
		$user = get_userdata( (int) $uid );
		if ( $user instanceof \WP_User && $user->user_nicename !== $handle ) {
			++$mismatch_handle;
		}
	}
}

printf( "mentions checked: %d | resolved to this site's handle: %d | wrong: %d | unmapped rows: %d\n", $checked, $correct, count( $wrong ), $unmapped );
printf( "of those, ids whose handle differs from user_nicename: %d\n", $mismatch_handle );

foreach ( array_slice( $wrong, 0, 10 ) as $w ) {
	printf( "  WRONG: %s\n", $w );
}

$leftover_posts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE content LIKE '%mention_user_id%'" );
$leftover_comments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE content LIKE '%mention_user_id%'" );
printf( "\nraw template tags left in migrated content: %d post(s), %d comment(s)\n", $leftover_posts, $leftover_comments );
