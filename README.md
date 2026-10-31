# Translation Assistant Publisher

WordPress plugin that receives chapter translation exports from [Translation Assistant](https://github.com/Punllarena/Translation-Assistant) via REST API and publishes them as:

- A **Page** containing the translated chapter body (Gutenberg-formatted, with a built-in previous/next navigation bar)
- A **Post** linking to that chapter page (series announcement)
- A self-updating **Index page** with a static HTML Table of Contents (ToC), volume headings and an optional **Illustrations** page per volume

---

## Requirements

- WordPress 6.0+
- PHP 8.0+

---

## Installation

1. Clone or copy the `translation-assistant-publisher/` folder into `wp-content/plugins/`
2. Activate in **WP Admin → Plugins**
3. **Enable pretty permalinks:** WP Admin → Settings → Permalinks → select **Post name** → Save. Required for clean chapter URLs (`/series-slug-c1/` instead of `?page_id=X`) and for the navigation bar to resolve ToC links.

---

## Configuration

Go to **WP Admin → Settings → TA Publisher** to manage API keys.

- Each key maps to one WordPress author
- Keys are stored as SHA-256 hashes — the raw key is shown only once on generation
- Multiple keys supported (one per translator/author)

All endpoints are under `/wp-json/ta-publisher/v1/`.

---

## `POST /publish`

### Payload

```json
{
  "api_key": "your-key-here",
  "series_title": "Sword of the Wanderer",
  "series_slug": "sword-of-the-wanderer",
  "series_title_short": "SotW",
  "series_link": "https://novel18.syosetu.com/n4903fq/",
  "volume_title": "Volume 2",
  "chapter_index": 14,
  "chapter_title": "The Forest Road",
  "chapter_label": "Volume 2 Chapter 3",
  "chapter_body": "<p>Translated paragraph one.</p><p>Translated paragraph two.</p>",
  "first_line": "Translated paragraph one."
}
```

| Field | Required | Notes |
|---|---|---|
| `api_key` | Yes | Generated from settings page |
| `series_title` | Yes | Full series title |
| `series_slug` | Yes | URL-safe identifier |
| `series_title_short` | Yes | Series short title used in page and post titles (e.g. "SotW Volume 2 - The Forest Road") |
| `series_link` | No | Link to the original source; EPUB-imported series have none |
| `volume_title` | No | Volume heading used on the index page; the name is frozen once published |
| `chapter_index` | Yes | `0` = synopsis, `1`+ = chapters; the series-wide number and the lookup key |
| `previous_chapter_index` | No | The index this chapter was last published at, when it has been renumbered |
| `chapter_title` | Yes | Chapter title (also the ToC link text) |
| `chapter_label` | No | Marks the chapter as sitting in a volume, which puts `volume_title` in the reader-facing titles. Trimmed; blank = absent; plain text |
| `chapter_body` | Yes | HTML content |
| `first_line` | When `chapter_index > 0` | Used as the teaser post's opening line |
| `password` | No | Protects the chapter page |
| `unlock_chapter_index` | No | Removes protection from that chapter |
| `publish_date` | No | UTC `YYYY-MM-DDTHH:MM:SSZ`; schedules the chapter; omitting it publishes now and clears an earlier schedule |

### Page and post titles

The chapter page and its teaser post share one title: the series short title, the volume when the chapter sits in one, and the chapter's own title.

| Chapter | Page title | Teaser title | Teaser "Read …" link |
|---|---|---|---|
| in `Volume 2` | `SotW Volume 2 - The Forest Road` | `SotW Volume 2 - The Forest Road` | `Read The Forest Road` |
| no volume | `SotW - The Forest Road` | `SotW - The Forest Road` | `Read The Forest Road` |

The series-wide chapter number and `chapter_label` never appear in a title. `chapter_label` is still sent and is the signal that the chapter sits in a volume: when it is present, and `volume_title` is too, the volume joins the title. ta-web sends it only for chapters of non-syosetu series that sit in a volume, so the desktop app's and syosetu payloads get the no-volume form. A payload with no usable `chapter_title` falls back to `Chapter {chapter_index}` after the volume, so the title never ends in a bare separator.

The ToC link text stays `chapter_title`, and page addresses never change (a chapter page is looked up by its `{series}-c{index}` slug, never by title). Re-publishing rebuilds the titles from the payload. The fallback teaser lookup accepts the old `SotW Chapter 14` and `SotW Volume 2 Chapter 3` titles as well as the new form, so the first re-publish after upgrading renames the existing teaser instead of duplicating it.

`chapter_label` and `volume_title` are ignored on a synopsis request (`chapter_index` 0).

### Special case: `chapter_index = 0` (Synopsis)

Rewrites only the part of the index page above the Table of Contents: the source title link, synopsis body and `<hr />`. The ToC itself — links, volume headings and illustrations links — is kept byte for byte, so editing a series summary no longer wipes navigation. On a fresh index page the full page is created as before. No chapter page or post is created. The response carries both `created` and `updated` keys (exactly one is `true`), matching chapter responses.

### Republish behaviour

When republishing (chapter already exists):

- The chapter **page title**, **body**, **opening line** (teaser), and **category** are updated from the payload. A page or teaser published by an earlier version is retitled to the current form (`SotW Volume 2 - The Forest Road`, or `SotW - The Forest Road` without a volume).
- The **teaser post** link, title, content, and category are updated to reflect the chapter's current content.
- The ToC link is rewritten in place, moved to the right volume or re-ordered after a renumber. A link that was removed by hand is re-added.
- If `password` is supplied, it is applied to the chapter page; if omitted, any existing password protection is preserved. Use `unlock_chapter_index` to remove protection.
- If `publish_date` is supplied, the chapter is scheduled; if omitted, any existing schedule is cleared and the chapter is published immediately.

### Response

```json
{
  "status": "ok",
  "page_url": "https://yoursite.com/sword-of-the-wanderer/sword-of-the-wanderer-c14/",
  "post_url": "https://yoursite.com/?p=123",
  "created": true,
  "updated": false
}
```

All responses include both `created` and `updated` keys — exactly one is always `true`. On first publish, `created` is `true`. Republishing an already-published chapter sets `created` to `false` and `updated` to `true`; the live page/post are overwritten with the client's current content.

---

## `POST /illustrations`

Publishes the illustrations gallery for one volume. The first batch (`replace`) creates or replaces the gallery; later batches (`append`) add images to it.

### Payload

```json
{
  "api_key": "your-key-here",
  "series_title": "Sword of the Wanderer",
  "series_slug": "sword-of-the-wanderer",
  "series_title_short": "SotW",
  "volume_title": "Volume 2",
  "mode": "replace",
  "images": [
    { "filename": "01.png", "mime": "image/png", "data_base64": "...", "position": 0 }
  ],
  "cover": { "filename": "cover.png", "mime": "image/png", "data_base64": "..." },
  "publish_date": "2030-01-01T00:00:00Z",
  "first_chapter_index": 14
}
```

| Field | Required | Notes |
|---|---|---|
| `api_key`, `series_title`, `series_slug`, `series_title_short` | Yes | As for `/publish` |
| `volume_title` | No | Selects the gallery; without it the series-wide gallery is used |
| `mode` | No | `replace` (default) or `append`; `append` needs an earlier `replace` gallery |
| `images` | Yes, unless `cover` is sent | Array of `filename`, `mime`, `data_base64`, `position` |
| `cover` | No | One image placed first |
| `publish_date` | No | Applied on `replace` only; schedules the gallery or, when omitted, publishes it now. `append` never changes status or date |
| `first_chapter_index` | No | Series-wide index of the volume's first numbered chapter. Sets `menu_order` and places a new volume section. Omit when the volume has no numbered chapter yet |

The page is titled `"{volume_title} Illustrations"` (or `"{series_title_short} Illustrations"` without a volume) and keeps the slug `{series}-illustrations-{sanitized volume}` (or `{series}-illustrations`), so existing galleries are found and renamed in place. Its ToC link is always the first entry under the volume heading; a re-publish moves it there, does not duplicate it and re-adds it if it was deleted by hand.

`menu_order` is `first_chapter_index` when sent. Without it, a new gallery gets `0`; a `replace` of an existing gallery leaves the current value alone.

---

## `GET /status`

Two forms, both returning `{"status": "<post_status>|not_found", "post_url": "<url>|null"}`:

```
/wp-json/ta-publisher/v1/status?api_key=…&series_slug=sword-of-the-wanderer&chapter=14
/wp-json/ta-publisher/v1/status?api_key=…&series_slug=sword-of-the-wanderer&chapter=0
/wp-json/ta-publisher/v1/status?api_key=…&series_slug=sword-of-the-wanderer&illustrations=1&volume_title=Volume%202
```

`illustrations=1` replaces `chapter`; `volume_title` is optional (no volume = the series-wide gallery). Sending both `chapter` and `illustrations=1` is a 400. With `illustrations=1` and no `chapter`, `chapter` is not required.

---

## Error codes

All error responses have the body `{"error": "<message>"}`.

| Code | Reason |
|---|---|
| 400 | Invalid JSON, missing required field, invalid `mode`, conflicting `/status` params, or a malformed optional field: `chapter_index` / `previous_chapter_index` / `unlock_chapter_index` / `first_chapter_index` not an integer ≥ 0 (an int or a string of digits), `publish_date` not parseable as a date, `chapter_label` / `volume_title` present but not a string |
| 401 | Invalid API key |
| 404 | Something the request names doesn't exist — `append` with no gallery (`no_gallery`) |
| 422 | Renumber conflict: `chapter_index` and `previous_chapter_index` both resolve to existing but different chapter pages. Neither page is modified |
| 500 | WordPress itself failed (insert/update/term/attachment errors) |

The plugin **never returns 409**.

---

## Chapter Page Format

Each chapter page is stored as Gutenberg blocks:

```
[cover image, if any]
─────────────────────────────
Chapter content paragraphs...
─────────────────────────────
```

The previous/next **navigation bar is drawn by this plugin at view time** — it is not stored in the page. It is ordered by the series index page's ToC, shows the current chapter and a grouped chapter picker, and appears at the top and bottom of every chapter and illustrations page. Scheduled, draft, private and trashed pages are never linked. Password-protected published chapters still get the bar around WordPress's password form.

Pages published by 1.5.5 contain a stored `[previous_page]` / `[next_page]` block. The plugin strips that block on display and uses the page's place in the ToC (or, when the ToC link is missing, its sibling page order) for navigation. **Deactivate *Next Page, Not Next Post* once the ToC links are in place** — the plugin no longer needs it.

---

## Index Page ToC

The Table of Contents is written as static HTML into the Index page content. It survives plugin removal — all content remains readable even if the plugin is deactivated or deleted.

- A volume section is an `<h3>` heading and the links after it. New sections are placed before the first section with a higher chapter index, or at the end.
- Within a section, chapter links are ordered by `chapter_index`; illustrations links stay first.
- Re-publishing, renumbering and moving a chapter between volumes update only that chapter's link.
- Editing the ToC by hand changes the navigation order immediately.
- If a chapter or illustrations page is missing from the ToC, re-publishing it adds the link back.

---

## Upgrading to 1.6.0

- Check each series' index page still lists its chapters before deactivating *Next Page, Not Next Post*. Pages that still contain the old navigation block keep navigating by page order until then; re-publishing a chapter (or its illustrations page) restores a missing ToC link and the ToC-ordered bar.
- New publishes no longer write the `[previous_page]` / `[next_page]` block. Existing pages are cleaned up when displayed, not rewritten.
- A synopsis publish (`chapter_index` 0) now keeps the existing ToC.
- The 1.6.0 changes:
  - volume-aware ToC placement, including chapter moves between volumes
  - illustrations pages titled "{volume} Illustrations", first under their volume, schedulable and visible to `/status`
  - optional `chapter_label` marking a volume chapter, and chapter page and teaser post titles that carry the volume and the chapter's own title instead of the chapter number
  - synopsis publishes keep the ToC
  - meaningful HTTP statuses (400/401/404/422/500; never 409)
  - built-in chapter navigation bar replacing *Next Page, Not Next Post*
  - a PHPUnit suite runnable with Docker only

---

## Running tests

From the repository root, with Docker running:

```sh
docker compose --profile test run --rm phpunit
```

Narrow to one test with `--filter`:

```sh
docker compose --profile test run --rm phpunit --filter TAP_Toc
```

The suite uses WordPress's test library (`wp-phpunit`) against the `db` service and creates/uses the `wordpress_test` database. The `db` and `wordpress` services hold the dev site; the test run does not touch that data. Nothing needs to be installed on the host beyond Docker.

---

## Uninstall

Deleting the plugin from WP Admin removes the `ta_publisher_keys` option and the `_tap_toc_cache` post meta. All published pages and posts are preserved.
