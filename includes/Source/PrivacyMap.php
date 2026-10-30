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
	 * Source activity privacy -> BuddyNext post privacy.
	 *
	 * BuddyNext stores public, followers, connections, space_members, or private.
	 * A friends-only post becomes connections, so the author's friends can still
	 * read it. Only-me and admin-only stay private. Members-only has no matching
	 * level: BuddyNext treats "members" as public, which guests would see, so
	 * the reader must pass private for that case. Group-scoped values stay
	 * public here because the space itself carries that audience.
	 *
	 * @param string $source Source privacy value.
	 */
	public static function post_privacy( string $source ): string {
		switch ( $source ) {
			case 'friends':
			case 'connections':
				return 'connections';
			case 'followers':
				return 'followers';
			case 'space_members':
				return 'space_members';
			case 'onlyme':
			case 'adminsonly':
			case 'private':
				return 'private';
			default:
				return 'public';
		}
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
