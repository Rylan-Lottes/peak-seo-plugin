#!/usr/bin/env python3
"""Peak SEO brain: one careful improvement per run.

Each run reads a WordPress site through the Peak SEO plugin's signed API, picks
the single most valuable improvement, asks Claude to write it using only the
facts in the site's notes file, sends it as a proposal, logs it, and approves
it only when the owner turned on central approval and the plugin did not mark
it as needing the owner.

Priority: search titles/descriptions first, then thin pages, then new pages.

  python service/peak_brain.py init service/sites/my-site --site-url https://example.com
  python service/peak_brain.py plan service/sites/my-site        # free: shows what it would pick
  python service/peak_brain.py run  service/sites/my-site --dry-run
  python service/peak_brain.py run  service/sites/my-site

Needs: ANTHROPIC_API_KEY (or an `ant auth login` profile) and the site key in
the environment variable named by the site's config.json ("key_env", default PEAK_KEY).
"""
import argparse
import datetime as dt
import json
import os
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "tools"))
from peak_client import call  # noqa: E402  (signed requests to the plugin)

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

# $ per million tokens: (input, output, cache write 5m, cache read). Estimates;
# check https://www.anthropic.com/pricing for current rates.
PRICES = {
    "claude-opus-5-5": (4.00, 20.00, 5.00, 0.20),
    "claude-opus-5": (5.00, 25.00, 6.25, 0.50),
    "claude-opus-4-8": (5.00, 25.00, 6.25, 0.50),
    "claude-sonnet-5-5": (2.00, 10.00, 2.50, 0.10),
    "claude-sonnet-5": (2.00, 10.00, 2.50, 0.20),
    "claude-haiku-5-5": (0.10, 0.50, 0.125, 0.01),
    "claude-fable-5-2": (8.00, 40.00, 10.00, 0.20),
}

SEO_TITLE_MAX = 60
SEO_DESC_MAX = 160


# --------------------------------------------------------------------------
# Site folder: config.json + notes file
# --------------------------------------------------------------------------

def load_config(site_dir):
    path = site_dir / "config.json"
    if not path.exists():
        sys.exit(f"No config.json in {site_dir}. Create one with: python service/peak_brain.py init {site_dir} --site-url URL")
    cfg = json.loads(path.read_text(encoding="utf-8"))
    cfg.setdefault("key_env", "PEAK_KEY")
    cfg.setdefault("model", "claude-opus-5-5")
    cfg.setdefault("effort", "medium")
    cfg.setdefault("thin_word_count", 300)
    cfg.setdefault("cooldown_days", 30)
    cfg.setdefault("notes_file", "SITE.md")
    return cfg


def site_key(cfg):
    key = os.environ.get(cfg["key_env"])
    if not key:
        sys.exit(f"Set the site key first: export {cfg['key_env']}=pk_...  (WordPress → Peak SEO → Settings)")
    return key


def sections(text):
    """Notes file → { '## heading' title: body text }."""
    out, title, buf = {}, None, []
    for line in text.splitlines():
        if line.startswith("## "):
            if title:
                out[title] = "\n".join(buf)
            title, buf = line[3:].strip(), []
        elif title:
            buf.append(line)
    if title:
        out[title] = "\n".join(buf)
    return out


def bullets(body):
    """'- item' lines, ignoring HTML comments."""
    body = re.sub(r"<!--.*?-->", "", body or "", flags=re.S)
    return [m.group(1).strip() for m in re.finditer(r"^\s*[-*]\s+(?:\[[ x]\]\s*)?(.+)$", body, re.M) if m.group(1).strip()]


def keyword_map(body):
    """Markdown table rows → [{'keyword', 'page'}] in priority order."""
    rows = []
    for line in re.sub(r"<!--.*?-->", "", body or "", flags=re.S).splitlines():
        cells = [c.strip() for c in line.strip().strip("|").split("|")] if line.strip().startswith("|") else []
        if len(cells) < 2 or set(cells[0]) <= set("-: ") or cells[0].lower() == "keyword":
            continue
        rows.append({"keyword": cells[0], "page": cells[1]})
    return rows


def add_to_section(text, title, lines):
    """Append lines at the end of a '## title' section (created if missing)."""
    if not lines:
        return text
    out = text.splitlines()
    start = next((i for i, l in enumerate(out) if l.strip() == f"## {title}"), None)
    if start is None:
        return text.rstrip("\n") + f"\n\n## {title}\n\n" + "\n".join(lines) + "\n"
    end = next((i for i in range(start + 1, len(out)) if out[i].startswith("## ")), len(out))
    while end > start + 1 and out[end - 1].strip() == "":
        end -= 1
    out[end:end] = lines
    return "\n".join(out) + "\n"


# --------------------------------------------------------------------------
# Choosing the one improvement
# --------------------------------------------------------------------------

def now_utc():
    return dt.datetime.now(dt.timezone.utc).replace(tzinfo=None)


def parse_time(s):
    try:
        return dt.datetime.fromisoformat(str(s).replace("Z", "+00:00")).replace(tzinfo=None)
    except ValueError:
        return dt.datetime.min


def recent_work(proposals, cooldown_days):
    """Targets with a pending proposal, (target, kind) pairs proposed recently, and slugs already proposed."""
    since = now_utc() - dt.timedelta(days=cooldown_days)
    busy, recent, slugs = set(), set(), set()
    for p in proposals:
        if p.get("status") == "pending":
            busy.add(p.get("target_id"))
        if parse_time(p.get("created")) >= since:
            recent.add((p.get("target_id"), p.get("kind")))
        if p.get("kind") == "create_page" and isinstance(p.get("changes"), dict):
            slugs.add(p["changes"].get("slug"))
    return busy, recent, slugs


