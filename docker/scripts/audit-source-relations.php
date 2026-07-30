<?php
/**
 * Audit the SOURCE community's referential integrity.
 *
 * Counts say a domain has data; they say nothing about whether the data points
 * at anything. A migration inherits every dangling reference in its source, and
 * an orphan there becomes a "silent shortfall" here that looks like an importer
 * bug - so the source is checked before it is blamed.
 *
 * Every check names the relation, the rows that break it, and whether that is
 * fatal for the migration or merely something to expect in the report.
 *
 * Usage: wp eval-file /scripts/audit-source-relations.php
 *
 * @package BuddyNextImporter
 */

global $wpdb;
$p = $wpdb->prefix;

$pass = 0;
$warn = 0;
$fail = 0;

/**
 * Report one relation.
 *
 * @param string $relation What must hold.
 * @param int    $total    Rows participating.
 * @param int    $broken   Rows that break it.
 * @param string $severity 'fail' when it would lose data, 'warn' when expected.
 * @param string $note     Explanation.
 */
$check = static function ( string $relation, int $total, int $broken, string $severity = 'fail', string $note = '' ) use ( &$pass, &$warn, &$fail ): void {
	if ( 0 === $broken ) {
		++$pass;
		printf( "  ok    %-46s %d row(s)\n", $relation, $total );
		return;
	}

	if ( 'warn' === $severity ) {
		++$warn;
		printf( "  note  %-46s %d of %d  %s\n", $relation, $broken, $total, $note );
		return;
	}

	++$fail;
	printf( "  FAIL  %-46s %d of %d  %s\n", $relation, $broken, $total, $note );
};

$exists = static function ( string $table ) use ( $wpdb, $p ): bool {
	$full = $p . $table;
	return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
};

$n = static function ( string $sql ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
};

echo "\n=== PROFILES ===\n";
if ( $exists( 'bp_xprofile_fields' ) ) {
	$check(
		'field -> field group',
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_fields WHERE parent_id = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_fields f WHERE f.parent_id = 0 AND NOT EXISTS (SELECT 1 FROM {$p}bp_xprofile_groups g WHERE g.id = f.group_id)" )
	);
	$check(
		'option -> parent field',
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_fields WHERE parent_id > 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_fields o WHERE o.parent_id > 0 AND NOT EXISTS (SELECT 1 FROM {$p}bp_xprofile_fields f WHERE f.id = o.parent_id)" )
	);
	$check(
		'profile value -> field',
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_data" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_data d WHERE NOT EXISTS (SELECT 1 FROM {$p}bp_xprofile_fields f WHERE f.id = d.field_id)" )
	);
	$check(
		'profile value -> user',
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_data" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_xprofile_data d WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = d.user_id)" )
	);
}

