<?php
/**
 * Maps source privacy/visibility levels to BuddyNext's. BuddyPress/BuddyBoss
 * have more granular levels than BuddyNext, so restricted levels collapse to the
 * closest BuddyNext equivalent.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Pure lookup for activity privacy and profile-field visibility.
 */
final class PrivacyMap {

	/**
	 * Source activity privacy -> BuddyNext post privacy (public|private).
	 *
	 * BuddyBoss values: public, loggedin, onlyme, friends, media, document, video,
	 * grouponly. Only owner/friends-restricted levels become private; the rest
	 * (including media/group, which are scoped by their space) stay public.
	 *
	 * @param string $source Source privacy value.
	 */
	public static function post_privacy( string $source ): string {
		return in_array( $source, array( 'onlyme', 'friends', 'adminsonly', 'private' ), true )
			? 'private'
			: 'public';
	}

	/**
	 * Source profile-field visibility -> BuddyNext field visibility.
	 *
	 * BuddyPress values: public, loggedin, friends, adminsonly. BuddyNext values:
	 * public, connections, private.
	 *
	 * @param string $source Source visibility value.
	 */
	public static function field_visibility( string $source ): string {
		switch ( $source ) {
			case 'friends':
				return 'connections';
			case 'adminsonly':
			case 'onlyme':
			case 'private':
				return 'private';
			default:
				// public, loggedin.
				return 'public';
		}
	}
}
