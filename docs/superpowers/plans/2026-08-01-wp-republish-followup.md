# WP Republish Follow-Up: Create/Update Path Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the create/update path divergences the whole-branch review of the republish work found, the worst of which publishes a duplicate teaser post for every legacy chapter on its first republish after upgrade.

**Architecture:** The republish branch (`plugins/translation-assistant-publisher`, v1.5.0) added an update path — `update_chapter_page()`, `update_post()`, `update_toc_entry()` — as a partial mirror of the existing create path. Every finding here is a place the mirror is incomplete: state the create path sets that the update path never touches, or a precondition the create path established that the update path assumes without verifying. The fix direction is consistently *toward parity*: make the update path set everything the create path sets, and derive teaser content the same way rather than patching it with regex.

**Tech Stack:** WordPress plugin, PHP 8.0+, no composer, no external dependencies. Verification is `wp-cli eval` inside Docker plus `curl` against the live REST endpoint — there is no PHPUnit in this repo.

## Provenance

Found by the final whole-branch review of `docs/superpowers/plans/2026-08-01-wp-republish-update.md` (branch `worktree-wp-republish-update`, commits `0f7422d..794e288`). Each task's per-task review passed; these only surfaced when one reviewer read the whole change at once. The originating ledger is `.superpowers/sdd/2026-08-01-wp-republish-update/progress.md` in that worktree.

## ⚠️ Deployment Warning — read before shipping 1.5.0

**Task 1 below is a data-corruption fix. Until it lands, do not deploy v1.5.0 to any site that has chapters published under v1.4.0 or earlier.** On such a site, the first republish of each legacy chapter creates a *second* published teaser post — categorized, in the feed, delivered to subscribers — while orphaning the original. A fresh site, or one whose chapters were all first published under 1.5.0, is unaffected.

## Global Constraints

- Only file changed for Tasks 1-4: `plugins/translation-assistant-publisher/includes/class-publisher.php`. Task 5 additionally touches the plugin header and `README.md`.
- No new files, no composer, no external dependencies.
- All `wp_insert_post` / `wp_update_post` calls must check for `WP_Error` and propagate it — return `WP_Error` from `publish()`, never `false`, never silently swallowed.
- Standalone `true` is a PHP 8.2+ return type; the plugin header declares `Requires PHP: 8.0`. Use `bool|WP_Error`, never `true|WP_Error`.
- Never build a `preg_replace` replacement string from user content — `esc_html()` does not escape `$` or `\`, so `$1` in a chapter title expands as a backreference. Use `preg_replace_callback`.
- Chapter page slug format stays `{series_slug}-c{chapter_index}`.
- Response shape: exactly one of `created` / `updated` is `true`, never both.
- Do not change the `chapter_idx === 0` (synopsis) branch except where Task 4 explicitly says to.

## Environment Setup

The dev stack is a Docker Compose WordPress + MySQL pair. From the worktree root:

```bash
docker compose up -d
```

The container has no `wp` CLI and no outbound network. Install it from the host once per container:

```bash
curl -sSL -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
docker cp /tmp/wp-cli.phar <wordpress-container>:/usr/local/bin/wp
docker exec <wordpress-container> chmod +x /usr/local/bin/wp
```

If the DB is fresh, install WordPress and activate the plugin:

```bash
docker exec <wordpress-container> wp core install --url=http://localhost:8080 \
  --title="WP Dev" --admin_user=admin --admin_password=admin \
  --admin_email=you@example.com --allow-root
