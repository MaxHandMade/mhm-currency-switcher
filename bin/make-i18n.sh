#!/usr/bin/env bash
#
# Regenerate the full i18n chain (.pot -> .po -> .mo/.l10n.php -> React
# .json) for every locale under languages/.
#
# WHY THIS SCRIPT EXISTS (do not run `wp i18n make-pot` by hand):
#   `wp i18n make-pot` only auto-excludes node_modules, .git, .svn, .CVS,
#   .hg, vendor and *.min.js. It does NOT auto-exclude `build/` — the
#   staging directory `bin/build-release.py` writes release copies of the
#   whole plugin into (`build/zip-staging/`, gitignored, can be 2+ MB).
#   Running make-pot without --exclude=build after a release build has
#   been made scans that stale copy and pollutes the catalogue with
#   hundreds of duplicate/dead `#:` references pointing at
#   build/zip-staging/... , including strings for controls that were
#   removed from the real source months ago. Happened once
#   (2026-07-26); this script is the fix so it can't happen again.
#
# Usage:
#   bin/make-i18n.sh
#
# Requires: WP-CLI (`wp`) with the `i18n` command available. Does not
# require a live WordPress install — `wp i18n *` subcommands run on
# `before_wp_load` and are pure PHP.
#
# After running, review `git diff languages/` — any brand-new source
# string needs a real Turkish translation before commit (this script
# does not translate, only extracts/merges/compiles).

set -euo pipefail

# 🔴 Regenerating on Windows silently produces a DEGRADED catalogue.
# WP-CLI's plugin detection does not fire under Git Bash: it never prints
# "Plugin file detected.", and the four plugin-header strings (Plugin URI,
# Description, Author, Author URI) plus a real `Report-Msgid-Bugs-To` are
# dropped without a word or a non-zero exit. Measured side by side on the
# same WP-CLI 2.12.0 and the same plugin file — Linux emits them, Windows
# does not. That is how the committed .pot came to hold entries this script
# could not reproduce, and why regenerating it here quietly deleted four
# translatable strings. Re-exec in a container so the catalogue does not
# depend on which machine happened to run the release.
if [ -z "${MHMCS_I18N_CONTAINER:-}" ]; then
	case "$(uname -s)" in
		MINGW* | MSYS* | CYGWIN*)
			REPO_WIN="$(cd "$(dirname "$0")/.." && pwd -W)"
			echo "[i18n] Windows host -> re-running inside wordpress:cli-php8.2"
			exec env MSYS_NO_PATHCONV=1 docker run --rm \
				-v "${REPO_WIN}:/app" -w /app \
				-e MHMCS_I18N_CONTAINER=1 \
				wordpress:cli-php8.2 bash bin/make-i18n.sh "$@"
			;;
	esac
fi

cd "$(dirname "$0")/.."

SLUG="mhm-currency-switcher"
DOMAIN="mhm-currency-switcher"
POT="languages/${SLUG}.pot"

# Everything that must never be scanned for strings: build output/staging
# dirs, dependency dirs, tests, tooling, docs. Keep in sync with
# .distignore's spirit (this list is about *scanning*, not shipping).
EXCLUDE="admin-app/build,build,node_modules,vendor,tests,bin,docs,.superpowers"

# Read the shipped version from the plugin header, so the catalogue always
# says what the plugin says. Left to itself `make-pot` writes an EMPTY
# `Project-Id-Version`, and `update-po` never touches the .po's copy of it —
# which is how the .po sat at 0.2.0 while the .pot had moved to 1.1.1, and
# how the .pot went blank again the next time this script ran. Deriving it
# here is the only place that cannot drift.
VERSION="$(sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "${SLUG}.php" | head -1)"

if [ -z "${VERSION}" ]; then
	echo "[i18n] ERROR: could not read Version from ${SLUG}.php" >&2
	exit 1
fi

PACKAGE_NAME="MHM Currency Switcher ${VERSION}"
echo "[i18n] version from plugin header: ${VERSION}"

