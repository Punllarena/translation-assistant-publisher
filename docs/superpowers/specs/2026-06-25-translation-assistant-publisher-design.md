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
  "series_link": "https://novel18.syosetu.com/n4903fq/",
  "chapter_index": 5,
  "chapter_title": "The Forest Road",
  "chapter_body": "<p>...</p>",
  "first_line": "The road wound through ancient cedars..."
}
```

### Required Fields

All fields are required except `first_line` (only required when `chapter_index > 0`). Missing a required field returns `400 "Missing field: {field}"`.

### Special Case: `chapter_index = 0` (Synopsis)

When `chapter_index` is `0`, the submission populates the Index page itself — no child page and no post are created. `first_line` is ignored. The Index page content is set to:

```html
<div style="text-align: center;">
  <a href="{series_link}"><strong>{series_title}</strong></a>
</div>

{chapter_body}

<hr />

<div>Table of Contents:</div>
<ul class="ta-toc"></ul>
```

If the Index page already has content (e.g. synopsis was sent before), it is replaced entirely. Response returns `{"status": "ok", "page_url": "{index_page_url}", "post_url": null, "created": true}`.

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

- `chapter_index=0`: if Index page already exists and has content, replace synopsis section, return `"created": false`
- `chapter_index>0`: if page with slug `{series_slug}-c{chapter_index}` already exists → skip all creation, return existing URLs with `"created": false`

### Index Page

- `post_type=page`, `post_status=publish`
- Title: `{series_title}`
- Slug: `{series_slug}`
- Author: mapped user from API key
- Seeded by `chapter_index=0` submission (see Special Case above)
- If a page with slug `{series_slug}` already exists, plugin adopts it as the Index page rather than creating a new one
- If no Index page exists when a `chapter_index>0` submission arrives (synopsis was skipped), plugin creates a bare Index page with just the ToC block and source header using available payload fields

### ToC Append

Finds `<ul class="ta-toc">` in Index page content, appends:
```html
<li><a href="{chapter_page_url}">Chapter {chapter_index} — {chapter_title}</a></li>
```
Updates via `wp_update_post()`. HTML remains in page content if plugin is removed.

If `<ul class="ta-toc">` is not found (e.g. user edited Index page), appends the full ToC block fresh at end of content:
```html
<h2>Table of Contents</h2>
<ul class="ta-toc">
  <li><a href="{chapter_page_url}">Chapter {chapter_index} — {chapter_title}</a></li>
</ul>
```

### Chapter Page

- `post_type=page`, `post_status=publish`
- `post_parent`: Index page ID
- Title: `{chapter_title}`
- Slug: `{series_slug}-c{chapter_index}` (e.g. `sword-of-the-wanderer-c5`)
- Author: mapped user from API key
- Content: plugin converts `chapter_body` into Gutenberg block format:

```
<!-- wp:html -->
<div id="textbox">
<p class="alignleft">[previous_page]</p>
<p class="alignright">[next_page]</p>
</div>
<!-- /wp:html -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

{each paragraph of chapter_body wrapped in <!-- wp:paragraph -->}

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:html -->
<div id="textbox">
<p class="alignleft">[previous_page]</p>
<p class="alignright">[next_page]</p>
</div>
<!-- /wp:html -->
```

`[previous_page]` and `[next_page]` are literal shortcode strings — a separate plugin resolves them. Each `<p>` in `chapter_body` becomes one `<!-- wp:paragraph -->` block. Non-`<p>` HTML is wrapped in `<!-- wp:html -->` blocks.

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
