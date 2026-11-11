#!/usr/bin/env python3
"""Build an installable plugin zip: dist/peak-seo-<version>.zip

Works anywhere Python 3 runs (including Windows without the `zip` command).
tools/build-zip.sh uses this automatically when `zip` is not installed.

  python tools/build_zip.py
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = "peak-seo"
SKIP_FILES = {".DS_Store", "Thumbs.db", "desktop.ini"}
SKIP_DIRS = {"__pycache__", ".git"}


def version():
    with open(os.path.join(ROOT, PLUGIN, "peak-seo.php"), encoding="utf-8") as f:
        m = re.search(r"^\s*\*\s*Version:\s*(\S+)", f.read(), re.M)
    if not m:
        sys.exit("Could not find 'Version:' in peak-seo/peak-seo.php")
    return m.group(1)


def main():
    ver = version()
    dist = os.path.join(ROOT, "dist")
    os.makedirs(dist, exist_ok=True)
    out = os.path.join(dist, f"peak-seo-{ver}.zip")
    if os.path.exists(out):
        os.remove(out)
    src = os.path.join(ROOT, PLUGIN)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for folder, dirs, files in os.walk(src):
            dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
            for name in sorted(files):
                if name in SKIP_FILES:
                    continue
                path = os.path.join(folder, name)
                # Forward slashes inside the zip, so WordPress unpacks it correctly on any OS.
                arc = os.path.relpath(path, ROOT).replace(os.sep, "/")
                z.write(path, arc)
    print(os.path.relpath(out, ROOT).replace(os.sep, "/"))


if __name__ == "__main__":
    main()
