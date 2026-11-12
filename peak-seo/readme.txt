=== Peak SEO ===
Contributors: peakvisibility
Tags: seo, local seo, search
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to the Peak SEO service. Suggested improvements arrive as proposals you preview, approve or reject.

== Description ==

Peak SEO is the connector for the Peak SEO service by Peak Visibility. The service reviews your site and suggests improvements: better search titles and descriptions, clearer page content, and new pages.

* Nothing changes on your site until it is approved: by you, or by Peak Visibility if you allow it.
* Every approved change can be undone with one click.
* Works with Yoast SEO, Rank Math and SEOPress, or on its own.
* Can update the text of headings, paragraphs and buttons on Elementor pages.
* The service can read your pages and send suggestions. It cannot publish, delete, or change themes, plugins, users or settings.

This plugin connects to an external service (Peak SEO). It sends no data until you connect it with your site key, and only the Peak SEO service can request your pages.

== Changelog ==

= 0.7.0 =
* New Search Console page: what to work on next (low click-through pages, searches close to page one, searches with no good page, biggest position changes).
* The same report as JSON at /wp-json/peak-seo/v1/gsc-report for administrators or signed requests.

= 0.6.0 =
* Weekly Search Console data pull (and a Pull now button): last 28 days and the 28 before, as totals, top queries, top pages and query+page pairs.
* Snapshots stored in the site's database for two years, so history goes beyond Google's 16 months.

= 0.5.0 =
* Connect Google Search Console (read-only) with a service account key, stored encrypted.
* Test connection with clear explanations of common setup problems.

= 0.4.0 =
* Approved facts (Settings, owner-only): service areas, services, phone numbers and hours you list no longer make a change wait for you. New or different ones still do.
* The reason a change needs you now names the fact, e.g. "service area: Decatur".

= 0.3.0 =
* Optional central approval: let Peak Visibility approve changes for you (off by default, owner-only setting).
* Changes that add prices, phone numbers, hours, service areas, guarantees or new services always wait for you.
* History shows who approved each change.
* Weekly summary email of changes Peak Visibility approved, with links to undo them.

= 0.2.0 =
* Text edits on Elementor pages (headings, text editors, buttons), with preview and undo.
* Fix: posts opened with "Edit with Elementor" but never saved are no longer treated as Elementor pages.

= 0.1.0 =
* First version: connect, read pages, proposals, approve/reject, preview, undo.
