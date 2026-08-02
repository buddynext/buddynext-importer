#!/usr/bin/env bash
# Print, NUL-separated, every tracked PHP file that ships in the release zip.
#
#   bin/shipped-php-files.sh            # NUL-separated (for xargs -0 / read -d '')
#   bin/shipped-php-files.sh --lines    # newline-separated (for humans and CI logs)
#
# WHY THIS FILE EXISTS
#
# The release gate used to build its own list with a hand-written pathspec:
#
#     git ls-files 'includes/**/*.php' 'templates/**/*.php' 'buddynext-importer.php'
#
# `includes/**/*.php` requires at least one directory level, so a file sitting
# DIRECTLY in includes/ never matched. Measured: the gate linted 44 of the 46 PHP
# files that ship. The two it skipped were includes/Autoloader.php and
# includes/Plugin.php — the autoloader and the boot class, the two files where a
# parse error is a white screen rather than a degraded feature.
#
# The gate could not fail for those files, and nobody noticed, because the card's
# own verify step ("introduce a parse error and confirm the build aborts") DID
# abort — from WPCS, which names no file, and which is documented as skipped
# rather than failed when the sibling phpcs binary is absent. On a release runner
# or a fresh clone, a committed parse error in Plugin.php shipped with both gates
# printing green.
#
# The fix is not a better glob. A second hand-written list of "what ships" will
# drift from the allowlist again — that is the same failure this plugin's own
# StepRegistry exists to prevent ("if you find yourself writing a phase name in a
# second place, stop"). So the list is derived from ONE allowlist, here, and both
# the release gate and CI read it. `git ls-files -- includes` enumerates every
# tracked file underneath at any depth, so there is no level to miss.
set -euo pipefail

cd "$(dirname "$0")/.."

# The allowlist. Must stay in step with RUNTIME + OPTIONAL in bin/build-release.sh;
# build-release.sh asserts that it does, and fails the build if it does not.
SHIPPED_PATHS=( buddynext-importer.php includes templates assets languages uninstall.php )

EXISTING=()
for p in "${SHIPPED_PATHS[@]}"; do
	[ -e "$p" ] && EXISTING+=( "$p" )
done

if [ ${#EXISTING[@]} -eq 0 ]; then
	exit 0
fi

if [ "${1:-}" = "--lines" ]; then
	git ls-files -- "${EXISTING[@]}" | grep -E '\.php$' || true
else
	git ls-files -z -- "${EXISTING[@]}" |
		while IFS= read -r -d '' f; do
			case "$f" in
				*.php) printf '%s\0' "$f" ;;
			esac
		done
fi
