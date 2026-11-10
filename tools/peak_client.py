#!/usr/bin/env python3
"""Command-line test client for the Peak SEO WordPress plugin.

It signs each request with the site key (the same way the Peak SEO service will),
so you can try the plugin end to end before the service exists.

Examples:
  export PEAK_SITE=http://peak-test.local
  export PEAK_KEY=pk_...        # from WordPress > Peak SEO > Settings

  python3 tools/peak_client.py site
  python3 tools/peak_client.py pages
  python3 tools/peak_client.py page 12
  python3 tools/peak_client.py propose-seo 12 --title "Water Heater Repair in Huntsville, AL | Test Plumbing" \
      --description "Same-day water heater repair..." --summary "Better search title" --reason "Adds the city people search for"
  python3 tools/peak_client.py propose-content 12 --content-file new.html --summary "Expand the page"
  python3 tools/peak_client.py propose-page --title "Tankless Water Heaters" --content-file page.html --summary "New service page"
  python3 tools/peak_client.py proposals --status pending
"""
import argparse
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

NS = "/peak-seo/v1"


def call(site, key, method, route, body=None, params=None):
    route = NS + route
    data = json.dumps(body) if body is not None else ""
    ts = str(int(time.time()))
    payload = f"{ts}\n{method}\n{route}\n{data}"
    sig = hmac.new(key.encode(), payload.encode(), hashlib.sha256).hexdigest()
    # ?rest_route= works on every WordPress site, with or without pretty permalinks.
    query = {"rest_route": route, **(params or {})}
    url = site.rstrip("/") + "/index.php?" + urllib.parse.urlencode(query)
    req = urllib.request.Request(url, data=data.encode() if body is not None else None, method=method)
    req.add_header("X-Peak-Timestamp", ts)
    req.add_header("X-Peak-Signature", sig)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b"null")
        except ValueError:
            return e.code, None


def read_file(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--site", default=os.environ.get("PEAK_SITE"))
    p.add_argument("--key", default=os.environ.get("PEAK_KEY"))
    sub = p.add_subparsers(dest="cmd", required=True)
    sub.add_parser("site")
    sub.add_parser("pages")
    s = sub.add_parser("page"); s.add_argument("id", type=int)
    s = sub.add_parser("proposals"); s.add_argument("--status", default="")
    s = sub.add_parser("proposal"); s.add_argument("id", type=int)
    s = sub.add_parser("propose-seo"); s.add_argument("id", type=int)
    s.add_argument("--title"); s.add_argument("--description")
    s.add_argument("--summary", required=True); s.add_argument("--reason", default="")
    s = sub.add_parser("propose-content"); s.add_argument("id", type=int)
    s.add_argument("--title"); s.add_argument("--content-file"); s.add_argument("--excerpt")
    s.add_argument("--summary", required=True); s.add_argument("--reason", default="")
    s = sub.add_parser("propose-page")
    s.add_argument("--title", required=True); s.add_argument("--content-file", required=True); s.add_argument("--slug")
    s.add_argument("--summary", required=True); s.add_argument("--reason", default="")
    a = p.parse_args()

    if not a.site or not a.key:
        sys.exit("Set --site and --key (or PEAK_SITE / PEAK_KEY).")

    if a.cmd == "site":
        r = call(a.site, a.key, "GET", "/site")
    elif a.cmd == "pages":
        r = call(a.site, a.key, "GET", "/pages")
    elif a.cmd == "page":
        r = call(a.site, a.key, "GET", f"/pages/{a.id}")
    elif a.cmd == "proposals":
        r = call(a.site, a.key, "GET", "/proposals", params={"status": a.status} if a.status else None)
    elif a.cmd == "proposal":
        r = call(a.site, a.key, "GET", f"/proposals/{a.id}")
    elif a.cmd == "propose-seo":
        changes = {k: v for k, v in {"seo_title": a.title, "seo_description": a.description}.items() if v is not None}
        r = call(a.site, a.key, "POST", "/proposals", {"kind": "update_seo", "target_id": a.id, "changes": changes, "summary": a.summary, "reason": a.reason})
    elif a.cmd == "propose-content":
        changes = {}
        if a.title: changes["title"] = a.title
        if a.content_file: changes["content"] = read_file(a.content_file)
        if a.excerpt: changes["excerpt"] = a.excerpt
        r = call(a.site, a.key, "POST", "/proposals", {"kind": "update_content", "target_id": a.id, "changes": changes, "summary": a.summary, "reason": a.reason})
    elif a.cmd == "propose-page":
        changes = {"title": a.title, "content": read_file(a.content_file)}
        if a.slug: changes["slug"] = a.slug
        r = call(a.site, a.key, "POST", "/proposals", {"kind": "create_page", "changes": changes, "summary": a.summary, "reason": a.reason})

    status, body = r
    print(f"HTTP {status}")
    print(json.dumps(body, indent=2))
    sys.exit(0 if status < 400 else 1)


if __name__ == "__main__":
    main()
