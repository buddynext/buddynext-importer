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
 * was never imported (spam, skipped) is dropped with it.
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
	 * @return bool Whether a reaction was written (or already existed).
	 */
	public function import_reaction( array $reaction ): bool {
		$user_id     = (int) $reaction['user_id'];
		$activity_id = (int) $reaction['activity_id'];

		if ( $user_id <= 0 || $activity_id <= 0 ) {
			return false;
		}

		$post_id = IdMap::get( $this->source, 'post', $activity_id );
		if ( null === $post_id ) {
			return false; // The liked activity was not imported - drop the like.
		}

		$date   = (string) ( $reaction['date_created'] ?? '' );
		$result = ImportMode::run(
			// Fifth argument is ReactionService's backdate seam.
			fn() => $this->service()->react( $user_id, 'post', $post_id, 'like', '' !== $date ? $date : null )
		);

		return true === $result;
	}
}
