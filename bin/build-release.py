#!/usr/bin/env python3
"""
Build a WordPress-installable release ZIP for MHM Currency Switcher.

Produces: build/mhm-currency-switcher.<version>.zip

Usage:
    python bin/build-release.py

What it does:
    1. Reads the version from mhm-currency-switcher.php
    2. Reads .distignore for the exclusion list
    3. Stages a clean copy of the plugin under build/zip-staging/mhm-currency-switcher/
    4. Creates build/mhm-currency-switcher.<version>.zip with POSIX (forward-slash)
       paths so WordPress's built-in unzip_file() and every other unzip tool accepts it

Why a separate script (and not Compress-Archive):
    PowerShell's Compress-Archive writes ZIPs with backslash path separators on
    Windows. WordPress core's unzip_file() tolerates them but prints a warning,
    and some hosting panels/plugins reject them outright. Python's zipfile
    always writes POSIX paths, which is the WordPress.org-recommended format.

No external dependencies — stdlib only (Python 3.8+).
"""
from __future__ import annotations

import fnmatch
import os
import re
import shutil
import sys
import zipfile
from pathlib import Path

PLUGIN_SLUG = "mhm-currency-switcher"
ROOT = Path(__file__).resolve().parent.parent
BUILD_DIR = ROOT / "build"
STAGING_DIR = BUILD_DIR / "zip-staging" / PLUGIN_SLUG
DISTIGNORE = ROOT / ".distignore"
MAIN_PLUGIN_FILE = ROOT / f"{PLUGIN_SLUG}.php"

VERSION_RE = re.compile(r"define\(\s*'MHM_CS_VERSION'\s*,\s*'([^']+)'\s*\)\s*;")


def read_version() -> str:
    text = MAIN_PLUGIN_FILE.read_text(encoding="utf-8")
    match = VERSION_RE.search(text)
    if not match:
        sys.exit(f"ERROR: could not find MHM_CS_VERSION in {MAIN_PLUGIN_FILE}")
    return match.group(1)


def load_distignore() -> list[str]:
    """Return non-comment, non-empty patterns from .distignore (stripped)."""
    if not DISTIGNORE.exists():
        return []
    patterns: list[str] = []
    for raw in DISTIGNORE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        patterns.append(line.rstrip("/"))
    return patterns


def is_excluded(rel_path: str, patterns: list[str]) -> bool:
    """Match a POSIX-style relative path against .distignore patterns.

    A pattern matches if it equals the path, is a prefix directory of the path,
    or matches any path component via glob (e.g. '*.zip', 'languages/*~').
    """
    parts = rel_path.split("/")
    for pat in patterns:
        if "/" in pat or "*" in pat or "?" in pat:
            # Glob / path pattern: test against full path and each suffix
            if fnmatch.fnmatch(rel_path, pat):
                return True
            # Directory-prefix match: "docs/" should exclude "docs/foo"
            if rel_path == pat or rel_path.startswith(pat + "/"):
                return True
        else:
            # Plain name: match on any path component (e.g. 'vendor', '.git')
            if pat in parts:
                return True
    return False


def stage_files(patterns: list[str]) -> int:
    if BUILD_DIR.exists():
        # Only clear the staging subdir, keep older ZIPs in build/
        if STAGING_DIR.parent.exists():
            shutil.rmtree(STAGING_DIR.parent, ignore_errors=True)
    STAGING_DIR.mkdir(parents=True, exist_ok=True)

    copied = 0
    for root, dirs, files in os.walk(ROOT):
        root_path = Path(root)
        # Compute path relative to plugin root with POSIX separators
        try:
            rel_root = root_path.relative_to(ROOT).as_posix()
        except ValueError:
            continue
        if rel_root == ".":
            rel_root = ""

        # Prune excluded directories in-place so os.walk does not descend into them
        pruned: list[str] = []
        for d in dirs:
            rel_d = f"{rel_root}/{d}" if rel_root else d
            if is_excluded(rel_d, patterns):
                continue
            pruned.append(d)
        dirs[:] = pruned

        # Never walk into our own staging output — but ONLY at the plugin root.
        # Do not prune nested "build" dirs like admin-app/build/, which contain
        # production React bundles that must ship with the plugin.
        if rel_root == "" and "build" in dirs:
            dirs.remove("build")

        for f in files:
            rel_f = f"{rel_root}/{f}" if rel_root else f
            if is_excluded(rel_f, patterns):
                continue
            src = root_path / f
            dest = STAGING_DIR / rel_f
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, dest)
            copied += 1
    return copied


def create_zip(version: str) -> Path:
    zip_path = BUILD_DIR / f"{PLUGIN_SLUG}.{version}.zip"
    if zip_path.exists():
        zip_path.unlink()

    count = 0
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for root, _dirs, files in os.walk(STAGING_DIR.parent):
            for f in files:
                full = Path(root) / f
                arcname = full.relative_to(STAGING_DIR.parent).as_posix()
                zf.write(full, arcname)
                count += 1
    return zip_path


def main() -> int:
    if not MAIN_PLUGIN_FILE.exists():
        sys.exit(f"ERROR: {MAIN_PLUGIN_FILE} not found")

    version = read_version()
    patterns = load_distignore()

    print(f"[build] Plugin   : {PLUGIN_SLUG}")
    print(f"[build] Version  : {version}")
    print(f"[build] Source   : {ROOT}")
    print(f"[build] Patterns : {len(patterns)} from .distignore")

    copied = stage_files(patterns)
    print(f"[build] Staged   : {copied} files -> {STAGING_DIR}")

    zip_path = create_zip(version)
    size_mb = zip_path.stat().st_size / (1024 * 1024)
    print(f"[build] SUCCESS  : {zip_path}")
    print(f"[build] Size     : {size_mb:.2f} MB")

    # Sanity-check: the ZIP must have exactly one top-level directory named
    # mhm-currency-switcher/ so WordPress installs it correctly.
    with zipfile.ZipFile(zip_path) as zf:
        roots = {name.split("/")[0] for name in zf.namelist()}
    if roots != {PLUGIN_SLUG}:
        sys.exit(f"ERROR: ZIP has unexpected top-level dirs: {sorted(roots)}")
    print(f"[build] Verified : single root '{PLUGIN_SLUG}/'")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
