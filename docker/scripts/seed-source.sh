#!/bin/bash
# Build the BuddyPress SOURCE community the migration will be run against.
#
# This is not "some demo data". Every block below exists because a specific
# import path can only be exercised by a specific shape of source row, and the
# adversarial cases are called out inline - a fixture that only contains the
# happy path proves the importer works on the happy path.
set -euo pipefail

# The image pins memory_limit=128M in php.ini and ignores WP_CLI_PHP_ARGS,
# which is not enough to extract WordPress core - so invoke php directly.
WP="php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html"

echo "== WordPress core =="
# The web container owns core + wp-config in the shared volume; racing it with
# our own core download left a half-copied tree. Wait for it, then install.
# version.php appears early while wp-content is still being copied, so waiting
# on it alone let the plugin install run against a half-populated tree.
for i in $(seq 1 90); do
	if [ -f /var/www/html/wp-includes/version.php ] && [ -f /var/www/html/wp-config.php ] \
		&& [ -f /var/www/html/wp-settings.php ] && [ -d /var/www/html/wp-content/plugins ]; then
		break
	fi
	sleep 2
done
mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads 2>/dev/null || true
$WP core install --url=http://localhost:8080 --title="BI Fixture" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyPress =="
$WP plugin install buddypress --activate
# Everything the importer reads from.
$WP bp component activate xprofile
$WP bp component activate groups
$WP bp component activate activity
$WP bp component activate friends
$WP bp component activate messages
$WP bp component activate notifications

echo "== Members =="
for i in $(seq 1 12); do
	$WP user create "member$i" "member$i@example.test" --role=subscriber --user_pass=pass >/dev/null
done
MEMBERS=$($WP user list --role=subscriber --field=ID | tr '\n' ' ')
set -- $MEMBERS
U1=$1; U2=$2; U3=$3; U4=$4; U5=$5

echo "== xProfile fields =="
GID=$($WP bp xprofile group create --name="Details" --porcelain)

# ADVERSARIAL: a checkbox field. BP stores its value as a serialised ARRAY of
# option labels. This is the card-10135388592 case - the old map sent it to a
# BuddyNext type that does not exist and every value was dropped on the way in.
CB=$($WP bp xprofile field create --field-group-id="$GID" --name="Checkboxes" --type=checkbox --porcelain)
# A single-value field, as the control - it always migrated, so if it breaks we
# know the regression is ours and not the checkbox path.
TB=$($WP bp xprofile field create --field-group-id="$GID" --name="Tagline" --type=textbox --porcelain)

# A BuddyPress checkbox field carries its choices as CHILD field rows. Without
# them the field is a checkbox with no boxes, and any value is unmatchable - so
# seed them the way BuddyPress itself does.
$WP eval "
\$wpdb = \$GLOBALS['wpdb'];
foreach ( array( 'checkbox 1', 'checkbox 2', 'checkbox 3', 'checkbox 4' ) as \$i => \$opt ) {
	\$wpdb->insert( \$wpdb->prefix . 'bp_xprofile_fields', array(
		'group_id' => $GID, 'parent_id' => $CB, 'type' => 'option',
		'name' => \$opt, 'is_required' => 0, 'field_order' => \$i + 1, 'option_order' => \$i + 1,
	) );
}
echo 'checkbox options seeded' . PHP_EOL;
"

for u in $MEMBERS; do
	$WP bp xprofile data set --user-id="$u" --field-id="$CB" --value='checkbox 2,checkbox 4' >/dev/null 2>&1 || true
	$WP bp xprofile data set --user-id="$u" --field-id="$TB" --value="tagline for user $u" >/dev/null 2>&1 || true
done

echo "== Groups =="
G_OPEN=$($WP bp group create --name="Photography" --slug=photography --creator-id="$U1" --status=public --porcelain)
G_PRIV=$($WP bp group create --name="Founders" --slug=founders --creator-id="$U2" --status=private --porcelain)
G_HIDE=$($WP bp group create --name="Backchannel" --slug=backchannel --creator-id="$U3" --status=hidden --porcelain)

