# Volumes, Labels and Chapter Navigation (1.6.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship plugin 1.6.0: volume-aware ToC placement, illustrations pages that open their volume, optional per-volume chapter labels, a synopsis publish that keeps the ToC, proper HTTP statuses, a built-in chapter navigation bar replacing *Next Page, Not Next Post*, and a PHPUnit suite.

**Architecture:** A new pure-string `TAP_Toc` class owns everything about the index page's ToC (region, parsing, heading match, placing/moving entries, synopsis head swap); `TAP_Publisher` calls it instead of its own `append_toc_entry`/`update_toc_entry`. A new `TAP_Navigation` class renders the bar from `TAP_Toc::parse` output on view through a `the_content` filter, cached on the index page by content hash. Errors map to statuses in one helper in the main plugin file.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, PHPUnit 9.6 with `wp-phpunit/wp-phpunit` and `yoast/phpunit-polyfills`, run in Docker against the existing MySQL service.

**Spec:** `docs/superpowers/specs/2026-10-28-volumes-and-chapter-navigation-design.md` — read it alongside this plan; this plan does not repeat its rules.

All paths below are relative to the repo root `translation-assistant-publisher/`; `P/` means `plugins/translation-assistant-publisher/`.

## Global Constraints

- PHP 8.0+, WordPress 6.0+ (plugin header unchanged); host needs only Docker to run tests.
- Every new request field is optional; without it behaviour equals 1.5.5 except the items listed under "Compatibility and release" in the spec.
- Never return HTTP 409. Statuses: 400 bad request / conflicting `/status` params, 401 bad key, 404 `no_gallery`, 422 `renumber_conflict`, 500 anything else. Body stays `{"error": "<message>"}`.
- No slug (page address) ever changes because of a label; `chapter_index` stays the lookup key.
- ToC rules change only the entry being written; never reorder other entries.
- Escape all output (`esc_html`, `esc_url`, `esc_attr`); labels are plain text.
- New code goes in new classes; do not grow `class-publisher.php` past its current 672 lines net.
- Version string `1.6.0`.

## Review Focus

