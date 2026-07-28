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
		// BuddyNext has NO 'checkbox' type — its 20 types are text, textarea, url,
		// email, phone, number, date, boolean, select, radio, multiselect,
		// category_multiselect, member_type(_multiselect), color, date_extended,
		// location, multi_select_advanced, number_advanced, conditional. Emitting
		// 'checkbox' created a field of an unknown type whose values were then
		// dropped on write: FieldType::is_multiselect_family('checkbox') is false,
		// so the array branch never ran, and sanitize() has no case for it either.
		// 'multiselect' is the exact semantic equivalent — several selections from
		// a fixed option list, stored as an array of slugs.
		'checkbox'                     => 'multiselect',
		'datebox'                      => 'date',
		'number'                       => 'number',
		'url'                          => 'url',
		'telephone'                    => 'phone',
		// BuddyBoss extras (gender/social-networks; member-types handled as assignment).
		'gender'                       => 'select',
		// social-networks stores an associative network => URL map, not option
		// slugs, so it lands in a textarea as readable "Network: URL" lines.
		'social-networks'              => 'textarea',
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
		// A single accept/decline tick, not a multi-choice list.
		'checkbox_acceptance'          => 'boolean',
		'color'                        => 'text',
		'fromto'                       => 'text',
		// 'file' is not a BuddyNext type either, so these dropped their values for
		// the same reason. There is no file/attachment field to migrate into, so
		// the stored reference is preserved verbatim as text rather than being
		// lost — 'url' would reject a bare attachment ID and drop it again.
		'file'                         => 'text',
		'image'                        => 'text',
	);

	/**
	 * BuddyNext types whose value is stored as multiple option slugs.
	 *
	 * @var array<int,string>
	 */
	private const MULTI = array( 'multiselect' );

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
		return in_array( self::to_bn_type( $source_type ), array( 'select', 'radio', 'multiselect' ), true );
	}
}