echo "\n=== GROUPS ===\n";
if ( $exists( 'bp_groups' ) ) {
	$check(
		'group -> creator',
		$n( "SELECT COUNT(*) FROM {$p}bp_groups" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_groups g WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = g.creator_id)" )
	);
	$check(
		'membership -> group',
		$n( "SELECT COUNT(*) FROM {$p}bp_groups_members" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE NOT EXISTS (SELECT 1 FROM {$p}bp_groups g WHERE g.id = m.group_id)" )
	);
	$check(
		'membership -> user',
		$n( "SELECT COUNT(*) FROM {$p}bp_groups_members" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_groups_members m WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = m.user_id)" )
	);
	$check(
		'creator is also a member',
		$n( "SELECT COUNT(*) FROM {$p}bp_groups" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_groups g WHERE NOT EXISTS (SELECT 1 FROM {$p}bp_groups_members m WHERE m.group_id = g.id AND m.user_id = g.creator_id)" ),
		'warn',
		'BuddyNext adds the owner as a member, so those spaces run one ahead'
	);
	$check(
		'group type assignment -> term',
		$n( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_group_type'" ),
		$n( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_group_type' WHERE NOT EXISTS (SELECT 1 FROM {$p}bp_groups g WHERE g.id = tr.object_id)" )
	);
	$check(
		'group type has a definition post',
		$n( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'bp_group_type'" ),
		$n( "SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'bp_group_type' WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} po ON po.ID = pm.post_id AND po.post_type = 'bp-group-type' WHERE pm.meta_key = '_bp_group_type_key' AND pm.meta_value = t.slug)" ),
		'warn',
		'without one the label migrates as the slug'
	);
}

echo "\n=== ACTIVITY ===\n";
if ( $exists( 'bp_activity' ) ) {
	$check(
		'activity -> author',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity WHERE is_spam = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.is_spam = 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = a.user_id)" )
	);
	$check(
		'group activity -> group',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity WHERE component = 'groups' AND is_spam = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.component = 'groups' AND a.is_spam = 0 AND NOT EXISTS (SELECT 1 FROM {$p}bp_groups g WHERE g.id = a.item_id)" )
	);
	$check(
		'comment -> root activity',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND is_spam = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity c WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND NOT EXISTS (SELECT 1 FROM {$p}bp_activity r WHERE r.id = c.item_id)" )
	);
	$check(
		'comment root is a type the importer carries',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'activity_comment' AND is_spam = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity c JOIN {$p}bp_activity r ON r.id = c.item_id WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND r.type NOT IN ('activity_update','new_blog_post')" ),
		'warn',
		'these cannot migrate - system notices have no BuddyNext equivalent'
	);
	$check(
		'nested comment -> parent comment',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity c WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND c.secondary_item_id <> c.item_id" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity c WHERE c.type = 'activity_comment' AND c.is_spam = 0 AND c.secondary_item_id <> c.item_id AND NOT EXISTS (SELECT 1 FROM {$p}bp_activity pa WHERE pa.id = c.secondary_item_id)" )
	);
	$check(
		'new_blog_post -> published post',
		$n( "SELECT COUNT(*) FROM {$p}bp_activity WHERE type = 'new_blog_post' AND is_spam = 0" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_activity a WHERE a.type = 'new_blog_post' AND a.is_spam = 0 AND ( a.secondary_item_id = 0 OR NOT EXISTS (SELECT 1 FROM {$wpdb->posts} po WHERE po.ID = a.secondary_item_id) )" ),
		'warn',
		'no local post means the card falls back to the activity text'
	);
}

echo "\n=== CONNECTIONS ===\n";
if ( $exists( 'bp_friends' ) ) {
	$check(
		'friendship -> both users',
		$n( "SELECT COUNT(*) FROM {$p}bp_friends" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_friends f WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = f.initiator_user_id) OR NOT EXISTS (SELECT 1 FROM {$wpdb->users} u2 WHERE u2.ID = f.friend_user_id)" )
	);
	$check(
		'no self-friendship',
		$n( "SELECT COUNT(*) FROM {$p}bp_friends" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_friends WHERE initiator_user_id = friend_user_id" )
	);
}

echo "\n=== MESSAGES ===\n";
if ( $exists( 'bp_messages_messages' ) ) {
	$check(
		'message -> sender',
		$n( "SELECT COUNT(*) FROM {$p}bp_messages_messages" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_messages_messages m WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = m.sender_id)" )
	);
	if ( $exists( 'bp_messages_recipients' ) ) {
		$check(
			'thread has recipients',
			$n( "SELECT COUNT(DISTINCT thread_id) FROM {$p}bp_messages_messages" ),
			$n( "SELECT COUNT(*) FROM (SELECT DISTINCT m.thread_id FROM {$p}bp_messages_messages m WHERE NOT EXISTS (SELECT 1 FROM {$p}bp_messages_recipients r WHERE r.thread_id = m.thread_id)) x" )
		);
		$check(
			'thread has TWO participants',
			$n( "SELECT COUNT(DISTINCT thread_id) FROM {$p}bp_messages_recipients" ),
			$n( "SELECT COUNT(*) FROM (SELECT thread_id FROM {$p}bp_messages_recipients GROUP BY thread_id HAVING COUNT(DISTINCT user_id) < 2) x" ),
			'warn',
			'a one-sided thread is refused by WPMediaVerse'
		);
	}
}

echo "\n=== MEDIA ===\n";
if ( $exists( 'bp_media' ) ) {
	$check(
		'media -> attachment post',
		$n( "SELECT COUNT(*) FROM {$p}bp_media" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_media m WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} po WHERE po.ID = m.attachment_id)" )
	);
	$check(
		'media attachment has a FILE',
		$n( "SELECT COUNT(*) FROM {$p}bp_media" ),
		$n( "SELECT COUNT(*) FROM {$p}bp_media m WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = m.attachment_id AND pm.meta_key = '_wp_attached_file')" ),
		'warn',
		'ingestion refuses a row with no file (file_missing_or_upload_refused)'
	);
	if ( $exists( 'bp_media_albums' ) ) {
		$check(
			'album media -> album',
			$n( "SELECT COUNT(*) FROM {$p}bp_media WHERE COALESCE( album_id, 0 ) > 0" ),
			$n( "SELECT COUNT(*) FROM {$p}bp_media m WHERE COALESCE( m.album_id, 0 ) > 0 AND NOT EXISTS (SELECT 1 FROM {$p}bp_media_albums a WHERE a.id = m.album_id)" )
		);
		$check(
			'album -> owner',
			$n( "SELECT COUNT(*) FROM {$p}bp_media_albums" ),
			$n( "SELECT COUNT(*) FROM {$p}bp_media_albums a WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = a.user_id)" )
		);
	}
}

echo "\n=== FORUMS ===\n";
$forum_ct = $n( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish'" );
if ( $forum_ct > 0 ) {
	$check(
		'topic -> forum',
		$n( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic'" ),
		$n( "SELECT COUNT(*) FROM {$wpdb->posts} t WHERE t.post_type = 'topic' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} f WHERE f.ID = t.post_parent AND f.post_type = 'forum')" )
	);
	$check(
		'reply -> topic',
		$n( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply'" ),
		$n( "SELECT COUNT(*) FROM {$wpdb->posts} r WHERE r.post_type = 'reply' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} t WHERE t.ID = r.post_parent AND t.post_type = 'topic')" )
	);
}

echo "\n=== MEMBER TYPES ===\n";
$check(
	'member type assignment -> user',
	$n( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_member_type'" ),
	$n( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'bp_member_type' WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = tr.object_id)" )
);
$check(
	'member type has a definition post',
	$n( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'bp_member_type'" ),
	$n( "SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'bp_member_type' WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} po ON po.ID = pm.post_id AND po.post_type = 'bp-member-type' WHERE pm.meta_key = '_bp_member_type_key' AND pm.meta_value = t.slug)" ),
	'warn',
	'without one the label migrates as the slug'
);

printf( "\n=== %d relation(s) intact, %d expected note(s), %d BROKEN ===\n\n", $pass, $warn, $fail );
