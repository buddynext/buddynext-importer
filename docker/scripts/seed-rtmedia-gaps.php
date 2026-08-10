<?php
/**
 * Seed the two rtMedia shapes the fixture was missing: a GROUP album, and media
 * that was never posted to activity.
 *
 * Written through rtMedia's OWN classes (RTMediaAlbum, RTMediaModel) rather than
 * raw SQL, so the rows are shaped the way rtMedia shapes them - the same reason
 * the activity photo was captured from a real browser upload rather than
 * hand-written. Raw INSERTs would prove only that the reader matches my
 * assumption about the schema.
 */

if ( ! class_exists( 'RTMediaAlbum' ) || ! class_exists( 'RTMediaModel' ) ) {
	echo "rtMedia classes unavailable - is buddypress-media active?\n";
	return;
}

$admin  = 1;
$groups = groups_get_groups( array( 'per_page' => 1 ) );
$group  = isset( $groups['groups'][0] ) ? (int) $groups['groups'][0]->id : 0;

if ( $group <= 0 ) {
	echo "no group to attach an album to\n";
	return;
}

// A real image on disk, so the migration has an actual file to ingest.
$src = wp_upload_dir();
$dir = trailingslashit( $src['basedir'] ) . 'bi-gap-fixtures';
wp_mkdir_p( $dir );

$make = function ( $path, $w, $h, $rgb ) {
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	imagepng( $im, $path );
	imagedestroy( $im );
	return $path;
};

$album_img = $make( $dir . '/bi-group-album-photo.png', 420, 280, array( 200, 120, 40 ) );
$loose_img = $make( $dir . '/bi-gallery-only-photo.png', 360, 240, array( 40, 160, 120 ) );

$model = new RTMediaModel();

// ---------------------------------------------------------------- group album
$album    = new RTMediaAlbum();
$album_id = $album->add( 'Garden Shots', $admin, true, false, 'group', $group );
printf( "group album: rt id %d (group %d)\n", (int) $album_id, $group );

/**
 * Attach a real file as an rtMedia row.
 *
 * @param string $file       Absolute path.
 * @param int    $author     Author id.
 * @param int    $album      rt_rtm_media.id of the album, or 0.
 * @param string $context    rtMedia context.
 * @param int    $context_id Context id.
 * @param object $model      RTMediaModel.
 */
function bi_attach( $file, $author, $album, $context, $context_id, $model ) {
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => sanitize_file_name( basename( $file, '.png' ) ),
			'post_status'    => 'inherit',
			'post_author'    => $author,
		),
		$file
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

	$row = array(
		'blog_id'      => get_current_blog_id(),
		'media_id'     => $id,
		'album_id'     => $album,
		'media_author' => $author,
		'media_title'  => get_the_title( $id ),
		'media_type'   => 'photo',
		'context'      => $context,
		'context_id'   => $context_id,
		'privacy'      => 0,
		'upload_date'  => current_time( 'mysql' ),
		'file_size'    => filesize( $file ),
	);

	return array( (int) $model->insert( $row ), (int) $id );
}

list( $in_album, $att_a ) = bi_attach( $album_img, $admin, (int) $album_id, 'group', $group, $model );
printf( "  photo in group album: rt id %d (attachment %d)\n", $in_album, $att_a );

// ------------------------------------------------- gallery-only, no activity
list( $loose, $att_b ) = bi_attach( $loose_img, $admin, 0, 'profile', $admin, $model );
printf( "gallery-only photo: rt id %d (attachment %d) - no album, no activity\n", $loose, $att_b );

echo "\n== rt_rtm_media now ==\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT id, media_id, media_type, album_id, context, context_id, activity_id FROM {$wpdb->prefix}rt_rtm_media ORDER BY id", ARRAY_A );
foreach ( $rows as $r ) {
	printf(
		"  id=%-3s media_id=%-5s type=%-6s album=%-5s ctx=%-8s ctx_id=%-4s activity=%s\n",
		$r['id'],
		$r['media_id'],
		$r['media_type'],
		null === $r['album_id'] ? 'NULL' : $r['album_id'],
		null === $r['context'] ? 'NULL' : $r['context'],
		null === $r['context_id'] ? 'NULL' : $r['context_id'],
		null === $r['activity_id'] ? 'NULL' : $r['activity_id']
	);
}
