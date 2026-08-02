#!/usr/bin/env bash
# Build a self-contained, command-free release zip for QA / testers.
#
#   bin/build-release.sh [dist-dir]
#
# Produces <dist>/buddynext-importer-<version>.zip that QA installs and tests with
# NO commands (no composer, no npm). This plugin has no runtime dependencies of its
# own — it uses a hand-written PSR-4 autoloader and writes through BuddyNext's
# service layer — so the committed tree is already complete.
#
# Selection is by ALLOWLIST over `git archive`, and that is load-bearing twice
# over. `docker/` is 46MB of fixture material including two BuddyBoss Platform
# zips: they are gitignored so git archive drops them, AND docker/ is not on the
# allowlist so it could not ship even if someone committed one. Shipping a paid
# third-party plugin inside our zip is the failure this guards against. Never
# replace either mechanism with a directory walk.
#
# Version is read from the plugin header — never bumped here.
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="buddynext-importer"
VERSION="$(grep -m1 'Version:' buddynext-importer.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d ' \r')"
DIST="${1:-$HOME/Documents/work-artifacts/scratch}"

# The ONLY paths that ship. Everything else — docker/, docs/, CLAUDE.md,
# README.md, .phpcs.xml.dist — is development material.
RUNTIME=( buddynext-importer.php includes templates assets )
OPTIONAL=( languages uninstall.php readme.txt LICENSE )

# The lint gate derives its file list from bin/shipped-php-files.sh, which carries
# its own copy of this allowlist. Two lists that must agree is exactly the drift
# that let includes/Plugin.php go unlinted, so assert they agree rather than
# trusting a comment. Entries that cannot contain PHP are exempt.
NO_PHP=( readme.txt LICENSE )
for item in "${RUNTIME[@]}" "${OPTIONAL[@]}"; do
	skip=0
	for n in "${NO_PHP[@]}"; do [ "$item" = "$n" ] && skip=1; done
	[ "$skip" -eq 1 ] && continue
	if ! grep -q "SHIPPED_PATHS=(.*[( ]${item}[ )]" bin/shipped-php-files.sh; then
		echo "build FAILED: '$item' ships but is absent from SHIPPED_PATHS in bin/shipped-php-files.sh," >&2
		echo "              so its PHP would never be linted. Add it there." >&2
		exit 1
	fi
done

# 0. Release gate. This plugin has no PHPUnit suite (correctness is established by
#    running a real migration against the docker fixture, see CLAUDE.md), so the
#    static gates are what can run unattended: PHP lint over everything that will
#    ship, then WPCS if a phpcs binary can be found. Lint is mandatory — a parse
#    error in a migration tool is discovered on a customer's community.
#    Set SKIP_RELEASE_GATE=1 to bypass (e.g. a docs-only re-zip).
if [ "${SKIP_RELEASE_GATE:-0}" != 1 ]; then
	# The file list comes from bin/shipped-php-files.sh, NOT from a pathspec written
	# here. This gate used to build its own list with 'includes/**/*.php', which
	# requires at least one directory level and so silently skipped
	# includes/Plugin.php and includes/Autoloader.php — 44 of 46 shipped files
	# linted, and the two omitted were the boot class and the autoloader. A second
	# hand-written list of "what ships" drifts from the allowlist; one derived list
	# cannot. See the header of that script for the full account.
	LINT_COUNT=0
	LINT_FAIL=0
	echo "release gate: php -l over shipped PHP…"
	while IFS= read -r -d '' f; do
		LINT_COUNT=$(( LINT_COUNT + 1 ))
		if ! php -l "$f" >/dev/null 2>&1; then
			echo "release gate FAILED: parse error in $f" >&2
			php -l "$f" >&2 || true
			LINT_FAIL=1
		fi
	done < <(bin/shipped-php-files.sh)
	[ "$LINT_FAIL" -eq 0 ] || exit 1

	# A gate that inspects nothing passes. Assert it actually saw files — a broken
	# allowlist or a pathspec typo would otherwise read as success.
	if [ "$LINT_COUNT" -eq 0 ]; then
		echo "release gate FAILED: linted 0 files — bin/shipped-php-files.sh returned nothing." >&2
		exit 1
	fi
	echo "release gate: php -l OK ($LINT_COUNT file(s))"

	# WPCS. There is no composer.json here, so borrow BuddyNext's vendor binary —
	# the same thing CLAUDE.md tells a developer to do. Skipped, not failed, when
	# it is absent: a machine without the sibling repo checked out can still cut a
	# zip, it just does not get the style gate.
	#
	# ERRORS block the build; WARNINGS do not. phpcs exits non-zero on either, so
	# ignore_warnings_on_exit is required or this gate fails a clean tree — there
	# are ~10 known pre-existing warnings in VerifyService (see CLAUDE.md, which
	# tells you to check a finding is yours before fixing it). Blocking on those
	# would mean every release either bypasses the gate or carries an unrelated
	# cleanup, and a gate people routinely bypass is not a gate.
	#
	# No explicit path is passed. .phpcs.xml.dist already declares <file>.</file>
	# with docker/ and vendor/ excluded, so the ruleset decides the scope. Passing
	# `includes/` here overrode it and left the entry file and templates/
	# unchecked — the same class of defect as the lint pathspec above, one scope
	# written twice and drifting.
	PHPCS="${PHPCS:-../buddynext/vendor/bin/phpcs}"
	if [ -x "$PHPCS" ]; then
		echo "release gate: WPCS…"
		if ! "$PHPCS" --standard=.phpcs.xml.dist --runtime-set ignore_warnings_on_exit 1 >/dev/null 2>&1; then
			echo "release gate FAILED: WPCS errors — run: $PHPCS --standard=.phpcs.xml.dist" >&2
			exit 1
		fi
	else
		echo "release gate: WPCS SKIPPED — no phpcs at $PHPCS (set PHPCS to override)" >&2
	fi
