<?php
/**
 * Seed a BuddyBoss source with ACTIVITY-ATTACHED media - photos posted straight
 * into the feed, in a space, and as a multi-photo post.
 *
 * This is the commonest media shape on a real BuddyBoss community and the one
 * shape the fixture had none of: seed-bb-rich.php creates album media and
 * standalone media, both of which leave `bp_media.activity_id` at 0. The
 * importer's activity-media path reads `bp_activity_meta.bp_media_ids`, so with
 * no such row it returned an empty map on every page - and a comparison of the
 * batched lookup against the per-row one it replaced agreed only because both
 * sides were empty. Measuring a query saving proves nothing if the result set is
 * empty either way.
 *
 * Created through BuddyBoss's own bp_media_add(), passing activity_id, then
 * writing the bp_media_ids activity meta exactly as bp-media-filters.php does
 * on a real upload (implode( ',', $media_ids ) against the parent activity).
 *
 * @package BuddyNextImporter
 */

global $wpdb;

if ( ! function_exists( 'bp_media_add' ) || ! function_exists( 'bp_activity_add' ) ) {
	echo "  BuddyBoss media/activity API unavailable - activate BuddyBoss first\n";
	return;
}
if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	echo "  GD is unavailable, cannot generate image files\n";
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$members = get_users(
	array(
		'fields' => 'ID',
		'number' => 40,
	)
);
if ( count( $members ) < 5 ) {
	echo "  need more members first\n";
	return;
}

/**
 * Write a real JPEG into uploads and attach it to a member. Same approach as
 * seed-bb-rich.php: generated, not downloaded, so the fixture works offline.
 *
 * @param int    $user_id Owner.
 * @param string $label   Drawn on the image so the fixture is legible.
 * @return int Attachment id, or 0.
 */
$make_image = static function ( int $user_id, string $label ): int {
	$w  = 1200;
	$h  = 800;
	$im = imagecreatetruecolor( $w, $h );

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

/**
 * Post one feed activity with N photos attached, the way an upload does.
 *
 * @param int    $user_id  Author.
 * @param string $content  Activity text.
 * @param int    $photos   How many photos to attach.
 * @param int    $group_id Space id, or 0 for a sitewide post.
 * @return array{activity:int,media:array<int,int>}
 */
$post_with_photos = static function ( int $user_id, string $content, int $photos, int $group_id = 0 ) use ( $make_image ): array {
	wp_set_current_user( $user_id );

	$args = array(
		'user_id'   => $user_id,
		'action'    => '',
		'content'   => $content,
		'component' => 0 === $group_id ? 'activity' : 'groups',
		'type'      => 'activity_update',
		'item_id'   => $group_id,
	);
	if ( 0 !== $group_id ) {
		$args['hide_sitewide'] = false;
	}

	$activity_id = bp_activity_add( $args );
	if ( ! $activity_id || is_wp_error( $activity_id ) ) {
		return array(
			'activity' => 0,
			'media'    => array(),
		);
	}

	$media_ids = array();
	foreach ( range( 1, max( 1, $photos ) ) as $n ) {
		$attachment = $make_image( $user_id, substr( $content, 0, 30 ) . ' ' . $n );
		if ( ! $attachment ) {
			continue;
		}

		$media_id = bp_media_add(
			array(
				'attachment_id' => $attachment,
				'user_id'       => $user_id,
				'title'         => substr( $content, 0, 30 ) . ' ' . $n,
				'activity_id'   => (int) $activity_id,
				'group_id'      => $group_id,
				'privacy'       => 0 === $group_id ? 'public' : 'grouponly',
			)
		);

		if ( $media_id && ! is_wp_error( $media_id ) ) {
			$media_ids[] = (int) $media_id;
		}
	}

	// What bp-media-filters.php writes on a real upload. Without it the media
	// rows exist but no activity points at them, which is not a shape BuddyBoss
	// ever produces.
	if ( array() !== $media_ids ) {
		bp_activity_update_meta( (int) $activity_id, 'bp_media_ids', implode( ',', $media_ids ) );
	}

	return array(
		'activity' => (int) $activity_id,
		'media'    => $media_ids,
	);
};

echo "== activity-attached media (feed photos) ==\n";

$made      = 0;
$total_med = 0;

// Single-photo sitewide posts - the ordinary case.
foreach ( range( 1, 6 ) as $n ) {
	$owner  = (int) $members[ $n % count( $members ) ];
	$result = $post_with_photos( $owner, 'Photo from the weekend ' . $n, 1 );
	if ( $result['activity'] > 0 && array() !== $result['media'] ) {
		++$made;
		$total_med += count( $result['media'] );
	}
}

// Multi-photo posts - one activity to many media rows, which is where a per-row
// lookup and a batched one can most easily disagree on ordering.
foreach ( range( 1, 3 ) as $n ) {
	$owner  = (int) $members[ ( $n * 5 ) % count( $members ) ];
	$result = $post_with_photos( $owner, 'Album dump ' . $n, 4 );
	if ( $result['activity'] > 0 && array() !== $result['media'] ) {
		++$made;
		$total_med += count( $result['media'] );
	}
}

// In-space photos, so the privacy path has activity media to carry too.
$group_ids = function_exists( 'groups_get_groups' )
	? (array) ( groups_get_groups(
		array(
			'per_page' => 3,
			'fields'   => 'ids',
		)
	)['groups'] ?? array() )
	: array();

foreach ( $group_ids as $i => $group_id ) {
	$members_of = function_exists( 'groups_get_group_members' )
		? (array) ( groups_get_group_members(
			array(
				'group_id' => (int) $group_id,
				'per_page' => 1,
			)
		)['members'] ?? array() )
		: array();
	$owner      = array() !== $members_of ? (int) $members_of[0]->ID : (int) $members[ $i % count( $members ) ];

	$result = $post_with_photos( $owner, 'Space photo ' . ( $i + 1 ), 2, (int) $group_id );
	if ( $result['activity'] > 0 && array() !== $result['media'] ) {
		++$made;
		$total_med += count( $result['media'] );
	}
}

wp_set_current_user( 0 );

$with_activity = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_media WHERE activity_id > 0" );
$meta_rows     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity_meta WHERE meta_key = 'bp_media_ids'" );

printf(
	"  %d activities with photos, %d media rows created\n  bp_media rows with activity_id: %d | activities carrying bp_media_ids: %d\n",
	$made,
	$total_med,
	$with_activity,
	$meta_rows
);
