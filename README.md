# NSPVault Redirects

A WordPress plugin that keeps old URLs alive with **single-hop, chain-free 301 redirects** whenever you rename a post's slug — built for NSPVault.com's re-slug-and-re-index workflow.

## The problem it solves

You have a live, indexed URL:

```
https://www.nspvault.com/roms/pokemon-lets-go-eevee-rom/
```

You rename it (change the slug) and re-submit the new URL in Google Search Console:

```
https://www.nspvault.com/roms/pokemon-lets-go-eevee-rom-1/
```

The old URL is no longer served by WordPress, so it would return **404** if it's
still indexed or shared somewhere. This plugin records the old URL and 301-redirects
it to the new one automatically.

## The important part: no redirect chains

Later you rename it again to `-2/`. A naïve system would build a chain:

```
original  ->  -1  ->  -2      ❌ hurts SEO (link equity lost, slower crawl)
```

This plugin **flattens** every historical URL to point directly at the newest one:

```
original  ->  -2                ✅ single 301 hop
-1        ->  -2                ✅ single 301 hop
```

No matter how many times you rename a post, **every** old URL always reaches the
current URL in exactly one redirect.

### How flattening works

On each rename `old → new`, the plugin (in `class-nspvault-redirects-db.php`,
`record_move()`):

1. Re-points every redirect currently targeting `old` so it targets `new` (this
   collapses existing chains).
2. Removes any redirect whose *source* is `new` (the new URL is now live and must
   not redirect).
3. Stores/updates the `old → new` redirect.
4. Deletes any self-referential rows as a safety net.

Because step 1 always rewrites older entries, a chain can never form.

## Installation

1. Copy the `nspvault-redirects/` folder into `wp-content/plugins/`.
2. Activate **NSPVault Redirects** in the WordPress admin. Activation creates the
   `{prefix}nspvault_redirects` table.
3. That's it — automatic capture is on by default.

## Usage

### Automatic (normal workflow)

1. Edit a published post and change its slug (e.g. add `-1`).
2. Update the post. The old URL → new URL redirect is recorded automatically.
3. Re-index the new URL in Google Search Console as you already do.

Rename again anytime; older URLs are re-pointed to the newest automatically.

### Manual redirects & management

Go to **Tools → NSPVault Redirects** to:

- Add redirects by hand (also chain-flattened).
- Search, review, and delete existing redirects.
- See hit counters for each old URL.
- Configure which post types are tracked, the default status code (301/302),
  and toggle automatic capture.

## Layout

```
nspvault-redirects/
├── nspvault-redirects.php                     Bootstrap, activation, settings
├── includes/
│   ├── class-nspvault-redirects-db.php        Table + CRUD + chain-flattening
│   ├── class-nspvault-redirects-manager.php   Capture slug changes + serve 301s
│   └── class-nspvault-redirects-admin.php     Tools admin screen
├── uninstall.php                              Drops table + options on uninstall
├── readme.txt                                 WordPress.org-style readme
└── README.md                                  This file
```

## Notes

- Redirects are served on `template_redirect` at priority 9, ahead of WordPress
  core's `wp_old_slug_redirect` (priority 10), so the flattened target wins.
- Matching is tolerant of trailing-slash differences and preserves incoming
  query strings.
- Only published posts of public post types are tracked (a renamed draft never
  exposed a public URL).
```