docker exec <wordpress-container> wp plugin activate translation-assistant-publisher --allow-root
docker exec <wordpress-container> wp rewrite structure '/%postname%/' --allow-root
```

Generate an API key for the REST tests (the option is a hash map, so mint it through the class, not `update_option`):

```bash
docker exec <wordpress-container> wp eval 'echo TAP_Auth::generate_key("smoke", 1) . "\n";' --allow-root
```

`wp-content/uploads` may be owned by root and block image sideloads. If attachment tests fail with a permissions error:

```bash
docker exec <wordpress-container> chown -R www-data:www-data /var/www/html/wp-content/uploads
```

For multi-line PHP, write the snippet to a file, `docker cp` it in, and use `wp eval-file` — this avoids shell quoting problems that will otherwise eat your time.

---

### Task 1: Adopt the existing teaser post instead of duplicating it

**Severity: Critical. This is the data-corruption fix — do this task first.**

**Files:**
- Modify: `plugins/translation-assistant-publisher/includes/class-publisher.php` — `publish()`, the partial-failure recovery branch

**Interfaces:**
- Consumes: `create_post()`, `update_post()`, `get_or_create_category()` (existing, unchanged)
- Produces: a new private method `find_existing_teaser_post( array $data ): int` returning a post ID or `0`

**Background:** Before v1.5.0, `publish()` wrote `_tap_linked_post_id` only inside `if ( ! empty( $data['password'] ) )`. Chapters published without a password therefore have no such meta. The new update path reads that meta, finds nothing, concludes "the post was never created" and calls `create_post()` — producing a duplicate. The meta's absence means two different things and the code cannot tell them apart, so it must fall back to *looking* for the post.

The teaser post's title is fully determined by the payload: `create_post()` builds it as `$data['series_title_short'] . ' Chapter ' . $chapter_idx`, and files it under the child category named `$data['series_title_short']`. That pair is specific enough to identify it.

- [ ] **Step 1: Add the lookup helper**

Add as a new private method near `create_post()`:

```php
    private function find_existing_teaser_post( array $data ): int {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;
        if ( ! $child_cat ) return 0;

        $found = get_posts( [
            'post_type'   => 'post',
            'post_status' => [ 'publish', 'draft', 'future', 'pending', 'private' ],
            'title'       => $title,
            'category'    => $child_cat,
            'numberposts' => 2,
            'fields'      => 'ids',
        ] );

        // Exactly one unambiguous match is adoptable. Zero means it really was
        // never created; two or more means we cannot tell which is canonical,
        // and guessing would compound the very duplication this prevents.
        return count( $found ) === 1 ? (int) $found[0] : 0;
    }
```

- [ ] **Step 2: Use it in the recovery branch**

In `publish()`, the recovery `else` branch currently reads:

```php
            } else {
                // partial-failure recovery: page exists but the linked post never got created
                $linked_post_id = $this->create_post( $data, $new_url, $user_id );
                if ( is_wp_error( $linked_post_id ) ) return $linked_post_id;
                update_post_meta( $existing->ID, '_tap_linked_post_id', $linked_post_id );
            }
```

Replace with:

```php
            } else {
                // The meta is missing for two different reasons: the post genuinely
                // failed to be created, or the chapter predates 1.5.0, when the meta
                // was only written for password-protected chapters. Look for the post
                // before assuming it does not exist — creating a second one publishes
                // a duplicate to the feed.
                $adopted = $this->find_existing_teaser_post( $data );
                if ( $adopted ) {
                    update_post_meta( $existing->ID, '_tap_linked_post_id', $adopted );
                    $linked_post_id = $adopted;
                    $post_result    = $this->update_post( $linked_post_id, $data, $new_url );
                    if ( is_wp_error( $post_result ) ) return $post_result;
                } else {
                    $linked_post_id = $this->create_post( $data, $new_url, $user_id );
                    if ( is_wp_error( $linked_post_id ) ) return $linked_post_id;
                    update_post_meta( $existing->ID, '_tap_linked_post_id', $linked_post_id );
                }
            }
```

- [ ] **Step 3: Verify — legacy chapter republishes without duplicating**

Write to a file, `docker cp` in, run with `wp eval-file`. Substitute your API key.

```php
<?php
// Simulate a v1.4.0 legacy chapter: published, no password, so no linked-post meta.
$p    = new TAP_Publisher();
$data = [
  "series_title" => "Legacy Series", "series_slug" => "legacy-series",
  "series_title_short" => "LS", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Original.</p>", "first_line" => "Original.",
];
$r = $p->publish( $data, 1 );
$page = get_page_by_path( "legacy-series/legacy-series-c1", OBJECT, "page" );
delete_post_meta( $page->ID, "_tap_linked_post_id" );   // <- the v1.4.0 state

$before = count( get_posts([ "post_type"=>"post", "title"=>"LS Chapter 1",
                            "numberposts"=>-1, "fields"=>"ids",
                            "post_status"=>"any" ]) );

$data["chapter_body"] = "<p>Typo fixed.</p>";
$data["first_line"]   = "Typo fixed.";
$r2 = $p->publish( $data, 1 );

