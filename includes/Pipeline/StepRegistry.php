<?php
/**
 * The canonical, ordered list of import steps - the single source of truth for
 * every surface that runs or reports a migration.
 *
 * There are four of those surfaces: the CLI (`migrate-all`), the server-side
 * background runner, the REST `/step` endpoint, and the admin page's in-browser
 * run loop. They used to each carry their own copy of the step list, and the
 * copies drifted: the browser loop knew 5 of the 16 domains and `/step` knew 13,
 * so "Start import" silently skipped member types, follows, reactions, forums,
 * images, media and messages while reporting success. A site owner got a
 * half-migrated community and no warning.
 *
 * So the list lives here once. A step declares its identity (phase + stage +
 * label + checkpoint domain) AND how it runs, and every surface reads both from
 * this registry - adding a domain is one entry here, not four edits that have to
 * be kept in sync by hand.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

namespace BuddyNextImporter\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Ordered import-step definitions shared by the CLI, background runner, REST and
 * admin surfaces.
 */
final class StepRegistry {

	/**
	 * The ordered steps for a source, in dependency order so relationships
	 * resolve (spaces before their activity, forums before topics before replies,
	 * albums before the photos that belong to them).
	 *
	 * Each entry is:
	 *  - phase/stage: the REST `/step` vocabulary, and the client's run order.
	 *  - label:       lower-case, rendered as "Importing {label}...".
	 *  - domain:      the resume-checkpoint key {@see Checkpoint}.
	 *  - empty_done:  step ends on an empty batch rather than a short one (for a
	 *                 non-uniform keyset).
	 *  - available:   whether this site can run the step at all (target engine +
	 *                 source reader both present).
	 *  - run:         (cursor, batch) -> the importer result, normalised to carry
	 *                 `last`, `fetched` and a uniform `count` of rows written.
	 *
	 * @param string $source Source key (buddypress|buddyboss).
	 * @return array<int,array{phase:string,stage:?string,label:string,domain:string,empty_done:bool,available:callable,run:callable}>
	 */
	public static function steps( string $source ): array {
		$steps = array();

		// Profiles report the row count as `users`, not `fetched`, so the batch is
		// normalised here rather than special-cased in every consumer.
		$steps[] = array(
			'phase'      => 'profiles',
			'stage'      => null,
			'label'      => __( 'profile fields', 'buddynext-importer' ),
			'domain'     => 'profile_value',
			'empty_done' => false,
			'available'  => static fn (): bool => null !== ProfileImporter::for_source( $source ),
			'run'        => static function ( int $cursor, int $batch ) use ( $source ): array {
				$importer = ProfileImporter::for_source( $source );
				if ( null === $importer ) {
					return self::empty_result( $cursor );
				}

				$schema = 0 === $cursor ? $importer->import_schema() : array();
				$result = $importer->import_values_batch( $cursor, $batch );

				return array_merge(
					$result,
					array(
						'schema'  => $schema,
						'fetched' => (int) $result['users'],
						'count'   => (int) $result['values'],
					)
				);
			},
		);

		$steps[] = array(
			'phase'      => 'member_types',
			'stage'      => null,
			'label'      => __( 'member types', 'buddynext-importer' ),
			'domain'     => 'member_type_user',
			'empty_done' => false,
			'available'  => static fn (): bool => MemberTypeImporter::target_available()
				&& null !== MemberTypeImporter::for_source( $source ),
			'run'        => static function ( int $cursor, int $batch ) use ( $source ): array {
				$importer = MemberTypeImporter::for_source( $source );
				if ( null === $importer ) {
					return self::empty_result( $cursor );
				}

				$types  = 0 === $cursor ? $importer->import_types() : array();
				$result = $importer->import_batch( $cursor, $batch );

				return array_merge(
					$result,
					array(
						'types' => $types,
						'count' => (int) $result['assignments'],
					)
				);
			},
		);

		$steps[] = self::step(
			'spaces',
			null,
			__( 'spaces and members', 'buddynext-importer' ),
			'space',
			static fn (): bool => null !== SpaceImporter::for_source( $source ),
			static fn ( int $c, int $b ): array => SpaceImporter::for_source( $source )->import_batch( $c, $b ),
			// A spaces batch writes both the space rows and their memberships.
			static fn ( array $r ): int => (int) $r['groups'] + (int) $r['members']
		);

		$activity_available = static fn (): bool => null !== ActivityImporter::for_source( $source );

		$steps[] = self::step(
			'activity',
			'posts',
			__( 'posts', 'buddynext-importer' ),
			'post',
			$activity_available,
			static fn ( int $c, int $b ): array => ActivityImporter::for_source( $source )->import_posts_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['posts']
		);

		$steps[] = self::step(
			'activity',
			'comments',
			__( 'comments', 'buddynext-importer' ),
			'comment',
			$activity_available,
			static fn ( int $c, int $b ): array => ActivityImporter::for_source( $source )->import_comments_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['comments']
		);

		$steps[] = self::step(
			'friends',
			null,
			__( 'connections', 'buddynext-importer' ),
			'connection',
			static fn (): bool => null !== FriendImporter::for_source( $source ),
			static fn ( int $c, int $b ): array => FriendImporter::for_source( $source )->import_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['connections']
		);

		$steps[] = self::step(
			'follows',
			null,
			__( 'follows', 'buddynext-importer' ),
			'follow',
			static fn (): bool => null !== FollowImporter::for_source( $source ),
			static fn ( int $c, int $b ): array => FollowImporter::for_source( $source )->import_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['follows']
		);

		// The reactions keyset is non-uniform (the usermeta fallback emits whole
		// users), so a short batch does not mean the domain is finished - only an
		// empty one does.
		$steps[] = self::step(
			'reactions',
			null,
			__( 'reactions', 'buddynext-importer' ),
			'reaction',
			static fn (): bool => null !== ReactionImporter::for_source( $source ),
			static fn ( int $c, int $b ): array => ReactionImporter::for_source( $source )->import_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['reactions'],
			true
		);

		$forums_available = static fn (): bool => ForumImporter::target_available()
			&& null !== ForumImporter::for_source( $source );

		$steps[] = self::step(
			'forums',
			'forums',
			__( 'forums', 'buddynext-importer' ),
			'forum_space',
			$forums_available,
			static fn ( int $c, int $b ): array => ForumImporter::for_source( $source )->import_forums_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['forums']
		);

		$steps[] = self::step(
			'forums',
			'topics',
			__( 'forum topics', 'buddynext-importer' ),
			'forum_post',
			$forums_available,
			static fn ( int $c, int $b ): array => ForumImporter::for_source( $source )->import_topics_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['topics']
		);

		$steps[] = self::step(
			'forums',
			'replies',
			__( 'forum replies', 'buddynext-importer' ),
			'forum_reply',
			$forums_available,
			static fn ( int $c, int $b ): array => ForumImporter::for_source( $source )->import_replies_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['replies']
		);

		$images_available = static fn (): bool => ImageImporter::target_available()
			&& null !== ImageImporter::for_source( $source );

		$steps[] = self::step(
			'images',
			'members',
			__( 'member images', 'buddynext-importer' ),
			'member_image',
			$images_available,
			static fn ( int $c, int $b ): array => ImageImporter::for_source( $source )->import_members_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['members']
		);

		$steps[] = self::step(
			'images',
			'groups',
			__( 'space images', 'buddynext-importer' ),
			'group_image',
			$images_available,
			static fn ( int $c, int $b ): array => ImageImporter::for_source( $source )->import_groups_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['spaces']
		);

		$media_available = static fn (): bool => MediaImporter::target_available()
			&& null !== MediaImporter::for_source( $source );

		$steps[] = self::step(
			'media',
			'albums',
			__( 'albums', 'buddynext-importer' ),
			'media_album',
			$media_available,
			static fn ( int $c, int $b ): array => MediaImporter::for_source( $source )->import_albums_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['albums']
		);

		$steps[] = self::step(
			'media',
			'media',
			__( 'media', 'buddynext-importer' ),
			'standalone_media',
			$media_available,
			static fn ( int $c, int $b ): array => MediaImporter::for_source( $source )->import_media_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['media']
		);

		$steps[] = self::step(
			'messages',
			null,
			__( 'messages', 'buddynext-importer' ),
			'dm_thread',
			static fn (): bool => MessageImporter::target_available()
				&& null !== MessageImporter::for_source( $source ),
			static fn ( int $c, int $b ): array => MessageImporter::for_source( $source )->import_batch( $c, $b ),
			static fn ( array $r ): int => (int) $r['messages']
		);

		return $steps;
	}

