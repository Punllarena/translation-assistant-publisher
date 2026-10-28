# Handover: volumes and chapter navigation (1.6.0)

## Summary

Implemented plugin 1.6.0 on branch `opencode/volumes-chapter-navigation`
(based on `claude/volumes-chapter-nav`): volume-aware ToC placement in a new
`TAP_Toc`, optional chapter labels, illustrations pages that open their volume,
scheduling and `/status` lookup for them, a synopsis publish that keeps the
ToC, HTTP 400/401/404/422/500 statuses, and a built-in chapter navigation bar
that replaces *Next Page, Not Next Post*. All ten tasks are done; the only
skipped step is Task 10's manual browser check (see Not verified).

## Tasks

| Task | State | Commit | Tests added |
|---|---|---|---|
| 1. Test harness | done | f3bf0fe | `tests/test-smoke.php` |
| 2. Characterisation of 1.5.5 | done | fb6ebd1 | `tests/test-publish-legacy.php`, `tests/test-illustrations-legacy.php` |
| 3. Error statuses | done | 74477de | `tests/test-errors.php` |
| 4. `TAP_Toc` region/parse | done | 8ebb380 | `tests/test-toc-parse.php` |
| 5. `TAP_Toc` placement | done | 988d86c | `tests/test-toc-place.php` |
| 6. Publisher uses `TAP_Toc` | done | df343e1 | `tests/test-publish-toc.php` |
| 7. Chapter labels | done | bdcedb2 | `tests/test-labels.php` |
| 8. Illustrations page | done | 204ca2a | `tests/test-illustrations.php` |
| 9. Chapter navigation | done | 8f09400 | `tests/test-navigation.php` |
| 10. Docs and version | done | 4e89b8b | — |

## Deviations

Everything below differs from the plan or spec, or is something the plan left
to the builder that is worth reviewing.

1. **Harness extras.** `composer.lock` is committed (not in the plan's `git add`
   list) so container runs are reproducible; `roots/wordpress-core-installer`
   was added to `require-dev` because `extra.wordpress-install-dir` needs it;
   `tests/bootstrap.php` also requires `tests/class-tap-testcase.php` (PHPUnit
   only auto-loads `test-*.php`); `tests/entrypoint.sh` passes `--skip-ssl` to
   the MariaDB client because the MySQL 8 image's self-signed certificate is
   rejected by default; `.gitattributes` forces LF for `*.sh` so a Windows
   checkout does not break the entrypoint copied into the Linux image.
2. **`tap_validate_fields()` takes `array &$data`** (`translation-assistant-publisher.php:57`)
   rather than `array $data`, so valid digit strings can be normalised to ints
   for the publisher. `tap_error_response()` reads a `status` key from the
   error data first, then maps `renumber_conflict` → 422, `no_gallery` → 404,
   else 500; no publisher error currently sets `status`.
