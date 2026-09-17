#!/usr/bin/env python3
"""
.distignore pattern gate (WP.org T1 remediation, Task 14).

Gate for the languages/ rules in .distignore. The RUNTIME translation
catalogues ship (languages/*.mo, *.l10n.php, *.json) and so does the .pot
template; only the editable source languages/*.po is excluded, and that
pattern must never reach anything outside languages/ (e.g. the compiled admin
bundle under admin-app/build/).

2026-09-17: the runtime catalogues used to be excluded on the premise that
WordPress.org language packs would provide them. None existed (stable/tr 0%,
tr_TR pack 404), so the plugin shipped English to Turkish sites. The
assertions below lock the reversed decision; see the comment in .distignore
for how WordPress loads each file type.

This loads the REAL is_excluded() from bin/build-release.py via
importlib (build-release.py has a hyphen in its filename, so it cannot be
imported with a plain `import` statement) — a reimplementation here would
drift from the shipped function and could pass while the real one fails.

Usage:
    python bin/test-distignore.py
    python3 bin/test-distignore.py

Exit code 0 = all assertions passed. Exit code 1 = a regression was caught.
"""
from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BUILD_RELEASE_PATH = ROOT / "bin" / "build-release.py"
DISTIGNORE_PATH = ROOT / ".distignore"


def load_build_release():
    """Import bin/build-release.py as a module despite its hyphenated name."""
    spec = importlib.util.spec_from_file_location("build_release", BUILD_RELEASE_PATH)
    if spec is None or spec.loader is None:
        sys.exit(f"ERROR: could not load module spec from {BUILD_RELEASE_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main() -> int:
    build_release = load_build_release()
    patterns = build_release.load_distignore()

    failures: list[str] = []

    def check(label: str, condition: bool) -> None:
        status = "ok" if condition else "FAIL"
        print(f"[{status}] {label}")
        if not condition:
            failures.append(label)

    # Runtime catalogues inside languages/ SHIP. These use the real file
    # names so a pattern that happens to match only a made-up name cannot
    # pass while the actual catalogue is dropped.
    check(
        "languages/*.mo ships",
        not build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.mo", patterns
        ),
    )
    check(
        "languages/*.l10n.php ships",
        not build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.l10n.php", patterns
        ),
    )
    check(
        "languages/*.json (React admin catalogue) ships",
        not build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR-8cd971876f635cc43f0d9e68f8f44c64.json",
            patterns,
        ),
    )

    # The editable source is not read at runtime and stays out.
    check(
        "languages/*.po is excluded",
        build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.po", patterns
        ),
    )

    # The compiled admin panel is never excluded. fnmatch's '*' crosses '/',
    # so any language rule written without its directory prefix could reach
    # admin-app/build/ and strip the panel out of the ZIP.
    check(
        "admin-app/build/whatever.json is NOT excluded",
        not build_release.is_excluded("admin-app/build/whatever.json", patterns),
    )
    check(
        "admin-app/build/index.js is NOT excluded",
        not build_release.is_excluded("admin-app/build/index.js", patterns),
    )

    # The .pot template ships — it must never be excluded.
    check(
        "languages/mhm-currency-switcher.pot is NOT excluded",
        not build_release.is_excluded(
            "languages/mhm-currency-switcher.pot", patterns
        ),
    )

    # .claude/ is Claude Code's own project config (editor permissions,
    # settings) -- untracked, not plugin code, and must never reach a
    # release ZIP. Confirm the new plain-name pattern added to .distignore
    # actually excludes it.
    check(
        ".claude/settings.json is excluded",
        build_release.is_excluded(".claude/settings.json", patterns),
    )

    # Negative control for the same pattern: it must match only the exact
    # path component ".claude", not merely overlap with real shipped files.
    # No file in this plugin's ZIP is literally dot-prefixed (leading-dot;
    # verified: the only leading-dot entries anywhere in the repo tree are
    # .git, .github, .gitattributes, .gitignore, .distignore, .superpowers,
    # .wordpress-org and .phpunit.result.cache, and every one of those is
    # independently excluded already) -- so this checks a real file that
    # DOES ship: mhm-currency-switcher.php, the plugin's main bootstrap
    # file, which sits at repo root the same way .claude/ does.
    check(
        "mhm-currency-switcher.php is NOT excluded",
        not build_release.is_excluded("mhm-currency-switcher.php", patterns),
    )

    print()
    if failures:
        print(f"FAIL: {len(failures)} assertion(s) failed:")
        for label in failures:
            print(f"  - {label}")
        return 1

    print(f"PASS: all assertions passed ({DISTIGNORE_PATH.name} patterns: {len(patterns)})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
