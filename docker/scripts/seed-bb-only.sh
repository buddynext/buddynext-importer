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

echo "== Reign theme =="
# Reign is what most BuddyBoss customers run, so the source looks like theirs.
$WP theme install reign-theme --activate 2>/dev/null \
	|| $WP theme install buddyx --activate 2>/dev/null \
	|| echo "  (neither Reign nor BuddyX available - staying on the default theme)"

echo "== BuddyBoss Platform =="
if [ ! -f "$BB_ZIP" ]; then
	echo "  ERROR: $BB_ZIP is missing. Fetch it on the host first:"
	echo "    curl -L -o docker/.dist/bb-platform-free-3.2.0.zip \\"
	echo "      https://github.com/buddyboss/buddyboss-platform/releases/download/3.2.0/bb-platform-free-3.2.0.zip"
	exit 1
fi
$WP plugin install "$BB_ZIP" --activate

# BuddyBoss resets bp-active-components during its own activation bootstrap, so
# these must go on AFTER it has settled - and the state is read back from the
# OPTION rather than parsed from `bp component list`, because BuddyBoss reports
# "Active" capitalised where BuddyPress reports "active" and a case-sensitive
# parse silently found nothing.
sleep 3
for c in xprofile groups activity friends messages notifications settings media forums; do
	$WP bp component activate "$c" >/dev/null 2>&1 || true
done

ACTIVE=$($WP option get bp-active-components --format=json 2>/dev/null || echo '{}')
echo "  active components: $ACTIVE"
case "$ACTIVE" in
	*groups*) ;;
	*) echo "  WARNING: groups is still inactive - the generators below will produce nothing" ;;
esac

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
echo "== BuddyBoss-specific shapes =="
$WP eval-file /scripts/seed-bb-shapes.php

echo
echo "== relationship baseline (before any migration) =="
$WP eval-file /scripts/snapshot-source.php

echo
echo "== SOURCE READY (BuddyBoss) =="
echo "  http://localhost:8080/wp-admin/  (admin / admin, or ?autologin=1)"