	/**
	 * Look up one step by its REST phase + stage.
	 *
	 * A null/unknown stage resolves to the first step of that phase, which is the
	 * stage a client should start with (posts before comments, forums before
	 * topics, albums before their media).
	 *
	 * @param string      $source Source key.
	 * @param string      $phase  Phase name.
	 * @param string|null $stage  Stage name, when the phase has stages.
	 * @return array{phase:string,stage:?string,label:string,domain:string,empty_done:bool,available:callable,run:callable}|null
	 */
	public static function find( string $source, string $phase, ?string $stage = null ): ?array {
		$in_phase = array_values(
			array_filter(
				self::steps( $source ),
				static fn ( array $step ): bool => $step['phase'] === $phase
			)
		);

		if ( array() === $in_phase ) {
			return null;
		}

		if ( null !== $stage && '' !== $stage ) {
			foreach ( $in_phase as $step ) {
				if ( $step['stage'] === $stage ) {
					return $step;
				}
			}
		}

		return $in_phase[0];
	}

	/**
	 * The step list the admin page's run loop iterates: identity only, with the
	 * unavailable steps already removed so the browser never calls a phase this
	 * site cannot run (no Jetonomy -> no forums, no WPMediaVerse -> no media).
	 *
	 * @param string $source Source key.
	 * @return array<int,array{phase:string,stage:?string,label:string}>
	 */
	public static function client_steps( string $source ): array {
		$client = array();

		foreach ( self::steps( $source ) as $step ) {
			if ( ! ( $step['available'] )() ) {
				continue;
			}

			$client[] = array(
				'phase' => $step['phase'],
				'stage' => $step['stage'],
				'label' => $step['label'],
			);
		}

		return $client;
	}