def is_front(page, site):
    return page.get("url", "").rstrip("/") == site.get("url", "").rstrip("/") or page.get("slug") in ("home", "")


def keyword_for(page, kmap):
    for row in kmap:
        if row["page"].strip("/ ").lower() == page.get("slug", "").lower():
            return row["keyword"]
    return None


def needs_seo(page):
    t, d = page.get("seo_title") or "", page.get("seo_description") or ""
    return not t or not d or len(t) > SEO_TITLE_MAX or len(d) > SEO_DESC_MAX


def pick(site, pages, proposals, kmap, cfg, blocked=frozenset(), new_pages_ok=True):
    """Fallback: the old simple checks (missing/too-long search fields, thin pages,
    keyword-map new pages). Used when Search Console data is missing, stale or thin."""
    busy, recent, slugs = recent_work(proposals, cfg["cooldown_days"])
    mapped = [r["page"].strip("/ ").lower() for r in kmap]

    def order(p):
        slug = p.get("slug", "").lower()
        return (0 if is_front(p, site) else 1, 0 if slug in mapped else 1,
                mapped.index(slug) if slug in mapped else 0, 0 if p.get("type") == "page" else 1, p.get("title", ""))

    free = [p for p in sorted(pages, key=order) if p["id"] not in busy and p["id"] not in blocked]

    for p in free:
        if needs_seo(p) and (p["id"], "update_seo") not in recent:
            missing = [n for n, v in (("search title", p.get("seo_title")), ("description", p.get("seo_description"))) if not v]
            why = ("missing " + " and ".join(missing)) if missing else "search title or description too long"
            return {"kind": "update_seo", "category": "fallback_seo", "page": p, "why": why, "keyword": keyword_for(p, kmap)}

    for p in free:
        if p.get("content_editing") in ("content", "elementor_text") and p.get("word_count", 0) < cfg["thin_word_count"] \
                and (p["id"], "update_content") not in recent:
            return {"kind": "update_content", "category": "fallback_thin", "page": p, "keyword": keyword_for(p, kmap),
                    "why": f"thin page ({p.get('word_count', 0)} words)"}

    if not new_pages_ok:
        return None
    existing = {p.get("slug", "").lower() for p in pages}
    for row in kmap:
        m = re.match(r"^new\s*:?\s*(.*)$", row["page"].strip(), re.I)
        if not m:
            continue
        slug = re.sub(r"[^a-z0-9-]+", "-", (m.group(1) or row["keyword"]).lower()).strip("-")
        if any(covers(words(p.get("title")) + words(p.get("slug")) + words(p.get("h1", "")), row["keyword"]) for p in pages):
            continue  # Evidence rule: a page already targets this keyword, so no new page.
        if slug and slug not in existing and slug not in slugs:
            return {"kind": "create_page", "category": "fallback_new", "page": None, "keyword": row["keyword"], "slug": slug,
                    "why": f"no page targets \"{row['keyword']}\" yet"}
    return None


# --------------------------------------------------------------------------
# Choosing work from the Search Console report (Page Discipline rules)
# --------------------------------------------------------------------------

STOP = {"a", "an", "and", "the", "of", "for", "in", "on", "to", "with", "is", "are", "what", "how", "why",
        "your", "our", "me", "my", "near", "best", "top", "vs", "versus", "do", "does", "can", "i"}


def path_of(url):
    p = urllib.parse.urlparse(str(url or "")).path
    return "/" + urllib.parse.unquote(p).strip("/").lower()


def words(text):
    out = []
    for w in re.split(r"[^a-z0-9]+", str(text or "").lower()):
        if w and not w.isdigit() and w not in STOP:
            out.append(w[:-1] if len(w) > 3 and w.endswith("s") else w)
    return out


def covers(page_words, query):
    """Every main word of the search appears (as a word or prefix) in the page's words."""
    need = [w for w in words(query) if len(w) > 2]  # Ignore state codes ("al") and other tiny words.
    return bool(need) and all(any(h.startswith(n) or n.startswith(h) for h in page_words if len(h) > 2) for n in need)


def towns(site, notes):
    """Approved service areas: plugin list first (owner-controlled), else the notes."""
    areas = (site.get("approved_facts") or {}).get("service_areas") or bullets(notes.get("Approved service areas"))
    return [re.sub(r",\s*[A-Z]{2}$", "", a).strip().lower() for a in areas if a.strip()]


def fetch_h1s(pages, limit=80, timeout=6):
    """Read each page's public H1 (the brain sees the site like a visitor). Best effort."""
    for p in pages[:limit]:
        if "h1" in p or not p.get("url"):
            continue
        try:
            req = urllib.request.Request(p["url"], headers={"User-Agent": "PeakSEO-brain/1.0"})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                html = r.read(400_000).decode("utf-8", "replace")
            m = re.search(r"<h1[^>]*>(.*?)</h1>", html, re.I | re.S)
            p["h1"] = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m.group(1))).strip() if m else ""
        except Exception:  # noqa: BLE001 - a page that doesn't load just has no H1 evidence.
            p["h1"] = ""


