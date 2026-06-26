# Translation Assistant Publisher — Page/Post Metadata Enhancement

**Date:** 2026-06-26  
**Status:** Approved  
**Parent spec:** `specs/2026-06-25-translation-assistant-publisher-design.md`

---

## Goal

Extend the publisher so every page and post carries the correct WordPress metadata on creation:

| Content type | Field | Value |
|---|---|---|
| Page (index + chapter) | `comment_status` | `open` |
| Page (index) | `menu_order` | `0` |
| Page (chapter) | `menu_order` | `chapter_index` |
| Post | `post_category` | child term of **Web Novel Translation** > **{series_title_short}** |

---

## Scope

Single file change: `plugins/includes/class-publisher.php`

### Changes

1. **`find_or_create_index_page()`** — add `comment_status => 'open'`, `menu_order => 0` to `wp_insert_post` args.

2. **`create_chapter_page()`** — add `comment_status => 'open'`, `menu_order => $chapter_idx` to `wp_insert_post` args.

3. **`create_post()`** — resolve/create category hierarchy, pass `post_category` to `wp_insert_post`.

4. **New private `get_or_create_category()`** — `term_exists()` first; `wp_insert_term()` only if absent. Returns `int` term ID (0 on error, gracefully skips category assignment).

---

## Category Logic

```
Web Novel Translation  (parent, id=P)
└── {series_title_short}  (child under P, id=C)
```

- First call: both terms created.
- Subsequent calls: `term_exists()` returns existing ID — no duplicate.
- Post gets `post_category => [$child_id]` which implicitly includes the parent in WP's taxonomy.

---

## Constraints

- No new files, no composer dependencies.
- Category creation errors are soft-fail: post publishes without category rather than aborting.
- Existing pages/posts (created before this change) are not retroactively updated.
