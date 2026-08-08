#!/bin/bash
# A blank site with a BuddyPress community on it, and nothing else.
#
# BuddyPress + bbPress only - BuddyNext and its addons stay INACTIVE. This is
# the migration SOURCE as a customer would hand it over: their community, before
# anything of ours has touched it.
#
#   USERS / GROUPS / ACTIVITIES override the size (defaults below).
set -euo pipefail

USERS="${USERS:-500}"
GROUPS="${GROUPS:-40}"
ACTIVITIES="${ACTIVITIES:-3000}"
MESSAGES="${MESSAGES:-800}"

WP="php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html"

echo "== WordPress core =="
for i in $(seq 1 90); do
	if [ -f /var/www/html/wp-includes/version.php ] && [ -f /var/www/html/wp-config.php ] \
		&& [ -f /var/www/html/wp-settings.php ] && [ -d /var/www/html/wp-content/plugins ]; then
		break
	fi
	sleep 2
done
mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads 2>/dev/null || true

$WP core install --url=http://localhost:8080 --title="BuddyPress source" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyX theme =="
# The source should look like a customer's site, and BuddyX is what most of
# them run - a theme that registers its own BuddyPress surfaces.
$WP theme install buddyx --activate 2>/dev/null || echo "  (buddyx unavailable - staying on the default theme)"

echo "== BuddyPress + bbPress + rtMedia =="
$WP plugin install buddypress --activate
$WP plugin install bbpress --activate 2>/dev/null || echo "  (bbpress unavailable)"

# rtMedia is the media layer on a BuddyPress source - BuddyPress core has none.
# Without it there is no rt_rtm_media table, so BuddyPressAdapter's rtMedia
# reads (activity_media_for(), media_albums(), standalone_media(), the coverage
# counts) have nothing to run against and the whole BuddyPress media story is
# untested. The wp.org slug is `buddypress-media`, not `rtmedia`.
$WP plugin install buddypress-media --activate 2>/dev/null || echo "  (rtMedia unavailable - rt_rtm_media paths will be untested)"

# Activating BuddyPress resets its component settings, so these go on AFTER
# activation or the community has no groups, friends or messages to build.
# The first attempt can race BuddyPress's own bootstrap, so each one is checked
# and retried - swallowing the failure left the components off and the
# generation died later with a much less obvious error.
for c in xprofile groups activity friends messages notifications settings; do
	for attempt in 1 2 3; do
		if $WP bp component activate "$c" 2>&1 | grep -qiE 'success|already active'; then
			break
		fi
		sleep 2
	done
done

echo "  active components: $($WP bp component list 2>/dev/null | awk -F'\t' '$3=="active"{printf "%s ", $2}')"


$WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP rewrite flush --hard >/dev/null 2>&1 || true

echo "== community: ${USERS} members, ${GROUPS} groups, ${ACTIVITIES} activities =="
$WP plugin activate buddypress-playground-cli

# Built in dependency order: members and their profiles exist before anything
# references them, groups before the activity posted into them.
$WP bp playground users      --count="$USERS" --member-types

# The granular commands do not build the xProfile structure (only `scenario`
# does), so a community made this way has no profile fields and no values -
# silently skipping one of the largest migration domains. Build it explicitly.
$WP eval '
$s = bp_playground_create_xprofile_structure();
printf( "  xprofile: %d group(s), %d field(s)" . PHP_EOL, (int) $s["groups_created"], (int) $s["fields_created"] );
$r = bp_playground_populate_xprofile( get_users( array( "fields" => "ID", "number" => 0 ) ), true );
printf( "  xprofile: %d value(s) across %d user(s)" . PHP_EOL, (int) $r["fields_populated"], (int) $r["users_populated"] );
'
$WP bp playground groups     --count="$GROUPS" --types=mixed --with-hierarchy --membership-patterns
$WP bp playground friends    --density="${DENSITY:-0.04}" --pending-rate=0.15
$WP bp playground activities --count="$ACTIVITIES" --with-comments --with-mentions --comment-rate=0.35 --favorite-rate=0.2
$WP bp playground messages   --count="$MESSAGES"
# --topics and --replies are PER PARENT, and replies is capped at 100.
$WP bp playground forums     --forums=8 --topics=15 --replies=8 2>/dev/null || echo "  (forums skipped - bbPress inactive)"

# The playground's new_blog_post rows are synthetic - secondary_item_id 0 and a
# /blog/ permalink - so they cannot exercise resolving the published post
# locally, which is the whole point of building a link card without an HTTP
# fetch. These carry real post ids and permalinks.
echo "== realistic blog-post activities =="
$WP eval-file /scripts/seed-blog-posts.php

echo
echo "== relationship baseline (before any migration) =="
# Captured now so what the migration moved is compared against a fixed
# reference, not against the source as it looks afterwards.
$WP eval-file /scripts/snapshot-source.php

echo
echo "== SOURCE READY =="
$WP bp playground stats 2>/dev/null || true
echo
echo "  http://localhost:8080/wp-admin/  (admin / admin, or ?autologin=1)"
echo "  BuddyNext is NOT active - this is a pure BuddyPress source."
