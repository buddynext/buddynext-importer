#!/bin/bash
# A blank site carrying a BUDDYBOSS community, and nothing of ours active.
#
# CLAUDE.md records that the BuddyBoss paths - BuddyBossAdapter, album media,
# {{mention_user_id_N}} mentions - have NO automated coverage, because BuddyBoss
# is a paid plugin. The free Platform build closes that: it is the same codebase
# for everything this importer reads.
#
# BuddyBoss and BuddyPress cannot both run; this fixture is BuddyBoss-only, and
# the source's own generator is used where one exists.
set -euo pipefail

USERS="${USERS:-200}"
GROUPS="${GROUPS:-20}"
BB_ZIP="/dist/bb-platform-free-3.2.0.zip"

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

$WP core install --url=http://localhost:8080 --title="BuddyBoss source" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyX theme =="
$WP theme install buddyx --activate 2>/dev/null || echo "  (buddyx unavailable - staying on the default theme)"

echo "== BuddyBoss Platform =="
if [ ! -f "$BB_ZIP" ]; then
	echo "  ERROR: $BB_ZIP is missing. Fetch it on the host first:"
	echo "    curl -L -o docker/.dist/bb-platform-free-3.2.0.zip \\"
	echo "      https://github.com/buddyboss/buddyboss-platform/releases/download/3.2.0/bb-platform-free-3.2.0.zip"
	exit 1
fi
$WP plugin install "$BB_ZIP" --activate

# BuddyBoss resets bp-active-components to its own defaults during the install
# pass that runs on a LATER request than the activation - so switching components
# on straight after `plugin install --activate` gets silently undone, and the
# generators then produce nothing for want of a component. A first pass installs
# the tables; the option is re-asserted afterwards and READ BACK, because the
# state is only trustworthy once BuddyBoss has finished with it.
#
# The readback uses the option, not `bp component list`: BuddyBoss reports
# "Active" capitalised where BuddyPress reports "active", so a case-sensitive
# parse silently sees nothing.
# document and video are not optional extras here: BuddyBoss's own Groups nav
# calls bp_is_group_video_support_enabled() and its document twin without
# guarding for the component, so groups + media WITHOUT them fatals every
# group page with "call to undefined function".
COMPONENTS="xprofile groups activity friends messages notifications settings media document video forums"

for pass in 1 2; do
	for c in $COMPONENTS; do
		$WP bp component activate "$c" >/dev/null 2>&1 || true
	done
	sleep 2
done

ACTIVE=$($WP option get bp-active-components --format=json 2>/dev/null || echo '{}')
echo "  active components: $ACTIVE"
MISSING=""
for c in groups friends messages media; do
	case "$ACTIVE" in
		*"\"$c\""*) ;;
		*) MISSING="$MISSING $c" ;;
	esac
done
if [ -n "$MISSING" ]; then
	echo "  ERROR: still inactive:$MISSING - the generators below would produce nothing."
	echo "         Re-run: ./run.sh bb"
	exit 1
fi

$WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP rewrite flush --hard >/dev/null 2>&1 || true

echo "== community =="
# The playground CLI targets BuddyPress APIs, most of which BuddyBoss keeps
# compatible - so try it, and fall back to BuddyBoss's own generator.
if $WP plugin activate buddypress-playground-cli 2>/dev/null; then
	$WP bp playground users      --count="$USERS" --member-types || echo "  users generator FAILED (see above)"
	$WP eval '
		if ( function_exists( "bp_playground_create_xprofile_structure" ) ) {
			$s = bp_playground_create_xprofile_structure();
			printf( "  xprofile: %d group(s), %d field(s)" . PHP_EOL, (int) $s["groups_created"], (int) $s["fields_created"] );
			$r = bp_playground_populate_xprofile( get_users( array( "fields" => "ID", "number" => 0 ) ), true );
			printf( "  xprofile: %d value(s)" . PHP_EOL, (int) $r["fields_populated"] );
		}' 2>/dev/null || true
	$WP bp playground groups     --count="$GROUPS" --types=mixed --membership-patterns || echo "  groups generator FAILED (see above)"
	$WP bp playground friends    --density=0.05 || echo "  friends generator FAILED (see above)"
	$WP bp playground activities --count=600 --with-comments --with-mentions --comment-rate=0.4 || echo "  activity generator FAILED (see above)"
	$WP bp playground messages   --count=100 || echo "  messages generator FAILED (see above)"
fi

# The BuddyBoss-only shapes: album media, and a mention stored as an id
# placeholder rather than a handle. Neither exists on a BuddyPress source, and
# both are what the newest importer commit addressed.
echo "== media, albums, group types and their comments =="
# Goes through BuddyBoss's own APIs, so each object is created the way an upload
# creates it - with a real file, an attachment and its activity.
$WP eval-file /scripts/seed-bb-rich.php

echo "== profile-type / group-type definition posts =="
# BuddyBoss defines types as a CPT and its admin screens list the POSTS. Without
# them the Profile Types screen is empty and type labels migrate as slugs.
$WP eval-file /scripts/seed-bb-type-posts.php

echo "== BuddyBoss-only source shapes =="
$WP eval-file /scripts/seed-bb-shapes.php

echo "== place group activity the generator left unparented =="
# The generator writes component='groups' with item_id = 0 when Groups was
# inactive at the time. Those cannot be placed, so the importer refuses them -
# correct, but as a fixture it means a quarter of the importable posts are
# skipped for a reason unrelated to the code under test.
$WP eval-file /scripts/fix-orphan-group-activity.php

echo "== blog posts, and comments on non-importable roots =="
$WP eval-file /scripts/seed-blog-posts.php
$WP eval-file /scripts/seed-comment-roots.php
# Reactions on COMMENTS: indistinguishable from a post reaction in the source,
# and the generators never produce them.
$WP eval-file /scripts/seed-comment-reactions.php
$WP eval-file /scripts/seed-locked-content.php
$WP eval-file /scripts/seed-reserved-slug.php

echo
echo "== relationship baseline (before any migration) =="
$WP eval-file /scripts/snapshot-source.php

echo
echo "== SOURCE READY (BuddyBoss) =="
echo "  http://localhost:8080/wp-admin/  (admin / admin, or ?autologin=1)"
