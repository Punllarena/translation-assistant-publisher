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
| 6. Publisher uses `TAP_Toc` | done | see `git log` | `tests/test-publish-toc.php` |

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

## Problems

## Not verified

## Review pointers

## Final state
