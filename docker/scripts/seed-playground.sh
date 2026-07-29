#!/bin/bash
# Build the SOURCE community with buddypress-playground-cli.
#
# Wbcom already maintains a purpose-built BuddyPress data generator, and it
# covers the domains this importer's own fixtures left empty - member types,
# group types, reactions, messages and forums - seeded in a documented
# dependency order. Reproducing that here would have been a second seeder to
# keep correct.
#
#   SCENARIO=small_community  (50 users)   default
#   SCENARIO=large_community  (5000 users) scale
set -euo pipefail

SCENARIO="${SCENARIO:-small_community}"
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

$WP core install --url=http://localhost:8080 --title="BI Fixture ($SCENARIO)" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyPress =="
$WP plugin install buddypress --activate
$WP plugin install bbpress --activate 2>/dev/null || echo "  (bbpress unavailable - forums will not be generated)"
for c in xprofile groups activity friends messages notifications settings; do
	$WP bp component activate "$c" >/dev/null 2>&1 || true
done

echo "== generating the community ($SCENARIO) =="
$WP plugin activate buddypress-playground-cli
$WP bp playground scenario generate "$SCENARIO" --clean --yes

# Importer-specific traps the generator has no reason to produce. These are not
# "more data" - each one is a source shape that broke this importer, kept so a
# green run means something.
echo "== importer edge cases =="
$WP eval-file /scripts/seed-comment-roots.php
$WP eval-file /scripts/seed-reserved-slug.php

echo
echo "== SOURCE SEEDED =="
$WP eval-file /scripts/source-counts.php
