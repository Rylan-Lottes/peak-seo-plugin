"""Work-selection tests for the brain (0.9.0): no network, no API keys."""
import copy
import sys
from pathlib import Path

REPO = Path(sys.argv[1])
sys.path.insert(0, str(REPO / "service"))
import peak_brain as B  # noqa: E402

fails = 0


def ok(c, m):
    global fails
    print(("PASS " if c else "FAIL ") + m)
    if not c:
        fails += 1


U = "https://acme.com"
SITE = {"name": "Acme", "url": U + "/", "seo_plugin": "yoast", "central_approval": True,
        "approved_facts": {"service_areas": ["Huntsville, AL", "Madison, AL"], "services": ["Web design"]},
        "discipline": {"recent_change_days": 28, "stale_data_days": 14}}


def page(i, path, title, **kw):
    p = {"id": i, "type": "page", "title": title, "slug": path.strip("/"), "url": U + path, "seo_title": "T", "seo_description": "D",
         "content_editing": "content", "word_count": 600, "is_front": False, "is_contact": False, "protected": False, "h1": title}
    p.update(kw)
    return p


PAGES = [page(1, "/", "Home", is_front=True), page(2, "/web-design/", "Web Design in Huntsville"), page(3, "/services/", "Our Services"),
         page(4, "/seo/", "SEO"), page(5, "/about/", "About"), page(6, "/old/", "Old promo", content_editing="elementor_text")]
REPORT = {
    "available": True, "enough_data": True, "previous": {"start": "2026-09-15"}, "current": {"start": "2026-10-13"},
    "sections": {
        "low_ctr": {"items": [{"page": U + "/seo/", "impressions": 900, "clicks": 1, "ctr": 0.0011, "expected_ctr": 0.04, "position": 6.0, "top_queries": ["seo huntsville"]}]},
        "striking": {"items": [{"page": U + "/web-design/", "position": 22.1, "search_count": 7, "searches": [{"query": "web design alabama", "position": 47.5, "impressions": 279}]}]},
        "new_content": {"items": [{"query": "graphic design huntsville", "impressions": 30, "best_position": 73.7, "best_page": U + "/web-design/"}]},
        "movers": {"items": []},
    },
}
DISC = {
    "settings": {"recent_change_days": 28, "stale_data_days": 14},
    "data": {"available": True, "fresh": True, "age_days": 2},
    "competing": [{"main": U + "/web-design/", "refocus": U + "/services/", "impressions": 180,
                   "queries": [{"query": "web design huntsville", "impressions": 180, "main_position": 14, "refocus_position": 22}]}],
    "cleanup": [{"id": 6, "url": U + "/old/", "action": "strengthen", "why": "Never in the top 20 (best 28)."}],
    "recently_changed": {}, "new_pages": {"allowed": True, "reasons": [], "used": 0, "cap": 2},
}
NOTES = B.sections("## Approved service areas\n- Huntsville, AL\n- Madison, AL\n## Keyword map\n| Keyword | Page |\n|---|---|\n| web design huntsville | new: web-design-huntsville |\n")
CFG = {"cooldown_days": 30, "thin_word_count": 300}


def ctx(**over):
    c = {"site": copy.deepcopy(SITE), "pages": copy.deepcopy(PAGES), "proposals": [], "report": copy.deepcopy(REPORT),
         "discipline": copy.deepcopy(DISC), "cfg": CFG, "notes": NOTES, "h1_fetcher": lambda pages: None}
    c.update(over)
    return c


def pick(c):
    return B.select_work(c)


# ---- The order: competing → low CTR → strengthen → cleanup strengthen → gap ------------
ch, _ = pick(ctx())
ok(ch["category"] == "competing" and ch["page"]["id"] == 3 and ch["link"]["anchor"] == "web design huntsville" and ch["link"]["url"].endswith("/web-design/"),
   "1st: competing pages → refocus /services/ with a link to /web-design/ (\"web design huntsville\")")
ok(ch["source"] == "report" and ch["kind"] == "update_content", "  from the report, as a content change")

c = ctx(); c["discipline"]["competing"] = []
ch, _ = pick(c)
ok(ch["category"] == "low_ctr" and ch["kind"] == "update_seo" and ch["page"]["id"] == 4 and ch["keyword"] == "seo huntsville", "2nd: shown often, clicked rarely → new search title/description for /seo/")

