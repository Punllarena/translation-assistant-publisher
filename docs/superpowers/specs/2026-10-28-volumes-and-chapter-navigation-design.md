# Volumes, labels and chapter navigation (plugin 1.6.0) — Design

**Date:** 2026-10-28
**Status:** Ready for planning
**Repos:** this plugin (`translation-assistant-publisher`), and the web app `ta-web`
(`C:\dev\ta-web`, the web port of Translation Assistant). Most of the work is here; the ta-web
half is in its own section at the end and is planned separately in that repo.

## Why

ta-web now organises series into volumes. Each volume can have an **Illustrations** page that should
open the volume, and chapters can be renumbered and moved between volumes. The plugin was written
for a flat, series-wide chapter list, so on a multi-volume series:

- the illustrations page is titled "{volume} — Illustrations", its table-of-contents (ToC) link
  goes wherever it happened to be published (often after the volume's chapters), and it can't be
  scheduled or looked up by `/status`;
- every illustrations page has `menu_order` 0, so page-order navigation bunches them all up front;
- chapter titles and teaser posts say "Chapter 14" where the reader thinks in "Volume 2 Chapter 3";
- a chapter re-published into another volume keeps its ToC link under the old volume's heading;
- previous/next navigation depends on a third-party plugin, *Next Page, Not Next Post* (2009,
  tested to WP 4.0), which orders sibling pages by `menu_order`, skips anything not published,
  and fills the `[previous_page]` / `[next_page]` shortcodes the plugin writes into every page;
- every failure is HTTP 500, and the plugin has no tests;
- **live bug:** publishing the synopsis (`chapter_index` 0, `handle_synopsis`) rebuilds the whole
  index page from `build_index_content`, leaving an *empty* ToC. After the user edits a series
  summary, ta-web marks it pending and sends it as chapter 0 just before the next chapter or
  illustrations publish of that series (`api/wp_summary.py`, `api/publish_job.py`), so every
  summary edit wipes every ToC
  link, volume heading and illustrations link on the site. Under D the ToC is the navigation, so it
  would also remove the bar from every chapter.

The user's verdict: the ToC page itself looks fine. What they want is correct volume ordering,
per-volume chapter labels, a proper chapter navigation bar like ta-web's editor status bar
(previous · chapter picker · next, top and bottom of each chapter), proper error codes and tests.

## Goals

- **A. Illustrations page:** titled "{volume_title} Illustrations"; listed first under its volume's
  ToC heading; schedulable; visible to `/status`.
- **B. Chapter labels:** an optional `chapter_label` ("Volume 2 Chapter 3") replaces the bare
  series-wide number in what readers see. Page addresses never change.
- **C. Errors and tests:** meaningful HTTP statuses; a PHPUnit suite.
- **D. Chapter navigation:** the plugin draws its own navigation bar on every chapter and
  illustrations page, ordered by the series' ToC page, replacing *Next Page, Not Next Post*.
- **E. Synopsis keeps the ToC:** publishing chapter 0 rewrites only the part of the index page above
  the ToC.
- One release, **1.6.0**, with README payload, status and error tables updated.

## Non-goals

- No change to page addresses (slugs) of any page, existing or new. `chapter_index` stays the
  series-wide number and stays the key for lookup, renumber, passwords and the unlock chain.
- No bulk relabelling. Existing chapters pick up a label only when they are re-published.
- No rewrite of the ToC page's look or of headings already on it (renaming a volume still does not
  rewrite its published heading; ta-web freezes the name WordPress gets at first publish).
- No settings UI for the navigation bar (no on/off switch, no styling options).
- Volume-name collisions (two volumes sending the same `volume_title`) are ta-web's job, not this spec.

## Anti-goals

- Breaking a link anyone has bookmarked.
- A "next" link that leads to a page the visitor can't open (scheduled, draft, trashed).
- Breaking the desktop Translation Assistant, which still uses this plugin and will never send the
  new fields. Every new field is optional; without it the plugin behaves exactly as 1.5.5.
- Returning HTTP 409. ta-web (inherited from the desktop) reads a 409 from `/publish` as "already
  published, nothing to do" and marks the request done. A real failure sent as 409 would be silently
  swallowed.

## Constraints

