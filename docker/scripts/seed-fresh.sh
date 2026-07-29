#!/bin/bash
# A CLEAN site: WordPress + BuddyPress + BuddyNext and friends, and NO content.
#
# Everything is installed and active, every BuddyPress component the importer
# reads is switched on, and nothing has been seeded - so the community is yours
# to build by hand before running the migration against it.
set -euo pipefail

WP="php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html"

echo "== WordPress core =="
# The web container owns core + wp-config in the shared volume; racing it with
# our own core download left a half-copied tree. Wait for it, then install.
for i in $(seq 1 90); do
	if [ -f /var/www/html/wp-includes/version.php ] && [ -f /var/www/html/wp-config.php ] \
		&& [ -f /var/www/html/wp-settings.php ] && [ -d /var/www/html/wp-content/plugins ]; then
		break
	fi
	sleep 2
done
mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads 2>/dev/null || true

$WP core install --url=http://localhost:8080 --title="BI Fixture (clean)" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email

echo "== BuddyPress =="
$WP plugin install buddypress --activate
for c in xprofile groups activity friends messages notifications settings; do
	$WP bp component activate "$c" >/dev/null 2>&1 || true
done

echo "== target stack =="
$WP plugin activate buddynext wpmediaverse jetonomy buddynext-importer 2>&1 | tail -2

# Permalinks, so BuddyPress and BuddyNext screens resolve rather than 404.
$WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
$WP rewrite flush --hard >/dev/null 2>&1 || true

echo
echo "== CLEAN SITE READY =="
echo "  URL       http://localhost:8080"
echo "  admin     http://localhost:8080/wp-admin/  (admin / admin)"
echo "  auto-login  add ?autologin=1 to any URL"
echo "  importer  http://localhost:8080/wp-admin/tools.php?page=buddynext-importer"
echo
echo "  No content seeded. Build the BuddyPress community however you like,"
echo "  then run the import from the page above or with:"
echo "    ./run.sh migrate      (wp buddynext-import migrate-all)"
echo "    ./run.sh verify       (wp buddynext-import verify)"
