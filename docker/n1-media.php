<?php
/**
 * Measures the activity-media N+1 fix empirically: how many queries the batch
 * lookup issues for a page of activities, against what the per-row lookup the
 * importer used to call would have cost for the same page.
 *
 * Run: ./wp.sh eval-file /var/www/html/wp-content/plugins/buddynext-importer/docker/n1-media.php
 */

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

global $wpdb;

$source = \BuddyNextImporter\Source\AdapterRegistry::detect_active_key();
if ( null === $source ) {
	// The source platform is deactivated before a migration by design, so fall
	// back to whichever adapter still finds its tables.
	foreach ( array_keys( \BuddyNextImporter\Source\AdapterRegistry::all() ) as $key ) {
		$candidate = \BuddyNextImporter\Source\AdapterRegistry::get( $key );
		if ( null !== $candidate && $candidate->is_available() ) {
			$source = $key;
			break;
		}
	}
}
$adapter = \BuddyNextImporter\Source\AdapterRegistry::get( (string) $source );
if ( null === $adapter ) {
	echo "no source adapter\n";
	return;
}

printf( "source: %s\n\n", $source );

$media_tables = array( $wpdb->prefix . 'bp_media', $wpdb->prefix . 'bp_document', $wpdb->prefix . 'bp_video' );

// Every query the lookup issues, not just the ones naming a media table: the
// batch's own work is a bp_activity_meta read, and the per-row cost that mattered
// most turned out to be a SHOW TABLES guard. Filtering by table name measured
// neither.
$count_media_queries = static function ( array $queries ): int {
	return count( $queries );
};
unset( $media_tables );

foreach ( array( 50, 200, 500 ) as $limit ) {
	$rows = $adapter->activities( 0, $limit );
	$ids  = array();
	foreach ( $rows as $r ) {
		$ids[] = (int) $r['source_id'];
	}
	if ( array() === $ids ) {
		echo "no activities in source\n";
		break;
	}

	// Batch: what the importer actually calls.
	$wpdb->queries = array();
	$t0            = microtime( true );
	$batch         = $adapter->activity_media_for( $ids );
	$batch_q       = $count_media_queries( $wpdb->queries );
	$batch_ms      = ( microtime( true ) - $t0 ) * 1000;

	// Per row: the shape the importer used to have.
	$wpdb->queries = array();
	$t0            = microtime( true );
	$per_row       = array();
	foreach ( $ids as $id ) {
		$m = $adapter->activity_media( $id );
		if ( array() !== $m ) {
			$per_row[ $id ] = $m;
		}
	}
	$row_q  = $count_media_queries( $wpdb->queries );
	$row_ms = ( microtime( true ) - $t0 ) * 1000;

	// The batch is only a valid replacement if it returns the same thing.
	ksort( $batch );
	ksort( $per_row );
	$same = $batch === $per_row ? 'IDENTICAL' : 'MISMATCH';

	printf(
		"page of %4d activities | batch: %2d queries, %6.1fms | per-row: %4d queries, %7.1fms | %5.0fx fewer queries | results %s\n",
		count( $ids ),
		$batch_q,
		$batch_ms,
		$row_q,
		$row_ms,
		$row_q > 0 && $batch_q > 0 ? $row_q / $batch_q : 0,
		$same
	);

	if ( 'MISMATCH' === $same ) {
		printf( "  batch keys: %d, per-row keys: %d\n", count( $batch ), count( $per_row ) );
		$only_batch = array_diff_key( $batch, $per_row );
		$only_row   = array_diff_key( $per_row, $batch );
		printf( "  only in batch: %s\n", implode( ',', array_slice( array_keys( $only_batch ), 0, 10 ) ) );
		printf( "  only in per-row: %s\n", implode( ',', array_slice( array_keys( $only_row ), 0, 10 ) ) );
	}
}

printf( "\nactivities with media in source: %d\n", count( $adapter->activity_media_for( array_map( static fn( $r ) => (int) $r['source_id'], $adapter->activities( 0, 100000 ) ) ) ) );
