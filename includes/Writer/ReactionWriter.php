<?php
/**
 * Writes source activity likes/favorites into BuddyNext THROUGH ITS SERVICE
 * API only (buddynext_service( 'reactions' )). Never touches bn_* tables.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Writer;

defined( 'ABSPATH' ) || exit;

use BuddyNextImporter\Pipeline\IdMap;
use BuddyNextImporter\Pipeline\ImportMode;

/**
 * Service-layer writer for the reactions domain.
 *
 * No IdMap for the reaction itself: bn_reactions carries a UNIQUE
 * (user, object, emoji) key and ReactionService::react() is INSERT IGNORE, so
 * re-runs are idempotent at the database. The activity -> post mapping DOES
 * come from the IdMap the activity import wrote - a like on an activity that
 * was never imported (spam, skipped) is dropped with it. A reaction on a
 * COMMENT resolves through the comment domain instead - a comment is an
 * activity in the source, so both arrive looking the same.
 */
final class ReactionWriter {

	/**
	 * Source key.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Construct for a source.
	 *
	 * @param string $source Source key.
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * BuddyNext reaction service.
	 */
	private function service(): object {
		return buddynext_service( 'reactions' );
	}

	/**
	 * Import one source like/favorite as a BuddyNext 'like' reaction.
	 *
	 * @param array<string,mixed> $reaction Source reaction record.
	 * @return string Empty string when written (or already present), else the skip reason.
	 */
	public function import_reaction( array $reaction ): string {
		$user_id     = (int) $reaction['user_id'];
		$activity_id = (int) $reaction['activity_id'];

		if ( $user_id <= 0 || $activity_id <= 0 ) {
			return 'invalid_row';
		}

		// A comment is an activity too - BuddyPress stores it as type
		// activity_comment - so a reaction can target either, and the source row
		// looks identical for both. Resolve as a post first, then as a comment:
		// BuddyNext reactions are object-generic (object_type / object_id), so a
		// comment reaction has somewhere to go, and dropping it as
		// 'activity_not_imported' was reporting the wrong reason for something
		// that had in fact migrated.
		$object_type = 'post';
		$object_id   = IdMap::get( $this->source, 'post', $activity_id );

		if ( null === $object_id ) {
			$object_id = IdMap::get( $this->source, 'comment', $activity_id );
			if ( null !== $object_id ) {
				$object_type = 'comment';
			}
		}

		if ( null === $object_id ) {
			// The liked activity was never imported (spam/skipped), so the like
			// goes with it. Expected, but counted - an operator comparing source
			// and target like totals needs to see this rather than wonder where
			// the difference went.
			return 'activity_not_imported';
		}

		// Already present from an earlier run - react() is INSERT IGNORE, but
		// asking first lets the run report an honest "already imported" rather
		// than re-counting it. The DB unique key remains the idempotency source.
		if ( $this->service()->has_reacted( $user_id, $object_type, (int) $object_id ) ) {
			return 'already_imported';
		}

		$date   = (string) ( $reaction['date_created'] ?? '' );
		$result = ImportMode::run(
			// Fifth argument is ReactionService's backdate seam.
			fn() => $this->service()->react( $user_id, $object_type, $object_id, 'like', '' !== $date ? $date : null )
		);

		if ( is_wp_error( $result ) ) {
			return sanitize_key( (string) $result->get_error_code() );
		}

		return true === $result ? '' : 'reaction_refused';
	}
}