	/**
	 * Build a step whose batch method returns the standard `last`/`fetched` pair.
	 *
	 * @param string      $phase      REST phase name.
	 * @param string|null $stage      REST stage name, when the phase has stages.
	 * @param string      $label      Lower-case display label.
	 * @param string      $domain     Checkpoint domain key.
	 * @param callable    $available  Returns whether the step can run here.
	 * @param callable    $run        (cursor, batch) -> importer result.
	 * @param callable    $count      Importer result -> rows written.
	 * @param bool        $empty_done Step ends on an empty batch, not a short one.
	 * @return array{phase:string,stage:?string,label:string,domain:string,empty_done:bool,available:callable,run:callable}
	 */
	private static function step(
		string $phase,
		?string $stage,
		string $label,
		string $domain,
		callable $available,
		callable $run,
		callable $count,
		bool $empty_done = false
	): array {
		return array(
			'phase'      => $phase,
			'stage'      => $stage,
			'label'      => $label,
			'domain'     => $domain,
			'empty_done' => $empty_done,
			'available'  => $available,
			'run'        => static function ( int $cursor, int $batch ) use ( $available, $run, $count ): array {
				// A step can go unavailable between the check and the call (a
				// plugin deactivated mid-import), so re-check rather than fatal on
				// a null importer.
				if ( ! $available() ) {
					return self::empty_result( $cursor );
				}

				$result = $run( $cursor, $batch );

				return array_merge( $result, array( 'count' => $count( $result ) ) );
			},
		);
	}

	/**
	 * The "nothing to do" batch result, shaped like a real one.
	 *
	 * @param int $cursor Cursor to hold at.
	 * @return array{last:int,fetched:int,count:int}
	 */
	private static function empty_result( int $cursor ): array {
		return array(
			'last'    => $cursor,
			'fetched' => 0,
			'count'   => 0,
		);
	}
}
