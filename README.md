# The WP Pages Plus

_1.2.0_

Fixes the three things that make WP Admin list tables (Pages, Posts, and any
custom post type) painful on larger, deeply-structured sites.

## Features

- **Sortable "Modified" column** — on every public post type. Shows the last
  modified date plus a muted "_x ago · by Author_" line, and sorts (it maps to
  `WP_Query`'s native `modified` orderby).
- **Full URL path under the title** — the relative permalink (e.g.
  `/services/europe/pricing/`) rendered as a muted line beneath each title, so
  you can tell apart similarly-named pages at a glance. Links to the live page
  when published; drafts show their intended pretty path (no link).
- **Slug / path search** — the list-table search box now also matches the
  slug, so you can find a page by its URL segment even when titles collide.
- **Parent filter + sortable Parent column** — for hierarchical post types
  (Pages and hierarchical CPTs):
  - a **Parent** dropdown in the table toolbar to filter to one parent's
    direct children;
  - a **Parent** column showing the parent title (linked to that filtered
    view) and sortable **by parent title** (self-join, not parent ID);
  - filtering to a parent keeps the **manual (menu) order** intact, not A–Z.
- **Duplicate — single + bulk** — one-click "Duplicate" on every row, plus a
  "Duplicate" bulk action. Clones content, excerpt, parent, menu order,
  taxonomy terms, and post meta into a new **draft** titled "… (Copy)"; the row
  action then opens it in the editor.

## Why this exists

WordPress list tables have no default sortable "Modified" date, never surface
the actual URL path (so on a big site three pages all called "Overview" are
indistinguishable), and give you no way to filter or sort by parent. This adds
all three.

## Usage

Activate the plugin. That's it — it applies automatically to every public post
type. There is no settings page.

- Click the **Modified** or **Parent** column header to sort.
- Pick a parent from the **All parents** dropdown and hit **Filter** to see
  only its children.
- Click a value in the **Parent** column to jump to that parent's filtered
  view.

## Technical notes

- Admin only; everything is scoped to `edit.php` and the screen's post type.
- The path is captured during a scoped `the_title` filter and injected under
  the title with a small footer script — WP `esc_html()`s the list-table title,
  so appended markup can't render there directly.
- Modified sorting uses native `WP_Query` orderby; Parent sorting adds a
  `posts_clauses` self-join to order by the parent's title.
- Slug search extends WP's `posts_search` clause (matches all terms against
  `post_name`); existing title/content search is untouched.
- Duplicate (single + bulk) shares one clone routine. The row action runs
  through `admin_action_*` with a per-post nonce; both paths check `edit_post`.
- Attachments are skipped (the media library is a separate screen).
- Note: cloning copies meta verbatim — it does **not** remap stored post-ID
  references (e.g. ACF relationship fields) to the new post.

## License

MIT — see `LICENSE`.

## Changelog

- 1.2.0 Slug/path search, bulk Duplicate, path links to the live page,
  "by Author" in the Modified column, and Parent sorts by title (not ID).
- 1.1.0 Path now renders reliably (footer-injected, was escaped by core).
  Parent filter preserves manual menu order. Added Duplicate row action.
- 1.0.0 Release.

## Disclaimer

- Built out of personal necessity for managing large WordPress sites.
- No configuration UI, by design. It either helps you or it doesn't.
