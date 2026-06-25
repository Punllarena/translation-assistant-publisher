# Translation Assistant Publisher — Design Spec

**Date:** 2026-06-25  
**Status:** Approved

---

## Overview

WordPress plugin that receives chapter translation exports from the Translation Assistant (ta-python) via REST API and publishes them as:
1. A **Page** containing the translated chapter body (child of a series Index page)
2. A **Post** linking to that page (series announcement)
3. Auto-updates the series **Index page ToC** (static HTML, survives plugin removal)

---

## File Structure

```
wp-dev/plugins/
└── translation-assistant-publisher/
    ├── translation-assistant-publisher.php   ← bootstrap, register hooks
    ├── includes/
    │   ├── class-auth.php          ← API key validation, key→author mapping
    │   ├── class-publisher.php     ← page/post creation, ToC append
    │   └── class-settings.php      ← WP admin settings page
    └── uninstall.php               ← clean up DB options on removal
```

---

## REST Endpoint

`POST /wp-json/ta-publisher/v1/publish`

### Request Payload

```json
{
  "api_key": "...",
  "series_title": "Sword of the Wanderer",
  "series_slug": "sword-of-the-wanderer",
  "series_title_short": "SotW",
  "chapter_index": 5,
  "chapter_title": "The Forest Road",
  "chapter_body": "<p>...</p>",
  "first_line": "The road wound through ancient cedars..."
}
```

### Required Fields

All fields are required. Missing any returns `400 "Missing field: {field}"`.

---

## Authentication

- API keys stored in WP options as `ta_publisher_keys` (serialized array)
- Key stored as `sha256` hash; raw key shown once on generation, never persisted
- Structure:
  ```php
  [
    'sha256_of_key' => [
      'user_id' => 3,
      'label'   => 'Pun - Desktop',
      'created' => '2026-06-25'
    ],
  ]
  ```
- Validation: `hash('sha256', $submitted_key)` compared against stored hashes
- Invalid/missing key → `401 "Invalid API key"`

---

## Settings Page

**Location:** WP Admin → Settings → TA Publisher  
**Capability required:** `manage_options`

**Features:**
- Table of active keys: Label | Author | Created | Revoke button
- "Add Key" form: select WP author from dropdown + enter label → generates key, displays raw value once in admin notice

---

## Publisher Logic

### Idempotency Check

Before creating anything, plugin checks if a page with slug `{series_slug}-c{chapter_index}` already exists. If yes → skip all creation, return existing URLs with `"created": false`.

### Index Page (auto-created on first chapter for a series)

- `post_type=page`, `post_status=publish`
- Title: `{series_title}`
- Slug: `{series_slug}`
- Author: mapped user from API key
- Initial content:
  ```html
  <h2>Table of Contents</h2>
  <ul class="ta-toc"></ul>
  ```

### ToC Append

Finds `<ul class="ta-toc">` in Index page content, appends:
```html
<li><a href="{chapter_page_url}">Chapter {chapter_index} — {chapter_title}</a></li>
```
Updates via `wp_update_post()`. HTML remains in page content if plugin is removed.

### Chapter Page

- `post_type=page`, `post_status=publish`
- `post_parent`: Index page ID
- Title: `{chapter_title}`
- Slug: `{series_slug}-c{chapter_index}` (e.g. `sword-of-the-wanderer-c5`)
- Content: `{chapter_body}`
- Author: mapped user from API key

### Accompanying Post

- `post_type=post`, `post_status=publish`
- Title: `{series_title_short} Chapter {chapter_index}`
- Content:
  ```html
  <p>{first_line}</p>
  <p><a href="{chapter_page_url}">Read Chapter {chapter_index}</a></p>
  ```
- Author: mapped user from API key

---

## Response

### Success `200`

```json
{
  "status": "ok",
  "page_url": "https://site.com/sword-of-the-wanderer-c5/",
  "post_url": "https://site.com/sotw-chapter-5/",
  "created": true
}
```

`"created": false` when chapter already existed (idempotent retry).

### Errors

| Condition | HTTP | Body |
|---|---|---|
| Missing/invalid API key | 401 | `{"error": "Invalid API key"}` |
| Missing required field | 400 | `{"error": "Missing field: {field}"}` |
| WP post creation failed | 500 | `{"error": "Failed to create page"}` |

---

## Multi-Author Support

Each API key maps to exactly one WP author. All content published via that key is attributed to that author. Adding a new translator = admin generates new key, assigns to their WP user account. No per-series author config needed.

---

## Uninstall

`uninstall.php` deletes `ta_publisher_keys` option from WP options table. All published pages/posts remain intact.
