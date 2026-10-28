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
| 4. `TAP_Toc` region/parse | done | f5bb5e8 | `tests/test-toc-parse.php` |

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

## Builder's calls

## Problems

## Not verified

## Review pointers

## Final state
