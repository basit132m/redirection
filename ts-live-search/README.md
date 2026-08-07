# TS Live Search

A WordPress plugin that adds a **live search bar to the homepage** and records
every keyword visitors search for, so you can see what content people are
looking for and mark which keywords already have an article written.

## What it does

- **Homepage search bar** — a pill-shaped search box (light-grey field + black
  round search button) that shows matching posts in a dropdown as the visitor
  types. Keyboard navigable (↑/↓/Enter/Esc).
- **Keyword tracking** — each settled search term is saved to a custom table and
  aggregated, so the admin panel shows *unique* keywords with a search count,
  how many results the term returned, and when it was last searched.
- **“Article written” marking** — mark any keyword as written once you have
  published a post for it, individually or in bulk.
- **Filters** — switch between **All / Not written / Written**, free-text filter
  the keyword list, and sort by any column.

## Where it appears

By default the bar is inserted automatically at the **top of the homepage**
(first page only). You can turn that off and place it anywhere instead with the
shortcode:

```
[ts_live_search]
```

## Admin panel

Go to **Search Keywords** (top-level menu, magnifier icon).

| Column        | Meaning                                             |
| ------------- | --------------------------------------------------- |
| Keyword       | The normalized search term (links to the live search results) |
| Searches      | How many times it has been searched                 |
| Results       | Number of results the term last returned            |
| Article       | *Written* ✅ or *To write* ✍️                        |
| Last searched | When the term was most recently used                |

Per-row buttons toggle **Mark written / Mark to-write** and **Delete**. Bulk
actions do the same across selected rows. The **Not written** filter is your
content to-do list.

## Settings (same screen, below the list)

- Placeholder text
- Show on homepage (on/off) — use the shortcode when off
- Record searches (on/off)
- Which post types the search looks in
- Minimum characters before searching
- Max results shown in the dropdown

## How search terms are recorded

The front-end logs a term ~1.1s after typing stops (and immediately on submit),
never every keystroke. Terms are normalized — trimmed, whitespace-collapsed,
lower-cased — so `Pokemon`, `pokemon ` and `POKEMON` all count as one keyword.

## REST endpoints

| Route                 | Method | Purpose                          |
| --------------------- | ------ | -------------------------------- |
| `/wp-json/tsls/v1/search?q=` | GET | Live results for the dropdown |
| `/wp-json/tsls/v1/log`       | POST | Record a searched keyword     |

## Layout

```
ts-live-search/
├── ts-live-search.php   Everything: settings, table, REST, front-end bar, admin panel
└── README.md            This file
```

## Notes

- The keyword table `{prefix}ts_search_keywords` is created on activation and
  re-checked on every admin load, so copying the folder in also works.
- Auto-insertion uses Astra's `astra_primary_content_top` hook when available,
  falling back to `loop_start` on other themes. If your theme places it oddly,
  disable “Show on homepage” and use the shortcode where you want it.
