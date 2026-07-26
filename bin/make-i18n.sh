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

cd "$(dirname "$0")/.."

SLUG="mhm-currency-switcher"
DOMAIN="mhm-currency-switcher"
POT="languages/${SLUG}.pot"

# Everything that must never be scanned for strings: build output/staging
# dirs, dependency dirs, tests, tooling, docs. Keep in sync with
# .distignore's spirit (this list is about *scanning*, not shipping).
EXCLUDE="admin-app/build,build,node_modules,vendor,tests,bin,docs,.superpowers"

echo "[i18n] 1/5 make-pot (--exclude=${EXCLUDE})"
wp i18n make-pot . "${POT}" \
	--slug="${SLUG}" \
	--domain="${DOMAIN}" \
	--exclude="${EXCLUDE}"

echo "[i18n] 2/5 update-po (all locales under languages/)"
wp i18n update-po "${POT}" languages/

echo "[i18n] 3/5 make-mo"
wp i18n make-mo languages/ languages/

echo "[i18n] 4/5 make-php"
wp i18n make-php languages/ languages/

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
