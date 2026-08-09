=== NSPVault Redirects ===
Contributors: nspvault
Tags: redirects, 301, seo, permalinks, slug
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Single-hop 301 redirects that never chain. Rename a post slug, re-index the new URL, and every old URL keeps working forever.

== Description ==

NSPVault Redirects solves a specific SEO problem: when you rename a post's URL
(e.g. `/roms/pokemon-lets-go-eevee-rom/` becomes
`/roms/pokemon-lets-go-eevee-rom-1/`), the old URL must keep redirecting to the
current one so it never 404s — even if it is later re-indexed or shared
elsewhere.

The key guarantee is **no redirect chains**. If you rename the same post again
(`-1/` -> `-2/`), the original URL AND the `-1/` URL both redirect **directly**
to `-2/` in a single 301 hop. Search engines never see
`original -> -1 -> -2`, which would dilute link equity and slow crawling.

= How it works =

* When a published post's slug changes, the old URL is recorded automatically.
* On every rename, all previously recorded URLs for that post are re-pointed to
  the newest URL, so chains are flattened before they can form.
* Retired URLs are served a 301 (configurable) to the current canonical URL.
* Redirects run before WordPress core's built-in old-slug redirect, so the
  flattened target always wins.

= Features =

* Automatic capture of slug/permalink changes for any public post type.
* Manual redirect editor (Tools -> NSPVault Redirects).
* Chain flattening applied to both automatic and manual redirects.
* Hit counters so you can see which old URLs still receive traffic.
* Query strings preserved on redirect.
* Trailing-slash tolerant matching.
* Pause switch: temporarily stop serving all redirects without deleting them.
* Per-redirect pause: pause or resume any single redirect on its own.

== Frequently Asked Questions ==

= Does it create a redirect chain if I rename a URL many times? =

No. That is the whole point. Every old URL is always re-pointed to the newest
URL, so there is only ever one hop.

= What about URLs that were never in WordPress? =

Add them by hand under Tools -> NSPVault Redirects. Manual redirects are
flattened the same way.

= Will it slow down my site? =

Redirects are only looked up for requests that would otherwise 404-style match a
stored source path, using an indexed single-row query.

== Changelog ==

= 1.2.0 =
* Pause/resume individual redirects: each row has its own Pause/Resume action and
  a Status column. A paused redirect stops serving while all others keep working.

= 1.1.0 =
* Add a Pause redirects switch (quick Pause/Resume toggle + settings option) that
  stops serving redirects while keeping every stored redirect intact.

= 1.0.0 =
* Initial release.