c["report"]["sections"]["low_ctr"]["items"] = []
ch, _ = pick(c)
ok(ch["category"] == "strengthen" and ch["page"]["id"] == 2 and "7 searches" in ch["why"], "3rd: strengthen /web-design/ for 7 searches: " + ch["why"])

c["report"]["sections"]["striking"]["items"] = []
ch, _ = pick(c)
ok(ch["category"] == "strengthen" and ch["page"]["id"] == 6 and "cleanup" in ch["why"], "then: a close cleanup candidate is strengthened")

c["discipline"]["cleanup"] = []
ch, _ = pick(c)
ok(ch["category"] == "gap" and ch["kind"] == "create_page" and ch["keyword"] == "graphic design huntsville", "4th: a real gap → new page (Page Discipline allows)")

# ---- Gaps: evidence check, monthly cap / health, location rule ------------------------
c2 = copy.deepcopy(c); c2["report"]["sections"]["new_content"]["items"] = [{"query": "web design huntsville al", "impressions": 40, "best_position": 35}]
ch, _ = pick(c2)
ok(ch["category"] == "strengthen" and ch["page"]["id"] == 2 and "already targets" in ch["why"], "evidence: a page whose title/H1 already targets the search is strengthened instead")

c3 = copy.deepcopy(c); c3["pages"][1]["title"] = "Websites"; c3["pages"][1]["slug"] = "sites"; c3["pages"][1]["h1"] = "Web design in Huntsville"
c3["report"]["sections"]["new_content"]["items"] = [{"query": "web design huntsville", "impressions": 40, "best_position": 35}]
ch, _ = pick(c3)
ok(ch["category"] == "strengthen" and ch["page"]["id"] == 2, "evidence: the H1 counts too (title and slug don't mention it)")

c4 = copy.deepcopy(c); c4["discipline"]["new_pages"] = {"allowed": False, "reasons": ["This month’s limit of 2 new pages is reached."]}
ch, notes = pick(c4)
ok((ch is None or ch["kind"] != "create_page") and any("new pages not allowed" in n for n in notes), "monthly cap reached → no new page from the report or the keyword map; note says why")
c4b = copy.deepcopy(c4); c4b["pages"][4]["word_count"] = 100
ch, _ = pick(c4b)
ok(ch["category"] == "fallback_thin" and "fallback" in ch["source"], "  …the run falls back to the simple checks instead, saying so: " + ch["source"])

c5 = copy.deepcopy(c); c5["report"]["sections"]["new_content"]["items"] = [{"query": "plumber madison", "impressions": 60, "best_position": 40}]
ch, notes = pick(c5)
ok((ch is None or ch["kind"] != "create_page") and any("location page" in n and "Madison" in n for n in notes), "location rule: another approved town needs a real job first (owner interview) → skipped")

# ---- 4-week rule, pending proposals, protected pages ------------------------------
c6 = ctx(); c6["discipline"]["competing"] = []; c6["discipline"]["recently_changed"] = {"4": "2026-11-10T00:00:00+00:00"}
ch, notes = pick(c6)
ok(ch["page"]["id"] == 2 and any("changed in the last 28 days" in n for n in notes), "4-week rule: /seo/ changed recently → skipped, next item chosen")

c7 = copy.deepcopy(c6); c7["report"]["sections"]["movers"]["items"] = [{"type": "page", "key": U + "/seo/", "direction": "down", "change": -6.0, "position": 14, "position_before": 8}]
ch, _ = pick(c7)
ok(ch["page"]["id"] == 4 and ch["category"] == "low_ctr", "4-week rule exception: the data shows a problem (big drop) → the page can be worked on")

c8 = ctx(); c8["discipline"]["competing"] = []; c8["proposals"] = [{"id": 9, "status": "pending", "target_id": 4, "kind": "update_seo", "created": "2026-11-18T00:00:00"}]
ch, notes = pick(c8)
ok(ch["page"]["id"] == 2 and any("already waiting" in n for n in notes), "a page with a pending proposal is skipped")