3. **`wp_slash()` retrofit** (`class-publisher.php`: ToC writes, index head,
   index creation, chapter/teaser titles, teaser and illustration content).
   `wp_insert_post`/`wp_update_post` unslash their input; without this a literal
   `\` in a volume/chapter title was silently dropped. Found by Task 6's
   `test_special_chars_volume_end_to_end`; 1.5.5 had the same bug. This is the
   broadest behavioural change outside the new features (visible only for
   backslashes).
4. **Navigation strip runs at priority 8, not 9** (`class-navigation.php:18`).
   `do_blocks` also runs on `the_content` at priority 9 and `default-filters.php`
   registers it before plugins, so at 9 the legacy block markup has already
   been rendered and the exact `LEGACY_NAV_BLOCK` string no longer exists.
   Priority 8 still runs before `wpautop` (10) and `do_shortcode` (11).
5. **Extra navigation guards** (`class-navigation.php:223`): besides
   `is_singular('page') && in_the_loop() && is_main_query()` and
   `get_the_ID() === get_queried_object_id()`, it also skips `is_feed()` and
   `doing_filter('get_the_excerpt')` / `doing_filter('the_excerpt')`. An
   auto-excerpt on a singular page runs `the_content` inside the loop, and
   Review Focus 5 requires no bar there.
6. **Legacy `<li>` links are rewritten, not moved.** If a re-publish finds its
   link inside a legacy `<ul class="ta-toc">` list item (no paragraph block to
   remove), `TAP_Toc::upsert` rewrites href/text in place instead of leaving an
   empty `<li>`. Legacy pages only have a leading section, so ordering is
   effectively unaffected.
7. **Order-only moves stay in the current section** (`class-toc.php:383`):
   a re-publish without `volume_title` that is out of order is re-inserted into
   the section it was already in; a move with a `volume_title` goes to that
   volume. A link that is entirely missing is placed in the leading section
   when no volume is sent.
8. **Gallery title** is `"{volume} Illustrations"` with a space (the spec's
   form), replacing 1.5.5's em dash; galleries keep their slug and are renamed
   by the next `replace`.
9. **`/status` conflict detection** uses `get_param('chapter') !== null`
   (`translation-assistant-publisher.php:136`), so `chapter=0&illustrations=1`
   is a 400 as specified. The old code's `(int)` check would have treated 0 as
   absent.
10. **`handle_synopsis()`** (`class-publisher.php:611`) returns a
    `page_missing` WP_Error if the index page disappears between lookup and
    update; previously the ID was assumed to exist.
11. **`class-publisher.php` is 722 lines** (672 before). The plan's global
    constraint asks not to grow it, but Tasks 6–8 explicitly put `write_toc`,
    `label`, `reader_page_title` and `get_illustrations_status` there; the old
    `append_toc_entry`/`update_toc_entry` (79 lines) were removed to offset.
    All string/ToC logic still lives in `TAP_Toc`.
12. **Task 4's tests were written before the class but not run red** (the plan's
    step 2); the class and tests landed in one edit. Tasks 5–9 were run red
    first.
13. **README documents `volume_title` on `/publish`** in addition to the
    fields the plan asked for; it existed in 1.5.5 but was undocumented and is
    central to volume placement.

## Builder's calls

- Harness: PHPUnit 9.6 + `wp-phpunit/wp-phpunit` 6.6 +
  `roots/wordpress-no-content` under `vendor/wordpress`; the repo root is
  bind-mounted at `/app`, the existing `db` service provides MySQL, and the
  entrypoint creates `wordpress_test` if missing. Command:
  `docker compose --profile test run --rm phpunit`.
- ToC parsing: offset-captured regexes (`PREG_OFFSET_CAPTURE`) merged by
  document offset, rather than DOM/`WP_HTML_Tag_Processor`. The region starts
  just after the last start marker (or last `<hr />`) and ends at the first
  END marker. Text/headings are decoded with
  `html_entity_decode( wp_strip_all_tags(...), ENT_QUOTES | ENT_HTML5 )`.
- The resolver passed by the publisher wraps `url_to_postid` and keeps an ID
  only when `post_parent === $index_id`; `TAP_Toc` itself only cares that the
  callable returns an int or 0.
- Insertion is `"\n\n"`-separated block splicing. A same-place rewrite replaces
  only the anchor (byte for byte elsewhere); a move can leave extra blank
  lines (invisible in HTML).
- Navigation cache: `_tap_toc_cache` = `['hash' => md5(post_content),
  'entries' => [...]]`; any edit re-parses on the next view. Visibility is
  checked after `_prime_post_caches()`, so a scheduled page going live needs no
  invalidation. `TAP_Toc::parse`'s `index` is not used for order.
- Bar markup: flex row with `.tap-nav__prev`, `<details class="tap-nav__picker">`
  and `.tap-nav__next`; empty slots are empty `<span>`s. The picker uses
  `<span class="tap-nav__heading">` labels and `<ul class="tap-nav__items">`
  (no `<p>`, so wpautop cannot inject one), marks the current link with
  `aria-current="page"`, and contains no newlines. Glyphs: `‹ Previous`,
  `Next ›`. CSS uses flex/wrap, `max-height` + `overflow:auto` for the list,
  and inherits colours/fonts.
- Fallback order is `menu_order title` over published child pages, matching the
  old plugin. Fallback is used only when the current page carries the legacy
  block and is missing from the parsed ToC.
- The stylesheet is enqueued with handle `tap-nav` on singular pages whose
  parent has a non-empty ToC.

## Problems

- **Plan's navigation priority 9 is too late.** `do_blocks` is also on
  `the_content` at 9 and is registered first by `default-filters.php`; the
  legacy block is rendered before a priority-9 callback runs. Fixed by using
  priority 8 (deviation 4). No other plan step failed.
- **WordPress unslashes post data.** Passing unslashed content to
  `wp_insert_post`/`wp_update_post` drops literal backslashes; the end-to-end
  special-character test caught it. Fixed with `wp_slash()` (deviation 3).
- Test-only issue: `get_pages( [ 'child_of' => … ] )` returned `false` under
  the harness; replaced with `get_posts( [ 'post_parent' => … ] )`.
- Test-only issue: the navigation helper originally stopped at the picker's
  nested `</nav>`; it now counts nested `<nav>` depth.
- Nothing else was abandoned or worked around.

## Not verified

- **Task 10 Step 3, the manual browser check on the dev site, was skipped**
  (no browser interaction in this run). It still needs a human: publish one
  chapter with 1.5.5 so a pre-1.6.0 page exists, switch to this branch, publish
  two volumes plus an illustrations page, then check the bar top/bottom, the
  grouped picker, and an old page with the old plugin deactivated.
- CSS rendering and the enqueued stylesheet path are not covered by tests.
- The container runs PHP 8.2; PHP 8.0 compatibility was checked by review
  (no enums, `readonly`, `never`, `array_is_list`, first-class callables,
  initialiser `new`, or intersection types were introduced).
- Real ta-web/desktop payloads, real `url_to_postid` behaviour after a site-URL
  change, and the old plugin still being active alongside this one are
  simulated only by the tests.
- Password form rendering is simulated with `get_the_content()`; the front-end
  template was not rendered.

## Review pointers

Most worrying first:

1. `plugins/translation-assistant-publisher/includes/class-toc.php:440`
   (`insert_entry`) and `:160` (`upsert`) — placement/removal boundaries are
   string-offset heuristics. Hand-edited ToC shapes (plain `<p>`, unusual
   whitespace, repeated headings) are the least certain area; the tests cover
   the block markup the plugin writes plus a legacy `<ul>`.
2. `plugins/translation-assistant-publisher/includes/class-navigation.php:18`
   and `:45` — the priority-8 deviation from the plan and its dependence on
   `do_blocks` ordering. Worth confirming on a real site with the old plugin
   both active and deactivated.
3. `plugins/translation-assistant-publisher/includes/class-navigation.php:86`
   — cache/hash and `url_to_postid` resolution. Drifted hrefs are tested for
   `https` and no trailing slash, but not for a changed site URL.
4. `plugins/translation-assistant-publisher/includes/class-publisher.php:573`
   — `write_toc` and the `wp_slash` retrofit. The slash change touches every
   title/content write; it is correct API usage but broader than the plan's
   wording.
5. `plugins/translation-assistant-publisher/translation-assistant-publisher.php:57`
   — `tap_validate_fields`: `first_chapter_index` is validated on `/publish`
   too (harmless, unused), an empty `publish_date` is accepted as absent, and
   `null` for the integer fields is rejected.

## Final state

```
$ git log --oneline claude/volumes-chapter-nav..HEAD
4e89b8b docs: 1.6.0 README and version
8f09400 feat: built-in chapter navigation from the ToC
204ca2a feat: illustrations page title, placement, schedule and status
bdcedb2 feat: optional chapter_label in page and teaser titles
df343e1 fix: synopsis keeps the ToC; chapters placed and moved by TAP_Toc
988d86c feat: TAP_Toc placement, moves and synopsis head swap
8ebb380 feat: TAP_Toc region, heading match and parsing
74477de fix: 404/422 statuses for missing gallery and renumber conflict
fb6ebd1 test: characterise 1.5.5 publish and illustrations behaviour
f3bf0fe test: PHPUnit harness in Docker
```

(One further commit records this final state in the handover.)

Final full-suite output: `OK (94 tests, 448 assertions)`.

`wc -l` of every PHP file under `plugins/`:

```
214 plugins/translation-assistant-publisher/translation-assistant-publisher.php
  6 plugins/translation-assistant-publisher/uninstall.php
 40 plugins/translation-assistant-publisher/includes/class-auth.php
231 plugins/translation-assistant-publisher/includes/class-navigation.php
722 plugins/translation-assistant-publisher/includes/class-publisher.php
101 plugins/translation-assistant-publisher/includes/class-settings.php
500 plugins/translation-assistant-publisher/includes/class-toc.php
```
