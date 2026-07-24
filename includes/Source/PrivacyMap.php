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
	 * Used for BOTH the field's admin default and a member's own per-field
	 * choice (bp_xprofile_visibility_levels), which share one vocabulary.
	 *
	 * BuddyPress/BuddyBoss values: public, loggedin, friends, adminsonly, onlyme.
	 * BuddyNext values: public, members, followers, connections, private.
	 *
	 * `loggedin` maps to `members`, not `public`: BuddyNext has a members-only
	 * level, so collapsing it to public would publish a field the member had
	 * deliberately restricted to logged-in users.
	 *
	 * @param string $source Source visibility value.
	 */
	public static function field_visibility( string $source ): string {
		switch ( $source ) {
			case 'loggedin':
				return 'members';
			case 'friends':
				return 'connections';
			case 'adminsonly':
			case 'onlyme':
			case 'private':
				return 'private';
			default:
				return 'public';
		}
	}
}