c9 = ctx(); c9["pages"][2]["protected"] = True
ch, _ = pick(c9)
ok(ch["category"] != "competing", "a protected page is never refocused")

# ---- Fallbacks ----------------------------------------------------------------------
c10 = ctx(); c10["discipline"]["data"] = {"available": True, "fresh": False, "age_days": 20}
c10["pages"][3]["seo_title"] = ""
ch, _ = pick(c10)
ok(ch["category"] == "fallback_seo" and "20 days old" in ch["source"], "stale data (20 days) → fallback, source says why: " + ch["source"])
cn = ctx(report=None, discipline=None); cn["pages"][3]["seo_title"] = ""
ch, _ = pick(cn)
ok(ch is not None and ch["source"].startswith("fallback (no Search Console data yet"), "no data → fallback: " + (ch or {}).get("source", ""))
c11 = ctx(); c11["report"]["enough_data"] = False
ch, _ = pick(c11)
ok(ch is None or "too little" in ch["source"], "too little data → fallback")

empty = ctx(); empty["discipline"].update({"competing": [], "cleanup": []}); empty["report"]["sections"] = {"movers": {"items": []}}
empty["pages"][4]["word_count"] = 100
ch, notes = pick(empty)
ok(ch["category"] == "fallback_thin" and "nothing in the report" in ch["source"], "nothing in the report → simple checks, logged: " + ch["source"])

# ---- Drops, link check, prompts -------------------------------------------------------
props = [{"id": 11, "status": "approved", "kind": "update_seo", "target_id": 4, "approved_at": "2026-10-20T00:00:00+00:00", "_path": "/seo"},
         {"id": 12, "status": "approved", "kind": "update_seo", "target_id": 4, "approved_at": "2026-08-01T00:00:00+00:00", "_path": "/seo"}]
rep = copy.deepcopy(REPORT); rep["sections"]["movers"]["items"] = [{"type": "page", "key": U + "/seo/", "direction": "down", "change": -6.0, "position": 14, "position_before": 8},
                                                                  {"type": "query", "key": "x", "direction": "up", "change": 5, "position": 3, "position_before": 8}]
d = B.find_drops(rep, props)
ok(len(d) == 1 and d[0]["changes"] == ["#11 update_seo (2026-10-20)"] and "dropped from 8 to 14" in d[0]["text"], "drops: only real drops, matched with changes inside the compared period: " + d[0]["text"])

ch, _ = pick(ctx())
good = f'<p>See our <a href="{U}/web-design/">web design huntsville</a> page.</p>'
ok(B.link_problems(ch, good) == [], "link check: correct link and text passes")
ok(B.link_problems(ch, '<p><a href="/web-design/">our web design page</a></p>') != [], "link check: wrong link text fails")
ok(B.link_problems(ch, "<p>No link</p>") != [], "link check: missing link fails")

shape, task = B.build_task(ch, ch["page"], SITE, CFG)
ok(shape == "content" and "web design huntsville" in task and "different, related question" in task and "No <h1>" in task, "competing task: refocus + exact link text + H1 rule")
c12 = ctx(); c12["discipline"]["competing"] = []; c12["report"]["sections"]["low_ctr"]["items"] = []
ch, _ = pick(c12)
shape, task = B.build_task(ch, ch["page"], SITE, CFG)
ok("web design alabama" in task and "FAQ" in task and "H2 headings" in task, "strengthen task lists the searches, asks for question H2s and an FAQ")
ok("title" in B.SCHEMAS["content"]["properties"] and "Why choose us" in B.SYSTEM and "schema" in B.SYSTEM, "content can change the title (H1); GoBIG rules in the instructions")

payload, probs = B.build_proposal(ch, {"id": 2, "title": "Web Design in Huntsville"}, "content",
                                  {"proceed": True, "summary": "s", "reason": "r", "owner_should_review": False, "title": "Web Design in Huntsville, AL",
                                   "content": "<h1>x</h1>" + "<p>word</p>" * 60})
ok(any("<h1>" in p for p in probs) and payload["changes"]["title"] == "Web Design in Huntsville, AL", "body with an <h1> is refused; a new title (H1) is sent")

print(f"\n{fails} FAILED" if fails else "\nALL PASSED")
sys.exit(1 if fails else 0)