def data_status(report, discipline, settings):
    """(usable, reason). Fallback is used when the data is missing, stale or too thin."""
    if not report or not report.get("available"):
        return False, "no Search Console data yet"
    if not report.get("enough_data"):
        return False, "too little Search Console data"
    d = (discipline or {}).get("data") or {}
    if not d.get("available"):
        return False, "no Search Console data yet"
    if not d.get("fresh", False):
        return False, f"Search Console data is {d.get('age_days', '?')} days old (limit {settings.get('stale_data_days', 14)})"
    return True, ""


def find_drops(report, proposals, min_drop=3.0):
    """Big position drops, matched with the Peak SEO changes approved during the two
    compared periods (report only, never a change)."""
    out = []
    start = parse_time((report.get("previous") or {}).get("start") or "1970-01-01")
    for m in ((report.get("sections") or {}).get("movers") or {}).get("items", []):
        if m.get("direction") != "down" or abs(m.get("change", 0)) < min_drop:
            continue
        related = []
        for p in proposals:
            if p.get("status") not in ("approved", "undone") or not p.get("approved_at") or parse_time(p["approved_at"]) < start:
                continue
            if m["type"] == "query" or p.get("_path") == path_of(m["key"]):
                related.append(f"#{p['id']} {p.get('kind')} ({str(p.get('approved_at'))[:10]})")
        out.append({
            "type": m["type"], "key": m["key"], "before": m.get("position_before"), "now": m.get("position"),
            "changes": related[:5],
            "text": f"{m['type']} \"{m['key']}\" dropped from {m.get('position_before')} to {m.get('position')}"
                    + (f"; Peak SEO changes approved in this period: {', '.join(related[:5])}" if related else "; no Peak SEO changes in this period"),
        })
    return out


def select_work(ctx):
    """The single most valuable improvement, following the GoBIG order:
    (1) competing pages, (2) low CTR, (3) strengthen, (4) real gaps; drops are reported, not changed.
    Returns (choice or None, notes[]) where notes explain skipped work and fallbacks."""
    site, pages, proposals = ctx["site"], ctx["pages"], ctx["proposals"]
    report, disc, cfg, notes_sec = ctx.get("report"), ctx.get("discipline"), ctx["cfg"], ctx["notes"]
    settings = (disc or {}).get("settings") or site.get("discipline") or {}
    by_path = {path_of(p.get("url")): p for p in pages}
    busy = {p.get("target_id") for p in proposals if p.get("status") == "pending"}
    for p in proposals:
        p["_path"] = path_of(next((x.get("url") for x in pages if x["id"] == p.get("target_id")), ""))
    drops = find_drops(report or {}, proposals) if report and report.get("available") else []
    problem = {by_path[path_of(d["key"])]["id"] for d in drops if d["type"] == "page" and path_of(d["key"]) in by_path}
    recent = {int(k) for k in ((disc or {}).get("recently_changed") or {}).keys()}
    blocked = (recent - problem) | busy  # 4-week rule, unless the data shows a problem with the page.
    log = []
    new_ok = ((disc or {}).get("new_pages") or {}).get("allowed", True)

    def ok_page(url, editable=True, refocus=False):
        p = by_path.get(path_of(url))
        if not p:
            return None
        if p["id"] in blocked:
            log.append(f"skipped {p['title']}: changed in the last {settings.get('recent_change_days', 28)} days" if p["id"] in recent else f"skipped {p['title']}: a proposal is already waiting")
            return None
        if refocus and (p.get("is_front") or p.get("is_contact") or p.get("protected")):
            return None
        if editable and p.get("content_editing") not in ("content", "elementor_text"):
            log.append(f"skipped {p['title']}: its page builder can't be edited yet")
            return None
        return p

    usable, why_not = data_status(report, disc, settings)
    if usable:
        sec = report.get("sections") or {}

        # (1) Competing pages: refocus the weaker page and link it to the main one.
        for c in (disc or {}).get("competing", []):
            main = by_path.get(path_of(c["main"]))
            page = ok_page(c["refocus"], refocus=True)
            if main and page:
                q = c["queries"][0]["query"]
                return {"kind": "update_content", "category": "competing", "page": page, "source": "report",
                        "keyword": None, "queries": c["queries"], "link": {"url": main["url"], "anchor": q, "title": main["title"]},
                        "why": f"competes with {main['title']} for \"{q}\"" + (f" and {len(c['queries']) - 1} more" if len(c["queries"]) > 1 else "")}, log

        # (2) Shown often, clicked rarely: new search title and description.
        for item in (sec.get("low_ctr") or {}).get("items", []):
            page = ok_page(item["page"], editable=False)
            if page:
                return {"kind": "update_seo", "category": "low_ctr", "page": page, "source": "report",
                        "keyword": (item.get("top_queries") or [None])[0], "queries": [{"query": q} for q in item.get("top_queries", [])],
                        "why": f"{item['impressions']} impressions but {round(item['ctr'] * 100, 2)}% clicks (usual for position {item['position']} is about {round(item['expected_ctr'] * 100, 1)}%)"}, log

        # (3) Strengthen pages already close to the top, then close cleanup candidates.
        for item in (sec.get("striking") or {}).get("items", []):
            page = ok_page(item["page"])
            if page:
                n = item.get("search_count", len(item.get("searches", [])))
                return {"kind": "update_content", "category": "strengthen", "page": page, "source": "report",
                        "keyword": item["searches"][0]["query"] if item.get("searches") else None, "queries": item.get("searches", []),
                        "why": f"strengthen for {n} {'search' if n == 1 else 'searches'} at average position {item.get('position')}"}, log
        for c in (disc or {}).get("cleanup", []):
            if c.get("action") == "strengthen":
                page = ok_page(c["url"])
                if page:
                    return {"kind": "update_content", "category": "strengthen", "page": page, "source": "report",
                            "keyword": None, "queries": [], "why": f"cleanup candidate close to ranking: {c['why']}"}, log

        # (4) Real gaps: a new page, only if Page Discipline allows and no page already targets it.
        gaps = (sec.get("new_content") or {}).get("items", [])
        if gaps and not new_ok:
            log.append("new pages not allowed: " + " ".join(((disc or {}).get("new_pages") or {}).get("reasons", [])))
        home_towns = towns(site, notes_sec)
        if gaps and new_ok:
            ctx.get("h1_fetcher", fetch_h1s)(pages)  # Evidence: titles, slugs and real H1s.
        for g in gaps if new_ok else []:
            q = g["query"]
            target = next((p for p in pages if covers(words(p.get("title")) + words(p.get("slug")) + words(p.get("h1", "")), q)), None)
            if target:
                page = ok_page(target["url"])
                if page:
                    return {"kind": "update_content", "category": "strengthen", "page": page, "source": "report",
                            "keyword": q, "queries": [g], "why": f"\"{q}\" has no ranking page, but {page['title']} already targets it: strengthen instead of adding a page"}, log
                continue
            other_town = next((t for t in home_towns[1:] if t and t in q.lower()), None)
            if other_town:
                log.append(f"skipped location page for \"{q}\": location pages need a real job in {other_town.title()} in the owner's facts (coming with the owner interview)")
                continue
            slug = re.sub(r"[^a-z0-9-]+", "-", q.lower()).strip("-")[:60]
            return {"kind": "create_page", "category": "gap", "page": None, "source": "report", "keyword": q, "slug": slug,
                    "queries": [g], "why": f"{g['impressions']} impressions for \"{q}\" and no page ranks better than {g.get('best_position')}"}, log
        log.append("nothing in the Search Console report needs work right now; using the simple checks")
        why_not = "nothing in the report needs work"

    choice = pick(site, pages, proposals, keyword_map(notes_sec.get("Keyword map")), cfg, blocked=blocked, new_pages_ok=new_ok)
    if choice:
        choice["source"] = f"fallback ({why_not})"
    return choice, log


