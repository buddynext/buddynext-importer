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

if ( class_exists( '\BuddyNextImporter\Pipeline\ImportLedger' ) ) {
	\BuddyNextImporter\Pipeline\ImportLedger::drop();
}
delete_option( 'buddynext_importer_bg_job' );
wp_cache_flush();

printf( "target reset - %d table(s) emptied, ledger and background job cleared\n", $cleared );
