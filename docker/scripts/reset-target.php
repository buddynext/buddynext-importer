<?php
/**
 * Empty the migration TARGET so an import can be re-run from nothing.
 *
 * Clears the BuddyNext side plus the importer's own working tables. The SOURCE
 * community is untouched - the point is to re-run a migration against the same
 * source, not to rebuild the fixture.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$tables = array(
	'bn_profile_values',
	'bn_profile_fields',
	'bn_profile_groups',
	'bn_spaces',
	'bn_space_members',
	'bn_space_categories',
	'bn_space_meta',
	'bn_posts',
	'bn_comments',
	'bn_reactions',
	'bn_connections',
	'bn_follows',
	'bn_member_types',
	'bn_member_type_assignments',
	'mvs_conversations',
	'mvs_messages',
	// The media side, or a re-run accumulates. Without these, every migration
	// left its albums and media records behind: the second run's source scan
	// still said "1 album" while the destination held three, and a tester
	// comparing the two saw phantom albums that no source row explains.
	'mvs_album_items',
	'mvs_media_index',
	'bni_id_map',
	'bni_checkpoint',
);

$cleared = 0;
foreach ( $tables as $table ) {
	$full = $p . $table;
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
		$wpdb->query( "TRUNCATE TABLE `{$full}`" ); // phpcs:ignore WordPress.DB
		++$cleared;
	}
}

// Albums are a CPT, so truncating mvs_album_items only unlinks their contents -
// the album posts themselves survive and the next run adds more beside them.
$albums = get_posts(
	array(
		'post_type'      => 'mvs_album',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( (array) $albums as $album_id ) {
	wp_delete_post( (int) $album_id, true );
}

if ( class_exists( '\BuddyNextImporter\Pipeline\ImportLedger' ) ) {
	\BuddyNextImporter\Pipeline\ImportLedger::drop();
}
delete_option( 'buddynext_importer_bg_job' );
wp_cache_flush();

printf(
	"target reset - %d table(s) emptied, %d album(s) deleted, ledger and background job cleared\n",
	$cleared,
	count( (array) $albums )
);

// NOTE: the WP attachments MediaIngest created are deliberately left. They are
// ordinary attachments with nothing marking them as imported, so deleting by
// guesswork risks taking a source file with them - and this script's contract is
// that the SOURCE is untouched. They cost disk in a throwaway fixture and
// nothing else; the id-map is gone, so the next run re-ingests cleanly.
