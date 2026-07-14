<?php
/**
 * Dev-only source seeder: builds a realistic community on the SOURCE platform
 * (BuddyPress today, BuddyBoss later) so the migration can be exercised end to
 * end and re-tested after any change.
 *
 * This is a TEST FIXTURE, not part of the customer migration. It is registered
 * only when the seeder is explicitly enabled (see Plugin::boot) and never runs
 * on a production install. The fixture is a Tinder-style dating community: three
 * xprofile field groups, a spread of field types (incl. select/multiselect with
 * options), and members with values filled in - deliberately including the
 * fields that SHOULD map onto BuddyNext's canonical profile fields (bio,
 * headline, location, interests) so a migration can prove that mapping.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Dev;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds (and can reset) the source-platform test community. Idempotent: groups,
 * fields, and members are matched by name/login, so re-running never duplicates.
 */
final class SourceSeeder {

	/**
	 * Login prefix + marker meta so the reset can find exactly what we created
	 * and nothing else. A hand-made account is never touched.
	 */
	private const SEED_META = '_bn_importer_seed';

	/**
	 * The dating-community field schema. group => [ label, [ field, type, options[] ] ].
	 * The four canonical-mappable fields are flagged in the comments.
	 *
	 * @return array<string,array{label:string,fields:array<int,array{name:string,type:string,options:array<int,string>}>}>
	 */
	private static function schema(): array {
		return array(
			'about'     => array(
				'label'  => 'About Me',
				'fields' => array(
					array( 'name' => 'About Me', 'type' => 'textarea', 'options' => array() ),   // -> BuddyNext bio
					array( 'name' => 'Tagline', 'type' => 'textbox', 'options' => array() ),      // -> BuddyNext headline
					array( 'name' => 'Birthdate', 'type' => 'datebox', 'options' => array() ),
					array( 'name' => 'Gender', 'type' => 'selectbox', 'options' => array( 'Man', 'Woman', 'Non-binary' ) ),
					array( 'name' => 'Location', 'type' => 'textbox', 'options' => array() ),     // -> BuddyNext location
				),
			),
			'prefs'     => array(
				'label'  => 'Dating Preferences',
				'fields' => array(
					array( 'name' => 'Looking For', 'type' => 'selectbox', 'options' => array( 'Relationship', 'Casual', 'Friendship', 'Not sure yet' ) ),
					array( 'name' => 'Interested In', 'type' => 'multiselectbox', 'options' => array( 'Men', 'Women', 'Everyone' ) ),
				),
			),
			'lifestyle' => array(
				'label'  => 'Interests & Lifestyle',
				'fields' => array(
					array( 'name' => 'Interests', 'type' => 'multiselectbox', 'options' => array( 'Travel', 'Music', 'Fitness', 'Foodie', 'Movies', 'Reading', 'Gaming', 'Art' ) ), // -> BuddyNext interests
					array( 'name' => 'Height (cm)', 'type' => 'number', 'options' => array() ),
					array( 'name' => 'Smoking', 'type' => 'selectbox', 'options' => array( 'Never', 'Socially', 'Regularly' ) ),
					array( 'name' => 'Zodiac Sign', 'type' => 'selectbox', 'options' => array( 'Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces' ) ),
				),
			),
		);
	}

	/**
	 * The seeded members. Values reference field names from the schema above.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function members(): array {
		return array(
			array( 'login' => 'emma', 'name' => 'Emma Clarke', 'About Me' => 'Coffee lover, weekend hiker, always planning my next trip.', 'Tagline' => 'Adventure seeker', 'Birthdate' => '1997-04-12', 'Gender' => 'Woman', 'Location' => 'London, UK', 'Looking For' => 'Relationship', 'Interested In' => array( 'Men' ), 'Interests' => array( 'Travel', 'Music', 'Foodie' ), 'Height (cm)' => 168, 'Smoking' => 'Never', 'Zodiac Sign' => 'Aries' ),
			array( 'login' => 'jack', 'name' => 'Jack Morrison', 'About Me' => 'Gym in the morning, guitar at night. Looking for someone real.', 'Tagline' => 'Musician & gym rat', 'Birthdate' => '1993-08-03', 'Gender' => 'Man', 'Location' => 'Manchester, UK', 'Looking For' => 'Relationship', 'Interested In' => array( 'Women' ), 'Interests' => array( 'Fitness', 'Music', 'Gaming' ), 'Height (cm)' => 182, 'Smoking' => 'Socially', 'Zodiac Sign' => 'Leo' ),
			array( 'login' => 'sophia', 'name' => 'Sophia Rossi', 'About Me' => 'Bookworm and amateur painter. Dogs over everything.', 'Tagline' => 'Artsy introvert', 'Birthdate' => '1995-11-21', 'Gender' => 'Woman', 'Location' => 'Brighton, UK', 'Looking For' => 'Friendship', 'Interested In' => array( 'Everyone' ), 'Interests' => array( 'Reading', 'Art', 'Movies' ), 'Height (cm)' => 165, 'Smoking' => 'Never', 'Zodiac Sign' => 'Scorpio' ),
			array( 'login' => 'liam', 'name' => 'Liam Walsh', 'About Me' => 'Foodie who travels for the meals. Ask me about ramen.', 'Tagline' => 'Here for the food', 'Birthdate' => '1990-01-30', 'Gender' => 'Man', 'Location' => 'Bristol, UK', 'Looking For' => 'Casual', 'Interested In' => array( 'Women' ), 'Interests' => array( 'Foodie', 'Travel', 'Movies' ), 'Height (cm)' => 178, 'Smoking' => 'Socially', 'Zodiac Sign' => 'Aquarius' ),
			array( 'login' => 'olivia', 'name' => 'Olivia Bennett', 'About Me' => 'Yoga, plants, and true-crime podcasts. Not sure what I want yet.', 'Tagline' => 'Just vibing', 'Birthdate' => '1998-06-17', 'Gender' => 'Woman', 'Location' => 'Leeds, UK', 'Looking For' => 'Not sure yet', 'Interested In' => array( 'Everyone' ), 'Interests' => array( 'Fitness', 'Reading', 'Music' ), 'Height (cm)' => 170, 'Smoking' => 'Never', 'Zodiac Sign' => 'Gemini' ),
		);
	}

	/**
	 * Seed the BuddyPress source community. Idempotent.
	 *
	 * @param int $members How many of the fixture members to create (max = fixture size).
	 * @return array{groups:int,fields:int,members:int} Counts of records ensured.
	 */
	public static function seed_buddypress( int $members = 5 ): array {
		if ( ! function_exists( 'xprofile_insert_field_group' ) ) {
			throw new \RuntimeException( 'BuddyPress xProfile is not active - cannot seed the source community.' );
		}

		$field_ids = array();
		$g_count   = 0;
		$f_count   = 0;

		foreach ( self::schema() as $group ) {
			$group_id = self::ensure_group( (string) $group['label'] );
			++$g_count;
			foreach ( $group['fields'] as $field ) {
				$fid = self::ensure_field( $group_id, (string) $field['name'], (string) $field['type'], (array) $field['options'] );
				$field_ids[ (string) $field['name'] ] = $fid;
				++$f_count;
			}
		}

		$m_count = 0;
		foreach ( array_slice( self::members(), 0, max( 0, $members ) ) as $person ) {
			self::ensure_member( $person, $field_ids );
			++$m_count;
		}

		return array( 'groups' => $g_count, 'fields' => $f_count, 'members' => $m_count );
	}