echo "[i18n] 1/5 make-pot (--exclude=${EXCLUDE})"
wp i18n make-pot . "${POT}" \
	--slug="${SLUG}" \
	--domain="${DOMAIN}" \
	--exclude="${EXCLUDE}" \
	--package-name="${PACKAGE_NAME}"

# Prove the extraction actually saw a plugin, instead of trusting that it
# did. Both markers come from the same detection step that fails silently
# above, so their absence means the .pot is missing the header strings —
# and a .pot that is merely *smaller* is exactly the kind of regression a
# green run hides. Checked here rather than left to review, because the
# last two releases both shipped a catalogue nobody had compared.
if ! grep -q '^# Copyright (C)' "${POT}"; then
	echo "[i18n] ERROR: no copyright preamble in ${POT} — WP-CLI did not detect a plugin." >&2
	echo "[i18n]        The plugin-header strings would be missing. Refusing to continue." >&2
	exit 1
fi

if ! grep -q '^"Report-Msgid-Bugs-To: https' "${POT}"; then
	echo "[i18n] ERROR: empty Report-Msgid-Bugs-To in ${POT} — plugin detection failed." >&2
	exit 1
fi

echo "[i18n] plugin detection confirmed (copyright preamble + bugs-to header)"

echo "[i18n] 2/5 update-po (all locales under languages/)"
wp i18n update-po "${POT}" languages/

# update-po carries msgids across but leaves the .po header alone, and the
# .mo/.l10n.php compiled below read their version from the .po — so without
# this the derived catalogues keep announcing the previous release.
for po in languages/*.po; do
	[ -e "${po}" ] || continue
	sed -i "s|^\"Project-Id-Version: .*\\\\n\"$|\"Project-Id-Version: ${PACKAGE_NAME}\\\\n\"|" "${po}"
done

echo "[i18n] 3/5 make-mo"
wp i18n make-mo languages/ languages/

echo "[i18n] 4/5 make-php"
wp i18n make-php languages/ languages/

# On Windows `make-php` writes a CRLF after the opening `<?php`. The ZIP is
# built from the working tree, not from what git normalised on commit, so
# that CRLF ships. phpcs cannot catch it either — `languages/` is not
# scanned. Strip it here rather than remembering to, once per release.
for l10n in languages/*.l10n.php; do
	[ -e "${l10n}" ] || continue
	sed -i 's/\r$//' "${l10n}"
done

echo "[i18n] 5/5 make-json (React JS strings, named by enqueued bundle path)"
# WordPress looks up the JS translation JSON by
# md5(<script's enqueued src path relative to the plugin root>), i.e.
# md5('admin-app/build/index.js') here — NOT by the source .jsx
# filename. `wp i18n make-json --extensions=jsx` on its own produces
# one JSON per source file, which WordPress's runtime never finds.
# Map every JSX source at admin-app/src/ to the single build entry
# point so make-json emits one correctly-named JSON per locale.
PYTHON_BIN="python3"
command -v "${PYTHON_BIN}" >/dev/null 2>&1 || PYTHON_BIN="python"

JSMAP="$(mktemp)"
"${PYTHON_BIN}" - "$JSMAP" <<'PY'
import json
import sys

sources = []
for root, _dirs, files in __import__("os").walk("admin-app/src"):
    for f in files:
        if f.endswith(".jsx") or f.endswith(".js"):
            sources.append(__import__("os").path.join(root, f).replace("\\", "/"))

mapping = {src: "admin-app/build/index.js" for src in sources}

with open(sys.argv[1], "w", encoding="utf-8") as fh:
    json.dump(mapping, fh, indent=4)
PY

for po in languages/*.po; do
	wp i18n make-json "${po}" languages/ --no-purge --use-map="${JSMAP}"
done

rm -f "${JSMAP}"

echo "[i18n] Done. Verify before committing:"
echo "  grep -c '^msgstr \"\"\$' languages/${SLUG}-*.po      # expect 1 (header only) per locale"
echo "  grep -c '^#, fuzzy' languages/${SLUG}-*.po           # expect 0 per locale"
echo "  grep -oE '^#: [^ :]+' ${POT} | sort -u                # every path must be under src/, admin-app/src/, templates/, or a root plugin file"
