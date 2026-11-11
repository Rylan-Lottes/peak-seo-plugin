# Peak SEO (WordPress plugin)

Connects a WordPress site to the Peak SEO service. The service reads the site and sends **proposals** (better search titles and descriptions, improved page content, new pages). Nothing changes on the site until it is approved, either by the owner in the WordPress dashboard or, if the owner allows it, by Peak Visibility. Every approved change can be undone.

Version 0.4.0. An early version, built to test the full loop on a test site.

## What it does

| | |
|---|---|
| **Connect** | On activation the plugin creates a private site key (Peak SEO → Settings). The service signs every request with it (HMAC-SHA256, 5-minute window). A wrong or old signature is rejected. |
| **Read** | The service can list pages/posts with their SEO fields, page builder and word count, and read one page's full content. |
| **Propose** | The service can submit three kinds of proposals: `update_seo`, `update_content`, `create_page`. New pages are saved as drafts so they can be previewed. |
| **Approve** | Peak SEO → Proposed changes shows each proposal with a before/after, a Google-style preview for search titles, a diff for content, and a **Preview** link. Approve or Reject. If the owner allows it, Peak Visibility can approve too (see [Central approval](#central-approval)). |
| **Undo** | Before applying a change the plugin saves the old version. **Undo** in the History table puts it back. WordPress revisions are kept too. |

The service **cannot** reject, delete, undo, publish on its own, or touch themes, plugins, settings or users. It can approve a proposal only when the owner has turned on central approval, and never one marked `needs_owner`.

### Central approval

**Peak SEO → Settings → Approvals** has an owner-only switch (needs `manage_options`), off by default: **Let Peak Visibility review and approve changes for me**.

- **Off:** `POST /proposals/{id}/approve` is refused with `403 peak_central_off`. Only logged-in users can approve.
- **On:** the service can approve pending proposals through the signed API, except ones marked **needs_owner**, which are refused with `403 peak_needs_owner`.
- A proposal is marked **needs_owner** when the service sends `"needs_owner": true`, or when the new text *adds* something only the owner can vouch for: a price or offer, a phone number, opening hours, a service area (e.g. "Madison, AL", "serving…"), a guarantee or warranty, or a new service ("we now offer…"). It's a simple word check that errs on the side of asking the owner, and the reason names the fact (e.g. `service area: Decatur`). Proposals received before 0.3.0 also count as needs_owner.
- **Not** counted as new: anything already in the old text, and service areas, services, phone numbers and hours on the owner's **Approved facts** list (**Peak SEO → Settings → Approved facts**, owner-only). Prices and guarantees always need the owner. The list is read-only to the service (`GET /site` → `approved_facts`). Facts sent by the service can't approve themselves, which keeps the owner in control of what counts as true.
- History shows who approved each change (**Peak Visibility** or the user's name).
- A weekly email to the site's admin email lists the changes Peak Visibility approved, each with a link to History to undo it. It's sent only when there's something to report. **Settings → Send this week's summary now** sends one immediately.

### SEO plugins

Search titles and descriptions are written into whichever SEO plugin the site uses: **Yoast**, **Rank Math** or **SEOPress**. With no SEO plugin, Peak SEO stores and prints its own. **All in One SEO** is detected but not editable yet (it uses its own database table).

### Page builders

Pages built with a page builder store their layout outside the normal page content.

- **Elementor**: the service can change the text of headings, text editors and buttons (classic widgets and the Elementor 4 atomic `e-heading`, `e-paragraph`, `e-button`). Each edit names the widget by its Elementor id. Only that text setting changes; layout, styles and every other setting are left alone. Approving checks that the text hasn't been changed in Elementor since the proposal arrived, so nothing you edited yourself gets overwritten. Preview and undo work as on normal pages.
- A post only counts as an Elementor page when its saved layout has at least one widget. Clicking "Edit with Elementor" without saving marks a post as Elementor but leaves its normal content in use, so such posts are treated as regular posts.
- **Divi, Beaver Builder, WPBakery and Cornerstone**: content edits are refused (search title/description edits still work).

Regular block-editor and classic pages support content edits.

## The Peak SEO brain

[`service/`](service/README.md) holds the Python program that drives the plugin: each run reads the site, picks one improvement, writes it with Claude from the site's notes file, sends it as a proposal and logs it. See [service/README.md](service/README.md).

## Install on a test site

1. Build the zip: `./tools/build-zip.sh` or `python tools/build_zip.py` (creates `dist/peak-seo-0.4.0.zip`). The shell script uses `zip` when installed and falls back to Python, so it works in Git Bash on Windows.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, **Activate**.
3. Go to **Peak SEO → Settings** and copy the site key.

## Try it with the test client

```bash
export PEAK_SITE=http://peak-test.local     # your test site address
export PEAK_KEY=pk_...                      # from Peak SEO → Settings

python3 tools/peak_client.py site
python3 tools/peak_client.py pages
python3 tools/peak_client.py propose-seo 12 \
  --title "Water Heater Repair in Huntsville, AL | Test Plumbing" \
  --description "Same-day water heater repair in Huntsville and Madison." \
  --summary "Add Huntsville to the search title" \
  --reason "People search for the service plus their city."
```

Then open **Peak SEO → Proposed changes** in the dashboard and approve, reject, preview or undo.

To approve as Peak Visibility instead (turn on central approval in Settings first):

```bash
python3 tools/peak_client.py approve <proposal id>
```

Other commands: `page <id>`, `texts <id>` (Elementor widgets), `propose-content`, `propose-elementor`, `propose-page`, `proposals --status pending`, `proposal <id>`. Add `--needs-owner` to any `propose-*` command to make it owner-only.

## REST API

Base: `/wp-json/peak-seo/v1` (or `/index.php?rest_route=/peak-seo/v1/...` on sites without pretty permalinks).

Headers on every request:

```
X-Peak-Timestamp: <unix seconds>
X-Peak-Signature: hex(HMAC_SHA256(site_key, "<timestamp>\n<METHOD>\n<route>\n<raw body>"))
```

`<route>` is the path without `/wp-json`, e.g. `/peak-seo/v1/proposals`.

| Method | Route | |
|---|---|---|
| GET | `/site` | Site name, URL, versions, theme, SEO plugin, page builders, `central_approval`, `approved_facts` |
| GET | `/pages` | Published pages and posts with SEO fields |
| GET | `/pages/{id}` | One page, including raw `content`, `rendered` HTML and, on Elementor pages, `elementor_text` |
| POST | `/proposals` | Submit a proposal (below) |
| GET | `/proposals?status=pending` | List proposals |
| GET | `/proposals/{id}` | One proposal with its status, `needs_owner`, `owner_reasons`, `approved_by` and log |
| POST | `/proposals/{id}/approve` | Approve as Peak Visibility (empty body). Only when central approval is on and the proposal isn't `needs_owner` |

Proposal body:

```json
{
  "kind": "update_seo",
  "target_id": 12,
  "changes": { "seo_title": "...", "seo_description": "..." },
  "summary": "One line the owner sees",
  "reason": "Why this helps",
  "needs_owner": false
}
```

- `update_content` changes: `title`, `content` (HTML; filtered with `wp_kses_post`), `excerpt`
- `update_content` on an Elementor page (`page_builder: "elementor"`): `title` and/or `elementor`, a list of `{ "id": "<widget id>", "text": "..." }`. Get the ids from `GET /pages/{id}` → `elementor_text`. Headings and buttons keep only inline tags (`strong`, `em`, `span`, `br`, links in headings); text editors accept normal post HTML. `content` and `excerpt` are refused on Elementor pages.
- `create_page` changes: `title`, `content`, optional `slug`, `parent_id` (no `target_id`)

## Not supported yet

- Email approvals (approval happens in the dashboard only)
- Editing content inside Divi, Beaver Builder, WPBakery or Cornerstone
- Elementor: images, links, and text in widgets other than headings, text editors and buttons
- All in One SEO fields, redirects, slug changes on existing pages
- Automatic updates from Peak SEO's own server

## License

GPL-2.0-or-later
