#!/bin/bash
# One command: build the source community, migrate it, reconcile the result.
#
#   ./run.sh          full cycle from scratch, hand-written adversarial fixture
#   ./run.sh reign    same, but seeded from the Wbcom Reign BuddyPress demo pack
#                     (a real community - every domain has data to move)
#   ./run.sh bb       BLANK site + a BUDDYBOSS community (Reign theme). Needs
#                     docker/.dist/bb-platform-free-3.2.0.zip on the host.
#   ./run.sh bp       BLANK site + a BuddyPress community only (no BuddyNext),
#                     sized with USERS/GROUPS/ACTIVITIES env vars
#   ./run.sh small    50-user community via buddypress-playground-cli
#   ./run.sh large    5000-user community, for scale
#   ./run.sh fresh    a CLEAN site - WordPress + BuddyPress + BuddyNext, no
#                     content at all, for seeding by hand at localhost:8080
#   ./run.sh save     snapshot the source DB (target reset first) to .dist/
#   ./run.sh restore  put that snapshot back, so a run starts from identical data
#   ./run.sh target-on  deactivate the source platform, activate BuddyNext + BI
#   ./run.sh verify   wp buddynext-import verify against the current fixture
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
	verify)
		$DC exec -T wp php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html buddynext-import verify --samples="${2:-5}"
		exit 0
		;;
	save)
		# Snapshot the SOURCE, so every later run starts from identical data.
		# Reset the target first: a dump taken after a migration carries the
		# result as well, and restoring it would silently skip the work.
		$DC exec -T wp php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html eval-file /scripts/reset-target.php
		mkdir -p .dist
		$DC exec -T db mysqldump -uroot -proot --single-transaction --quick --default-character-set=utf8mb4 wordpress > .dist/source-snapshot.sql
		echo "saved $(wc -c < .dist/source-snapshot.sql | tr -d ' ') bytes to docker/.dist/source-snapshot.sql"
		exit 0
		;;
	restore)
		if [ ! -f .dist/source-snapshot.sql ]; then
			echo "No snapshot. Take one first: ./run.sh save"
			exit 1
		fi
		$DC exec -T db mysql -uroot -proot --default-character-set=utf8mb4 wordpress < .dist/source-snapshot.sql
		$DC exec -T wp php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html cache flush >/dev/null 2>&1 || true
		echo "restored the source snapshot - the target is empty, ready to import"
		exit 0
		;;
	target-on)
		echo "Deactivating the source platform and activating BuddyNext + the importer."
		$DC exec -T wp php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html plugin deactivate buddyboss-platform buddypress 2>/dev/null || true
		$DC exec -T wp php -d memory_limit=1024M /usr/local/bin/wp --allow-root --path=/var/www/html plugin activate buddynext buddynext-pro wpmediaverse jetonomy buddynext-importer
		exit 0
		;;
	migrate)
		$DC exec -T wp php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html buddynext-import migrate-all --source=buddypress
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
SOURCE_ONLY=no
case "${1:-all}" in
	reign)    SEED=/scripts/seed-source-reign.sh; set -- all ;;
	fresh)    SEED=/scripts/seed-fresh.sh; MIGRATE=no; set -- all ;;
	bp)       SEED=/scripts/seed-bp-only.sh; MIGRATE=no; SOURCE_ONLY=yes; set -- all ;;
	bb)       SEED=/scripts/seed-bb-only.sh; MIGRATE=no; SOURCE_ONLY=yes; set -- all ;;
	small)    SEED=/scripts/seed-playground.sh; export SCENARIO=small_community; set -- all ;;
	large)    SEED=/scripts/seed-playground.sh; export SCENARIO=large_community; set -- all ;;
	reign-ui) SEED=/scripts/seed-source-reign.sh; MIGRATE=no; set -- all ;;
esac

if [ "${1:-all}" = "all" ]; then
	echo "== tearing down any previous fixture =="
	$DC down -v >/dev/null 2>&1 || true
	$DC up -d
	echo "== waiting for the database =="
	until $DC exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; do sleep 2; done
	$DC exec -T -e SCENARIO="${SCENARIO:-small_community}" -e USERS="${USERS:-}" -e GROUPS="${GROUPS:-}" -e ACTIVITIES="${ACTIVITIES:-}" -e MESSAGES="${MESSAGES:-}" wp bash "$SEED"
fi

# A source-only fixture leaves the target stack OFF, so the site is what a
# customer hands over: their community, with nothing of ours running in it. The
# migration is a separate, deliberate step (./run.sh target-on).
if [ "$SOURCE_ONLY" = "yes" ]; then
	echo
	echo "== source-only: BuddyNext and the importer are NOT active =="
	echo "  when you are ready:  ./run.sh target-on   then   ./run.sh migrate"
	exit 0
fi

echo
echo "== activating the target stack =="
# BuddyNext writes through its own service API, so it has to be active. The
# addons are optional: without WPMediaVerse there are no DMs or media to import,
# without Jetonomy no forums - the importer skips those domains by design.
$WP plugin activate buddynext wpmediaverse jetonomy buddynext-importer || true

if [ "$MIGRATE" = "no" ]; then
	exit 0
fi

echo
echo "== migrate =="
$WP buddynext-import migrate-all --source=buddypress

echo
echo "== reconcile =="
$WP eval-file /scripts/reconcile.php