$after = count( get_posts([ "post_type"=>"post", "title"=>"LS Chapter 1",
                           "numberposts"=>-1, "fields"=>"ids",
                           "post_status"=>"any" ]) );

echo "teaser posts before republish: $before\n";
echo "teaser posts after republish:  $after\n";
echo ( $after === $before ? "PASS no duplicate teaser" : "FAIL duplicated teaser" ) . "\n";
$relinked = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );
echo ( $relinked ? "PASS meta backfilled ($relinked)" : "FAIL meta still missing" ) . "\n";
```

Expected: `before` and `after` both `1`, plus two `PASS` lines.

- [ ] **Step 4: Verify — genuine partial failure still creates**

The recovery path must still work when the post really is gone:

```php
<?php
$p = new TAP_Publisher();
$page = get_page_by_path( "legacy-series/legacy-series-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );
wp_delete_post( $l, true );
delete_post_meta( $page->ID, "_tap_linked_post_id" );

$data = [
  "series_title" => "Legacy Series", "series_slug" => "legacy-series",
  "series_title_short" => "LS", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Typo fixed.</p>", "first_line" => "Typo fixed.",
];
$r = $p->publish( $data, 1 );
echo ( ! empty( $r["post_url"] ) ? "PASS recreated after real loss" : "FAIL post_url empty" ) . "\n";
```

Expected: one `PASS`.

- [ ] **Step 5: Commit**

```bash
git add plugins/translation-assistant-publisher/includes/class-publisher.php
git commit -m "fix: adopt existing teaser post on republish instead of duplicating it

Chapters published before 1.5.0 without a password have no
_tap_linked_post_id, because the meta write was gated on the password.
The update path read that absence as 'the post was never created' and
published a second teaser to the feed on every legacy chapter's first
republish."
```

---

### Task 2: Apply `post_password` on the update path

**Files:**
- Modify: `plugins/translation-assistant-publisher/includes/class-publisher.php` — `update_chapter_page()`

**Interfaces:**
- Consumes: nothing new
- Produces: no signature change

**Background:** `create_chapter_page()` sets `post_password` from the payload; `update_chapter_page()` never does. The desktop client mints a fresh random password on every publish and — since the republish branch added `updated` — now displays it whenever `created || updated`. So republishing a protected chapter shows the user a password the server never applied, while the working one disappears from the UI. Enabling protection on an already-published chapter likewise leaves the page public while the client reports it protected.

**Semantics:** apply the password when the payload carries one; leave the existing password alone when it does not. Clearing protection is already an explicit separate operation (`unlock_chapter_index` → `unlock_chapter()`), so absence must not mean "unlock" — otherwise every ordinary republish would silently unprotect the chapter.

- [ ] **Step 1: Set the password in the update args**

In `update_chapter_page()`, after the `$args` array is built and before the `publish_date` block, add:

```php
        // Absence never means "unlock" — unlocking is the explicit
        // unlock_chapter_index operation. Only apply what was sent.
        if ( ! empty( $data['password'] ) ) {
            $args['post_password'] = sanitize_text_field( $data['password'] );
        }
```

- [ ] **Step 2: Verify — password applies on republish, and survives one without**

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Pw Series", "series_slug" => "pw-series",
  "series_title_short" => "PW", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Body.</p>", "first_line" => "Body.",
];
$p->publish( $data, 1 );
$page = get_page_by_path( "pw-series/pw-series-c1", OBJECT, "page" );
echo ( get_post( $page->ID )->post_password === "" ? "PASS starts unprotected" : "FAIL" ) . "\n";

// Turn protection on via republish.
$data["password"] = "secret-one";
$p->publish( $data, 1 );
echo ( get_post( $page->ID )->post_password === "secret-one" ? "PASS password applied" : "FAIL password ignored" ) . "\n";

// Rotate it.
$data["password"] = "secret-two";
$p->publish( $data, 1 );
echo ( get_post( $page->ID )->post_password === "secret-two" ? "PASS password rotated" : "FAIL rotation ignored" ) . "\n";

// A republish with no password must NOT silently unprotect.
unset( $data["password"] );
$p->publish( $data, 1 );
echo ( get_post( $page->ID )->post_password === "secret-two" ? "PASS absence preserves" : "FAIL silently unlocked" ) . "\n";
```

Expected: four `PASS` lines.

- [ ] **Step 3: Commit**

```bash
git add plugins/translation-assistant-publisher/includes/class-publisher.php
git commit -m "fix: apply post_password on the republish update path

The client mints a fresh password each publish and displays it whenever
created or updated is true, so republishing a protected chapter handed
the user a password the server had never applied."
```

---

### Task 3: Make scheduling reversible, via one shared helper

**Files:**
- Modify: `plugins/translation-assistant-publisher/includes/class-publisher.php` — new private helper plus four call sites

**Interfaces:**
- Consumes: nothing new
- Produces: a new private method `apply_schedule( array $args, array $data, bool $is_page = false ): array` returning the args with date/status keys set

**Background:** All four post-arg builders set `post_status` only inside `if ( ! empty( $data['publish_date'] ) )`. There is no else-branch, so status is a one-way ratchet into `future`: a user who schedules a chapter and then changes their mind cannot unschedule it. The server updates the content, leaves both objects `future`, and returns `scheduled: false` — after which the client records `wp_status = "publish"` locally and its state disagrees with the server. The date block is also duplicated four times, so this fix and that dedup are the same edit.

The unscheduled status, though, is **not** the same rule for pages and posts. Teaser posts already gate on password today: `create_post()` sets `post_status => empty($data['password']) ? 'publish' : 'draft'` ([class-publisher.php:141](plugins/translation-assistant-publisher/includes/class-publisher.php:141)). Chapter *pages* never do this — `create_chapter_page()` always sets `post_status => 'publish'` and relies solely on `post_password` for protection ([class-publisher.php:235](plugins/translation-assistant-publisher/includes/class-publisher.php:235)). A single helper that applies the password→draft rule to all four call sites would, combined with Task 2's password application, put a passworded chapter page into `draft` on any unscheduled republish — draft pages 404 for visitors, so this breaks password protection outright rather than fixing scheduling. The helper must take a page/post flag and only apply the password rule on the post side.

- [ ] **Step 1: Add the helper**

Add as a new private method near `create_post()`:

```php
    private function apply_schedule( array $args, array $data, bool $is_page = false ): array {
        if ( ! empty( $data['publish_date'] ) ) {
            $dt = new DateTime( $data['publish_date'], new DateTimeZone( 'UTC' ) );
            $args['post_date_gmt'] = $dt->format( 'Y-m-d H:i:s' );
            $dt->setTimezone( wp_timezone() );
            $args['post_date']   = $dt->format( 'Y-m-d H:i:s' );
            $args['post_status'] = 'future';
            return $args;
        }

        // No publish_date: go live now. Without this branch, post_status is a
        // one-way ratchet — a chapter scheduled once can never be unscheduled.
        // Pages are never password-gated into draft — protection is the
        // post_password field, not status — only teaser posts use that rule.
        $args['post_status'] = $is_page || empty( $data['password'] ) ? 'publish' : 'draft';
        $args['post_date']     = current_time( 'mysql' );
        $args['post_date_gmt'] = current_time( 'mysql', true );
        return $args;
    }
```

- [ ] **Step 2: Replace all four duplicated blocks**

In each of `create_post()`, `update_post()`, `create_chapter_page()`, and `update_chapter_page()`, delete the local `if ( ! empty( $data['publish_date'] ) ) { ... }` block and replace it with one line immediately before the `wp_insert_post`/`wp_update_post` call:

```php
        $post_args = $this->apply_schedule( $post_args, $data );
```

using whatever the local args variable is named (`$post_args` in the two post methods, `$args` in the two page methods). In `create_chapter_page()` and `update_chapter_page()`, pass `true` for the third argument: `$args = $this->apply_schedule( $args, $data, true );`. In `create_post()`, also delete the now-redundant `'post_status' => empty( $data['password'] ) ? 'publish' : 'draft',` line from the initial array — `apply_schedule()` sets it in both branches.

Read each method after editing and confirm `post_status` is set exactly once, and that the two page call sites pass `true`.

- [ ] **Step 3: Verify — schedule, then unschedule**

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Sched Series", "series_slug" => "sched-series",
  "series_title_short" => "SC", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Body.</p>", "first_line" => "Body.",
  "publish_date" => gmdate( "Y-m-d\TH:i:s\Z", strtotime("+3 days") ),
];
$p->publish( $data, 1 );
$page = get_page_by_path( "sched-series/sched-series-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );
echo ( get_post($page->ID)->post_status === "future" ? "PASS page scheduled" : "FAIL page not future" ) . "\n";
echo ( get_post($l)->post_status === "future" ? "PASS post scheduled" : "FAIL post not future" ) . "\n";

// Change of mind: republish with no publish_date.
unset( $data["publish_date"] );
$p->publish( $data, 1 );
echo ( get_post($page->ID)->post_status === "publish" ? "PASS page unscheduled" : "FAIL page still " . get_post($page->ID)->post_status ) . "\n";
echo ( get_post($l)->post_status === "publish" ? "PASS post unscheduled" : "FAIL post still " . get_post($l)->post_status ) . "\n";
```

Expected: four `PASS` lines.

- [ ] **Step 4: Verify — a passworded unschedule lands in draft, not publish**

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Sched Pw", "series_slug" => "sched-pw",
  "series_title_short" => "SP", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Body.</p>", "first_line" => "Body.",
  "password" => "secret",
];
$p->publish( $data, 1 );
$page = get_page_by_path( "sched-pw/sched-pw-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );
echo ( get_post($l)->post_status === "draft" ? "PASS passworded post is draft" : "FAIL post is " . get_post($l)->post_status ) . "\n";
echo ( get_post($page->ID)->post_status === "publish" ? "PASS passworded page stays publish" : "FAIL page is " . get_post($page->ID)->post_status ) . "\n";
```

Expected: two `PASS` lines. The teaser post matches the pre-existing create-path rule that a passworded chapter's teaser stays a draft. The chapter *page* must stay `publish` — it is never password-gated into draft, only the `post_password` field protects it. This is the case the shared `apply_schedule()` helper must not regress (`$is_page` flag added in Task 3 Step 1 exists for exactly this).

- [ ] **Step 5: Re-run the original branch scenarios**

The republish plan's four scenarios all touch scheduling. Re-run them against a fresh series slug and confirm create, republish-in-place, renumber, partial-failure recovery, and reschedule all still behave. Report actual output, not a summary.

- [ ] **Step 6: Commit**

```bash
git add plugins/translation-assistant-publisher/includes/class-publisher.php
git commit -m "fix: make scheduling reversible; fold the 4x date block into apply_schedule

post_status was only ever set when publish_date was present, so a
scheduled chapter could never be unscheduled — the server left it future
while replying scheduled:false, and the client then recorded it as
published."
```

---

### Task 4: Rebuild the teaser body instead of regex-patching it

**Files:**
- Modify: `plugins/translation-assistant-publisher/includes/class-publisher.php` — `update_post()`

**Interfaces:**
- Consumes: `apply_schedule()` from Task 3
- Produces: `update_post()` keeps its `int|WP_Error` signature

**Background:** `update_post()` rewrites only the `Read Chapter N` anchor, so `first_line` edits never reach the teaser — a typo fix in the opening line updates the chapter page and not the post, which is the exact bug class the republish work exists to eliminate. The teaser body has no human-authored content to preserve: `create_post()` derives all of it from `first_line` plus the link. Rebuilding it is both more correct and less code — the `preg_replace_callback` and its `$count === 0` log path disappear. The child category is likewise assigned on create and never re-derived, so renaming `series_title_short` retitles the post but leaves it in the old category.

- [ ] **Step 1: Replace the regex block with a rebuild**

`update_post()` currently builds `$post_args`, then does a `get_post()` + `preg_replace_callback` dance. Replace the whole method body with:

```php
    public function update_post( int $post_id, array $data, string $chapter_url ): int|WP_Error {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;

        // The teaser body is wholly derived from the payload — there is no
        // human-authored content to preserve — so rebuild it exactly the way
        // create_post does rather than patching one line of it.
        $content = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                 . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">Read Chapter ' . $chapter_idx . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'ID'            => $post_id,
            'post_title'    => $title,
            'post_content'  => $content,
            'post_category' => $child_cat ? [ $child_cat ] : [],
        ];

        $post_args = $this->apply_schedule( $post_args, $data );

        return wp_update_post( $post_args, true );
    }
```

- [ ] **Step 2: Verify — first_line edit reaches the teaser**

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Teaser Series", "series_slug" => "teaser-series",
  "series_title_short" => "TS", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Teh openning line.</p>", "first_line" => "Teh openning line.",
];
$p->publish( $data, 1 );
$page = get_page_by_path( "teaser-series/teaser-series-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );

$data["chapter_body"] = "<p>The opening line.</p>";
$data["first_line"]   = "The opening line.";
$p->publish( $data, 1 );

$body = get_post( $l )->post_content;
echo ( str_contains( $body, "The opening line." ) ? "PASS first_line updated" : "FAIL first_line stale" ) . "\n";
echo ( ! str_contains( $body, "Teh openning" ) ? "PASS typo gone" : "FAIL typo still present" ) . "\n";
echo ( str_contains( $body, "Read Chapter 1" ) ? "PASS link intact" : "FAIL link lost" ) . "\n";
```

Expected: three `PASS` lines.

- [ ] **Step 3: Verify — a `$` in first_line survives**

The old code path was the one the republish work hardened against backreference expansion; the rebuild must not reintroduce it. `esc_html()` still does not escape `$`, but there is no longer a regex replacement string, so this should pass trivially — assert it so a future refactor cannot silently regress:

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Dollar Series", "series_slug" => "dollar-series",
  "series_title_short" => "DS", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>He owed $1,000 exactly.</p>", "first_line" => "He owed $1,000 exactly.",
];
$p->publish( $data, 1 );
$page = get_page_by_path( "dollar-series/dollar-series-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );
$data["first_line"] = "He owed $2,000 exactly.";
$p->publish( $data, 1 );
echo ( str_contains( get_post($l)->post_content, '$2,000' ) ? "PASS dollar preserved" : "FAIL dollar eaten" ) . "\n";
```

Expected: one `PASS`.

- [ ] **Step 4: Verify — renaming the series moves the post's category**

```php
<?php
$p = new TAP_Publisher();
$data = [
  "series_title" => "Cat Series", "series_slug" => "cat-series",
  "series_title_short" => "CATOLD", "series_link" => "https://example.com/",
  "chapter_index" => 1, "chapter_title" => "Chapter One",
  "chapter_body" => "<p>Body.</p>", "first_line" => "Body.",
];
$p->publish( $data, 1 );
$page = get_page_by_path( "cat-series/cat-series-c1", OBJECT, "page" );
$l    = (int) get_post_meta( $page->ID, "_tap_linked_post_id", true );

$data["series_title_short"] = "CATNEW";
$p->publish( $data, 1 );
$names = wp_list_pluck( get_the_category( $l ), "name" );
echo ( in_array( "CATNEW", $names, true ) ? "PASS recategorized" : "FAIL still in: " . implode(",", $names) ) . "\n";
```

Expected: one `PASS`.

- [ ] **Step 5: Commit**

```bash
git add plugins/translation-assistant-publisher/includes/class-publisher.php
git commit -m "fix: rebuild the teaser body on update instead of patching one line

update_post only rewrote the Read Chapter anchor, so first_line edits
never reached the teaser. The body is wholly derived from the payload,
so deriving it the way create_post does is both correct and less code."
```

---

### Task 5: Response-shape gap, version bump, README

**Files:**
- Modify: `plugins/translation-assistant-publisher/includes/class-publisher.php` — synopsis branch
- Modify: `plugins/translation-assistant-publisher/translation-assistant-publisher.php` — version header
- Modify: `README.md`

**Background:** The synopsis branch returns `created` but no `updated` key at all, while both chapter branches always include both. The client reads `result.get("updated", False)`, so a synopsis republish reports "Already published." and skips the local status write even though the server did overwrite the index page. This is the one self-review checklist item from the original plan that does not fully hold.

- [ ] **Step 1: Add `updated` to the synopsis response**

In `publish()`, the `chapter_idx === 0` branch returns an array ending `'created' => ! $already_has_content,`. Add one key so the shape matches the chapter branches:

```php
                'created'  => ! $already_has_content,
                'updated'  => $already_has_content,
```

- [ ] **Step 2: Verify the invariant across all three branches**

```php
<?php
$p = new TAP_Publisher();
$base = [
  "series_title" => "Shape Series", "series_slug" => "shape-series",
  "series_title_short" => "SH", "series_link" => "https://example.com/",
];

$syn = $base + [ "chapter_index" => 0, "chapter_title" => "Synopsis",
                 "chapter_body" => "<p>About it.</p>", "first_line" => "About it." ];
$r1 = $p->publish( $syn, 1 );
$syn["chapter_body"] = "<p>About it, revised.</p>";
$r2 = $p->publish( $syn, 1 );

$ch = $base + [ "chapter_index" => 1, "chapter_title" => "Chapter One",
                "chapter_body" => "<p>Body.</p>", "first_line" => "Body." ];
$r3 = $p->publish( $ch, 1 );
$r4 = $p->publish( $ch, 1 );

foreach ( [ "synopsis create" => $r1, "synopsis update" => $r2,
            "chapter create"  => $r3, "chapter update"  => $r4 ] as $label => $r ) {
    $has = array_key_exists( "created", $r ) && array_key_exists( "updated", $r );
    $one = ( $r["created"] ?? false ) xor ( $r["updated"] ?? false );
    echo ( $has && $one ? "PASS" : "FAIL" ) . " $label: created="
       . var_export( $r["created"] ?? null, true ) . " updated="
       . var_export( $r["updated"] ?? null, true ) . "\n";
}
```

Expected: four `PASS` lines — every response carries both keys and exactly one is true.

- [ ] **Step 3: Bump the version**

In `translation-assistant-publisher.php`, change `Version:     1.5.0` to `Version:     1.5.1`.

- [ ] **Step 4: Update the README**

Document what changed for a client implementor:
- The synopsis response now carries `updated` alongside `created`, like the chapter responses.
- Republishing a password-protected chapter applies the password from the payload; omitting `password` leaves any existing protection in place (use `unlock_chapter_index` to remove it).
- Omitting `publish_date` on a republish now un-schedules a previously scheduled chapter and publishes it immediately.
- Republishing updates the teaser post's opening line and category, not just its title and link.

- [ ] **Step 5: Full clean pass**

Against a series slug never used in any earlier test, run the whole matrix and report actual output for each: first publish; republish with an edited body and edited `first_line`; renumber; a legacy chapter with the meta stripped (Task 1); password on, rotated, then a republish without one (Task 2); schedule then unschedule (Task 3); synopsis create then republish (Task 5).

- [ ] **Step 6: Commit**

```bash
git add plugins/translation-assistant-publisher/includes/class-publisher.php \
        plugins/translation-assistant-publisher/translation-assistant-publisher.php \
        README.md
git commit -m "chore: add updated to synopsis response, bump to 1.5.1, document update-path semantics"
```

---

## Deliberately Out of Scope

Recorded so a future reader knows these were seen and judged, not missed. Each was triaged as ship-as-is by the whole-branch review:

- **Attachment-delete rollback.** `update_chapter_page()` force-deletes the page's attachments before rebuilding; a subsequent `wp_update_post` failure leaves live content referencing deleted IDs. A real rollback means re-uploading deleted media — more machinery than the failure window justifies. Worth pairing with a cheap guard someday: skip the deletion entirely when the payload carries no `images` or `cover` key, so a payload that simply omits images cannot wipe them.
- **`renumber_conflict` returns HTTP 500.** The REST layer maps every `WP_Error` to 500. A dedicated 409 would let clients distinguish an invalid renumber from a retry-safe server failure. The client currently just surfaces the message, so this is cosmetic.
- **Renumber slug suffixing.** `wp_update_post` reports success even when `wp_unique_post_slug` had to append a suffix, leaving the page at `{slug}-c5-2` where `chapter_exists(5)` will never find it. Reachable only when something already holds the target slug outside the plugin's own bookkeeping.
- **Missing ToC entry is unrecoverable.** `update_toc_entry()` returns silently when it finds no anchor, so a chapter absent from the index can never be restored by republishing. Fix direction: have it return `bool` and fall back to `append_toc_entry()`, matching on either the pretty permalink or `?page_id={ID}`.
- **`?page_id=N` baked into durable content.** WordPress issues no pretty permalink for `future` posts, so a chapter first published scheduled writes a query-string URL into the ToC href and the teaser link, never rewritten when it goes live. Fix direction: build the URL from the known slug rather than `get_permalink()` on a future object.
- **No concurrency control.** Two overlapping republishes of one chapter can interleave the delete-then-rebuild. Pre-existing; no locking anywhere in the plugin.
- **`convert_to_blocks()` swallows per-image attach failures**, so `update_chapter_page()` can return success when zero of N images attached. Note this item's severity *rose* with the republish branch: deletion now happens first, so a failed re-attach destroys the images rather than merely omitting them.
