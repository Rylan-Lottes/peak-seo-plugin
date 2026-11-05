# Peak SEO (WordPress plugin)

Connects a WordPress site to the Peak SEO service. The service reads the site and sends **proposals** (better search titles and descriptions, improved page content, new pages). Nothing changes on the site until someone approves it in the WordPress dashboard, and every approved change can be undone.

Version 0.1.0. This is the first working version, built to test the full loop on a test site.

## What it does

| | |
|---|---|
| **Connect** | On activation the plugin creates a private site key (Peak SEO → Settings). The service signs every request with it (HMAC-SHA256, 5-minute window). A wrong or old signature is rejected. |
| **Read** | The service can list pages/posts with their SEO fields, page builder and word count, and read one page's full content. |
| **Propose** | The service can submit three kinds of proposals: `update_seo`, `update_content`, `create_page`. New pages are saved as drafts so they can be previewed. |
| **Approve** | Peak SEO → Proposed changes shows each proposal with a before/after, a Google-style preview for search titles, a diff for content, and a **Preview** link. Approve or Reject. |
| **Undo** | Before applying a change the plugin saves the old version. **Undo** in the History table puts it back. WordPress revisions are kept too. |

The service **cannot** approve, publish, delete, or touch themes, plugins, settings or users.

### SEO plugins

Search titles and descriptions are written into whichever SEO plugin the site uses: **Yoast**, **Rank Math** or **SEOPress**. With no SEO plugin, Peak SEO stores and prints its own. **All in One SEO** is detected but not editable yet (it uses its own database table).

### Page builders

Pages built with **Elementor, Divi, Beaver Builder, WPBakery or Cornerstone** store their layout outside the normal page content, so v0 refuses *content* edits on those pages (search title/description edits still work). Regular block-editor and classic pages support content edits.

## Install on a test site

1. Build the zip: `./tools/build-zip.sh` (creates `dist/peak-seo-0.1.0.zip`), or download it from the latest commit.
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
| GET | `/site` | Site name, URL, versions, theme, SEO plugin, page builders |
| GET | `/pages` | Published pages and posts with SEO fields |
| GET | `/pages/{id}` | One page, including raw `content` and `rendered` HTML |
| POST | `/proposals` | Submit a proposal (below) |
| GET | `/proposals?status=pending` | List proposals |
| GET | `/proposals/{id}` | One proposal with its status and log |

Proposal body:

```json
{
  "kind": "update_seo",
  "target_id": 12,
  "changes": { "seo_title": "...", "seo_description": "..." },
  "summary": "One line the owner sees",
  "reason": "Why this helps"
}
```

- `update_content` changes: `title`, `content` (HTML; filtered with `wp_kses_post`), `excerpt`
- `create_page` changes: `title`, `content`, optional `slug`, `parent_id` (no `target_id`)

## Not in v0 yet

- Email approvals (approval happens in the dashboard only)
- Editing content inside page builders (Elementor, Divi, etc.)
- All in One SEO fields, redirects, slug changes on existing pages
- Automatic updates from Peak SEO's own server

## License

GPL-2.0-or-later