# --------------------------------------------------------------------------
# Asking Claude
# --------------------------------------------------------------------------

SYSTEM = """You are the writer for Peak SEO, a service that improves small-business websites one careful change at a time. A person at the business will see exactly what you write before it goes live (or Peak Visibility approves it for them), so accuracy matters more than polish.

Rules:
- The <site_notes> are the only source of facts about the business. Use nothing else: not your general knowledge about the business, the area, or the industry.
- Never invent reviews, ratings, testimonials, prices, discounts, guarantees, warranties, licenses, awards, years in business, team size, response times, service areas, or services. The current page text may be used, but don't add claims to it.
- If the change would be stronger with a fact that isn't in the notes, write it without that fact and list a short, specific question for the owner in open_questions (for example "What is the main phone number?").
- Write plainly and specifically for the people the business serves. No hype ("best", "#1", "top-rated"), no keyword stuffing; use the target keyword naturally once where it fits.
- Search titles: at most 60 characters, including the business name if it fits. Descriptions: 120 to 155 characters, one or two sentences, with a reason to click.
- Set proceed to false (and explain in why_not) only if a useful change truly can't be written from the notes.
- Set owner_should_review to true if the change alters what the page promises, even when every fact comes from the notes.
- summary is one short line the owner sees in their dashboard; reason says in one or two sentences why the change helps.
- facts_used lists each fact from the notes you relied on, quoted briefly.

Local SEO method (GoBIG Systems), which every change follows:
- A small site where every page earns its place: improve an existing page before suggesting another.
- One page per money keyword. A page that shares a search with another page is refocused on a different question.
- On service and location pages, the title shown as the page's H1 names the service and the town (an approved service area).
- H2 headings are questions people actually search (use the searches given in the task, worded naturally).
- True, specific details instead of generic copy: real services, towns, materials, and jobs from the notes. Never write "Why choose us" blocks, emergency clichés ("burst pipe at 2 a.m."), directions ("just off Highway 72"), vague "trusted experts" claims, or filler intros ("When it comes to…", "In today's world…").
- FAQ entries answer specific questions a customer would ask this business, built from the notes and the searches; never generic ones ("Do you take credit cards?") or ones whose answer isn't in the notes.
- Text changes only: never change layout, design, or structured data (schema)."""

COMMON = {
    "proceed": {"type": "boolean"},
    "why_not": {"type": "string"},
    "summary": {"type": "string"},
    "reason": {"type": "string"},
    "facts_used": {"type": "array", "items": {"type": "string"}},
    "open_questions": {"type": "array", "items": {"type": "string"}},
    "owner_should_review": {"type": "boolean"},
}


def schema(extra):
    props = dict(COMMON, **extra)
    return {"type": "object", "properties": props, "required": list(props), "additionalProperties": False}


SCHEMAS = {
    "seo": schema({"seo_title": {"type": "string"}, "seo_description": {"type": "string"}}),
    "content": schema({"title": {"type": "string"}, "content": {"type": "string"}}),
    "elementor": schema({"edits": {"type": "array", "items": {
        "type": "object", "properties": {"id": {"type": "string"}, "text": {"type": "string"}},
        "required": ["id", "text"], "additionalProperties": False}}}),
    "new_page": schema({"title": {"type": "string"}, "slug": {"type": "string"}, "content": {"type": "string"}}),
}


