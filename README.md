# The WP Pages Plus

_1.0.0_

Fixes the three things that make WP Admin list tables (Pages, Posts, and any
custom post type) painful on larger, deeply-structured sites.

## Features

- **Sortable "Modified" column** — on every public post type. Shows the last
  modified date plus a "_x ago_" relative line, and sorts (it maps to
  `WP_Query`'s native `modified` orderby).
- **Full URL path under the title** — the relative permalink (e.g.
  `/services/europe/pricing/`) rendered as a muted line beneath each title, so
  you can tell apart similarly-named pages at a glance. Drafts show their
  intended pretty path.
- **Parent filter + sortable Parent column** — for hierarchical post types
  (Pages and hierarchical CPTs):
  - a **Parent** dropdown in the table toolbar to filter to one parent's
    direct children;
  - a **Parent** column showing the parent title (linked to that filtered
    view) and sortable to group siblings together.

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
- The path is appended via a tightly-scoped `the_title` filter; quick-edit
  reads the raw `post_title`, so the inline editor stays clean.
- Modified/Parent sorting use native `WP_Query` orderby — no custom SQL.
- Attachments are skipped (the media library is a separate screen).

## License

MIT — see `LICENSE`.

## Changelog

- 1.0.0 Release.

## Disclaimer

- Built out of personal necessity for managing large WordPress sites.
- No configuration UI, by design. It either helps you or it doesn't.