# ADVERSARIAL: 'joined' is a RESERVED space slug in BuddyNext
# (SpaceService::RESERVED_SLUGS). SpaceWriter::unique_slug() only loops on
# slug_exists(), never on is_reserved_slug(), so this sails through uniquing and
# is then refused by SpaceService::create() -> no IdMap entry for the space.
# Its activities are the ones ActivityWriter would republish at space_id = 0.
G_RSVD=$($WP bp group create --name="Joined" --slug=joined --creator-id="$U4" --status=private --porcelain)

for g in $G_OPEN $G_PRIV $G_HIDE $G_RSVD; do
	for u in $U2 $U3 $U5; do
		$WP bp group member add --group-id="$g" --user-id="$u" >/dev/null 2>&1 || true
	done
done

echo "== Activity =="
# Sitewide updates.
for u in $MEMBERS; do
	$WP bp activity create --component=activity --type=activity_update --user-id="$u" \
		--content="sitewide update from $u" >/dev/null
done

# Group updates, including in the reserved-slug group. THESE are the privacy
# case: a hidden/private group's post must never surface in the global feed
# just because its space failed to map.
for g in $G_OPEN $G_PRIV $G_HIDE $G_RSVD; do
	for u in $U1 $U2 $U3; do
		$WP bp activity create --component=groups --type=activity_update --user-id="$u" \
			--item-id="$g" --content="group $g update from $u" >/dev/null
	done
done

echo "== Activity comments =="
# Comments on activity_update roots - these SHOULD all migrate.
ROOTS=$($WP bp activity list --type=activity_update --field=id --number=10 2>/dev/null | tr '\n' ' ' || true)
for r in $ROOTS; do
	$WP bp activity create --type=activity_comment --user-id="$U2" --item-id="$r" \
		--secondary-item-id="$r" --content="top-level comment on $r" >/dev/null 2>&1 || true
done

# ADVERSARIAL: a comment whose ROOT IS NOT an activity_update. The posts query
# only takes type='activity_update', so this root is never imported and
# ActivityWriter drops the comment with no counter and no log line. This is the
# "activity comments 50% loss" leg of card 10135432239.
NONUPDATE=$($WP bp activity create --component=activity --type=new_member --user-id="$U5" \
	--content="joined the site" --porcelain 2>/dev/null || true)
if [ -n "${NONUPDATE:-}" ]; then
	for n in 1 2 3 4 5 6; do
		$WP bp activity create --type=activity_comment --user-id="$U3" --item-id="$NONUPDATE" \
			--secondary-item-id="$NONUPDATE" --content="comment $n on a non-update root" >/dev/null 2>&1 || true
	done
fi

# ADVERSARIAL: a nested reply (comment on a comment) - secondary_item_id points
# at the parent COMMENT, not the root. Nesting must survive, not flatten.
FIRST_C=$($WP bp activity list --type=activity_comment --field=id --number=1 2>/dev/null | head -1 || true)
if [ -n "${FIRST_C:-}" ]; then
	ROOT_OF_C=$($WP bp activity get "$FIRST_C" --field=item_id 2>/dev/null || true)
	$WP bp activity create --type=activity_comment --user-id="$U4" --item-id="${ROOT_OF_C:-1}" \
		--secondary-item-id="$FIRST_C" --content="nested reply" >/dev/null 2>&1 || true
fi

echo "== Friendships =="
for u in $U2 $U3 $U4 $U5; do
	$WP bp friend create "$U1" "$u" --force-accept >/dev/null 2>&1 || true
done

echo "== Favourites (BP 'likes' -> BN reactions) =="
# BP stores favourites in usermeta, which is the ReactionImporter's fallback path.
FAV=$(echo "$ROOTS" | tr ' ' '\n' | head -5 | tr '\n' ' ')
for u in $U1 $U2 $U3; do
	PHP_ARR="a:$(echo $FAV | wc -w | tr -d ' '):{"
	i=0
	for f in $FAV; do
		PHP_ARR="${PHP_ARR}i:${i};s:${#f}:\"${f}\";"
		i=$((i+1))
	done
	PHP_ARR="${PHP_ARR}}"
	$WP user meta update "$u" bp_favorite_activities "$PHP_ARR" >/dev/null 2>&1 || true
done

echo "== Private messages =="
for u in $U2 $U3 $U4; do
	$WP bp message send --from="$U1" --to="$u" --subject="hello $u" --content="fixture thread" >/dev/null 2>&1 || true
done

echo
echo "== SOURCE SEEDED =="
$WP eval-file /scripts/source-counts.php
