<?php
/**
 * Seed a BuddyBoss source with REAL media, albums, group types and comments.
 *
 * Every relation the importer reads needs data, or a green migration only means
 * the populated half worked. The previous BuddyBoss fixture wrote bp_media rows
 * by hand with no file behind them, so ingestion correctly refused all four -
 * proving the refusal path and nothing else.
 *
 * This goes through BuddyBoss's OWN APIs instead, so each object is created the
 * way an upload would create it:
 *
 *   bp_album_add()  -> an album, and the activity announcing it
 *   bp_media_add()  -> a photo in that album, its attachment, and its activity
 *   bp_groups_register_group_type() / set_group_type()
 *                   -> the bp_group_type TAXONOMY rows the importer reads,
 *                      which is the path no generator has ever exercised
 *
 * Files are generated with GD rather than downloaded: an offline fixture cannot
 * depend on a stock-photo host being up, and the importer only cares that a real
 * file exists behind the attachment.
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

if ( ! function_exists( 'bp_media_add' ) ) {
	echo "  BuddyBoss media API is unavailable - is the Media component active?\n";
	return;
}
if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	echo "  GD is unavailable, cannot generate image files\n";
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$members = get_users( array( 'fields' => 'ID', 'number' => 40 ) );
if ( count( $members ) < 5 ) {
	echo "  need more members first\n";
	return;
}

/**
 * Write a real JPEG into the uploads dir and attach it to a member.
 *
 * @param int    $user_id Owner.
 * @param string $label   Drawn on the image so the fixture is legible.
 * @return int Attachment id, or 0.
 */
$make_image = static function ( int $user_id, string $label ): int {
	$w  = 1200;
	$h  = 800;
	$im = imagecreatetruecolor( $w, $h );

	// A flat colour block per image, so they are visually distinguishable in the
	// feed without shipping binary fixtures in the repo.
	$seed = crc32( $label );
	$bg   = imagecolorallocate( $im, 60 + ( $seed % 150 ), 60 + ( ( $seed >> 8 ) % 150 ), 60 + ( ( $seed >> 16 ) % 150 ) );
	imagefilledrectangle( $im, 0, 0, $w, $h, $bg );
	$fg = imagecolorallocate( $im, 255, 255, 255 );
	imagestring( $im, 5, 40, 40, $label, $fg );

	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . sanitize_file_name( $label ) . '-' . wp_generate_password( 6, false ) . '.jpg';
	imagejpeg( $im, $file, 82 );
	imagedestroy( $im );

	$attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $label,
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		),
		$file
	);

	if ( is_wp_error( $attachment ) || ! $attachment ) {
		return 0;
	}

	wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $file ) );

	return (int) $attachment;
};

// ---------------------------------------------------------------- albums --- //

echo "== albums + media, through BuddyBoss's own API ==\n";

$album_titles = array( 'Summer meetup', 'Studio session', 'Trail weekend', 'Community day' );
$albums       = 0;
$photos       = 0;

foreach ( $album_titles as $i => $title ) {
	$owner = (int) $members[ $i % count( $members ) ];

	// Act as the member, so BuddyBoss attributes the album and its activity to
	// them rather than to whoever is running the CLI.
	wp_set_current_user( $owner );

	$album_id = bp_album_add(
		array(
			'user_id' => $owner,
			'title'   => $title,
			'privacy' => 'public',
		)
	);

	if ( ! $album_id || is_wp_error( $album_id ) ) {
		continue;
	}
	++$albums;

	foreach ( range( 1, 3 ) as $n ) {
		$uploader   = (int) $members[ ( $i + $n ) % count( $members ) ];
		$attachment = $make_image( $uploader, $title . ' photo ' . $n );
		if ( ! $attachment ) {
			continue;
		}

		wp_set_current_user( $uploader );
		$media_id = bp_media_add(
			array(
				'attachment_id' => $attachment,
				'user_id'       => $uploader,
				'title'         => $title . ' photo ' . $n,
				'album_id'      => (int) $album_id,
				'privacy'       => 'public',
			)
		);

		if ( $media_id && ! is_wp_error( $media_id ) ) {
			++$photos;
		}
	}
}