1. Titles with `$`, `\`, `&`, quotes or Japanese text in `volume_title`, `chapter_title` or `chapter_label` — heading matching, placement and rendered text must be exact (no backreference mangling, no double-escaping). Test in Task 4 and Task 5.
2. Two series whose slugs are prefixes of each other (`foo` and `foo-bar`) — a `foo-bar-c3` link must never count as chapter 3 of `foo`. Test in Task 4.
3. A password-protected chapter shows the bar around WordPress's password form, so readers can still move on. Test in Task 9.
4. ToC hrefs that differ from `get_permalink` in form (no trailing slash, `http` vs `https`) still resolve for navigation. Test in Task 9.
5. `the_content` run for something other than the main queried page (a widget, an excerpt, a REST render) gets no bar. Test in Task 9.

---

## File Structure

| File | Responsibility |
|---|---|
| `composer.json`, `phpunit.xml.dist` (repo root) | dev deps and PHPUnit config |
| `tests/Dockerfile`, `tests/bootstrap.php`, `tests/wp-tests-config.php` | test runtime |
| `tests/class-tap-testcase.php` | base case: API key, REST helpers |
| `tests/test-*.php` | one file per area (named in tasks) |
| `docker-compose.yml` (modify) | add `phpunit` service under profile `test` |
| `P/translation-assistant-publisher.php` (modify) | routes, validation, `tap_error_response`, require new classes, version |
| `P/includes/class-toc.php` (create) | `TAP_Toc`: pure string operations on index-page content |
| `P/includes/class-publisher.php` (modify) | uses `TAP_Toc`; labels; illustrations; synopsis; no `NAV_BLOCK` |
| `P/includes/class-navigation.php` (create) | `TAP_Navigation`: filter, cache, render |
| `P/assets/nav.css` (create) | bar styles |
| `P/uninstall.php` (modify) | delete `_tap_toc_cache` meta |
| `README.md` (modify) | tables, page format, tests |

---

### Task 1: Test harness

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `tests/Dockerfile`, `tests/bootstrap.php`, `tests/wp-tests-config.php`, `tests/class-tap-testcase.php`, `tests/test-smoke.php`
- Modify: `docker-compose.yml`, `.gitignore` (add `vendor/`, `.phpunit.result.cache`)

**Interfaces:**
- Produces: `class TAP_TestCase extends WP_UnitTestCase` with
  - `protected string $key;` and `protected int $author;` (set in `set_up`: an editor user + `TAP_Auth::generate_key('test', $author)`)
  - `protected function post_json(string $route, array $body): WP_REST_Response` — `rest_do_request` on `/ta-publisher/v1{$route}`, `api_key` merged in
  - `protected function get_status(array $params): WP_REST_Response`
  - `protected function chapter(array $over = []): array` — a valid `/publish` body (`series_title` "Test Series", `series_slug` "test-series", `series_title_short` "TS", `chapter_index` 1, `chapter_title` "Chapter 1: Start", `chapter_body` "<p>One.</p><p>Two.</p>", `first_line` "One.") merged with `$over`
  - `protected function index_page(string $slug = 'test-series'): WP_Post`
- Run command used by every later task: `docker compose --profile test run --rm phpunit` (append `--filter <name>` to narrow).

- [ ] **Step 1: Write `composer.json`** with `require-dev`: `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills ^2.0`, `wp-phpunit/wp-phpunit 6.6.*`, `roots/wordpress-no-content 6.6.*` (core files for `ABSPATH`); `config.allow-plugins` for `roots/wordpress-core-installer`; `extra.wordpress-install-dir` = `vendor/wordpress`.
- [ ] **Step 2: Write the runtime.** `tests/Dockerfile`: `php:8.2-cli` + `mysqli` extension + composer; `WORKDIR /app`; entrypoint `sh -c "composer install -q && vendor/bin/phpunit $@"`. `docker-compose.yml` gains service `phpunit` (profile `test`, build `tests/`, volume `.:/app`, depends on `db`, env `WP_PHPUNIT__TESTS_CONFIG=/app/tests/wp-tests-config.php`). `tests/wp-tests-config.php`: `ABSPATH` = `/app/vendor/wordpress/`, DB `wordpress_test` on `db` (root/rootpass), `WP_TESTS_DOMAIN` example.org, table prefix `wptests_`. The config creates the `wordpress_test` database if missing (mysqli `CREATE DATABASE IF NOT EXISTS`). `tests/bootstrap.php`: load wp-phpunit's `includes/functions.php`, `tests_add_filter('muplugins_loaded', fn() => require '/app/plugins/translation-assistant-publisher/translation-assistant-publisher.php')`, set permalink structure `/%postname%/` in `set_up` of the base case, then wp-phpunit's `includes/bootstrap.php`. `phpunit.xml.dist`: bootstrap above, testsuite `tests/` with prefix `test-`.
- [ ] **Step 3: Write `tests/test-smoke.php`**

```php
public function test_publish_creates_page_and_post() {
    $r = $this->post_json( '/publish', $this->chapter() );
    $this->assertSame( 200, $r->get_status() );
    $this->assertTrue( $r->get_data()['created'] );
    $this->assertNotNull( get_page_by_path( 'test-series/test-series-c1', OBJECT, 'page' ) );
}
```

- [ ] **Step 4: Run** `docker compose --profile test run --rm phpunit` — Expected: `OK (1 test`.
- [ ] **Step 5: Commit** `git add composer.json phpunit.xml.dist tests docker-compose.yml .gitignore && git commit -m "test: PHPUnit harness in Docker"`

---

### Task 2: Characterisation tests of 1.5.5

Pins today's behaviour before anything changes. All tests must pass against unmodified 1.5.5 code.

**Files:**
- Create: `tests/test-publish-legacy.php`, `tests/test-illustrations-legacy.php`
- Consumes: `TAP_TestCase` (Task 1)

- [ ] **Step 1: Write tests** (`test-publish-legacy.php`), each asserting on stored posts, not just the response:
  - `test_republish_updates_not_duplicates` — publish twice; `created` false/`updated` true; one page, one teaser post in category "TS".
  - `test_renumber_moves_slug` — publish c3, then publish `chapter_index` 4 with `previous_chapter_index` 3: page now at `test-series-c4`, `menu_order` 4, ToC href updated.
  - `test_renumber_conflict_rejected_unchanged` — c3 and c4 exist; publish 4 with previous 3 → non-200, both pages untouched. (Status asserted as `!== 200` here; Task 3 pins 422.)
  - `test_password_applied_and_unlock_clears` — publish c2 with `password`, then c3 with `unlock_chapter_index` 2 → c2 `post_password` ''.
  - `test_schedule_then_unschedule` — `publish_date` 2030-01-01T00:00:00Z → page and post `future`; republish without date → `publish`.
  - `test_synopsis_on_fresh_index` — `chapter_index` 0 body "<p>Blurb</p>" on a new series: index content contains Blurb, `Table of Contents:` and `<!-- ta-toc-end -->`.
  - `test_teaser_adopted_when_meta_missing` — publish c1, delete `_tap_linked_post_id`, republish → still one teaser post, meta restored.
  - `test_volume_heading_written_once` — c1 and c2 with `volume_title` "Volume 1" → exactly one `<h3>Volume 1</h3>`.
- [ ] **Step 2: Write tests** (`test-illustrations-legacy.php`): replace creates gallery under index with one ToC "Illustrations" link; second replace keeps one link and replaces attachments; append without gallery → non-200; cover-only replace accepted. Use a 1×1 PNG base64 fixture constant in the file.
- [ ] **Step 3: Run** the suite — Expected: all pass.
- [ ] **Step 4: Commit** `git commit -m "test: characterise 1.5.5 publish and illustrations behaviour"`

---

### Task 3: Error statuses

**Files:**
- Modify: `P/translation-assistant-publisher.php`
- Create: `tests/test-errors.php`

**Interfaces:**
- Produces: `function tap_error_response( WP_Error $e ): WP_REST_Response` — status from `$e->get_error_data()['status']` if set, else map `renumber_conflict` → 422, `no_gallery` → 404, anything else → 500; body `['error' => $e->get_error_message()]`. Both handlers use it instead of their inline 500.

- [ ] **Step 1: Write tests:** `test_renumber_conflict_is_422`, `test_append_without_gallery_is_404`, `test_missing_field_is_400`, `test_bad_key_is_401`, `test_no_response_is_409` (loop over the cases above, assert none is 409).
- [ ] **Step 2: Run** — Expected: 422/404 tests FAIL (got 500).
- [ ] **Step 3: Implement `tap_error_response`** and use it in `tap_handle_publish` and `tap_handle_illustrations`.
- [ ] **Step 4: Run** — Expected: all pass (Task 2's `!== 200` tests still pass).
- [ ] **Step 5: Commit** `git commit -m "fix: 404/422 statuses for missing gallery and renumber conflict"`

---

### Task 4: `TAP_Toc` — region, headings, parsing

Pure functions on strings; tests need no REST calls (still run under `WP_UnitTestCase` for `esc_html` etc.).

**Files:**
- Create: `P/includes/class-toc.php`, `tests/test-toc-parse.php`
- Modify: `P/translation-assistant-publisher.php` (require it)

**Interfaces:**
- Produces (all `public static`):
  - `TAP_Toc::END = '<!-- ta-toc-end -->'`
  - `region( string $content ): array{start:int,end:int}|null` — byte offsets of the parse region per the spec's "The ToC region" (start = just after the last start marker before END; fallback last `<hr />`; null when empty or no END).
  - `heading_matches( string $h3_inner_html, string $volume_title ): bool` — lenient rule from spec.
  - `chapter_index_from_href( string $href, string $series_slug ): ?int` — matches path ending `/{series_slug}-c{N}` with optional trailing slash, anchored so `foo` never matches `foo-bar-c3`.
  - `is_illustrations_href( string $href, string $series_slug ): bool` — path ends `/{series_slug}-illustrations` or `/{series_slug}-illustrations-{x}`, optional slash.
  - `parse( string $content, string $series_slug ): array` — list of `['href'=>string,'text'=>string,'heading'=>string,'index'=>?int,'illustrations'=>bool]` in document order; `text` and `heading` decoded plain text; legacy `<ul class="ta-toc">` `<li>` links first with heading ''; duplicates by href keep the first.

- [ ] **Step 1: Write tests:**
  - `test_region_after_div_marker`, `test_region_after_h2_marker`, `test_region_after_legacy_ul`, `test_region_falls_back_to_last_hr`, `test_region_null_without_end_marker`.
  - `test_synopsis_h3_and_links_ignored` — synopsis above the marker has `<h3>Volume 9</h3>` and a link to `/test-series/test-series-c9/`; `parse` returns only the ToC's entries.
  - `test_heading_match_block_editor_class` — `heading_matches('Volume 1', 'Volume 1')` and the inner HTML from `<h3 class="wp-block-heading">Volume 1</h3>` true; `'Volume 10'` vs `'Volume 1'` false.
  - `test_heading_match_special_chars` — title `Vol $1 \& "Ω" 第二巻`: `esc_html` of it matches; entity-encoded form matches.
  - `test_index_from_href` — `https://x/test-series/test-series-c12/` → 12; no trailing slash → 12; `.../foo-bar-c3/` with slug `foo` → null.
  - `test_parse_sections_and_order` — fixture with leading entry, `Volume 1` (illustrations + c1 + c2), `Volume 2` (c3) → five entries with headings, indexes and the illustrations flag as built.
- [ ] **Step 2: Run** `--filter TAP_Toc_Parse` — Expected: FAIL, class not found.
- [ ] **Step 3: Implement** in `class-toc.php`. Parse with `preg_match_all` over the region for `<h3[^>]*>(.*?)</h3>` and `<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>` in offset order (`PREG_OFFSET_CAPTURE`), merging the two match lists by offset; decode with `html_entity_decode( wp_strip_all_tags( $x ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )`, trimmed.
- [ ] **Step 4: Run** — Expected: pass.
- [ ] **Step 5: Commit** `git commit -m "feat: TAP_Toc region, heading match and parsing"`

---

### Task 5: `TAP_Toc` — placing, moving and the synopsis head

**Files:**
- Modify: `P/includes/class-toc.php`
- Create: `tests/test-toc-place.php`

**Interfaces:**
- Consumes: Task 4's functions.
- Produces:
  - `TAP_Toc::upsert( string $content, string $series_slug, array $entry, ?string $old_href = null ): string` where `$entry = ['href'=>string,'text'=>string,'volume_title'=>string,'index'=>?int,'illustrations'=>bool,'anchor_index'=>?int]` (`anchor_index` = `first_chapter_index` for illustrations, else = `index`). Behaviour, per spec "ToC placement rules":
    1. Find the existing link by `$old_href ?? href` (compare with `esc_url` forms).
    2. If found and in the right section (`volume_title` '' = stay in current section) and in order (illustrations: first in section; chapter: neighbours' indexes not out of order) → rewrite only its href and text in place; return.
    3. Otherwise remove its paragraph block (if found) and insert per placement rules, creating the volume section (heading block in 1.5.5's exact markup) where the spec says.
    4. No END marker and no legacy `<ul>` → append `\n\n<h2>Table of Contents</h2>\n{entry}\n\n<!-- ta-toc-end -->` as 1.5.5 did.
  - `TAP_Toc::entry_block( string $href, string $text ): string` — 1.5.5's paragraph markup.
  - `TAP_Toc::replace_head( string $content, string $new_head ): string` — spec section E: replace everything before the start marker with `$new_head` (which ends with the start marker + END as `build_index_content` builds it; the function keeps the original from the start marker onward); no start marker → `$new_head` with the old fallback-region entries carried in before END.
- All string splicing uses `substr_replace`/concatenation — never a `preg_replace` replacement string built from titles.

- [ ] **Step 1: Write tests** (each builds content, calls `upsert`, asserts on `parse()` output order/headings):
  - `test_new_chapter_appended_in_existing_volume`
  - `test_new_chapter_placed_by_index_within_section` — c1, c3 present; upsert c2 → c1, c2, c3.
  - `test_new_volume_section_between_existing` — sections V1(c1–2), V3(c5); upsert c3 in "Volume 2" → V1, V2, V3.
  - `test_new_volume_section_at_end_without_index`
  - `test_illustrations_first_after_heading` — V2 already has c3, c4; upsert illustrations for V2 → first in V2.
  - `test_illustrations_creates_section_by_anchor_index` — V1(c1–2), V3(c5); illustrations "Volume 2" anchor 3 → new V2 section between.
  - `test_illustrations_without_volume_first_in_leading_section`
  - `test_republish_in_place_updates_text_only` — same section and order: content identical except link text.
  - `test_republish_moves_between_volumes_heading_stays` — c3 moves V1→V2; V1 heading still present.
  - `test_republish_reorders_after_renumber` — in V1 links c1, c3(old href c2 renumbered); upsert with `old_href` → order by index.
  - `test_republish_without_volume_never_changes_section`
  - `test_missing_link_readded`
  - `test_special_chars_round_trip` — title/heading `Vol $1 \& "Ω" 第二巻` inserted and parsed back exactly.
  - `test_replace_head_keeps_toc_bytes` — everything from the marker onward byte-identical.
  - `test_replace_head_without_marker_carries_entries`
- [ ] **Step 2: Run** — Expected: FAIL (`upsert` undefined).
- [ ] **Step 3: Implement** `upsert`, `entry_block`, `replace_head`.
- [ ] **Step 4: Run** — Expected: pass.
- [ ] **Step 5: Commit** `git commit -m "feat: TAP_Toc placement, moves and synopsis head swap"`

---

### Task 6: Publisher uses `TAP_Toc`; synopsis keeps the ToC

**Files:**
- Modify: `P/includes/class-publisher.php` (remove `append_toc_entry`, `update_toc_entry`; `publish`, `handle_synopsis`)
- Create: `tests/test-publish-toc.php`

**Interfaces:**
- Consumes: `TAP_Toc::upsert`, `TAP_Toc::replace_head`.
- Produces: `private function write_toc( int $index_id, string $series_slug, array $entry, ?string $old_href = null ): void` in `TAP_Publisher` — reads the index page, calls `upsert`, saves only when content changed, `error_log`s a `wp_update_post` failure as today.

- [ ] **Step 1: Write tests** (via REST):
  - `test_summary_edit_keeps_toc` — publish c1, c2 in "Volume 1", then chapter 0 → both links and heading still on the index page, blurb updated.
  - `test_chapter_republished_into_other_volume_moves_link`
  - `test_renumbered_chapter_link_reordered`
  - `test_deleted_link_readded_on_republish`
  - `test_out_of_order_publish_lands_in_order` — publish c2 then c1 in one volume → ToC c1, c2.
  - Update `test_synopsis_on_fresh_index` (Task 2) only if its assertions conflict; they shouldn't.
- [ ] **Step 2: Run** — Expected: new tests FAIL.
- [ ] **Step 3: Implement.** In `publish()` new-page path call `write_toc` with the new URL; in the existing-page path call it with `old_href` = the pre-rename permalink (replaces `update_toc_entry`). `handle_synopsis` builds the head with `build_index_content` and applies `TAP_Toc::replace_head` to the current content.
- [ ] **Step 4: Run** full suite — Expected: pass, including Task 2.
- [ ] **Step 5: Commit** `git commit -m "fix: synopsis keeps the ToC; chapters placed and moved by TAP_Toc"`

---

### Task 7: Chapter labels

**Files:**
- Modify: `P/includes/class-publisher.php` (`create_post`, `update_post`, `find_existing_teaser_post`, `create_chapter_page`, `update_chapter_page`)
- Create: `tests/test-labels.php`

**Interfaces:**
- Produces: `private function label( array $data ): ?string` — trimmed `chapter_label` if a non-empty string, else null. Reader text:
  - page title `"{short} {label} - {chapter_title}"` (else `"{short} {N} - …"`)
  - teaser title `"{short} {label}"` (else `"{short} Chapter {N}"`), link text `"Read {label}"` (else `"Read Chapter {N}"`)
  - ToC text unchanged (`chapter_title`).

- [ ] **Step 1: Write tests:** `test_label_in_page_and_teaser_titles`, `test_no_label_matches_1_5_5`, `test_blank_label_ignored`, `test_relabel_on_republish_no_duplicate_teaser` (publish c3 without label, delete `_tap_linked_post_id`, republish with label "Volume 2 Chapter 1" → one teaser titled "TS Volume 2 Chapter 1"), `test_label_escaped` (label `<b>x</b> & $1` appears escaped in teaser content), `test_toc_text_ignores_label`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** `find_existing_teaser_post` queries both titles (two `get_posts` calls, ids merged unique) and keeps the exactly-one rule.
- [ ] **Step 4: Run** — pass.
- [ ] **Step 5: Commit** `git commit -m "feat: optional chapter_label in page and teaser titles"`

---

### Task 8: Illustrations page

**Files:**
- Modify: `P/includes/class-publisher.php` (`publish_illustrations`, `get_chapter_status`), `P/translation-assistant-publisher.php` (`tap_handle_status`, `tap_handle_illustrations` validation)
- Create: `tests/test-illustrations.php`

**Interfaces:**
- Consumes: `write_toc` (Task 6), `apply_schedule` (existing), `tap_error_response` (Task 3).
- Produces: `TAP_Publisher::get_illustrations_status( string $series_slug, string $volume_title ): array` (same shape as `get_chapter_status`).

- [ ] **Step 1: Write tests:**
  - `test_title_volume_illustrations` — "Volume 2 Illustrations"; without volume "TS Illustrations"; a replace on an existing gallery renames from the dash form.
  - `test_toc_first_when_published_after_chapters`, `test_republish_does_not_duplicate_link`, `test_missing_link_readded`.
  - `test_menu_order_first_chapter_index` — sent 5 → `menu_order` 5; absent → 0.
  - `test_publish_date_schedules_replace_only` — replace with date → `future`; append leaves status; replace without date → `publish`.
  - `test_status_lookup` — `GET /status?series_slug=test-series&illustrations=1&volume_title=Volume 2` → `publish` + URL; unknown volume → `not_found`; `chapter=1&illustrations=1` → 400; `illustrations=1` without `chapter` no longer 400.
  - `test_bad_publish_date_is_400`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Title per spec; `write_toc` on every `replace` (upsert handles de-dupe and re-add) with `illustrations` true and `anchor_index` = `first_chapter_index`; `menu_order` set on create and replace when sent; `apply_schedule(..., true)` on replace only; validate `publish_date` parses (`DateTime` in try/catch → 400) in both handlers; status route branches on `illustrations`.
- [ ] **Step 4: Run** — pass.
- [ ] **Step 5: Commit** `git commit -m "feat: illustrations page title, placement, schedule and status"`

---

### Task 9: Chapter navigation

**Files:**
- Create: `P/includes/class-navigation.php`, `P/assets/nav.css`, `tests/test-navigation.php`
- Modify: `P/includes/class-publisher.php` (`convert_to_blocks` drops `NAV_BLOCK`; keep the constant as `LEGACY_NAV_BLOCK` for stripping), `P/translation-assistant-publisher.php` (require + `TAP_Navigation::register()`), `P/uninstall.php` (`delete_post_meta_by_key( '_tap_toc_cache' )`)

**Interfaces:**
- Consumes: `TAP_Toc::parse`, `TAP_Publisher::LEGACY_NAV_BLOCK` (make it `public const`).
- Produces:
  - `TAP_Navigation::register(): void` — `add_filter('the_content', [self::class,'filter'], 9)`; `wp_enqueue_scripts` enqueues `nav.css` only when `self::entries_for_current()` is non-empty.
  - `TAP_Navigation::filter( string $content ): string` — only when `is_singular('page') && in_the_loop() && is_main_query()` and `get_the_ID() === get_queried_object_id()`; strips `LEGACY_NAV_BLOCK` (all copies, exact string); wraps with the bar when the current page is in the visible list.
  - `TAP_Navigation::entries( int $index_id ): array` — list of `['id'=>int,'text'=>string,'heading'=>string]`, from post meta `_tap_toc_cache` = `['hash'=>md5(post_content),'entries'=>…]`, re-parsed on hash mismatch. Series slug for `parse` = the index page's `post_name`. Hrefs resolved with `url_to_postid`; kept only when `post_parent === $index_id`.
  - `TAP_Navigation::visible( array $entries ): array` — `_prime_post_caches( ids )`, keep `post_status === 'publish'`.
  - `TAP_Navigation::render( array $visible, int $current_id ): string` — markup per spec D: `<nav class="tap-nav" aria-label="Chapter navigation">` with `.tap-nav__prev`, `<details class="tap-nav__picker"><summary>` (current text, heading under it) `</summary>` grouped `<ul>`s under heading labels, current link `aria-current="page"`, `.tap-nav__next`; empty prev/next slots kept as empty `<span>`s.

- [ ] **Step 1: Write tests** (set up the global query with `$this->go_to( get_permalink( $id ) )`, then `the_post()` and `apply_filters( 'the_content', … )`):
  - `test_prev_next_follow_toc_not_menu_order` — ToC order illustrations, c1, c2 with `menu_order` 5, 1, 2 → on c1 prev is illustrations, next c2.
  - `test_picker_grouped_with_current_marked`
  - `test_scheduled_and_draft_skipped` — c2 `future` → on c1 next is c3; c2 not in picker.
  - `test_password_protected_page_gets_bar` (Review Focus 3).
  - `test_href_without_trailing_slash_and_http_resolves` (Review Focus 4).
  - `test_no_bar_outside_main_query` — `apply_filters('the_content', …)` for another page while the queried page is c1 → unchanged; excerpt/feed context → unchanged (Review Focus 5).
  - `test_index_page_gets_no_bar`, `test_hand_edited_toc_changes_order` (edit index content → cache hash miss → new order).
  - `test_legacy_nav_block_stripped` — page content built with 1.5.5's markup → no `[previous_page]`, separators kept, bar twice.
  - `test_new_publish_has_no_shortcodes`.
  - `test_link_text_escaped` — chapter title `<script>` in ToC renders escaped.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** class, CSS (flex row, wrap, picker list `max-height` + `overflow:auto`, colours via `currentColor`/`inherit`), wiring, uninstall line.
- [ ] **Step 4: Run** full suite — pass.
- [ ] **Step 5: Commit** `git commit -m "feat: built-in chapter navigation from the ToC"`

---

### Task 10: Docs and version

**Files:**
- Modify: `P/translation-assistant-publisher.php` (header `Version: 1.6.0`), `README.md`

- [ ] **Step 1: Update README:** `/publish` table adds `chapter_label`; new `/illustrations` table rows `publish_date`, `first_chapter_index`; `/status` illustrations form; error table from Global Constraints (note: never 409); synopsis section drops "an empty Table of Contents"; "Chapter Page Format" says the bar is drawn by this plugin and to deactivate *Next Page, Not Next Post*; a "Running tests" section with `docker compose --profile test run --rm phpunit`; a 1.6.0 changes list.
- [ ] **Step 2: Run** full suite once more — Expected: all pass.
- [ ] **Step 3: Manual check** on the dev site (`docker compose up -d`, http://localhost:8080): publish two volumes with an illustrations page via curl or ta-web, view a chapter — bar at top and bottom, picker grouped, the old plugin deactivated.
- [ ] **Step 4: Commit** `git commit -m "docs: 1.6.0 README and version"`
