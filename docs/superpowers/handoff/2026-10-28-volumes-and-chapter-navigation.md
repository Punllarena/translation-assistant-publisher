# Handover: volumes and chapter navigation (1.6.0)

## Summary

Implementation of the 1.6.0 plan on branch `opencode/volumes-chapter-navigation`.
In progress.

## Tasks

| Task | State | Commit | Tests added |
|---|---|---|---|
| 1. Test harness | done | f3bf0fe | `tests/test-smoke.php` |
| 2. Characterisation of 1.5.5 | done | see `git log` | `tests/test-publish-legacy.php`, `tests/test-illustrations-legacy.php` |

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

## Builder's calls

## Problems

## Not verified

## Review pointers

## Final state