def page_brief(page):
    keep = ("id", "type", "title", "slug", "url", "seo_title", "seo_description", "page_builder",
            "content_editing", "word_count", "content", "excerpt", "elementor_text")
    return {k: page[k] for k in keep if k in page}


def search_lines(choice):
    rows = []
    for q in (choice.get("queries") or [])[:12]:
        bits = [f"\"{q['query']}\""]
        if q.get("position") is not None:
            bits.append(f"position {q['position']}")
        if q.get("impressions"):
            bits.append(f"{q['impressions']} impressions")
        rows.append("- " + ", ".join(bits))
    return "\n".join(rows)


def build_task(choice, page, site, cfg):
    kw = choice.get("keyword")
    kw_line = f"Target keyword: \"{kw}\"." if kw else "No target keyword is mapped for this page; choose wording from what the page is about."
    cat = choice.get("category", "")
    searches = search_lines(choice)
    elementor = page.get("content_editing") == "elementor_text"
    widget_rules = (
        "You can only change the text of the widgets listed in elementor_text, by id. Headings: short, no HTML except "
        "<strong>/<em>. Text widgets (label 'Text' or 'Paragraph'): short paragraphs of HTML (<p>, <strong>, <em>, <ul>/<li>, "
        "<a href>). Only include widgets you change; don't fill an empty widget unless it clearly should hold a heading."
    )
    body_rules = (
        "Return the complete new body in WordPress block markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->, "
        "<!-- wp:heading --><h2>…</h2><!-- /wp:heading -->, lists as <!-- wp:list --><ul><li>…</li></ul><!-- /wp:list -->). "
        "No <h1> in the body: the theme shows the page title as the H1. Return title as the new page title (its H1) "
        "only if it should change, else an empty string. Keep every accurate point that is there now."
    )

    if choice["kind"] == "update_seo":
        extra = f"\nThe page's main searches in Google:\n{searches}" if searches else ""
        return "seo", (
            f"Write a better search title and meta description for this page ({choice['why']}). {kw_line}{extra}\n"
            f"The site uses the {site.get('seo_plugin')} SEO plugin. Return both fields, even if one is fine today "
            f"(return it unchanged in that case)."
        )

    if cat == "competing":
        link = choice["link"]
        task = (
            f"This page competes with another page of the site, \"{link['title']}\" ({link['url']}), for these searches:\n{searches}\n"
            f"\"{link['title']}\" should own them. Refocus THIS page on a different, related question a customer asks, one it can "
            "answer well from the notes, so it no longer targets those searches (adjust its headings and wording; keep what's "
            "accurate). Then add ONE natural sentence that links to the main page, with the link text exactly "
            f"\"{link['anchor']}\": <a href=\"{link['url']}\">{link['anchor']}</a>."
        )
        return ("elementor", task + " Put the link inside a Text widget.\n" + widget_rules) if elementor else ("content", task + "\n" + body_rules)

    if cat == "strengthen":
        task = (
            f"Strengthen this page ({choice['why']}).\n"
            + (f"Searches it should win:\n{searches}\n" if searches else "")
            + "Make it the clearly best answer for these searches: fuller, specific content from the notes; H2 headings worded as "
            "the questions people search; a short FAQ of specific questions answered from the notes; the service and town in the "
            "H1/title if this is a service page. Don't add searches' wording unnaturally."
        )
        return ("elementor", task + "\n" + widget_rules) if elementor else ("content", task + " Aim for roughly 400–800 useful words.\n" + body_rules)

    if choice["kind"] == "update_content" and elementor:
        return "elementor", (
            f"This page is thin ({page.get('word_count')} words) and built with Elementor. {kw_line}\n"
            "Improve it so the page explains clearly what the business offers and to whom.\n" + widget_rules
        )
    if choice["kind"] == "update_content":
        return "content", (
            f"This page is thin ({page.get('word_count')} words). {kw_line}\n"
            "Rewrite and expand the page body to roughly 350–600 useful words for the people the business serves. " + body_rules
        )
    return "new_page", (
        f"Write a new page targeting \"{kw}\" ({choice['why']}). Use slug \"{choice['slug']}\" unless it reads badly.\n"
        "Only write it if the notes contain enough approved facts to make it genuinely useful; otherwise set "
        "proceed to false and ask for what's missing. 350–600 words in WordPress block markup as described: "
        "paragraphs, <h2> headings, lists. No <h1>."
    )


def ask_claude(cfg, notes_text, site, page, choice):
    import anthropic

    shape, task = build_task(choice, page or {}, site, cfg)
    site_info = {k: site.get(k) for k in ("name", "tagline", "url", "seo_plugin", "theme")}
    user = (
        f"<site_notes>\n{notes_text}\n</site_notes>\n\n"
        f"<site>\n{json.dumps(site_info, indent=1)}\n</site>\n\n"
        + (f"<page>\n{json.dumps(page_brief(page), indent=1)}\n</page>\n\n" if page else "")
        + f"<task>\n{task}\n</task>"
    )
    client = anthropic.Anthropic()
    try:
        resp = client.beta.messages.create(
            model=cfg["model"],
            max_tokens=16000,
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",  # if the model declines, the API retries on a recommended fallback model
            system=SYSTEM,
            messages=[{"role": "user", "content": user}],
            output_config={"effort": cfg["effort"], "format": {"type": "json_schema", "schema": SCHEMAS[shape]}},
        )
    except anthropic.AuthenticationError:
        sys.exit("Anthropic API key rejected. Set ANTHROPIC_API_KEY (or run `ant auth login`).")
    except anthropic.RateLimitError:
        sys.exit("Anthropic API rate limit hit. Wait a minute and run again.")
    except anthropic.APIStatusError as e:
        sys.exit(f"Anthropic API error {e.status_code}: {e.message}")
    except anthropic.APIConnectionError:
        sys.exit("Could not reach the Anthropic API. Check the internet connection.")

    cost = usage_cost(resp)
    if resp.stop_reason == "refusal":
        return shape, None, cost, "Claude declined to write this change."
    if resp.stop_reason == "max_tokens":
        return shape, None, cost, "Claude's answer was cut off (max_tokens)."
    text = next((b.text for b in resp.content if b.type == "text"), "")
    try:
        return shape, json.loads(text), cost, None
    except ValueError:
        return shape, None, cost, "Claude's answer was not valid JSON."


