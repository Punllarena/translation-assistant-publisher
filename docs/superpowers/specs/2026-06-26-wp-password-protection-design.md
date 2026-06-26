# WordPress Password Protection — Plugin Spec

**Date:** 2026-06-26
**Status:** Ready for implementation

## Overview

The Translation Assistant desktop app now supports per-chapter password protection. When enabled, the app sends two new optional fields in the publish payload:

- `password` — a 12-char alphanumeric string to apply to the new chapter page
- `unlock_chapter_index` — the chapter index whose password should be removed

The plugin must apply the password to the new chapter page on creation and, when instructed, clear the password from an earlier chapter page. Both operations must be non-fatal and idempotent.

## Context: Client-Side Logic

The client (Translation Assistant) handles all the locking policy:

- Chapter 0 (synopsis/index page): never sends `password`
- Chapters 1..N (free window): never sends `password`
- Chapter N+1 and beyond: sends `password`
- When publishing chapter X (X > 2N): also sends `unlock_chapter_index = X − N`
- Password is generated client-side via `secrets.choice` over alphanumerics, 12 chars
- The plugin never stores, generates, or tracks passwords — it only applies and clears them

## New Payload Fields

Both fields are optional. Existing publishes without them are unaffected.

| Field | Type | When present |
|---|---|---|
| `password` | `string` | Apply as `post_password` on the new chapter page |
| `unlock_chapter_index` | `int` | Find that chapter's page and clear its `post_password` |

## Changes Required

### `class-publisher.php`

#### `create_chapter_page()` — apply password

The `$data` array already flows into `create_chapter_page()`. Add `post_password` to the `wp_insert_post` args when present:

```php
public function create_chapter_page( array $data, int $index_id, int $user_id ): int|WP_Error {
    $series_slug = sanitize_title( $data['series_slug'] );
    $chapter_idx = (int) $data['chapter_index'];
    $slug        = "{$series_slug}-c{$chapter_idx}";
    $content     = $this->convert_to_blocks( $data['chapter_body'] );

    $args = [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'post_title'     => $data['chapter_title'],
        'post_name'      => $slug,
        'post_parent'    => $index_id,
        'post_author'    => $user_id,
        'post_content'   => $content,
        'comment_status' => 'open',
        'menu_order'     => $chapter_idx,
    ];

    if ( ! empty( $data['password'] ) ) {
        $args['post_password'] = sanitize_text_field( $data['password'] );
    }

    return wp_insert_post( $args, true );
}
```

#### `unlock_chapter()` — new method

Finds the chapter page by series slug + chapter index and clears its password. Non-fatal: if the page doesn't exist or has no password, silently returns.

```php
public function unlock_chapter( string $series_slug, int $chapter_index ): void {
    $page = $this->chapter_exists( $series_slug, $chapter_index );
    if ( ! $page ) return;
    if ( empty( $page->post_password ) ) return;
    wp_update_post( [
        'ID'            => $page->ID,
        'post_password' => '',
    ] );
}
```

#### `publish()` — wire in unlock

After the `create_chapter_page` / `append_toc_entry` / `create_post` block succeeds, call `unlock_chapter` if the field is present:

```php
// After: $post_id = $this->create_post( ... );
// Before: return [ 'status' => 'ok', ... ];

if ( isset( $data['unlock_chapter_index'] ) ) {
    $this->unlock_chapter( $series_slug, (int) $data['unlock_chapter_index'] );
}
```

The `unlock_chapter` call must be placed **after** a successful chapter creation — do not unlock if `create_chapter_page` or `create_post` returned a `WP_Error`.

## Idempotency and Error Handling

- `create_chapter_page` already returns early via `chapter_exists` check — if the chapter page already exists, `publish()` returns `created: false` without touching the password or unlocking anything.
- `unlock_chapter` is safe to call multiple times — clearing an already-clear `post_password` is a no-op in WordPress.
- A missing `unlock_chapter_index` page (e.g. the chapter was never published) is silently ignored.
- Unlock failures must not affect the HTTP response — the chapter was successfully published regardless.

## Response

No changes to the response shape. The client already handles the success dialog independently using `page_url`, `post_url`, and `created`.

## Security

- `sanitize_text_field()` on the incoming `password` before passing to `wp_insert_post` — prevents injection via the password string.
- `unlock_chapter_index` cast to `int` before use.
- The client sends only alphanumeric passwords (12 chars), but the plugin must not depend on that invariant.

## Testing Scenarios

| Scenario | password sent | unlock_chapter_index sent | Expected |
|---|---|---|---|
| Chapter ≤ N (free window) | no | no | Page published, no password |
| Chapter N+1 (first locked) | yes | no | Page published with post_password set |
| Chapter 2N+1 | yes | yes (=N+1) | Page published with password; chapter N+1 page cleared |
| Re-publish existing chapter | — | — | Returns created:false, no unlock call |
| unlock_chapter_index page missing | yes | yes (bad index) | Chapter created with password; unlock silently no-ops |
| password field empty string | "" | — | Treated as no password (empty guard) |