// Standalone photos, not in any album - a different code path from album media.
$standalone = 0;
foreach ( range( 1, 5 ) as $n ) {
	$uploader   = (int) $members[ ( $n * 3 ) % count( $members ) ];
	$attachment = $make_image( $uploader, 'Standalone ' . $n );
	if ( ! $attachment ) {
		continue;
	}
	wp_set_current_user( $uploader );
	$media_id = bp_media_add(
		array(
			'attachment_id' => $attachment,
			'user_id'       => $uploader,
			'title'         => 'Standalone ' . $n,
			'privacy'       => 'public',
		)
	);
	if ( $media_id && ! is_wp_error( $media_id ) ) {
		++$standalone;
	}
}

wp_set_current_user( 0 );
printf( "  %d album(s), %d album photo(s), %d standalone photo(s)\n", $albums, $photos, $standalone );

// ----------------------------------------------------------- group types --- //

echo "\n== group types (the bp_group_type taxonomy) ==\n";

$types = array(
	'team'    => 'Teams',
	'club'    => 'Clubs',
	'course'  => 'Courses',
	'support' => 'Support',
);

foreach ( $types as $slug => $name ) {
	if ( function_exists( 'bp_groups_register_group_type' ) ) {
		bp_groups_register_group_type( $slug, array( 'labels' => array( 'name' => $name ) ) );
	}
}

$groups   = $wpdb->get_col( "SELECT id FROM {$p}bp_groups ORDER BY id" );
$slugs    = array_keys( $types );
$assigned = 0;

foreach ( (array) $groups as $i => $group_id ) {
	// Leave every fifth group untyped: a group with no type must still import,
	// with no category rather than a wrong one.
	if ( 4 === $i % 5 ) {
		continue;
	}

	$slug = $slugs[ $i % count( $slugs ) ];
	if ( function_exists( 'bp_groups_set_group_type' ) ) {
		bp_groups_set_group_type( (int) $group_id, $slug );
		++$assigned;

		// The first group gets a SECOND type, so the many-to-one collapse into a
		// single space category is exercised rather than assumed.
		if ( 0 === $i ) {
			bp_groups_set_group_type( (int) $group_id, 'course', true );
		}
	}
}

printf( "  %d type(s) registered, %d group(s) typed\n", count( $types ), $assigned );
printf(
	"  bp_group_type taxonomy rows: %d\n",
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'bp_group_type'" )
);

// -------------------------------------------------------------- comments --- //

echo "\n== comments on the media and album activity ==\n";

// The activity BuddyBoss just created for those uploads is exactly the kind that
// had no comments before, so its comment thread was never migrated or checked.
$media_activity = $wpdb->get_col(
	"SELECT id FROM {$p}bp_activity
	  WHERE component = 'media' OR type IN ( 'activity_update', 'bp_media_upload', 'bp_media_album_created' )
	  ORDER BY id DESC LIMIT 40"
);

$comments = 0;
foreach ( (array) $media_activity as $i => $root ) {
	$n = 1 + ( $i % 3 );
	for ( $c = 1; $c <= $n; $c++ ) {
		$author = (int) $members[ ( $i + $c ) % count( $members ) ];
		$wpdb->insert(
			$p . 'bp_activity',
			array(
				'user_id'           => $author,
				'component'         => 'activity',
				'type'              => 'activity_comment',
				'action'            => 'commented',
				'content'           => sprintf( 'Comment %d on activity %d', $c, (int) $root ),
				'item_id'           => (int) $root,
				'secondary_item_id' => (int) $root,
				'date_recorded'     => current_time( 'mysql', true ),
				'hide_sitewide'     => 0,
				'is_spam'           => 0,
			)
		);
		++$comments;
	}
}

printf( "  %d comment(s) across %d activity item(s)\n", $comments, count( (array) $media_activity ) );

echo "\n== source now holds ==\n";
foreach ( array(
	'bp_media'        => 'media rows',
	'bp_media_albums' => 'albums',
	'bp_groups'       => 'groups',
) as $t => $label ) {
	$full = $p . $t;
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
		printf( "  %-14s %d\n", $label, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" ) );
	}
}
printf( "  media WITH a file  %d\n", (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$p}bp_media m JOIN {$wpdb->postmeta} pm ON pm.post_id = m.attachment_id AND pm.meta_key = '_wp_attached_file'"
) );
