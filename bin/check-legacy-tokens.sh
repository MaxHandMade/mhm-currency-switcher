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
#
# Match by basename, not by the whole "$1" field: in --source mode grep
# prints "readme.txt:N:...", but in --zip mode it walks a directory and
# prints "<zip_root>/readme.txt:N:...", so an exact-field match never fires
# there and every changelog line gets re-flagged (found by fix-round review).
#
# The heading position is read from the SAME file the hit came from (not a
# hardcoded repo-root readme.txt), cached per path, so a staged ZIP's copy of
# readme.txt is judged against its own heading, never the repo's.
filter_readme_changelog() {
	local line path base lineno
	local -A changelog_cache
	while IFS= read -r line; do
		path="${line%%:*}"
		base="${path##*/}"
		if [ "$base" = "readme.txt" ]; then
			if [ -z "${changelog_cache[$path]+set}" ]; then
				changelog_cache[$path]=$(grep -n '^== Changelog ==' -- "$path" 2>/dev/null | head -1 | cut -d: -f1)
			fi
			if [ -n "${changelog_cache[$path]}" ]; then
				lineno="${line#*:}"; lineno="${lineno%%:*}"
				if [ "$lineno" -ge "${changelog_cache[$path]}" ] 2>/dev/null; then
					continue
				fi
			fi
		fi
		printf '%s\n' "$line"
	done
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