- WordPress 6.0+, PHP 8.0+ (unchanged).
- The ToC page content is static HTML that must stay readable with the plugin deactivated.
- Request bodies must stay under ~1 MB (the host's proxy limit); nothing here adds large fields.
- `includes/class-publisher.php` is already 672 lines; new work goes in new classes
  (e.g. `class-toc.php`, `class-navigation.php`) rather than growing it.

---

## A. Illustrations page

### Title

`publish_illustrations` titles the page `"{volume_title} Illustrations"` (e.g. "Volume 2
Illustrations"). Without `volume_title`, `"{series_title_short} Illustrations"` as today. A `replace`
publish of an existing gallery renames it to the new form. The slug is unchanged
(`{series}-illustrations-{sanitize_title(volume_title)}`), so existing galleries are found as before.

### ToC position

The illustrations link is placed **immediately after its volume's `<h3>` heading**, ahead of the
volume's first chapter link, regardless of publish order. Link text stays "Illustrations" (it sits
under the volume heading; the page title carries the volume name).

- If the volume heading exists: insert right after it (if the link is not already there).
- If it doesn't: create the volume section (heading + link) in its proper place — see "Placing a new
  volume section" below.
- A re-publish (`replace` on an existing gallery) does not add a second link. If the link is missing
  (deleted by hand), it is re-added. If it is present but not directly under the heading, it is moved
  there.

### `menu_order`

*My call (differs from what was talked through):* the illustrations page gets `menu_order` =
`first_chapter_index` when sent. When absent: 0 on create (as today), and left as it is on a
`replace` of an existing gallery. Chapter pages use `menu_order` =
series-wide chapter index, so there is no integer strictly between Volume 1's last chapter and
Volume 2's first; renumbering every existing page to make room is not worth it now that the
navigation (D) no longer orders by `menu_order`. This is a best-effort hint for other plugins/themes
that list child pages; the tie with chapter 1 is accepted.

### Scheduling

`/illustrations` accepts optional `publish_date` (same format and meaning as `/publish`: UTC
`YYYY-MM-DDTHH:MM:SSZ`; present = `future`, absent = publish now, clearing any earlier schedule).
It is applied on `replace` only, through the existing `apply_schedule` with `$is_page = true`;
`append` batches never touch status or date.

### Status

`GET /status` accepts `volume_title` (string) and `illustrations=1` instead of `chapter`. It returns
the same shape (`status` = the gallery page's `post_status` or `not_found`, `post_url`).
`chapter` is then not required. A request with both `chapter` and `illustrations=1` is a 400.

### New request fields (`/illustrations`)

| Field | Required | Notes |
|---|---|---|
| `publish_date` | No | Schedule the gallery (on `replace` only) |
| `first_chapter_index` | No | Series-wide index of the volume's first numbered chapter; used for `menu_order` and for placing a new volume section. Omitted when the volume has no numbered chapter yet |

---

## B. Chapter labels

### New request field (`/publish`)

| Field | Required | Notes |
|---|---|---|
| `chapter_label` | No | e.g. "Volume 2 Chapter 3". Trimmed; blank = absent. Plain text (escaped on output) |

ta-web decides who gets a label (see the ta-web section). The plugin only uses what it's sent.

### Where the label is used

With `chapter_label` present (L), replacing the series-wide index N in reader-facing text:

| Thing | Today | With label |
|---|---|---|
| Chapter page title | `{short} {N} - {chapter_title}` | `{short} {L} - {chapter_title}` |
| Teaser post title | `{short} Chapter {N}` | `{short} {L}` |
| Teaser "Read …" link text | `Read Chapter {N}` | `Read {L}` |

**The ToC link text stays `chapter_title`**, as today — the user's decision after review. Labels
go only to non-syosetu chapters in volumes, in practice EPUB-imported and pasted series, whose
titles come from the book's own table of contents ("Chapter 3: …", "Interlude", "The Forest Road").
The user wants the book's titles kept as they are. The ToC entry already sits under its volume
heading, and the navigation (D) takes its text from the ToC, so it shows the book's title too;
accepted that prev/next may then show titles without numbers.

Without a label, everything is as in 1.5.5.

### Re-publish and the teaser post

On re-publish the page title, teaser title and teaser text are rebuilt from the payload (as today),
so a newly sent label replaces the old "Chapter N" wording. The fallback teaser lookup
(`find_existing_teaser_post`, used when `_tap_linked_post_id` is missing) must look for **both**
`{short} Chapter {N}` and `{short} {L}` titles, so the first relabel of a 1.5.5 chapter doesn't
create a duplicate teaser. The "exactly one match" rule is kept across the combined result.

Accepted gap: the fallback only knows the titles the current request implies. A teaser created under
a *different* earlier label (e.g. "Volume 2 Chapter 1" renumbered to "Volume 2 Chapter 2") or an
earlier number is found only through `_tap_linked_post_id`, which every publish since 1.5.0 writes.
If that meta is missing as well, a duplicate teaser is created, exactly as 1.5.5 does for a renumber.

`chapter_label` and `volume_title` are ignored on a `chapter_index` 0 (synopsis) request.

Renumbering (`previous_chapter_index`) works as today; the label plays no part in lookup.

---

## A + B. ToC placement rules (shared)

These rules live in one ToC class used by `/publish`, `/illustrations`, the synopsis (E) and the
navigation (D). They change only the entry being written; other entries are never reordered.

**The ToC region** (*my call*) runs from the **start marker** to `<!-- ta-toc-end -->`. The start
marker is the last of these found before the end marker: `<div>Table of Contents:</div>` (what
`build_index_content` writes), `<h2>Table of Contents</h2>` (the `append_toc_entry` fallback), or
the opening `<ul class="ta-toc">` (legacy). Anything above it — series title, synopsis — is never
parsed or edited by the ToC rules, so `<h3>`s or links in a synopsis are ignored. If no start marker
is found but the end marker is, the region starts after the last `<hr />` before the end marker; if
there's no `<hr />` either, the region is empty for parsing (the navigation draws nothing) and new
entries are still inserted before the end marker as today. With no end marker at all, today's
fallback (append a fresh `<h2>Table of Contents</h2>` … end marker) stays.

**Identifying a link.** A ToC link is matched to a page by the **page it resolves to**
(`url_to_postid` on its href, kept only if that page's parent is the index page), falling back to
exact string comparison of the `esc_url` forms when it doesn't resolve. So an href written as
`http://` or without a trailing slash, or left over from a site-URL change that WordPress still
resolves, is recognised as the same page and rewritten in place, never duplicated.

**Reading the ToC.** A *section* is an `<h3>` volume heading block and the paragraph-link entries
after it, up to the next heading or the end marker. Entries before the first heading belong to an
unnamed leading section. An entry's *chapter index* is parsed from its link (the page slug
`{series}-c{N}`); illustrations links and links to other pages have none.

**Matching headings** (*my call*): leniently, for both "does this volume's heading exist" and
parsing — any `<h3>` with or without attributes (the block editor rewrites headings as
`<h3 class="wp-block-heading">` when the index page is saved), text compared after
`wp_strip_all_tags` + `html_entity_decode` + trim, case-sensitive. New headings are still written as
today's exact block markup.

**Placing a new volume section.** When a heading for `volume_title` is not on the page yet, the new
section goes **before the first existing section that contains a chapter index greater than the
new entry's index** (for illustrations, `first_chapter_index`). If there is no such section, or no
index to compare (no `first_chapter_index`), it goes at the end, as today.

Limitation: a section, once created, is never moved. A volume section that landed at the end (no
index to compare, or published after a later volume on a pre-1.6.0 page) stays there until the ToC
is edited by hand. ta-web keeps this rare by always sending `first_chapter_index` when the volume has
any numbered chapter in its database, published or not.

**Placing a new chapter entry** inside its section: before the first entry with a higher chapter
index, else at the end of the section. Illustrations links always stay first. Without
`volume_title`, the entry goes in the leading section by the same rule (*my call*; 1.5.5 appended
at the very end, which is the same place on a series without volumes).

**Illustrations without `volume_title`** (the desktop app, a series with no volumes): its link goes
first in the leading section (*my call*).

**Moving on re-publish** (*my call*, extends the walkthrough). On a chapter re-publish the link is
re-placed by the rules above when either:
- it is not in the section of the `volume_title` sent (moved between volumes; creates the section
  if needed), or
- it is out of index order within its section — the previous entry has a higher chapter index or
  the next a lower one. This covers ta-web reordering chapters inside a volume: the renumber renames
  the slug, and the re-publish puts the link in its new place.

A heading left with no entries **stays**. A re-publish without `volume_title` moves a link only for
index order within its current section, never between sections.

**Missing links are re-added** (*my call*). A re-published chapter (or illustrations page) whose
link isn't in the ToC at all — removed by hand, or lost to the 1.5.5 synopsis wipe — gets it back,
placed by the rules above. 1.5.5 never re-added chapter links; under D a missing link means a page
missing from navigation, so re-publish is the repair path.

**No END marker.** The three 1.5.5 shapes are kept: END present → insert inside the region; no END
but a legacy `<ul class="ta-toc">` → insert after its `</ul>` and add END there; neither → append a
fresh `<h2>Table of Contents</h2>`, the entry and END.

**Existing behaviour kept:** an existing link's href and text are rewritten in place on re-publish
and renumber (what `update_toc_entry` did), when it doesn't need to move.

---

## C. Error codes and tests

### Statuses

| Status | When |
|---|---|
| 400 | Invalid JSON, missing field, invalid `mode`, conflicting `/status` params, and these malformed fields: `chapter_index`, `previous_chapter_index`, `unlock_chapter_index`, `first_chapter_index` not an integer ≥ 0 (an int, or a string of digits); `publish_date` not parseable as a date; `chapter_label` / `volume_title` present but not a string |
| 401 | Invalid API key |
| 404 | Something the request names doesn't exist: `append` with no gallery (`no_gallery`) |
| 422 | Renumber conflict (`renumber_conflict`) |
| 500 | WordPress itself failed (insert/update/term/attachment errors) |

**Never 409** (see Anti-goals). `no_gallery` is arguably a conflict, but 409 is taken by ta-web's
"already published" reading, so it is a 404. Body stays `{"error": "<message>"}`; clients show only that field.
Implementation: `WP_Error` codes map to statuses in one place (e.g. `status` in the error data, or a
code→status table in the route handlers); anything unmapped is 500.

### Tests

A PHPUnit suite using WordPress's own test library (`wp-phpunit` / the core test scaffold),
runnable in Docker alongside the existing `docker-compose.yml` (WordPress + MySQL) with one
documented command in the README. The builder picks the exact harness (e.g. `wordpress/env`'s
`wp-env`, or a `phpunit` service with a test database), as long as it needs nothing installed
on the host but Docker.

Order: **first** characterisation tests of 1.5.5 behaviour (publish, re-publish, renumber and its
conflict, password and unlock, schedule and unschedule, synopsis on a fresh index page,
illustrations replace/append,
teaser adoption, ToC heading de-dupe), **then** the new behaviour on top:

- labels in page title, teaser title/text; teaser adoption across old and new titles
- illustrations title, ToC placement (published before and after its chapters), re-publish not
  duplicating, `menu_order`, `publish_date`, `/status` lookup
- ToC: region start markers and fallback; synopsis/`<h3>` above the ToC ignored; lenient heading
  match (block-editor `class` attribute); new volume section placed between existing ones; chapter
  moved between volumes; out-of-order link re-placed after a renumber; missing link re-added
- synopsis publish keeps the ToC intact on all four page shapes, with exactly one start marker and
  one END afterwards (and the legacy wipe is no longer pinned)
- navigation fallback for pages with the legacy block missing from the ToC
- navigation: order from the ToC, grouping, skipping non-public pages, current-page marking,
  old nav block stripped, cache invalidation
- each error status

---

## D. Chapter navigation

Replaces *Next Page, Not Next Post*. The plugin draws a navigation bar when a page is viewed; nothing
about it is stored in page content.

### Which pages

A page gets the bar when its parent is a page whose ToC links to it — i.e. it appears in its parent's
parsed ToC (below). This covers every chapter and illustrations page this plugin ever made,
including pages from before 1.6.0, with no meta needed. The series index page itself gets none.

**Fallback for pages missing from the ToC** (*my call*, after review). A 1.5.5 synopsis publish may
already have wiped a series' ToC, while its chapters still carry the legacy nav block that *Next
Page, Not Next Post* fills. So that deactivating the old plugin doesn't strand those readers: a
page that **contains the legacy nav block** but is not in its parent's parsed ToC gets the bar built
from its published sibling pages (same parent, excluding the index page itself) ordered by
`menu_order` then `post_title` — what the old plugin did — with no grouping (one unnamed section)
and each page's title as link text. Pages without the legacy block and not in the ToC get no bar.
Re-publishing chapters restores their ToC links (see "Missing links are re-added") and with them the
ToC-ordered bar. The README's upgrade note says to check each series' ToC has its links before
deactivating the old plugin, and that missing ones come back on re-publish.
Only on the singular front-end view of that page (`is_singular('page')`, main query, in the loop);
not in feeds, excerpts, REST or admin.

### Order and grouping: the ToC is the source of truth

Parse the parent's ToC region into an ordered list of `{page_id, text, heading}`:

- entries in document order; `heading` = the nearest preceding `<h3>` text (empty for the leading
  section); legacy `<ul class="ta-toc">` `<li>` links count as leading-section entries before the
  paragraph entries, in their order;
- `text` = the link text as it appears;
- links are resolved to page IDs and kept only if the page's `post_parent` is that index page
  (links to anything else are ignored); duplicates keep their first occurrence.

Editing the ToC by hand therefore changes the navigation.

### Visibility

At render time, entries whose page is not `publish` status are dropped for visitors: a scheduled,
draft, pending, private or trashed page never appears in the bar, as previous/next or in the picker.
Password-protected published pages do appear (as today). *My call:* logged-in users who can edit the
page see the same filtered list, not the drafts.

### The bar

Drawn at the top and the bottom of the content, identical:

- **‹ Previous** — link to the previous visible entry; absent (not disabled-looking clutter, just
  an empty slot keeping the layout) on the first.
- **Chapter picker** — shows the current entry's text, and under it the heading (volume). Opens a
  list of every visible entry grouped under its headings, the current one marked
  (`aria-current="page"`). *My call (differs from the walkthrough's `<select>` + script):* built as
  `<details><summary>…</summary><nav>…grouped lists of links…</nav></details>` — it works with no
  JavaScript at all, is real links (crawlable, middle-clickable), and needs no fallback.
- **Next ›** — link to the next visible entry; absent on the last.

If the current page is not in the visible list (e.g. an editor previewing a draft), no bar is drawn.

The two bars are separate landmarks with distinct labels ("Chapter navigation, top" / "Chapter
navigation, bottom"). Markup is semantic and namespaced (`.tap-nav`, `.tap-nav__prev`, …). A small stylesheet, enqueued
only on pages that get the bar, lays it out (flex row, wraps on narrow screens, picker list scrolls
with a max height) and uses `currentColor` / `inherit` so the theme supplies colours and fonts. No
images, no external assets. The builder chooses exact spacing and the arrow glyphs.

### Hooking in

Two `the_content` callbacks:

1. At priority **9** (before `wpautop` at 10 and `do_shortcode` at 11): remove the stored legacy
   nav block — the exact `NAV_BLOCK` string 1.5.5 writes, both copies — and nothing else. The
   separators stay (*my call*). Remove only that exact string; never touch other content. Record
   whether it was there (for the fallback above).
2. At priority **20** (after `wpautop`, so it never turns the bar's markup into `<br />`s or stray
   `<p>`s): prepend and append the bar.

Because the old block is stripped before shortcodes run, it doesn't matter whether *Next Page, Not
Next Post* is still active; the README and release notes tell the user to deactivate it.

New publishes (create and re-publish) stop writing `NAV_BLOCK`; the body becomes cover (if any) +
separator + body + separator. An untouched old page minus `NAV_BLOCK` is exactly that, so old and
re-published pages render the same: bar, cover, rule, body, rule, bar.

Illustrations pages have no `NAV_BLOCK` and no separators; they get the bar above and below the
gallery.

### Cache

Parsing the ToC on every view is wasteful. Store the parsed list (IDs, text, headings — before the
visibility filter) on the index page as post meta, with a hash of the index page's `post_content`.
On read, a hash mismatch means re-parse and re-store, so any edit to the ToC — by the plugin or by
hand — invalidates it without hooks. Visibility is checked per view after priming the post cache
for the listed IDs in one query (`_prime_post_caches`), so scheduled pages going live appear
without any invalidation.

---

## E. Synopsis keeps the ToC

`handle_synopsis` (chapter 0) rewrites only what lies **above the ToC**. Exact byte rule (after
review), with *head* = `build_index_content(...)` cut just before its `<div>Table of Contents:</div>`
(title heading, synopsis body, `<hr />`):

- **`<div>Table of Contents:</div>` or `<h2>Table of Contents</h2>` found** (the last one before
  END): new content = *head* + the old content **from that marker onward**, byte for byte. The
  marker and END appear exactly once.
- **Legacy `<ul class="ta-toc">` found** (no `<div>`/`<h2>` marker): new content = *head* + the old
  content from the `<ul …>` onward. (No marker is added; the region rule already starts at the `<ul>`.)
- **No marker, but END found** (hand-edited page): new content = *head* +
  `<div>Table of Contents:</div>\n` + the old content after the last `<hr />` before END (or, with
  no `<hr />`, nothing) + END, so existing entries carry over.
- **Neither** (fresh index page): `build_index_content(...)` in full, as today.

Response shape unchanged. README: the "Special case: chapter_index = 0" section no longer says
"an empty Table of Contents".

Pages already wiped by 1.5.5 aren't repaired automatically; re-publishing chapters re-adds their
links (see "Missing links are re-added").

---

## Compatibility and release

- Every new request field is optional; old clients (desktop TA, ta-web before its update) get
  1.5.5 behaviour except: the new illustrations title, ToC placement rules, the navigation bar,
  no `NAV_BLOCK` on new publishes, the synopsis no longer wiping the ToC, and the new error statuses (4xx instead of 500 for the cases
  listed — both clients already show the `error` field for any non-2xx).
- Version 1.6.0 in the plugin header. README: payload tables for `/publish` (`chapter_label`) and
  `/illustrations` (`publish_date`, `first_chapter_index`), the `/status` illustrations form, the
  error table, the "Chapter Page Format" section (navigation is drawn by this plugin now; deactivate
  *Next Page, Not Next Post*), and how to run the tests.

---

## ta-web side (planned separately in `C:\dev\ta-web`)

Recorded here so both halves are designed together. ta-web's `docs/TODO.md` entries "WordPress
plugin: volume Illustrations page first" and "WordPress: volume-aware chapter indexing" are
replaced by this spec (point them at it when the ta-web work lands).

1. **`chapter_label`** in `build_payload` (`api/core/wp.py`, fed from `build_chapter` in
   `api/publish.py`). Sent only when the series is **not** syosetu-sourced (`syosetu_url` empty) and
   the chapter is **in a volume**. Value: `f"{volume.wp_name} Chapter {n}"`, where `n` is the
   chapter's 1-based position among the volume's chapters in `series_order`, not counting the
   Illustrations chapter. Loose chapters ("No volume"), series without volumes, and syosetu series
   send no label. *My call:* uses `wp_name` (frozen at first publish) so the label matches the ToC
   heading.
2. A changed label alone does **not** trigger the re-publish confirmation (409 `confirm_republish`).
3. **Illustrations payloads** (`build_illustrations_payloads`, `api/illustrations.py` `upload`) add
   `first_chapter_index` — the series-wide `chapter_number` of the volume's first non-Illustrations
   chapter in the database, published or not; omitted only if the volume has none — and `publish_date` on the first (`replace`) batch when scheduled.
4. **Batch publish** gives the Illustrations chapter a schedule slot like any chapter (today it takes
   none: `api/routers/wp.py` around line 173, `api/publish_job.py` around line 52). Single publish of
   the Illustrations chapter may offer a date the same way chapters do (builder's call in ta-web).
   Write-back records `wp_status = "future"` and `wp_scheduled_for` when scheduled.
5. **Status refresh** stops skipping the Illustrations chapter (`api/routers/wp.py` around line 90):
   `wp_http.check_status` gains the illustrations form (`volume_title` = the volume's `wp_name`,
   `illustrations=1`).
6. The plugin's new 4xx statuses need no change (ta-web shows the `error` field of any error).
   Optional cleanup, not required: `wp_http._send`'s `ok_409` handling exists only for the desktop
   behaviour and no plugin version returns 409.
7. Older plugins ignore the new fields, so ta-web can ship before or after the plugin.

## Left to the builder

- Exact class split and names in the plugin; the PHPUnit harness; HTML parsing approach for the ToC
  (`WP_HTML_Tag_Processor` / DOM / regex), as long as it handles the block-comment markup the
  plugin writes and the legacy `<ul class="ta-toc">` form.
- Navigation CSS details and glyphs.
- How `WP_Error` codes carry their status.
