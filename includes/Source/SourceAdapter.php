<?php
/**
 * Read-only source adapter contract. Each supported platform implements this to
 * read its own schema and normalize records to a common shape.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Source;

defined( 'ABSPATH' ) || exit;

/**
 * The pipeline talks to sources only through this interface. v1 implementations:
 * BuddyPress and BuddyBoss. Data-read methods (profiles, groups, activities...)
 * are added per build phase; Phase 1 establishes identity + availability +
 * stats.
 */
interface SourceAdapter {

	/**
	 * Machine key, e.g. "buddypress" or "buddyboss".
	 */
	public function key(): string;

	/**
	 * Human label for the admin UI / CLI.
	 */
	public function label(): string;

	/**
	 * Whether this source's data is present on the current site.
	 */
	public function is_available(): bool;

	/**
	 * Per-domain record counts, used by the stats command and progress monitor.
	 *
	 * @return array<string,int> Map of domain key to row count.
	 */
	public function stats(): array;

	/**
	 * Source profile field groups, ordered.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_groups(): array;

	/**
	 * Source profile fields (parent fields), each with its options list.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_fields(): array;

	/**
	 * User ids that have profile values, keyset-paginated by user id.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array<int,int>
	 */
	public function profile_value_user_ids( int $after, int $limit ): array;

	/**
	 * A user's stored profile values, joined to field type + name.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	public function profile_values( int $user_id ): array;
}
