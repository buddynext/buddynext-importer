#!/bin/bash
# One command: build the source community, migrate it, reconcile the result.
#
#   ./run.sh          full cycle from scratch, hand-written adversarial fixture
#   ./run.sh reign    same, but seeded from the Wbcom Reign BuddyPress demo pack
#                     (a real community - every domain has data to move)
#   ./run.sh reign-ui seed the Reign source but do NOT migrate, so the import can
#                     be run from the admin page at http://localhost:8080 - that
#                     is a different code path from the CLI and needs its own
#                     verification
#   ./run.sh migrate  re-run the migration + reconcile against the existing fixture
#   ./run.sh shell    a wp-cli shell inside the container
#   ./run.sh down     tear everything down
set -euo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )"

DC="docker compose"
WP="$DC exec -T wp php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html"

case "${1:-all}" in
	down)
		$DC down -v
		exit 0
		;;
	shell)
		$DC exec wp bash
		exit 0
		;;
	reconcile)
		$DC exec -T wp php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html eval-file /scripts/reconcile.php
		exit 0
		;;
esac

# Which source community to build. The hand-written one is adversarial but
# small; the Reign demo pack is real and exercises every domain.
SEED=/scripts/seed-source.sh
MIGRATE=yes
case "${1:-all}" in
	reign)    SEED=/scripts/seed-source-reign.sh; set -- all ;;
	reign-ui) SEED=/scripts/seed-source-reign.sh; MIGRATE=no; set -- all ;;
esac

if [ "${1:-all}" = "all" ]; then
	echo "== tearing down any previous fixture =="
	$DC down -v >/dev/null 2>&1 || true
	$DC up -d
	echo "== waiting for the database =="
	until $DC exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; do sleep 2; done
	$DC exec -T wp bash "$SEED"
fi

echo
echo "== activating the target stack =="
# BuddyNext writes through its own service API, so it has to be active. The
# addons are optional: without WPMediaVerse there are no DMs or media to import,
# without Jetonomy no forums - the importer skips those domains by design.
$WP plugin activate buddynext wpmediaverse jetonomy buddynext-importer || true

if [ "$MIGRATE" = "no" ]; then
	echo
	echo "== source ready, target untouched =="
	echo "Run the import from the admin page, then reconcile:"
	echo "  http://localhost:8080/wp-admin/tools.php?page=buddynext-importer&autologin=1"
	echo "  ./run.sh reconcile"
	exit 0
fi

echo
echo "== migrate =="
$WP buddynext-import migrate-all --source=buddypress

echo
echo "== reconcile =="
$WP eval-file /scripts/reconcile.php
