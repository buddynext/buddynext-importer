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
# Which BuddyBoss build to install. Overridable because only some builds carry
# the media component - albums and bp_media exist there, and they are the whole
# point of the BuddyBoss-only paths.
#
# The default is RESOLVED, not hard-coded: the release filename changes between
# versions (bb-platform-free-X.zip vs buddyboss-platform-X.zip), and a literal
# default goes stale the moment a different build is dropped into .dist - which
# is exactly what happened (the default named 3.3.0 while .dist held the 3.2.0
# free build, so `./run.sh bb` aborted on a zip nobody had). Take the
# newest-sorting BuddyBoss zip actually present instead.
#
# The `|| true` is load-bearing: `ls` returns non-zero when ANY operand does not
# match, and under `set -euo pipefail` that non-zero propagates out of the
# command substitution and kills the script before its first echo - a silent
# exit 1 with no output at all. Only one of the two filename shapes is ever
# present, so a partial match is the NORMAL case here, not an error.
if [ -z "${BB_ZIP:-}" ]; then
	BB_ZIP="$( { ls -1 /dist/bb-platform-free-*.zip /dist/buddyboss-platform-*.zip 2>/dev/null || true; } | sort -V | tail -1 )"
fi
BB_ZIP="${BB_ZIP:-/dist/buddyboss-platform.zip}"

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
# --------------------------------------------------------------------------- #
# Re-assert the components ONCE MORE, and verify FUNCTIONALLY this time.
#
# The two-pass block above is necessary but NOT sufficient. Its readback reads
# `bp-active-components` immediately, and BuddyBoss's install/upgrade routines
# keep running on later requests - so the option can be reset AFTER the readback
# has already reported success. Observed directly: the seed reported groups,
# friends, messages and media active, and BuddyBoss's own Features screen then
# showed Social Groups, Private Messaging and Media Uploading switched OFF, with
# a real photo upload failing at admin-ajax with a bare `0` (no handler
# registered).
#
# That is the difference between a fixture that LOOKS seeded and one that can
# actually be exercised: with media off, bp_media_add() below still writes rows,
# but they land with activity_id = 0 and NO bp_media_ids activity meta - which is
# exactly the shape BuddyBossAdapter::activity_media_for() reads. The activity
# media path then has nothing to read, and the fixture silently proves nothing.
#
# So: assert again here, at the point it matters, and verify through the same
# gate the front end uses rather than through the option.
echo "== re-asserting components before seeding media =="
for c in $COMPONENTS; do
	$WP bp component activate "$c" >/dev/null 2>&1 || true
done

# Turning the COMPONENT on is not enough - BuddyBoss gates each upload surface
# behind its own setting, and they are OFF by default. Observed on this fixture:
# every one of these was unset, the composer offered only "Attach photo", and
# messages/documents/videos/follow could not be produced at all. These are the
# same option names BuddyBoss's own settings screens write (verified by toggling
# each in wp-admin and reading the option back).
echo "== enabling the per-surface upload + follow settings =="
$WP eval '
	$opts = array(
		// Photos.
		"bp_media_profile_media_support"     => 1,
		"bp_media_profile_albums_support"    => 1,
		"bp_media_group_media_support"       => 1,
		"bp_media_group_albums_support"      => 1,
		"bp_media_messages_media_support"    => 1,
		// Documents.
		"bp_media_profile_document_support"  => 1,
		"bp_media_group_document_support"    => 1,
		"bp_media_messages_document_support" => 1,
		// Videos.
		"bp_video_profile_video_support"     => 1,
		"bp_video_group_video_support"       => 1,
		"bp_video_messages_video_support"    => 1,
		// Follow - the whole of the follows domain depends on this one.
		"_bp_enable_activity_follow"         => 1,
	);
	foreach ( $opts as $k => $v ) {
		bp_update_option( $k, $v );
	}
	printf( "  %d upload/follow settings written" . PHP_EOL, count( $opts ) );
' 2>/dev/null || echo "  WARNING: could not write upload settings"

# A FRESH wp invocation, so the check sees a bootstrap that ran after every
# BuddyBoss install pass - not the one this script started with.
GATES=$($WP eval '
	$need = array( "groups", "messages", "media", "document", "friends" );
	$off  = array();
	foreach ( $need as $c ) {
		if ( ! bp_is_active( $c ) ) { $off[] = $c; }
	}
	// The front end gates uploads on THESE, not on the component alone. The
	// activity composer is governed by the *profile* gates - BuddyBoss labels
	// that setting "in profiles and activity posts" and there is no
	// bp_is_activity_*_support_enabled() function at all. Guessing that name
	// gives a check that function_exists() skips silently, i.e. a gate that
	// always passes. Assert the functions exist rather than tolerate absence.
	$gates = array(
		"profile-media"     => "bp_is_profile_media_support_enabled",
		"profile-document"  => "bp_is_profile_document_support_enabled",
		"profile-video"     => "bp_is_profile_video_support_enabled",
		"group-media"       => "bp_is_group_media_support_enabled",
		"messages-media"    => "bp_is_messages_media_support_enabled",
		"messages-document" => "bp_is_messages_document_support_enabled",
		"follow"            => "bp_is_activity_follow_active",
	);
	foreach ( $gates as $label => $fn ) {
		if ( ! function_exists( $fn ) ) {
			$off[] = $label . "(no-such-function:" . $fn . ")";
		} elseif ( ! $fn() ) {
			$off[] = $label;
		}
	}
	echo implode( " ", $off );
' 2>/dev/null || echo "eval-failed" )

if [ -n "$GATES" ]; then
	echo "  ERROR: still gated off:$GATES"
	echo "         Media seeded now would land with activity_id = 0 and no"
	echo "         bp_media_ids meta, and the fixture would prove nothing."
	exit 1
fi
echo "  all component + upload gates open"

# Media first: albums (profile AND group), photos that carry an activity, photos
# that do not, and media in no album. Generated by the playground rather than
# here - building a source community is its job, this repo only reads BuddyBoss.
# It writes the bp_media_ids ACTIVITY META too, which is what readers actually
# resolve media through; the bp_media.activity_id column alone leaves a photo
# invisible.
echo "== media + albums (via the playground generator) =="
# Asserted rather than assumed: the generator is activated inside an `if` far
# above, so a failure there would surface here as a bare "command not found"
# and a fixture with no media - which is the silence this whole file exists to
# stop.
$WP plugin activate buddypress-playground-cli >/dev/null 2>&1 || true
# --messages matters as much as the rest: a DM photo is stored on the MESSAGE
# (bp_media.message_id plus a bp_media_ids row in bp_messages_meta), a different
# path from activity and album media, and the one a fixture most easily omits
# because nothing else breaks when it is missing.
$WP bp playground media --count=2 --albums=2 --group-albums=1 --standalone=1 --messages=2

echo "== group types, and comments on the media activity =="
# Order matters: the comment pass hangs comments off the media activities the
# step above just created.
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
