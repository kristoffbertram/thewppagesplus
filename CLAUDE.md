# The WP Pages Plus — project notes

Single-file WordPress plugin (sibling in spirit to `theeditjump`): minimal,
zero-config, MIT, no settings UI.

## What it does

List-table (`edit.php`) enhancements, applied automatically to all public
post types with a UI (minus attachments):

1. Sortable **Modified** column (native `modified` orderby) + muted
   "x ago · by Author" subline.
2. Relative URL **path under the title** — captured during a scoped `the_title`
   filter, injected via a footer script (see gotcha below). Links to the live
   page for publish/private; plain span otherwise.
3. **Slug/path search** via `posts_search`: injects ` OR (post_name LIKE …)`
   just inside WP's outer search paren so a row matches normal search OR a slug
   containing all terms. Touches only the outer wrap → robust to term count.
4. **Parent** filter dropdown + sortable **Parent** column for hierarchical
   post types. Filter: `pre_get_posts` sets `post_parent` from the
   `thewppagesplus_parent` query var (preserves menu order). Sort: column maps
   to orderby `thewppagesplus_parent`, handled by a `posts_clauses` self-join
   on `twpp_parent` to order by the parent's **title**.
5. **Duplicate** — single row action (`admin_action_thewppagesplus_duplicate`,
   nonce + `edit_post`) and bulk action (`handle_bulk_actions-edit-*`). Both
   call `thewppagesplus_duplicate_post()` (clone to draft + terms + meta).
   Ported from the TKToolboxes `thetheme_modules/wp-admin.php` (commented out
   there).

## Conventions / gotchas

- Everything is wired on `load-edit.php`, scoped to `get_current_screen()->post_type`,
  so the broad `the_title` hook only runs on the list table.
- **Path can't be appended via `the_title`**: core's `_draft_or_post_title()`
  runs the filter then `esc_html()`s the result, so any markup shows literally.
  We instead record id→path in a static store and inject a `<span>` after the
  `<strong>` in `.column-title` from an `admin_footer` script.
- The Parent column renders the parent's **raw** `post_title` deliberately —
  using `get_the_title()` would re-trigger the path-append filter.
- `pre_get_posts` is the one top-level hook (the main query runs after
  `load-edit.php`); it self-guards on `$pagenow` + main query.
- Path resolution: published/private/future use `get_permalink()`; other
  statuses fall back to `get_sample_permalink()` for the intended pretty path.

## Decisions

- Path shows the **URL/slug** form (not a title breadcrumb) and sits **under
  the title** (not a separate column) — chosen to keep column width sane on
  deep hierarchies.
- Parent sort groups by `post_parent` id (siblings cluster); sorting by parent
  *name* would need a custom join and was judged not worth it.

## Dev

No build step. Drop the folder in `wp-content/plugins/` and activate. Lint:
`"$HOME/Library/Application Support/Herd/bin/php84" -l thewppagesplus.php`.