fi

TMP="$(mktemp -d)"
SRC="$TMP/src"
STAGE="$TMP/$SLUG"
mkdir -p "$SRC" "$STAGE"

# 1. Clean committed state only. Anything untracked or gitignored — docker/.dist,
#    docker/.playground, editor cruft — cannot reach the zip.
git archive HEAD | tar -x -C "$SRC"

# 2. Copy ONLY the allowlist into the staged plugin dir.
for item in "${RUNTIME[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done
for item in "${OPTIONAL[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done

# 3. Strip dev cruft that could ride along inside an allowlisted directory.
find "$STAGE" -type f -name '*.md' -delete
find "$STAGE" -type f \( -name '.gitignore' -o -name '.gitattributes' -o -name '.editorconfig' \) -delete
find "$STAGE" -depth -type d \( -name '.github' -o -name '.git' \) -exec rm -rf {} +

# 4. Assert the package is actually runnable. The allowlist and the strip above are
#    two independent ways to lose something, and losing one is silent — the zip
#    builds, installs, and fails on a customer's site. Check the entry file, the
#    boot class, the step registry every run surface reads, and the admin template.
REQUIRED_FILES=(
	"buddynext-importer.php"
	"includes/Plugin.php"
	"includes/Pipeline/StepRegistry.php"
	"includes/CLI/MigrateCommand.php"
	"includes/Rest/ProgressController.php"
	"includes/Admin/ImporterPage.php"
	"templates/admin/importer-page.php"
	"assets/js/admin-importer.js"
)
MISSING=0
for f in "${REQUIRED_FILES[@]}"; do
	if [ ! -f "$STAGE/$f" ]; then
		echo "build FAILED: required file missing from the staged package: $f" >&2
		MISSING=1
	fi
done
if [ "$MISSING" -ne 0 ]; then
	echo "" >&2
	echo "Check the RUNTIME allowlist and the strip in step 3." >&2
	rm -rf "$TMP"
	exit 1
fi

# 4b. Refuse to ship fixture material. docker/ holds two BuddyBoss Platform zips
#     (~46MB). They are gitignored and not allowlisted, so this can only fire if
#     someone changes the selection logic — which is exactly when it should.
if [ -d "$STAGE/docker" ] || find "$STAGE" -name '*.zip' -type f | grep -q .; then
	echo "build FAILED: fixture material or a nested zip reached the package." >&2
	echo "docker/ must never ship — it contains third-party plugin zips." >&2
	rm -rf "$TMP"
	exit 1
fi
echo "package: $(find "$STAGE" -type f | wc -l | tr -d ' ') file(s), no fixture material"

# 5. Zip.
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$TMP"

echo "built: $ZIP ($(du -h "$ZIP" | cut -f1))"
