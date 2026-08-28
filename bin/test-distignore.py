#!/usr/bin/env python3
"""
.distignore pattern gate (WP.org T1 remediation, Task 14).

Negative test for the languages/ exclusion patterns added to .distignore:
languages/*.po, languages/*.mo, languages/*.l10n.php and languages/*.json
must exclude only compiled translation catalogues inside languages/, never
anything that merely happens to share an extension elsewhere in the tree
(e.g. the compiled admin bundle under admin-app/build/), and must never
exclude the .pot template, which ships.

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

    # Compiled language files inside languages/ ARE excluded (do not ship).
    check(
        "languages/*.json is excluded",
        build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR-abc.json", patterns
        ),
    )
    check(
        "languages/*.po is excluded",
        build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.po", patterns
        ),
    )
    check(
        "languages/*.mo is excluded",
        build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.mo", patterns
        ),
    )
    check(
        "languages/*.l10n.php is excluded",
        build_release.is_excluded(
            "languages/mhm-currency-switcher-tr_TR.l10n.php", patterns
        ),
    )

    # A JSON file outside languages/ is NOT excluded by the languages/*.json
    # pattern. This is the regression this file exists to catch: fnmatch's
    # '*' crosses '/', so a bare '*.json' (instead of the directory-bound
    # 'languages/*.json') would also strip the compiled admin panel.
    check(
        "admin-app/build/whatever.json is NOT excluded",
        not build_release.is_excluded("admin-app/build/whatever.json", patterns),
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