def price_for(model):
    for name in sorted(PRICES, key=len, reverse=True):
        if (model or "").startswith(name):
            return PRICES[name]
    return None


def usage_cost(resp):
    """Tokens and estimated dollars, summed over every attempt (fallbacks included)."""
    u = resp.usage
    rows = [(getattr(it, "model", None) or resp.model, it) for it in (getattr(u, "iterations", None) or [])
            if getattr(it, "input_tokens", None) is not None]
    if not rows:
        rows = [(resp.model, u)]
    total = {"input": 0, "output": 0, "cache_write": 0, "cache_read": 0, "usd": 0.0, "models": [], "unpriced": []}
    for model, x in rows:
        i, o = x.input_tokens or 0, x.output_tokens or 0
        cw, cr = getattr(x, "cache_creation_input_tokens", 0) or 0, getattr(x, "cache_read_input_tokens", 0) or 0
        total["input"] += i
        total["output"] += o
        total["cache_write"] += cw
        total["cache_read"] += cr
        total["models"].append(model)
        p = price_for(model)
        if p:
            total["usd"] += (i * p[0] + o * p[1] + cw * p[2] + cr * p[3]) / 1_000_000
        else:
            total["unpriced"].append(model)
    return total


def cost_line(c):
    if not c:
        return "no API call"
    s = f"{c['input']:,} in / {c['output']:,} out tokens"
    if c["cache_read"] or c["cache_write"]:
        s += f" (+{c['cache_write']:,} cache write, {c['cache_read']:,} cache read)"
    s += f" · ~${c['usd']:.4f}"
    if len(set(c["models"])) > 1:
        s += " · models: " + " → ".join(c["models"])
    if c["unpriced"]:
        s += f" · no price on file for {', '.join(set(c['unpriced']))}"
    return s


# --------------------------------------------------------------------------
# Turning Claude's answer into a proposal
# --------------------------------------------------------------------------

def link_problems(choice, html):
    """A competing-pages fix must link to the main page with the search as link text."""
    link = choice.get("link")
    if not link:
        return []
    for href, text in re.findall(r"<a\s[^>]*href=[\"']([^\"']+)[\"'][^>]*>(.*?)</a>", html, re.I | re.S):
        if path_of(href) == path_of(link["url"]) and re.sub(r"<[^>]+>|\s+", " ", text).strip().lower() == link["anchor"].lower():
            return []
    return [f"no link to {link['url']} with the text \"{link['anchor']}\""]


def build_proposal(choice, page, shape, out):
    """→ (payload, problems)."""
    problems = []
    common = {"summary": (out.get("summary") or "").strip()[:200], "reason": (out.get("reason") or "").strip(),
              "needs_owner": bool(out.get("owner_should_review"))}
    if not common["summary"]:
        problems.append("no summary")

    if shape == "seo":
        t, d = out.get("seo_title", "").strip(), out.get("seo_description", "").strip()
        if not t or not d:
            problems.append("empty search title or description")
        if len(t) > SEO_TITLE_MAX:
            problems.append(f"search title is {len(t)} characters (max {SEO_TITLE_MAX})")
        if len(d) > SEO_DESC_MAX:
            problems.append(f"description is {len(d)} characters (max {SEO_DESC_MAX})")
        changes = {}
        if t != (page.get("seo_title") or ""):
            changes["seo_title"] = t
        if d != (page.get("seo_description") or ""):
            changes["seo_description"] = d
        if not changes:
            problems.append("nothing changed")
        return dict(common, kind="update_seo", target_id=page["id"], changes=changes), problems

    if shape == "elementor":
        known = {w["id"]: w for w in page.get("elementor_text", [])}
        edits = []
        for e in out.get("edits", []):
            if e.get("id") not in known:
                problems.append(f"unknown widget id {e.get('id')}")
            elif e.get("text", "").strip() and e["text"].strip() != known[e["id"]]["text"].strip():
                edits.append({"id": e["id"], "text": e["text"].strip()})
        if not edits:
            problems.append("no widget text changed")
        problems += link_problems(choice, " ".join(e["text"] for e in edits))
        return dict(common, kind="update_content", target_id=page["id"], changes={"elementor": edits}), problems

    if shape == "content":
        content = (out.get("content") or "").strip()
        title = (out.get("title") or "").strip()
        if len(re.sub(r"<[^>]+>", " ", content).split()) < 50:
            problems.append("new content is too short")
        if re.search(r"<h1[\s>]", content, re.I):
            problems.append("the body has an <h1>: the page title is the H1")
        problems += link_problems(choice, content)
        changes = {"content": content}
        if title and title != (page.get("title") or ""):
            changes["title"] = title
        return dict(common, kind="update_content", target_id=page["id"], changes=changes), problems

    title, content = (out.get("title") or "").strip(), (out.get("content") or "").strip()
    slug = re.sub(r"[^a-z0-9-]+", "-", (out.get("slug") or choice["slug"]).lower()).strip("-")
    if not title or not content or not slug:
        problems.append("new page needs a title, slug and content")
    return dict(common, kind="create_page", changes={"title": title, "slug": slug, "content": content}), problems


