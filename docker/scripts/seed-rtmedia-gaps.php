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
	exit( 1 );
}

global $wpdb;

/**
 * Make sure rtMedia's own tables exist before anything tries to write to them.
 *
 * On a FRESH install under WP-CLI they do not, and rtMedia does not notice.
 * RTDBUpdate::do_upgrade() is gated on
 * `version_compare( db_version, install_db_version, '>' )`, and the install
 * option is already stamped at the current version by the time the schema
 * would be built - so the upgrade path runs (ALTERing tables that were never
 * created, which is what fills the log with "Table wp_rt_rtm_media doesn't
 * exist") while the create path never does. rtMedia's own self-heal in
 * update_db() calls do_upgrade() again, and that lands on dbDelta, which
 * silently creates nothing here and reports no error.
 *
 * Observed exactly once as a silent failure: this script printed three "rt id 0"
 * lines and an empty table dump, the seed exited 0, and the fixture looked
 * built. That is the failure mode this whole fixture exists to prevent, so the
 * tables are built from rtMedia's OWN schema files and then VERIFIED.
 */
$rtm_media_table = $wpdb->prefix . 'rt_rtm_media';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rtm_media_table ) ) !== $rtm_media_table ) {
	echo "  rtMedia tables missing - building them from its own schema files\n";

	$updater = new RTDBUpdate( false, RTMEDIA_PATH . 'index.php', RTMEDIA_PATH . 'app/schema/', true );
	foreach ( (array) glob( RTMEDIA_PATH . 'app/schema/*.schema' ) as $schema ) {
		$wpdb->query( $updater->genrate_sql( basename( $schema ), (string) file_get_contents( $schema ) ) ); // phpcs:ignore WordPress.DB
	}
}

if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rtm_media_table ) ) !== $rtm_media_table ) {
	echo "ERROR: rt_rtm_media still does not exist - every rtMedia path would be untested.\n";
	exit( 1 );
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

// ------------------------------------- profile album + a photo ON an activity
//
// The shape Phase 1 of the card is about: rtMedia uploads are `rtmedia_update`
// activities, and the photo rides that activity into a post. A fresh fixture had
// none of these - the only one that ever existed came from a browser upload by
// hand - so activity_media_for()'s rtMedia path and the whole "photos ride their
// activity" claim had nothing proving them on a rebuilt fixture.
//
// The activity content is rtMedia's REAL wrapper markup, copied from a genuine
// upload rather than simplified. That matters twice over: it is what
// activity_media_for() reads around, and it is what ActivityWriter's
// clean_content() has to strip - the indentation and the media-list title are
// precisely what used to leak into migrated post bodies (#10185722422). A
// tidied-up sample would quietly stop testing the fix.
$wall_img  = $make( $dir . '/bi-wall-post-photo.png', 600, 400, array( 30, 120, 200 ) );
$wall      = new RTMediaAlbum();
$wall_id   = $wall->add( 'Wall Posts', $admin, true, false, 'profile', $admin );
$wall_att  = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'bi-wall-post-photo',
		'post_status'    => 'inherit',
		'post_author'    => $admin,
	),
	$wall_img
);
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $wall_att, wp_generate_attachment_metadata( $wall_att, $wall_img ) );

$media_url   = wp_get_attachment_url( $wall_att );
$rtm_content = '<div class="rtmedia-activity-container"><div class="rtmedia-activity-text">' . "\n\t\t\t\t\t"
	. '<span>A photo posted through rtMedia</span>' . "\n\t\t\t\t"
	. '</div><ul class="rtmedia-list rtm-activity-media-list rtmedia-activity-media-length-1 rtm-activity-photo-list">'
	. '<li class="rtmedia-list-item media-type-photo"><a href="' . esc_url( home_url( '/members/admin/media/' ) ) . '">' . "\n\t\t\t\t\t\t"
	. '<div class="rtmedia-item-thumbnail">' . "\n\t\t\t\t\t\t\t"
	. '<img alt="bi-wall-post-photo" src="' . esc_url( (string) $media_url ) . '" />' . "\n\t\t\t\t\t\t"
	. '</div>' . "\n\t\t\t\t\t\t"
	. '<div class="rtmedia-item-title">' . "\n\t\t\t\t\t\t\t"
	. '<h4 title="bi-wall-post-photo">' . "\n\t\t\t\t\t\t\t\t"
	. 'bi-wall-post-photo' . "\n\t\t\t\t\t\t\t"
	. '</h4>' . "\n\t\t\t\t\t\t"
	. '</div>' . "\n\t\t\t\t\t"
	. '</a></li></ul></div>';

$activity_id = bp_activity_add(
	array(
		'user_id'   => $admin,
		'component' => 'activity',
		'type'      => 'rtmedia_update',
		'content'   => $rtm_content,
		'recorded_time' => current_time( 'mysql', true ),
	)
);

$wall_row = $model->insert(
	array(
		'blog_id'      => get_current_blog_id(),
		'media_id'     => $wall_att,
		'album_id'     => (int) $wall_id,
		'media_author' => $admin,
		'media_title'  => 'bi-wall-post-photo',
		'media_type'   => 'photo',
		'context'      => 'profile',
		'context_id'   => $admin,
		'activity_id'  => (int) $activity_id,
		'privacy'      => 0,
		'upload_date'  => current_time( 'mysql' ),
		'file_size'    => filesize( $wall_img ),
	)
);
printf(
	"profile album: rt id %d | photo on activity %d: rt id %d (attachment %d)\n",
	(int) $wall_id,
	(int) $activity_id,
	(int) $wall_row,
	(int) $wall_att
);

// Assert, rather than trust the printed ids. An insert that returns 0 leaves a
// fixture that looks seeded and proves nothing - which is exactly how this
// script failed the first time it ran on a clean build.
$expected = array(
	'group album'            => (int) $album_id,
	'photo in group album'   => $in_album,
	'gallery-only photo'     => $loose,
	'profile album'          => (int) $wall_id,
	'rtmedia_update activity' => (int) $activity_id,
	'photo on that activity' => (int) $wall_row,
);
$missing  = array();
foreach ( $expected as $label => $id ) {
	if ( $id <= 0 ) {
		$missing[] = $label;
	}
}
if ( ! empty( $missing ) ) {
	printf( "ERROR: rtMedia seeding produced no row for: %s\n", implode( ', ', $missing ) );
	echo "       standalone_media() and the group-album routing would be untested.\n";
	exit( 1 );
}

echo "\n== rt_rtm_media now ==\n";
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