	/**
	 * Remove everything this seeder created (marked members + the named groups).
	 *
	 * @return array{members:int,groups:int}
	 */
	public static function reset_buddypress(): array {
		$m = 0;
		foreach ( get_users( array( 'meta_key' => self::SEED_META, 'fields' => 'ID' ) ) as $uid ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( (int) $uid );
			++$m;
		}

		$g = 0;
		if ( function_exists( 'bp_xprofile_get_groups' ) ) {
			foreach ( (array) self::schema() as $group ) {
				$gid = self::group_id_by_name( (string) $group['label'] );
				if ( $gid > 0 && function_exists( 'xprofile_delete_field_group' ) ) {
					xprofile_delete_field_group( $gid );
					++$g;
				}
			}
		}

		return array( 'members' => $m, 'groups' => $g );
	}

	/**
	 * Find a field group id by exact name, or 0.
	 */
	private static function group_id_by_name( string $name ): int {
		foreach ( (array) bp_xprofile_get_groups() as $group ) {
			if ( isset( $group->name ) && $group->name === $name ) {
				return (int) $group->id;
			}
		}
		return 0;
	}

	/**
	 * Create the group if it does not already exist; return its id.
	 */
	private static function ensure_group( string $name ): int {
		$existing = self::group_id_by_name( $name );
		if ( $existing > 0 ) {
			return $existing;
		}
		return (int) xprofile_insert_field_group( array( 'name' => $name, 'can_delete' => true ) );
	}

	/**
	 * Create the field (and its options) if a field of that name does not exist;
	 * return its id.
	 *
	 * @param array<int,string> $options Option labels for select-style fields.
	 */
	private static function ensure_field( int $group_id, string $name, string $type, array $options ): int {
		$existing = (int) xprofile_get_field_id_from_name( $name );
		if ( $existing > 0 ) {
			return $existing;
		}
		$field_id = (int) xprofile_insert_field(
			array(
				'field_group_id' => $group_id,
				'name'           => $name,
				'type'           => $type,
				'can_delete'     => true,
			)
		);
		foreach ( $options as $i => $label ) {
			xprofile_insert_field(
				array(
					'field_group_id' => $group_id,
					'parent_id'      => $field_id,
					'type'           => 'option',
					'name'           => $label,
					'option_order'   => $i + 1,
				)
			);
		}
		return $field_id;
	}

	/**
	 * Create a member if the login is free, then write their profile values.
	 *
	 * @param array<string,mixed> $person    One row from members().
	 * @param array<string,int>   $field_ids name => xprofile field id.
	 */
	private static function ensure_member( array $person, array $field_ids ): void {
		$login = (string) $person['login'];
		$uid   = username_exists( $login );
		if ( ! $uid ) {
			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 20 ),
					'user_email'   => $login . '@example.test',
					'display_name' => (string) $person['name'],
				)
			);
			if ( is_wp_error( $uid ) ) {
				return;
			}
			update_user_meta( (int) $uid, self::SEED_META, 1 );
		}

		// The primary xprofile "Name" field is always id 1 on BuddyPress.
		xprofile_set_field_data( 1, (int) $uid, (string) $person['name'] );

		foreach ( $field_ids as $field_name => $fid ) {
			if ( ! array_key_exists( $field_name, $person ) ) {
				continue;
			}
			$value = $person[ $field_name ];
			if ( 'Birthdate' === $field_name ) {
				$value = (string) $value . ' 00:00:00';
			}
			xprofile_set_field_data( (int) $fid, (int) $uid, $value );
		}
	}
}
