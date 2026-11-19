"""Offline tests for service/peak_brain.py: no site key or API key needed."""
import io
import json
import shutil
import sys
import tempfile
import types
from contextlib import redirect_stdout
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


SITE = {"name": "Rocket Valley Plumbing (Demo)", "url": "http://peak-seo.local/", "plugin_version": "0.4.0",
        "seo_plugin": "yoast", "central_approval": True,
        "approved_facts": {"service_areas": ["Huntsville, AL"], "services": ["Water heater repair", "Drain cleaning", "Leak repair"], "phones": [], "hours": []}}
PAGES = [
    {"id": 14, "type": "post", "title": "3 Ways to Prevent Clogged Drains", "slug": "3-ways-to-prevent-clogged-drains", "url": "http://peak-seo.local/3-ways-to-prevent-clogged-drains/", "seo_title": "", "seo_description": "", "content_editing": "content", "word_count": 116},
    {"id": 1, "type": "post", "title": "Hello world!", "slug": "hello-world", "url": "http://peak-seo.local/hello-world/", "seo_title": "", "seo_description": "", "content_editing": "content", "word_count": 15},
    {"id": 35, "type": "page", "title": "Home", "slug": "home", "url": "http://peak-seo.local/home/", "seo_title": "", "seo_description": "", "content_editing": "elementor_text", "word_count": 16},
    {"id": 15, "type": "post", "title": "How to Tell If Your Water Heater Is Failing", "slug": "how-to-tell-if-your-water-heater-is-failing", "url": "http://peak-seo.local/how-to-tell-if-your-water-heater-is-failing/", "seo_title": "", "seo_description": "Learn the warning signs of a failing water heater and when it is time to call a plumber.", "content_editing": "content", "word_count": 102},
]
CFG = {"site_url": "http://peak-seo.local", "key_env": "PEAK_KEY", "model": "claude-opus-5-5", "effort": "medium", "thin_word_count": 300, "cooldown_days": 30, "notes_file": "SITE.md"}

import re as _re
notes_text = (REPO / "service/sites/peak-seo-local/SITE.md").read_text(encoding="utf-8")
# Start from clean notes: drop real run-log lines and open questions from live runs.
notes_text = _re.sub(r"(?m)^(- \[ \] .*|- 20\d\d-.*|  - finding:.*)\n", "", notes_text)
notes = B.sections(notes_text)
kmap = B.keyword_map(notes["Keyword map"])

# ---- Notes parsing ----------------------------------------------------------
ok(B.bullets(notes["Approved service areas"]) == ["Huntsville, AL", "Madison, AL"], "reads approved service areas")
ok(B.bullets(notes["Phone"]) == [], "empty Phone section (comment only) reads as empty")
ok([r["page"] for r in kmap] == ["home", "how-to-tell-if-your-water-heater-is-failing", "3-ways-to-prevent-clogged-drains", "new: water-heater-repair-huntsville"], "keyword map rows in order")

# ---- Picking ----------------------------------------------------------------
c = B.pick(SITE, PAGES, [], kmap, CFG)
ok(c["kind"] == "update_seo" and c["page"]["id"] == 35 and c["keyword"] == "plumber Huntsville AL", f"1st pick: Home SEO with keyword ({c['why']})")
props = [{"id": 50, "kind": "update_seo", "target_id": 35, "status": "pending", "created": B.now_utc().isoformat()}]
c = B.pick(SITE, PAGES, props, kmap, CFG)
ok(c["kind"] == "update_seo" and c["page"]["id"] == 15, "Home busy (pending) → next mapped page: post 15")
ok(c["why"] == "missing search title", f"why names what's missing: {c['why']}")
props = [{"id": 50 + i, "kind": "update_seo", "target_id": p["id"], "status": "approved", "created": B.now_utc().isoformat()} for i, p in enumerate(PAGES)]
c = B.pick(SITE, PAGES, props, kmap, CFG)
ok(c["kind"] == "update_content" and c["page"]["id"] == 35, f"SEO done recently → thin page: Home ({c['why']})")
old = B.now_utc().replace(year=2025).isoformat()
props_old = [dict(p, created=old) for p in props]
ok(B.pick(SITE, PAGES, props_old, kmap, CFG)["kind"] == "update_seo", "proposals older than cooldown don't block")
done = [dict(p, seo_title="T", seo_description="D", word_count=900) for p in PAGES]
c = B.pick(SITE, done, [], kmap, CFG)
ok(c["kind"] == "create_page" and c["slug"] == "water-heater-repair-huntsville", "all good → new page from keyword map")
ok(B.pick(SITE, done, [{"kind": "create_page", "changes": {"slug": "water-heater-repair-huntsville"}, "status": "pending", "target_id": 99, "created": old}], kmap, CFG) is None, "new page already proposed → nothing to do")
long = [dict(p, seo_title="x" * 61, seo_description="D", word_count=900) for p in PAGES]
ok(B.pick(SITE, long, [], kmap, CFG)["why"] == "search title or description too long", "too-long title counts")

