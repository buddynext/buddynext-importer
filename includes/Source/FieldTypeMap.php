<?php
/**
 * Maps source profile field types to BuddyNext field types. Covers BuddyPress
 * core, BuddyBoss extras, and the popular BP xProfile Custom Field Types addon.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Pure lookup: source type slug -> BuddyNext type + multi-value flag + skip flag.
 * Verified against the data mappings in docs/data-mapping/.
 */
final class FieldTypeMap {

	/**
	 * Source type slug -> BuddyNext field type.
	 *
	 * @var array<string,string>
	 */
	private const MAP = array(
		// BuddyPress core.
		'textbox'                      => 'text',
		'textarea'                     => 'textarea',
		'selectbox'                    => 'select',
		'multiselectbox'               => 'multiselect',
		'radio'                        => 'radio',
		'checkbox'                     => 'checkbox',
		'datebox'                      => 'date',
		'number'                       => 'number',
		'url'                          => 'url',
		'telephone'                    => 'phone',
		// BuddyBoss extras (gender/social-networks; member-types handled as assignment).
		'gender'                       => 'select',
		'social-networks'              => 'multiselect',
		// BP xProfile Custom Field Types.
		'email'                        => 'email',
		'web'                          => 'url',
		'oembed'                       => 'url',
		'datepicker'                   => 'date',
		'birthdate'                    => 'date',
		'decimal_number'               => 'number',
		'number_minmax'                => 'number',
		'slider'                       => 'number',
		'country'                      => 'select',
		'select_custom_taxonomy'       => 'select',
		'select_custom_post_type'      => 'select',
		'multiselect_custom_taxonomy'  => 'multiselect',
		'multiselect_custom_post_type' => 'multiselect',
		'tags'                         => 'multiselect',
		'token'                        => 'multiselect',
		'checkbox_acceptance'          => 'checkbox',
		'color'                        => 'text',
		'fromto'                       => 'text',
		'file'                         => 'file',
		'image'                        => 'file',
	);

	/**
	 * BuddyNext types whose value is stored as multiple option slugs.
	 *
	 * @var array<int,string>
	 */
	private const MULTI = array( 'multiselect', 'checkbox' );

	/**
	 * Source types that are not custom profile data (synced to the WP user, or an
	 * assignment), so they are skipped by the profile-value import.
	 *
	 * @var array<int,string>
	 */
	private const SKIP = array(
		'wordpress',
		'wordpress-textbox',
		'wordpress-biography',
		'member-types',
		'placeholder',
	);

	/**
	 * Whether a source type is skipped (not imported as a custom field).
	 *
	 * @param string $source_type Source field type slug.
	 */
	public static function is_skipped( string $source_type ): bool {
		return in_array( $source_type, self::SKIP, true );
	}

	/**
	 * Resolve a source type to a BuddyNext type. Unknown types degrade to text.
	 *
	 * @param string $source_type Source field type slug.
	 */
	public static function to_bn_type( string $source_type ): string {
		return self::MAP[ $source_type ] ?? 'text';
	}

	/**
	 * Whether the resolved BuddyNext type is multi-value (value is an array of
	 * option slugs), so a serialized source value must be unserialized first.
	 *
	 * @param string $source_type Source field type slug.
	 */
	public static function is_multi( string $source_type ): bool {
		return in_array( self::to_bn_type( $source_type ), self::MULTI, true );
	}

	/**
	 * Whether the resolved BuddyNext type carries selectable options.
	 *
	 * @param string $source_type Source field type slug.
	 */
	public static function has_options( string $source_type ): bool {
		return in_array( self::to_bn_type( $source_type ), array( 'select', 'radio', 'checkbox', 'multiselect' ), true );
	}
}
