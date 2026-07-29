#!/bin/bash
# One command: build the source community, migrate it, reconcile the result.
#
#   ./run.sh          full cycle from scratch (destroys any previous fixture)
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
esac

if [ "${1:-all}" = "all" ]; then
	echo "== tearing down any previous fixture =="
	$DC down -v >/dev/null 2>&1 || true
	$DC up -d
	echo "== waiting for the database =="
	until $DC exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; do sleep 2; done
	$DC exec -T wp bash /scripts/seed-source.sh
fi

echo
echo "== activating the target stack =="
# BuddyNext writes through its own service API, so it has to be active. The
# addons are optional: without WPMediaVerse there are no DMs or media to import,
# without Jetonomy no forums - the importer skips those domains by design.
$WP plugin activate buddynext wpmediaverse jetonomy buddynext-importer || true

echo
echo "== migrate =="
$WP buddynext-import migrate-all --source=buddypress

echo
echo "== reconcile =="
$WP eval-file /scripts/reconcile.php
