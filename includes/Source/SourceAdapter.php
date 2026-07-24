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

	/**
	 * A user's per-field visibility choices — the member's OWN privacy setting on
	 * each field, which is separate from the field's admin default.
	 *
	 * @param int $user_id User id.
	 * @return array<int,string> Map of source field id to source visibility level.
	 */
	public function profile_visibility_levels( int $user_id ): array;

	/**
	 * Member types defined on the source (the type vocabulary, not assignments).
	 *
	 * Row shape: slug, name, description.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function member_types(): array;

	/**
	 * Member-type assignments, keyset-paginated by user id.
	 *
	 * Row shape: user_id, slug.
	 *
	 * @param int $after Exclusive lower-bound user id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function member_type_assignments( int $after, int $limit ): array;

	/**
	 * Source groups, keyset-paginated by group id.
	 *
	 * @param int $after Exclusive lower-bound group id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function groups( int $after, int $limit ): array;

	/**
	 * A group's members, keyset-paginated by membership row id.
	 *
	 * @param int $group_id Source group id.
	 * @param int $after    Exclusive lower-bound membership row id.
	 * @param int $limit    Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function group_members( int $group_id, int $after, int $limit ): array;

	/**
	 * Real activity posts (activity_update, non-spam), keyset-paginated by id.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function activities( int $after, int $limit ): array;

	/**
	 * Activity comments (activity_comment, non-spam), keyset-paginated by id.
	 *
	 * @param int $after Exclusive lower-bound activity id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function activity_comments( int $after, int $limit ): array;

	/**
	 * Friendships, keyset-paginated by friendship id.
	 *
	 * @param int $after Exclusive lower-bound friendship id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function friendships( int $after, int $limit ): array;

	/**
	 * WP attachment ids of the media (photos + videos) attached to an activity.
	 * BuddyPress core has none; BuddyBoss reads bp_media_ids / bp_video_ids.
	 *
	 * @param int $activity_id Source activity id.
	 * @return array<int,int>
	 */
	public function activity_media( int $activity_id ): array;

	/**
	 * Source bbPressforums, keyset-paginated by post id.
	 *
	 * Row shape includes `group_id`: the source group this forum belongs to
	 * (bbPress `_bbp_group_ids`), or 0 for a standalone site forum. Group forums
	 * must land inside the space their group migrated into, not beside it.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forums( int $after, int $limit ): array;

	/**
	 * Source bbPresstopics, keyset-paginated by post id.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_topics( int $after, int $limit ): array;

	/**
	 * Source bbPressreplies, keyset-paginated by post id.
	 *
	 * @param int $after Exclusive lower-bound post id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function forum_replies( int $after, int $limit ): array;

	/**
	 * User follows, keyset-paginated by follow row id.
	 *
	 * Row shape: source_id, follower_id, leader_id, date_recorded ('' when the
	 * source table has no date column - classic bp_follow does not).
	 *
	 * @param int $after Exclusive lower-bound follow id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function follows( int $after, int $limit ): array;

	/**
	 * Activity reactions/likes, keyset-paginated.
	 *
	 * Row shape: source_id, user_id, activity_id, date_created ('' when the
	 * source stores no per-like date - BuddyPress usermeta favorites do not).
	 * The keyset id is the reaction row id (BuddyBoss bb_user_reactions) or the
	 * user id (usermeta-favorites fallback, one batch emits whole users).
	 *
	 * @param int $after Exclusive lower-bound keyset id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function reactions( int $after, int $limit ): array;

	/**
	 * Private-message threads, keyset-paginated by thread id.
	 *
	 * Row shape: thread_id, participants (int[]), subject, date_sent (of the
	 * thread's first message).
	 *
	 * @param int $after Exclusive lower-bound thread id.
	 * @param int $limit Batch size.
	 * @return array<int,array<string,mixed>>
	 */
	public function message_threads( int $after, int $limit ): array;

	/**
	 * Every message in one thread, oldest first.
	 *
	 * Row shape: source_id, sender_id, content, date_sent.
	 *
	 * @param int $thread_id Source thread id.
	 * @return array<int,array<string,mixed>>
	 */
	public function thread_messages( int $thread_id ): array;
}
