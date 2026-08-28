#!/usr/bin/env bash
#
# WP.org prefix compliance gate (T1 finding 2).
#
# The tokenizer splits a prefix at its first underscore OR dash, so
# `mhm-cs-admin` and `mhm_cs_foo` both reduce to the 3-character token `mhm`.
# phpcs cannot see this class: WordPress.NamingConventions.PrefixAllGlobals
# targets declarations, not enqueue handles, DOM ids or CSS classes.
#
set -uo pipefail
cd "$(dirname "$0")/.." || exit 2

MODE="${1:---source}"
STATUS=0

# Every shipped surface. Mirrors check-no-license-refs.sh's SCAN_ALL, which
# already learned this lesson: a violation in the root bootstrap or in
# uninstall.php ships just as surely as one in src/.
SOURCE_ROOTS="src/ assets/ admin-app/src/ mhm-currency-switcher.php uninstall.php readme.txt README.md README-tr.md docs/kullanim-kilavuzu.md"

# tests/ is deliberately out of scope: tests carry forbidden tokens ON PURPOSE
# (asserting that an old name is no longer recognised). Their closure is
# measured by `rg` plus a green suite, not by this gate.

FORBIDDEN='mhm-cs-|mhm_cs_|_mhm_cs_|mhm_currency_switcher_|mhm_currency_prices_panel'
# Bare forms need their own pattern: `mhm-cs` with no trailing dash is the
# WP-CLI command name, and `mhm-cs-` would never match it. The brief's
# quoted-literal-only form ('mhm-cs'|"mhm-cs") misses the unquoted form that
# ships in README.md/README-tr.md ("wp mhm-cs rates-sync"), so match `mhm-cs`
# wherever it is NOT immediately followed by a hyphen (that continuation is
# already FORBIDDEN's job) instead of only inside quotes.
BARE='mhm-cs([^a-zA-Z0-9_-]|$)|\[mhm_currency_switcher\]|\[mhm_currency_prices\]'

scan() {
	local label="$1"; shift
	local hits
	hits=$(grep -rnE "$FORBIDDEN|$BARE" "$@" 2>/dev/null \
		| grep -v 'CHANGELOG.md' \
		| filter_readme_changelog || true)
	if [ -n "$hits" ]; then
		echo "FAIL [$label]"
		echo "$hits"
		STATUS=1
	else
		echo "PASS [$label]"
	fi
}

# readme.txt's `== Changelog ==` section legitimately names old shortcodes.
# The exemption is SECTION-bound, not file-bound: a hit above that heading
# is still a failure.
filter_readme_changelog() {
	local changelog_line
	changelog_line=$(grep -n '^== Changelog ==' readme.txt 2>/dev/null | cut -d: -f1)
	if [ -z "$changelog_line" ]; then cat; return; fi
	awk -v cl="$changelog_line" -F: '
		$1 == "readme.txt" && $2 >= cl { next }
		{ print }
	'
}

case "$MODE" in
	--source)
		scan "source" $SOURCE_ROOTS
		;;
	--zip)
		ZIP_ROOT="${2:-}"
		if [ -z "$ZIP_ROOT" ] || [ ! -d "$ZIP_ROOT" ]; then
			echo "FAIL [zip] staged ZIP root missing or not a directory: '${ZIP_ROOT}'"
			echo "  A fail-closed gate must not go green on an empty root."
			exit 1
		fi
		scan "zip" "$ZIP_ROOT"
		;;
	*)
		echo "usage: $0 [--source | --zip <staged-dir>]"; exit 2
		;;
esac

exit $STATUS
