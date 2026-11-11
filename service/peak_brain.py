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


def pick(site, pages, proposals, kmap, cfg):
    """The single most valuable improvement, or None."""
    busy, recent, slugs = recent_work(proposals, cfg["cooldown_days"])
    mapped = [r["page"].strip("/ ").lower() for r in kmap]

    def order(p):
        slug = p.get("slug", "").lower()
        return (0 if is_front(p, site) else 1, 0 if slug in mapped else 1,
                mapped.index(slug) if slug in mapped else 0, 0 if p.get("type") == "page" else 1, p.get("title", ""))

    free = [p for p in sorted(pages, key=order) if p["id"] not in busy]

    for p in free:
        if needs_seo(p) and (p["id"], "update_seo") not in recent:
            missing = [n for n, v in (("search title", p.get("seo_title")), ("description", p.get("seo_description"))) if not v]
            why = ("missing " + " and ".join(missing)) if missing else "search title or description too long"
            return {"kind": "update_seo", "page": p, "why": why, "keyword": keyword_for(p, kmap)}

    for p in free:
        if p.get("content_editing") in ("content", "elementor_text") and p.get("word_count", 0) < cfg["thin_word_count"] \
                and (p["id"], "update_content") not in recent:
            return {"kind": "update_content", "page": p, "keyword": keyword_for(p, kmap),
                    "why": f"thin page ({p.get('word_count', 0)} words)"}

    existing = {p.get("slug", "").lower() for p in pages}
    for row in kmap:
        m = re.match(r"^new\s*:?\s*(.*)$", row["page"].strip(), re.I)
        if not m:
            continue
        slug = re.sub(r"[^a-z0-9-]+", "-", (m.group(1) or row["keyword"]).lower()).strip("-")
        if slug and slug not in existing and slug not in slugs:
            return {"kind": "create_page", "page": None, "keyword": row["keyword"], "slug": slug,
                    "why": f"no page targets \"{row['keyword']}\" yet"}
    return None


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
- facts_used lists each fact from the notes you relied on, quoted briefly."""

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
    "content": schema({"content": {"type": "string"}}),
    "elementor": schema({"edits": {"type": "array", "items": {
        "type": "object", "properties": {"id": {"type": "string"}, "text": {"type": "string"}},
        "required": ["id", "text"], "additionalProperties": False}}}),
    "new_page": schema({"title": {"type": "string"}, "slug": {"type": "string"}, "content": {"type": "string"}}),
}


def page_brief(page):
    keep = ("id", "type", "title", "slug", "url", "seo_title", "seo_description", "page_builder",
            "content_editing", "word_count", "content", "excerpt", "elementor_text")
    return {k: page[k] for k in keep if k in page}


def build_task(choice, page, site, cfg):
    kw = choice.get("keyword")
    kw_line = f"Target keyword: \"{kw}\"." if kw else "No target keyword is mapped for this page; choose wording from what the page is about."
    if choice["kind"] == "update_seo":
        return "seo", (
            f"Write a better search title and meta description for this page ({choice['why']}). {kw_line}\n"
            f"The site uses the {site.get('seo_plugin')} SEO plugin. Return both fields, even if one is fine today "
            f"(return it unchanged in that case)."
        )
    if choice["kind"] == "update_content" and page.get("content_editing") == "elementor_text":
        return "elementor", (
            f"This page is thin ({page.get('word_count')} words) and built with Elementor. {kw_line}\n"
            "You can only change the text of the widgets listed in elementor_text, by id. Improve them so the page "
            "explains clearly what the business offers and to whom. Headings: short, no HTML except <strong>/<em>. "
            "Text widgets (label 'Text' or 'Paragraph'): short paragraphs of HTML (<p>, <strong>, <em>, <ul>/<li>). "
            "Only include widgets you actually change; don't fill a widget that is empty unless it clearly should "
            "hold a heading."
        )
    if choice["kind"] == "update_content":
        return "content", (
            f"This page is thin ({page.get('word_count')} words). {kw_line}\n"
            "Rewrite and expand the page body to roughly 350–600 useful words for the people the business serves. "
            "Keep every accurate point that is there now. Return the complete new body in WordPress block markup "
            "(<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->, <!-- wp:heading --><h2>…</h2><!-- /wp:heading -->, "
            "lists as <!-- wp:list --><ul><li>…</li></ul><!-- /wp:list -->). No <h1>; the theme shows the title."
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
        return dict(common, kind="update_content", target_id=page["id"], changes={"elementor": edits}), problems

    if shape == "content":
        content = (out.get("content") or "").strip()
        if len(re.sub(r"<[^>]+>", " ", content).split()) < 50:
            problems.append("new content is too short")
        return dict(common, kind="update_content", target_id=page["id"], changes={"content": content}), problems

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
    def get(route, params=None):
        status, body = call(cfg["site_url"], key, "GET", route, params=params)
        if status >= 400:
            msg = body.get("message") if isinstance(body, dict) else body
            sys.exit(f"Site API {route} failed: HTTP {status} {msg}")
        return body
    return get("/site"), get("/pages"), get("/proposals")


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
    site, pages, proposals = read_site(cfg, site_key(cfg))
    print(f"{site['name']} · plugin {site.get('plugin_version')} · central approval {'on' if site.get('central_approval') else 'off'}")
    facts_warning(notes, site)
    choice = pick(site, pages, proposals, keyword_map(notes.get("Keyword map")), cfg)
    if not choice:
        print("Nothing to do: every page has search fields, no thin pages, no new pages in the keyword map.")
        return
    target = f"{choice['page']['title']} (#{choice['page']['id']})" if choice["page"] else f"new page /{choice['slug']}/"
    print(f"Next run would do: {choice['kind']} on {target}: {choice['why']}"
          + (f" · keyword \"{choice['keyword']}\"" if choice.get("keyword") else ""))


def cmd_run(args):
    site_dir = Path(args.site_dir)
    cfg = load_config(site_dir)
    key = site_key(cfg)
    notes_path = site_dir / cfg["notes_file"]
    notes_text = notes_path.read_text(encoding="utf-8")
    notes = sections(notes_text)
    started = now_utc()

    site, pages, proposals = read_site(cfg, key)
    print(f"{site['name']} · plugin {site.get('plugin_version')} · central approval {'on' if site.get('central_approval') else 'off'}"
          + (" · DRY RUN" if args.dry_run else ""))
    facts_warning(notes, site)

    choice = pick(site, pages, proposals, keyword_map(notes.get("Keyword map")), cfg)
    if not choice:
        print("Nothing to do: every page has search fields, no thin pages, no new pages in the keyword map.")
        if not args.dry_run:
            log(notes_path, started, "nothing to do", None, [])
        return

    page = None
    if choice["page"]:
        status, page = call(cfg["site_url"], key, "GET", f"/pages/{choice['page']['id']}")
        if status >= 400:
            sys.exit(f"Could not read page #{choice['page']['id']}: HTTP {status}")
    target = f"{page['title']} (#{page['id']})" if page else f"new page /{choice['slug']}/"
    print(f"Picked: {choice['kind']} on {target}: {choice['why']}"
          + (f" · keyword \"{choice['keyword']}\"" if choice.get("keyword") else ""))
    print(f"Asking {cfg['model']} (effort {cfg['effort']})…")

    shape, out, cost, error = ask_claude(cfg, notes_text, site, page, choice)
    questions = [q.strip() for q in (out or {}).get("open_questions", []) if q.strip()]

    def finish(outcome, code=0):
        print(f"\nCost: {cost_line(cost)}")
        if questions:
            print("Open questions for the owner:\n  - " + "\n  - ".join(questions))
        if not args.dry_run:
            log(notes_path, started, f"{choice['kind']} · {target} · {outcome}", cost, questions)
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


def log(notes_path, started, outcome, cost, questions):
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
    text = add_to_section(text, "Run log", [entry])
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
