# Translation Assistant Publisher

WordPress plugin that receives chapter translation exports from [Translation Assistant]([https://github.com/Punllarena/ta-python](https://github.com/Punllarena/Translation-Assistant)) via REST API and publishes them as:

- A **Page** containing the translated chapter body (Gutenberg-formatted, with prev/next navigation)
- A **Post** linking to that chapter page (series announcement)
- A self-updating **Index page** with a static HTML Table of Contents

---

## Requirements

- WordPress 6.0+
- PHP 8.0+

---

## Installation

1. Clone or copy the `translation-assistant-publisher/` folder into `wp-content/plugins/`
2. Activate in **WP Admin → Plugins**
3. **Enable pretty permalinks:** WP Admin → Settings → Permalinks → select **Post name** → Save. Required for clean chapter URLs (`/series-slug-c1/` instead of `?page_id=X`).

---

## Configuration

Go to **WP Admin → Settings → TA Publisher** to manage API keys.

- Each key maps to one WordPress author
- Keys are stored as SHA-256 hashes — the raw key is shown only once on generation
- Multiple keys supported (one per translator/author)

---

## REST Endpoint

**`POST /wp-json/ta-publisher/v1/publish`**

### Payload

```json
{
  "api_key": "your-key-here",
  "series_title": "Sword of the Wanderer",
  "series_slug": "sword-of-the-wanderer",
  "series_title_short": "SotW",
  "series_link": "https://novel18.syosetu.com/n4903fq/",
  "chapter_index": 1,
  "chapter_title": "The Forest Road",
  "chapter_body": "<p>Translated paragraph one.</p><p>Translated paragraph two.</p>",
  "first_line": "Translated paragraph one."
}
```

| Field | Required | Notes |
|---|---|---|
| `api_key` | Yes | Generated from settings page |
| `series_title` | Yes | Full series title |
| `series_slug` | Yes | URL-safe identifier |
| `series_title_short` | Yes | Used in post title (e.g. "SotW Chapter 1") |
| `series_link` | Yes | Link to original source |
| `chapter_index` | Yes | `0` = synopsis, `1`+ = chapters |
| `chapter_title` | Yes | Chapter title |
| `chapter_body` | Yes | HTML content |
| `first_line` | When `chapter_index > 0` | Used as post excerpt |

### Special case: `chapter_index = 0` (Synopsis)

Populates the series Index page with the source title link, synopsis body, HR separator, and an empty Table of Contents. No chapter page or post is created.

### Response

```json
{
  "status": "ok",
  "page_url": "https://yoursite.com/sword-of-the-wanderer-c1/",
  "post_url": "https://yoursite.com/sotw-chapter-1/",
  "created": true
}
```

`"created": false` on duplicate submission (idempotent — safe to retry).

### Error codes

| Code | Reason |
|---|---|
| 400 | Missing required field or invalid JSON body |
| 401 | Invalid API key |
| 500 | WordPress post creation failed |

---

## Chapter Page Format

Each chapter page is stored as Gutenberg blocks:

```
[previous_page] / [next_page]  ← navigation shortcodes (resolved by a separate plugin)
─────────────────────────────
Chapter content paragraphs...
─────────────────────────────
[previous_page] / [next_page]
```

`[previous_page]` and `[next_page]` are literal shortcode strings — register them with a separate plugin of your choice.

---

## Index Page ToC

The Table of Contents is written as static HTML into the Index page content. It survives plugin removal — all content remains readable even if the plugin is deactivated or deleted.

---

## Uninstall

Deleting the plugin from WP Admin removes the `ta_publisher_keys` option. All published pages and posts are preserved.