def show_proposal(payload, page):
    c = payload["changes"]
    print(f"\n  Kind:    {payload['kind']}")
    if page:
        print(f"  Page:    {page['title']} (#{page['id']}) {page.get('url', '')}")
    print(f"  Summary: {payload['summary']}")
    print(f"  Why:     {payload['reason']}")
    if payload.get("needs_owner"):
        print("  Marked:  needs the owner's approval (Claude flagged it)")
    if "seo_title" in c or "seo_description" in c:
        for k, label, mx in (("seo_title", "Search title", SEO_TITLE_MAX), ("seo_description", "Description", SEO_DESC_MAX)):
            if k in c:
                print(f"\n  {label} ({len(c[k])}/{mx})\n    now: {page.get(k) or '(empty)'}\n    new: {c[k]}")
    for e in c.get("elementor", []):
        before = next((w["text"] for w in page.get("elementor_text", []) if w["id"] == e["id"]), "")
        print(f"\n  Widget {e['id']}\n    now: {before or '(empty)'}\n    new: {e['text']}")
    if "content" in c:
        print(f"\n  {'Title: ' + c['title'] if 'title' in c else 'New content'} ({len(re.sub(r'<[^>]+>', ' ', c['content']).split())} words)")
        print("    " + c["content"][:1500].replace("\n", "\n    ") + (" …" if len(c["content"]) > 1500 else ""))


# --------------------------------------------------------------------------
# Commands
# --------------------------------------------------------------------------

def read_site(cfg, key):
    def get(route, params=None, optional=False):
        status, body = call(cfg["site_url"], key, "GET", route, params=params)
        if status >= 400:
            if optional:
                return None  # Older plugin or Search Console not connected: the brain falls back.
            msg = body.get("message") if isinstance(body, dict) else body
            sys.exit(f"Site API {route} failed: HTTP {status} {msg}")
        return body
    return get("/site"), get("/pages"), get("/proposals"), get("/gsc-report", optional=True), get("/discipline", optional=True)


def context(cfg, key, notes):
    site, pages, proposals, report, discipline = read_site(cfg, key)
    return {"site": site, "pages": pages, "proposals": proposals, "report": report, "discipline": discipline,
            "cfg": cfg, "notes": notes}


def describe(choice):
    target = f"{choice['page']['title']} (#{choice['page']['id']})" if choice.get("page") else f"new page /{choice['slug']}/"
    return target, (f"{choice['kind']} on {target}: {choice['why']}"
                    + (f" · keyword \"{choice['keyword']}\"" if choice.get("keyword") else "")
                    + f" · from: {choice.get('source', 'report')}")


def print_findings(ctx, log_lines):
    drops = find_drops(ctx["report"], ctx["proposals"]) if ctx.get("report") and ctx["report"].get("available") else []
    for d in drops:
        print(f"Finding (report only): {d['text']}")
    for line in log_lines:
        print(f"Note: {line}")
    return [f"finding: {d['text']}" for d in drops]


def facts_warning(notes, site):
    """Facts in the notes that the plugin doesn't know are approved → those changes will wait for the owner."""
    approved = site.get("approved_facts") or {}
    msgs = []
    for title, key in (("Approved service areas", "service_areas"), ("Approved services", "services")):
        have = {a.lower() for a in approved.get(key, [])}
        missing = [b for b in bullets(notes.get(title)) if b.lower() not in have]
        if missing:
            msgs.append(f"{title.split(' ', 1)[1]}: {', '.join(missing)}")
    if msgs:
        print("Note: these facts are in the notes but not in the site's Peak SEO → Settings → Approved facts,")
        print("      so a change that mentions them will wait for the owner:  " + "; ".join(msgs))


def cmd_plan(args):
    site_dir = Path(args.site_dir)
    cfg = load_config(site_dir)
    notes = sections((site_dir / cfg["notes_file"]).read_text(encoding="utf-8"))
    ctx = context(cfg, site_key(cfg), notes)
    site = ctx["site"]
    print(f"{site['name']} · plugin {site.get('plugin_version')} · central approval {'on' if site.get('central_approval') else 'off'}")
    facts_warning(notes, site)
    choice, log_lines = select_work(ctx)
    print_findings(ctx, log_lines)
    if not choice:
        print("Nothing to do right now.")
        return
    print("Next run would do: " + describe(choice)[1])


