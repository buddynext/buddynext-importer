#!/bin/bash
# Build the SOURCE community from the Wbcom Reign BuddyPress demo pack.
#
# Richer than seed-source.sh: a real community with 11 xProfile field types,
# groups, activity, comments, friendships, reactions, messages and member types
# - so every import domain has something to move, instead of eleven of sixteen
# sitting at zero.
set -euo pipefail

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
$WP core install --url=http://localhost:8080 --title="BI Fixture (Reign demo)" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyPress + the plugins the demo data belongs to =="
$WP plugin install buddypress --activate
# The demo carries bp_reactions_* and member-type data. Without the plugins that
# own those tables the rows load but nothing reads them - and the importer's
# reaction + member-type domains would look empty for the wrong reason.
$WP plugin install buddypress-reactions --activate 2>/dev/null || echo "  (buddypress-reactions unavailable - reactions domain will read from usermeta only)"
$WP plugin install buddypress-member-type --activate 2>/dev/null || echo "  (buddypress-member-type unavailable - member types may not import)"
$WP plugin install bbpress --activate 2>/dev/null || echo "  (bbpress unavailable - forums will not import)"

for c in xprofile groups activity friends messages notifications; do
	$WP bp component activate "$c" >/dev/null 2>&1 || true
done

echo "== loading the Reign demo community =="
$WP eval-file /scripts/load-demo-pack.php

echo
echo "== SOURCE SEEDED =="
$WP eval-file /scripts/source-counts.php
