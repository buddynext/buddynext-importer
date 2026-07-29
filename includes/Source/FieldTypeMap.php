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
	 * EVERY value here must be a slug registered in BuddyNext's FieldType::types().
	 * A type the engine does not know has no render, sanitize or display path, so
	 * the field imports as an empty shell and its values are dropped on the way in.
	 * `self::unregistered_targets()` asserts this against the live registry.
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
		// A BP checkbox field is a multi-value list of option slugs, which is
		// exactly BuddyNext's `multiselect`. ('checkbox' is NOT a BuddyNext type -
		// mapping to it dropped every value, and left a field the engine degraded
		// to a text input until Installer's schema v16 converged it to boolean.)
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
		// A single "I accept" tickbox, not an option list - BuddyNext's `boolean`
		// reads and writes the same '1'/'0' this stores.
		'checkbox_acceptance'          => 'boolean',
		'color'                        => 'text',
		'fromto'                       => 'text',
		// BuddyNext has no file/upload field type (neither Free nor Pro registers
		// one). The stored value is an attachment URL, so it lands in a `url`
		// field - the same convergence Installer applies to legacy 'file' rows.
		'file'                         => 'url',
		'image'                        => 'url',
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

	/**
	 * Map targets that BuddyNext's field-type registry does not know about.
	 *
	 * The registry is filterable (`buddynext_field_types`), so this is answered
	 * against the LIVE registry on this site rather than a copied list that would
	 * drift. Empty is the healthy result; anything returned would import as a
	 * field with no render, sanitize or display path.
	 *
	 * @return array<int,string> Sorted, unique unregistered target slugs.
	 */
	public static function unregistered_targets(): array {
		if ( ! class_exists( '\BuddyNext\Profile\FieldType' ) ) {
			return array();
		}

		$registered = \BuddyNext\Profile\FieldType::types();
		$unknown    = array_filter(
			array_unique( array_values( self::MAP ) ),
			static function ( string $target ) use ( $registered ): bool {
				return ! array_key_exists( $target, $registered );
			}
		);

		sort( $unknown );

		return $unknown;
	}
}
