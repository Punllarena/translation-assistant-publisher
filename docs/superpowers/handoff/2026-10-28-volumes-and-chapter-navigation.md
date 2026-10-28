# Handover: volumes and chapter navigation (1.6.0)

## Summary

Implementation of the 1.6.0 plan on branch `opencode/volumes-chapter-navigation`.
In progress.

## Tasks

| Task | State | Commit | Tests added |
|---|---|---|---|
| 1. Test harness | done | f3bf0fe | `tests/test-smoke.php` |
| 2. Characterisation of 1.5.5 | done | see `git log` | `tests/test-publish-legacy.php`, `tests/test-illustrations-legacy.php` |
| 3. Error statuses | done | 74477de | `tests/test-errors.php` |
| 4. `TAP_Toc` region/parse | done | 8ebb380 | `tests/test-toc-parse.php` |
| 5. `TAP_Toc` placement | done | 988d86c | `tests/test-toc-place.php` |
| 6. Publisher uses `TAP_Toc` | done | df343e1 | `tests/test-publish-toc.php` |
| 7. Chapter labels | done | bdcedb2 | `tests/test-labels.php` |
| 8. Illustrations page | done | 204ca2a | `tests/test-illustrations.php` |
| 9. Chapter navigation | done | see `git log` | `tests/test-navigation.php` |

## Deviations

- `composer.lock` is committed (the plan's `git add` list omits it) so every
  container run installs the same dependency versions without re-resolving.
- Added `roots/wordpress-core-installer` to `require-dev`: the WordPress core
  installer plugin is needed for `extra.wordpress-install-dir` to work.
- `tests/bootstrap.php` also requires `tests/class-tap-testcase.php`; PHPUnit
  only auto-loads `test-*.php` files, so the base class would otherwise be
  undefined.
- `tests/entrypoint.sh` passes `--skip-ssl` to the MariaDB client: the MySQL 8
  image presents a self-signed certificate that the default client rejects.
- `tap_validate_fields()` takes `array &$data` rather than `array $data` so it
  can normalise digit strings to ints for the publisher, as Task 3 requires.
- Status checking normalised in `tap_error_response()`: a `status` key in the
  error data wins; `renumber_conflict` → 422, `no_gallery` → 404, otherwise 500.
  No publisher WP_Error sets `status` yet, so the map is by code.
- Task 4's tests were written before the implementation but not run red
  (the plan's step 2); the class and tests were added in one edit. Later tasks
  run red first.
- Content and titles passed to `wp_insert_post`/`wp_update_post` are now
  `wp_slash()`ed (ToC content, index head, chapter/teaser titles and teaser
  content). WordPress unslashes these arrays; without this a literal `\` in a
  volume or chapter title was silently dropped. This surfaced in Task 6's
  `test_special_chars_volume_end_to_end`; 1.5.5 had the same bug.
- `handle_synopsis()` now returns a `page_missing` WP_Error if the index page
  disappeared between lookup and update (it previously assumed it existed).
- On re-publish the publisher passes a resolver that only accepts pages whose
  `post_parent` is the index page, as the plan specifies; `TAP_Toc` itself
  stays resolver-agnostic.
- The teaser "Read …" link text is now `esc_html()`ed (with a label it is
  reader-supplied text; the old numeric text needed no escaping). The
  reader-facing page title is built by one `reader_page_title()` helper used
  by both create and update paths.
- `/status` conflicts are detected with `get_param('chapter') !== null`, so
  `chapter=0&illustrations=1` is a 400 as the spec says (not by an integer
  comparison, which would have treated 0 as absent).
- The gallery page title is `"{volume} Illustrations"` (space, no em dash);
  existing galleries keep their slug and are renamed on the next replace.
- `TAP_Navigation::strip` is registered at **priority 8**, not the plan's 9.
  `do_blocks` is also on `the_content` at 9 and `default-filters.php` registers
  it before plugins, so at 9 the legacy block markup has already been rendered
  and the exact `LEGACY_NAV_BLOCK` string no longer exists. Priority 8 still
  runs before `wpautop` (10) and `do_shortcode` (11).
- The navigation filters also skip `is_feed()` and `doing_filter(
  'get_the_excerpt' | 'the_excerpt' )`: an auto-excerpt on a singular page
  runs `the_content` inside the loop, and Review Focus 5 requires no bar there.
- `TAP_Publisher::LEGACY_NAV_BLOCK` is public (the plan asks for it);
  `convert_to_blocks()` no longer writes it, so a re-published chapter is
  cover + separator + body + separator.

## Builder's calls

- Harness: PHPUnit 9.6 with `wp-phpunit/wp-phpunit` 6.6 and
  `roots/wordpress-no-content` installed under `vendor/wordpress`; the repo
  root is bind-mounted at `/app`, MySQL runs in the existing `db` service and
  the entrypoint creates `wordpress_test` if needed.
- ToC parsing uses offset-captured regexes (`PREG_OFFSET_CAPTURE`) merged by
  document offset; the ToC region starts just after the last start marker (or
  last `<hr />`) and ends at the first END marker.
- A legacy `<ul class="ta-toc">` link that a re-publish wants to move is
  rewritten in place instead: this class did not create the list item and
  removing it would leave an empty `<li>`. Legacy pages only have a leading
  section, so ordering is effectively unaffected.
- An order-only move (a re-publish without `volume_title` that is out of
  order) re-inserts the entry into the section it was already in; a move with
  a `volume_title` re-inserts into that volume's section. A missing link is
  placed in the leading section when no volume is sent.
- Insertion whitespace is `"\n\n"`-separated block splicing; extra blank
  lines can accumulate after a move (invisible in HTML), but a same-place
  rewrite replaces only the anchor, byte for byte.
- Navigation cache: `_tap_toc_cache` stores `['hash' => md5(post_content),
  'entries' => [...]]`; reads re-parse on any hash change. Visibility is
  filtered after `_prime_post_caches()`, so a scheduled page going live needs
  no cache invalidation.
- `TAP_Toc::parse`'s chapter `index` is never used for navigation order; the
  index page's `post_name` is passed as the series slug.
- Bar markup: flex row with `.tap-nav__prev`, `<details class="tap-nav__picker">`
  and `.tap-nav__next`; empty slots are empty `<span>`s. The picker groups by
  `<span class="tap-nav__heading">` labels and `<ul class="tap-nav__items">`,
  marks the current link with `aria-current="page"`, and has no newlines.
  Glyphs are `‹ Previous` / `Next ›`; group labels are spans, not `<p>`, so
  wpautop cannot inject `<p>` inside the bar.
- Fallback order is `menu_order title` over published child pages, as 1.5.5's
  old navigation plugin did.

## Problems

## Not verified

## Review pointers

## Final state