# ---- Building proposals -----------------------------------------------------
home = dict(PAGES[2], elementor_text=[{"id": "e1264aa", "label": "Heading", "text": "Fast, honest plumbing in Huntsville"}, {"id": "ce24710", "label": "Text", "text": "<p>Call us.</p>"}])
base = {"proceed": True, "why_not": "", "summary": "Better search title", "reason": "r", "facts_used": [], "open_questions": [], "owner_should_review": False}
p, probs = B.build_proposal({"kind": "update_seo"}, home, "seo", dict(base, seo_title="Plumber in Huntsville, AL | Rocket Valley Plumbing", seo_description="d" * 140))
ok(not probs and p["kind"] == "update_seo" and p["target_id"] == 35 and set(p["changes"]) == {"seo_title", "seo_description"}, "SEO proposal built")
p, probs = B.build_proposal({"kind": "update_seo"}, home, "seo", dict(base, seo_title="x" * 61, seo_description="d" * 140))
ok(any("61 characters" in x for x in probs), "title over 60 chars rejected")
p, probs = B.build_proposal({"kind": "update_seo"}, PAGES[3], "seo", dict(base, seo_title="New title", seo_description=PAGES[3]["seo_description"]))
ok(not probs and list(p["changes"]) == ["seo_title"], "unchanged description left out")
p, probs = B.build_proposal({"kind": "update_content"}, home, "elementor", dict(base, edits=[{"id": "e1264aa", "text": "Same-day plumbing"}, {"id": "ce24710", "text": "<p>Call us.</p>"}]))
ok(not probs and p["changes"] == {"elementor": [{"id": "e1264aa", "text": "Same-day plumbing"}]}, "Elementor: unchanged widget dropped")
p, probs = B.build_proposal({"kind": "update_content"}, home, "elementor", dict(base, edits=[{"id": "zzz", "text": "x"}]))
ok(any("unknown widget" in x for x in probs), "Elementor: unknown widget id rejected")
p, probs = B.build_proposal({"kind": "update_content"}, PAGES[0], "content", dict(base, content="<p>too short</p>"))
ok(any("too short" in x for x in probs), "content: too short rejected")
p, probs = B.build_proposal({"kind": "update_seo"}, home, "seo", dict(base, owner_should_review=True, seo_title="T", seo_description="D"))
ok(p["needs_owner"] is True, "owner_should_review → needs_owner sent to the plugin")
p, probs = B.build_proposal({"kind": "create_page", "slug": "water-heater-repair-huntsville"}, {}, "new_page", dict(base, title="Water Heater Repair", slug="Water Heater Repair!", content="<p>x</p>"))
ok(not probs and p["changes"]["slug"] == "water-heater-repair" and "target_id" not in p, "new page: slug cleaned, no target")

# ---- Schemas are strict-valid --------------------------------------------------
for name, s in B.SCHEMAS.items():
    ok(s["additionalProperties"] is False and set(s["required"]) == set(s["properties"]), f"schema {name}: all fields required, no extras")

# ---- Cost -------------------------------------------------------------------
U = types.SimpleNamespace
r = U(model="claude-opus-5-5", usage=U(input_tokens=3000, output_tokens=1000, cache_creation_input_tokens=0, cache_read_input_tokens=0, iterations=None))
c = B.usage_cost(r)
ok(abs(c["usd"] - (3000 * 4 + 1000 * 20) / 1e6) < 1e-9, f"cost single model: ${c['usd']:.4f}")
r = U(model="claude-opus-4-8", usage=U(input_tokens=3000, output_tokens=900, cache_creation_input_tokens=0, cache_read_input_tokens=0, iterations=[
    U(type="message", model="claude-opus-5-5", input_tokens=3000, output_tokens=0, cache_creation_input_tokens=0, cache_read_input_tokens=0),
    U(type="fallback_message", model="claude-opus-4-8", input_tokens=3000, output_tokens=900, cache_creation_input_tokens=0, cache_read_input_tokens=0)]))
c = B.usage_cost(r)
ok(abs(c["usd"] - (3000 * 4 + 3000 * 5 + 900 * 25) / 1e6) < 1e-9 and "→" in B.cost_line(c), f"cost with fallback sums attempts: {B.cost_line(c)}")

# ---- Notes writing ------------------------------------------------------------
tmp = Path(tempfile.mkdtemp())
np = tmp / "SITE.md"
np.write_text(notes_text, encoding="utf-8")
B.log(np, B.now_utc(), "update_seo · Home (#35) · proposal #51 · approved", c, ["What is the main phone number?"])
B.log(np, B.now_utc(), "update_seo · post 15 · proposal #52", None, ["What is the main phone number?", "What are your hours?"])
t = B.sections(np.read_text(encoding="utf-8"))
qs = B.bullets(t["Open questions"])
ok(len(qs) == 2 and qs[0].startswith("What is the main phone number?"), f"open questions added once each: {qs}")
ok(len(B.bullets(t["Run log"])) == 2 and "proposal #51" in t["Run log"], "two run log lines")
ok(B.bullets(t["Approved services"]) == ["Water heater repair", "Drain cleaning", "Leak repair"], "other sections untouched")

