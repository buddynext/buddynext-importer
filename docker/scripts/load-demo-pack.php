<?php
/**
 * Load the Wbcom Reign BuddyPress demo pack as the migration SOURCE.
 *
 * The hand-written fixture in seed-source.sh is deliberately adversarial but
 * small, and it leaves eleven of the sixteen import domains at zero - reactions,
 * messages, forums, media, member types and the rest never run, so "the
 * migration succeeded" only ever meant "the five domains with data succeeded".
 *
 * The Reign demo pack is a real community: 24 xProfile fields across 11 types
 * (checkbox among them, with its option children), groups with members,
 * activity and comments, friendships, BuddyPress Reactions, private messages,
 * and member types. Loading it gives every domain something to move.
 *
 * The demo INSTALLER cannot be used here: it is an AJAX wizard behind a nonce
 * and a capability check, with no WP-CLI path. But the wizard's own remote call
 * is a plain POST returning a manifest of table dumps, so this makes that call
 * directly and loads the tables - the same data, without a browser.
 *
 * Usage: wp eval-file /scripts/load-demo-pack.php
 *
 * @package BuddyNextImporter
 */

global $wpdb;

$target = 'https://installer.wbcomdesigns.com/reign-buddypress/';
$key    = 'demo-export-2024';

// Only source-side tables. The pack also carries posts, options, WooCommerce
// and Action Scheduler state, none of which the importer reads - loading them
// would replace this site's own configuration with the demo site's.
$wanted = array(
	'users',
	'usermeta',
	'bp_xprofile_groups',
	'bp_xprofile_fields',
	'bp_xprofile_data',
	'bp_xprofile_meta',
	'bp_groups',
	'bp_groups_members',
	'bp_groups_groupmeta',
	'bp_activity',
	'bp_activity_meta',
	'bp_friends',
	'bp_messages_messages',
	'bp_messages_recipients',
	'bp_notifications',
);

echo "== fetching the demo manifest ==\n";
$response = wp_remote_post(
	$target . '?wbcom_theme_demo_listing=yes&api_key=' . $key,
	array(
		'timeout'   => 120,
		'headers'   => array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		),
		'body'      => array(
			'theme_slug' => 'reign',
			'demo_slug'  => 'theme_demo',
		),
		'sslverify' => false,
	)
);

if ( is_wp_error( $response ) ) {
	echo '  FAILED: ' . $response->get_error_message() . "\n";
	return;
}

$manifest = json_decode( (string) wp_remote_retrieve_body( $response ), true );
if ( ! is_array( $manifest ) || empty( $manifest['database_tables'] ) ) {
	echo "  FAILED: manifest had no database_tables\n";
	return;
}

// Group the dump URLs by table, preserving the numbered file order so a
// multi-part table loads in the sequence it was exported.
$by_table = array();
foreach ( $manifest['database_tables'] as $url ) {
	$file                = basename( (string) $url );
	$name                = (string) preg_replace( '/_\d+\.json$/', '', $file );
	$by_table[ $name ][] = (string) $url;
}
foreach ( $by_table as $name => $urls ) {
	sort( $urls );
	$by_table[ $name ] = $urls;
}

printf( "  manifest lists %d tables\n\n", count( $by_table ) );
echo "== loading source tables ==\n";

$totals = array();

foreach ( $wanted as $table ) {
	if ( empty( $by_table[ $table ] ) ) {
		printf( "  %-24s -- not in pack, skipped\n", $table );
		continue;
	}

	$full = $wpdb->prefix . $table;

	// The importer reads these tables as the source of truth, so they must hold
	// the demo community and nothing else - a half-replaced table would migrate
	// a mix of two sites. Users are the exception: truncating them would delete
	// the admin running the import.
	if ( 'users' !== $table && 'usermeta' !== $table ) {
		$wpdb->query( "TRUNCATE TABLE `{$full}`" ); // phpcs:ignore WordPress.DB
	}

	$rows_loaded = 0;

	foreach ( $by_table[ $table ] as $url ) {
		$body = wp_remote_retrieve_body(
			wp_remote_get(
				$url,
				array(
					'timeout'   => 180,
					'sslverify' => false,
				)
			)
		);
		$rows = json_decode( (string) $body, true );
		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// Keep the demo's own ids: the whole point is the relationships
			// between them (an activity's item_id is a group id, a comment's
			// item_id is an activity id). Re-keying would break every join the
			// importer relies on.
			if ( 'users' === $table ) {
				// Never overwrite user 1 - that is the account running this.
				if ( 1 === (int) ( $row['ID'] ?? 0 ) ) {
					continue;
				}
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$full}` WHERE ID = %d", // phpcs:ignore WordPress.DB
						(int) $row['ID']
					)
				);
			}

			$wpdb->insert( $full, $row ); // phpcs:ignore WordPress.DB
			if ( '' === $wpdb->last_error ) {
				++$rows_loaded;
			}
		}
	}

	$totals[ $table ] = $rows_loaded;
	printf( "  %-24s %6d rows\n", $table, $rows_loaded );
}

// BuddyPress caches component tables aggressively; a stale cache would make the
// importer read the pre-load state and report an empty source.
wp_cache_flush();

echo "\n== source loaded ==\n";
echo "  xProfile field types present:\n";
$types = $wpdb->get_results(
	"SELECT type, COUNT(*) n FROM {$wpdb->prefix}bp_xprofile_fields GROUP BY type ORDER BY n DESC",
	ARRAY_A
); // phpcs:ignore WordPress.DB
foreach ( (array) $types as $t ) {
	printf( "    %-20s %d\n", (string) $t['type'], (int) $t['n'] );
}