def cmd_run(args):
    site_dir = Path(args.site_dir)
    cfg = load_config(site_dir)
    key = site_key(cfg)
    notes_path = site_dir / cfg["notes_file"]
    notes_text = notes_path.read_text(encoding="utf-8")
    notes = sections(notes_text)
    started = now_utc()

    ctx = context(cfg, key, notes)
    site = ctx["site"]
    print(f"{site['name']} · plugin {site.get('plugin_version')} · central approval {'on' if site.get('central_approval') else 'off'}"
          + (" · DRY RUN" if args.dry_run else ""))
    facts_warning(notes, site)

    choice, log_lines = select_work(ctx)
    findings = print_findings(ctx, log_lines)
    if not choice:
        print("Nothing to do right now.")
        if not args.dry_run:
            log(notes_path, started, "nothing to do", None, [], findings)
        return

    page = None
    if choice["page"]:
        status, page = call(cfg["site_url"], key, "GET", f"/pages/{choice['page']['id']}")
        if status >= 400:
            sys.exit(f"Could not read page #{choice['page']['id']}: HTTP {status}")
    target, line = describe(choice)
    print("Picked: " + line)
    print(f"Asking {cfg['model']} (effort {cfg['effort']})…")

    shape, out, cost, error = ask_claude(cfg, notes_text, site, page, choice)
    questions = [q.strip() for q in (out or {}).get("open_questions", []) if q.strip()]

    def finish(outcome, code=0):
        print(f"\nCost: {cost_line(cost)}")
        if questions:
            print("Open questions for the owner:\n  - " + "\n  - ".join(questions))
        if not args.dry_run:
            log(notes_path, started, f"{choice.get('source', 'report')} · {choice.get('category', choice['kind'])} · {target} · {outcome}", cost, questions, findings)
            print(f"Logged to {notes_path}")
        sys.exit(code)

    if error:
        print(f"Stopped: {error}")
        finish(f"failed: {error}", 1)
    if not out.get("proceed"):
        print(f"Claude skipped this change: {out.get('why_not')}")
        finish(f"skipped: {out.get('why_not')}")

    payload, problems = build_proposal(choice, page or {}, shape, out)
    show_proposal(payload, page)
    if out.get("facts_used"):
        print("\n  Facts used:\n    - " + "\n    - ".join(out["facts_used"]))
    if problems:
        print("\nNot sent, because: " + "; ".join(problems))
        finish("not sent: " + "; ".join(problems), 1)

    if args.dry_run:
        print("\nDry run: nothing was sent to the site.")
        finish("dry run")

    status, created = call(cfg["site_url"], key, "POST", "/proposals", payload)
    if status >= 400:
        msg = created.get("message") if isinstance(created, dict) else created
        print(f"\nThe site refused the proposal: HTTP {status} {msg}")
        finish(f"refused by site: {msg}", 1)
    pid = created["id"]
    print(f"\nSent as proposal #{pid}.")

    if created.get("needs_owner"):
        reasons = ", ".join(created.get("owner_reasons") or [])
        outcome = f"proposal #{pid} · waiting for the owner ({reasons})"
        print(f"It needs the owner's approval: {reasons}.")
    elif not site.get("central_approval"):
        outcome = f"proposal #{pid} · waiting for the owner (central approval is off)"
        print("Central approval is off, so it waits for the owner in the dashboard.")
    elif args.no_approve:
        outcome = f"proposal #{pid} · pending (--no-approve)"
        print("Not approved (--no-approve).")
    else:
        status, approved = call(cfg["site_url"], key, "POST", f"/proposals/{pid}/approve")
        if status >= 400:
            msg = approved.get("message") if isinstance(approved, dict) else approved
            outcome = f"proposal #{pid} · approve refused: {msg}"
            print(f"Approve was refused: HTTP {status} {msg}")
        else:
            outcome = f"proposal #{pid} · approved by Peak Visibility (live)"
            print("Approved by Peak Visibility. It's live and the owner can undo it in History.")
    finish(outcome)


def log(notes_path, started, outcome, cost, questions, findings=()):
    text = notes_path.read_text(encoding="utf-8")
    existing = {q.lower() for q in bullets(sections(text).get("Open questions"))}
    day = started.strftime("%Y-%m-%d")
    new_q = []
    for q in questions:
        line = f"{q} (asked {day})"
        if not any(q.lower() in e for e in existing):
            new_q.append(f"- [ ] {line}")
            existing.add(line.lower())
    text = add_to_section(text, "Open questions", new_q)
    entry = f"- {started.strftime('%Y-%m-%d %H:%M')} UTC · {outcome}" + (f" · {cost_line(cost)}" if cost else "")
    text = add_to_section(text, "Run log", [entry] + [f"  - {f}" for f in findings])
    notes_path.write_text(text, encoding="utf-8")


def cmd_init(args):
    site_dir = Path(args.site_dir)
    if (site_dir / "config.json").exists():
        sys.exit(f"{site_dir} already has a config.json.")
    site_dir.mkdir(parents=True, exist_ok=True)
    name = args.name or site_dir.name
    cfg = (HERE / "templates" / "config.json").read_text(encoding="utf-8").replace("{{SITE_URL}}", args.site_url)
    notes = (HERE / "templates" / "SITE.md").read_text(encoding="utf-8").replace("{{SITE_NAME}}", name)
    (site_dir / "config.json").write_text(cfg, encoding="utf-8")
    (site_dir / "SITE.md").write_text(notes, encoding="utf-8")
    print(f"Created {site_dir / 'config.json'} and {site_dir / 'SITE.md'}. Fill in SITE.md before the first run.")


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("init", help="create a site folder from the templates")
    s.add_argument("site_dir")
    s.add_argument("--site-url", required=True)
    s.add_argument("--name")
    s = sub.add_parser("plan", help="show what the next run would pick (no Claude call, free)")
    s.add_argument("site_dir")
    s = sub.add_parser("run", help="make one improvement")
    s.add_argument("site_dir")
    s.add_argument("--dry-run", action="store_true", help="print the proposal without sending it (still calls Claude)")
    s.add_argument("--no-approve", action="store_true", help="send the proposal but never approve it")
    a = p.parse_args()
    {"init": cmd_init, "plan": cmd_plan, "run": cmd_run}[a.cmd](a)


if __name__ == "__main__":
    main()
