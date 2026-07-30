<?php
/**
 * Did activity-attached photos survive the migration?
 *
 * Walks every source activity carrying bp_media_ids, resolves it through the
 * id-map to its BuddyNext post, and compares the photo count on each side. A
 * batched media lookup that returns the right map still proves nothing unless
 * the photos actually land on the migrated post.
 *
 * @package BuddyNextImporter
 */

global $wpdb;

$source  = 'buddyboss';
$adapter = \BuddyNextImporter\Source\AdapterRegistry::get( $source );
if ( null === $adapter ) {
	echo "no adapter\n";
	return;
}

$rows = $wpdb->get_results(
	"SELECT activity_id, meta_value FROM {$wpdb->prefix}bp_activity_meta WHERE meta_key = 'bp_media_ids' ORDER BY activity_id ASC",
	ARRAY_A
);

printf( "source activities carrying photos: %d\n\n", count( $rows ) );

$ok        = 0;
$unmapped  = 0;
$mismatch  = 0;
$src_total = 0;
$bn_total  = 0;

foreach ( $rows as $row ) {
	$aid        = (int) $row['activity_id'];
	$expected   = $adapter->activity_media_for( array( $aid ) )[ $aid ] ?? array();
	$src_n      = count( $expected );
	$src_total += $src_n;

	$post_id = \BuddyNextImporter\Pipeline\IdMap::get( $source, 'post', $aid );
	if ( null === $post_id ) {
		++$unmapped;
		printf( "  activity %-6d -> NOT MAPPED (%d photo(s) in source)\n", $aid, $src_n );
		continue;
	}

	// BuddyNext keeps a post's media as a JSON id list on the row itself.
	$raw       = (string) $wpdb->get_var( $wpdb->prepare( "SELECT media_ids FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) );
	$decoded   = json_decode( $raw, true );
	$bn_media  = is_array( $decoded ) ? $decoded : array_filter( array_map( 'intval', explode( ',', $raw ) ) );
	$bn_n      = count( $bn_media );
	$bn_total += $bn_n;

	$type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) );

	if ( $bn_n === $src_n ) {
		++$ok;
		printf( "  activity %-6d -> post %-6d  %d/%d photo(s)  type=%s  OK\n", $aid, $post_id, $bn_n, $src_n, (string) $type );
	} else {
		++$mismatch;
		printf( "  activity %-6d -> post %-6d  %d/%d photo(s)  type=%s  MISMATCH\n", $aid, $post_id, $bn_n, $src_n, (string) $type );
	}
}

printf(
	"\n%d matched, %d mismatched, %d unmapped | photos: %d in source, %d on migrated posts\n",
	$ok,
	$mismatch,
	$unmapped,
	$src_total,
	$bn_total
);

// A media post with no media would render as an empty card.
$empty = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
	  WHERE type = 'media' AND ( media_ids IS NULL OR media_ids = '' OR media_ids = '[]' )"
);
printf( "media-type posts with no media attached: %d\n", $empty );