# ---- Full run with a fake site and a fake Claude ----------------------------------
site_dir = tmp / "site"
site_dir.mkdir()
(site_dir / "config.json").write_text(json.dumps(CFG), encoding="utf-8")
(site_dir / "SITE.md").write_text(notes_text, encoding="utf-8")
sent = []


def fake_call(site, key, method, route, body=None, params=None):
    sent.append((method, route, body))
    if route == "/site":
        return 200, SITE
    if route == "/pages":
        return 200, PAGES
    if route == "/proposals" and method == "GET":
        return 200, []
    if route == "/pages/35":
        return 200, home
    if route == "/proposals" and method == "POST":
        return 201, {"id": 60, "needs_owner": False, "owner_reasons": []}
    if route == "/proposals/60/approve":
        return 200, {"id": 60, "status": "approved"}
    return 404, {"message": "nope"}


answer = dict(base, summary="Clearer Home search title", seo_title="Plumber in Huntsville, AL | Rocket Valley Plumbing",
              seo_description="Family-owned plumbers in Huntsville and Madison, AL for water heater repair, drain cleaning and leak repair. Fast, honest service.",
              facts_used=["Huntsville, AL", "Water heater repair"], open_questions=["What is the main phone number?"])


class FakeMessages:
    def create(self, **kw):
        FakeMessages.kw = kw
        return U(model="claude-opus-5-5", stop_reason="end_turn", content=[U(type="text", text=json.dumps(answer))],
                 usage=U(input_tokens=2500, output_tokens=700, cache_creation_input_tokens=0, cache_read_input_tokens=0, iterations=None))


fake_anthropic = types.ModuleType("anthropic")
fake_anthropic.Anthropic = lambda: U(beta=U(messages=FakeMessages()))
for n in ("AuthenticationError", "RateLimitError", "APIStatusError", "APIConnectionError"):
    setattr(fake_anthropic, n, type(n, (Exception,), {}))
sys.modules["anthropic"] = fake_anthropic
B.call = fake_call
import os
os.environ["PEAK_KEY"] = "pk_test"


def run(*argv):
    buf = io.StringIO()
    code = 0
    try:
        with redirect_stdout(buf):
            B.cmd_run(types.SimpleNamespace(site_dir=str(site_dir), dry_run="--dry-run" in argv, no_approve="--no-approve" in argv))
    except SystemExit as e:
        code = e.code or 0
    return code, buf.getvalue()


code, out = run("--dry-run")
ok(code == 0 and "Dry run: nothing was sent" in out and "Cost:" in out and "$0.0" in out, "dry run prints proposal and cost")
ok(not any(m == "POST" for m, _, _ in sent), "dry run sends nothing")
ok("Run log" in B.sections((site_dir / "SITE.md").read_text(encoding="utf-8")) and not B.bullets(B.sections((site_dir / "SITE.md").read_text(encoding="utf-8"))["Run log"]), "dry run writes nothing to notes")
kw = FakeMessages.kw
ok(kw["model"] == "claude-opus-5-5" and kw["fallbacks"] == "default" and kw["output_config"]["format"]["type"] == "json_schema" and "thinking" not in kw, "Claude request: model, fallbacks, structured output, adaptive thinking by default")
ok("<site_notes>" in kw["messages"][0]["content"] and "Approved services" in kw["messages"][0]["content"], "notes file is sent to Claude")

sent.clear()
code, out = run()
posts = [(r, b) for m, r, b in sent if m == "POST"]
ok(code == 0 and [r for r, _ in posts] == ["/proposals", "/proposals/60/approve"], f"real run: proposal sent then approved ({[r for r, _ in posts]})")
ok(posts[0][1]["kind"] == "update_seo" and posts[0][1]["target_id"] == 35 and posts[0][1]["needs_owner"] is False, "payload is update_seo on Home")
t = B.sections((site_dir / "SITE.md").read_text(encoding="utf-8"))
ok("approved by Peak Visibility" in t["Run log"] and "$" in t["Run log"], "run logged with outcome and cost")
ok(any("phone number" in q for q in B.bullets(t["Open questions"])), "open question saved to notes")

SITE["central_approval"] = False
sent.clear()
code, out = run()
ok(not any(r.endswith("/approve") for _, r, _ in sent) and "Central approval is off" in out, "central approval off: not approved")
SITE["central_approval"] = True


def needs_owner_call(site, key, method, route, body=None, params=None):
    if route == "/proposals" and method == "POST":
        sent.append((method, route, body))
        return 201, {"id": 61, "needs_owner": True, "owner_reasons": ["service area: Madison"]}
    return fake_call(site, key, method, route, body, params)


B.call = needs_owner_call
sent.clear()
code, out = run()
ok(not any(r.endswith("/approve") for _, r, _ in sent) and "service area: Madison" in out, "needs_owner from plugin: not approved, reason shown")

print(f"\n{fails} FAILED" if fails else "\nALL PASSED")
sys.exit(1 if fails else 0)
