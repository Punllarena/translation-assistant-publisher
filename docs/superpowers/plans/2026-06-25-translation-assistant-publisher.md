# Translation Assistant Publisher — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin that receives chapter translation exports via REST API and publishes them as a Gutenberg-formatted Page (with nav blocks), an accompanying Post, and a self-updating Index page with static HTML ToC.

**Architecture:** Three classes (Auth, Publisher, Settings) loaded by a bootstrap file. One REST endpoint (`POST /wp-json/ta-publisher/v1/publish`) handles all publishing. API keys are stored as SHA-256 hashes in WP options, each mapped to a WP user ID.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, WordPress REST API, WP options API, `wp_insert_post` / `wp_update_post`.

## Global Constraints

- Plugin root is `/home/pun/workspace/wp-dev/plugins/` — this directory IS mapped directly to the WP plugin folder inside Docker (`/var/www/html/wp-content/plugins/translation-assistant`). Do NOT create a subdirectory; put all files directly under `plugins/`.
- WordPress dev runs at `http://localhost:8080` via Docker Compose (`/home/pun/workspace/wp-dev/docker-compose.yml`).
- PHP 8.0+ — use typed properties and named arguments freely.
- No composer, no external dependencies.
- All `wp_insert_post` / `wp_update_post` calls must check for `WP_Error`.
- API keys stored under WP option key `ta_publisher_keys` (serialized array).
- Chapter page slug format: `{series_slug}-c{chapter_index}` (e.g. `sword-of-the-wanderer-c5`).
- Nav shortcodes `[previous_page]` and `[next_page]` are embedded as literal strings — do NOT register or resolve them.

---

## File Map

| File | Responsibility |
|---|---|
| `plugins/translation-assistant-publisher.php` | Bootstrap: headers, require includes, register REST route and admin menu hooks |
| `plugins/includes/class-auth.php` | Key generation, SHA-256 storage, validation → user_id |
| `plugins/includes/class-settings.php` | WP admin settings page (list/add/revoke keys) |
| `plugins/includes/class-publisher.php` | All publishing logic: block conversion, index page, synopsis, chapter page, ToC append, post creation |
| `plugins/uninstall.php` | Delete `ta_publisher_keys` option on plugin removal |

---

## Task 1: Plugin Bootstrap

**Files:**
- Create: `plugins/translation-assistant-publisher.php`

**Interfaces:**
- Produces: plugin activated in WP; REST route `ta-publisher/v1/publish` registered; admin menu hook registered

- [ ] **Step 1: Create bootstrap file**

```php
<?php
/**
 * Plugin Name: Translation Assistant Publisher
 * Description: Receives chapter exports from Translation Assistant and publishes pages/posts.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TAP_DIR', plugin_dir_path( __FILE__ ) );

require_once TAP_DIR . 'includes/class-auth.php';
require_once TAP_DIR . 'includes/class-publisher.php';
require_once TAP_DIR . 'includes/class-settings.php';

add_action( 'rest_api_init', function () {
    register_rest_route( 'ta-publisher/v1', '/publish', [
        'methods'             => 'POST',
        'callback'            => 'tap_handle_publish',
        'permission_callback' => '__return_true',
    ] );
} );

function tap_handle_publish( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    $required = [ 'api_key', 'series_title', 'series_slug', 'series_title_short',
                  'series_link', 'chapter_index', 'chapter_title', 'chapter_body' ];

    foreach ( $required as $field ) {
        if ( empty( $data[ $field ] ) && $data[ $field ] !== 0 ) {
            return new WP_REST_Response( [ 'error' => "Missing field: {$field}" ], 400 );
        }
    }

    if ( (int) $data['chapter_index'] > 0 && empty( $data['first_line'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing field: first_line' ], 400 );
    }

    $user_id = TAP_Auth::validate_key( $data['api_key'] );
    if ( ! $user_id ) {
        return new WP_REST_Response( [ 'error' => 'Invalid API key' ], 401 );
    }

    $publisher = new TAP_Publisher();
    $result    = $publisher->publish( $data, $user_id );

    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
    }

    return new WP_REST_Response( $result, 200 );
}

$settings = new TAP_Settings();
add_action( 'admin_menu', [ $settings, 'register_menu' ] );
```

- [ ] **Step 2: Verify WordPress sees the plugin**

Start Docker if not running:
```bash
cd /home/pun/workspace/wp-dev && docker compose up -d
```

Visit `http://localhost:8080/wp-admin/plugins.php` — confirm "Translation Assistant Publisher" appears in the list. Activate it.

- [ ] **Step 3: Commit**

```bash
cd /home/pun/workspace/wp-dev
git add plugins/translation-assistant-publisher.php
git commit -m "feat: add plugin bootstrap and REST route registration"
```

---

## Task 2: Auth Class

**Files:**
- Create: `plugins/includes/class-auth.php`

**Interfaces:**
- Produces:
  - `TAP_Auth::validate_key( string $raw_key ): int|false` — returns WP user_id or false
  - `TAP_Auth::generate_key( string $label, int $user_id ): string` — returns raw key (show once)
  - `TAP_Auth::get_all_keys(): array` — returns `[ hash => [ 'user_id', 'label', 'created' ], ... ]`
  - `TAP_Auth::revoke_key( string $hash ): void`

- [ ] **Step 1: Create Auth class**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Auth {

    private const OPTION = 'ta_publisher_keys';

    public static function validate_key( string $raw_key ): int|false {
        $keys = get_option( self::OPTION, [] );
        $hash = hash( 'sha256', $raw_key );
        if ( isset( $keys[ $hash ] ) ) {
            return (int) $keys[ $hash ]['user_id'];
        }
        return false;
    }

    public static function generate_key( string $label, int $user_id ): string {
        $raw  = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $raw );
        $keys = get_option( self::OPTION, [] );
        $keys[ $hash ] = [
            'user_id' => $user_id,
            'label'   => sanitize_text_field( $label ),
            'created' => gmdate( 'Y-m-d' ),
        ];
        update_option( self::OPTION, $keys );
        return $raw;
    }

    public static function get_all_keys(): array {
        return get_option( self::OPTION, [] );
    }

    public static function revoke_key( string $hash ): void {
        $keys = get_option( self::OPTION, [] );
        unset( $keys[ $hash ] );
        update_option( self::OPTION, $keys );
    }
}
```

- [ ] **Step 2: Smoke-test Auth via WP CLI**

```bash
docker exec $(docker compose -f /home/pun/workspace/wp-dev/docker-compose.yml ps -q wordpress) \
  wp eval '
    $raw = TAP_Auth::generate_key("test-key", 1);
    echo "Raw: $raw\n";
    $uid = TAP_Auth::validate_key($raw);
    echo "User ID: $uid\n";
    echo ($uid === 1 ? "PASS" : "FAIL") . "\n";
    TAP_Auth::revoke_key(hash("sha256", $raw));
    echo (TAP_Auth::validate_key($raw) === false ? "Revoke PASS" : "Revoke FAIL") . "\n";
  ' --allow-root
```

Expected output:
```
Raw: <64-char hex>
User ID: 1
PASS
Revoke PASS
```

- [ ] **Step 3: Commit**

```bash
git add plugins/includes/class-auth.php
git commit -m "feat: add Auth class with SHA-256 key storage and validation"
```

---

## Task 3: Settings Page

**Files:**
- Create: `plugins/includes/class-settings.php`

**Interfaces:**
- Consumes: `TAP_Auth::generate_key()`, `TAP_Auth::get_all_keys()`, `TAP_Auth::revoke_key()`
- Produces: `TAP_Settings::register_menu()` — callable by `add_action('admin_menu', ...)`

- [ ] **Step 1: Create Settings class**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Settings {

    public function register_menu(): void {
        add_options_page(
            'TA Publisher',
            'TA Publisher',
            'manage_options',
            'ta-publisher',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $message = '';

        // Handle revoke
        if ( isset( $_POST['tap_revoke_nonce'], $_POST['tap_revoke_hash'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tap_revoke_nonce'] ) ), 'tap_revoke' )
        ) {
            TAP_Auth::revoke_key( sanitize_text_field( wp_unslash( $_POST['tap_revoke_hash'] ) ) );
            $message = '<div class="notice notice-success"><p>Key revoked.</p></div>';
        }

        // Handle add key
        $new_key = '';
        if ( isset( $_POST['tap_add_nonce'], $_POST['tap_label'], $_POST['tap_user_id'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tap_add_nonce'] ) ), 'tap_add' )
        ) {
            $label   = sanitize_text_field( wp_unslash( $_POST['tap_label'] ) );
            $user_id = (int) $_POST['tap_user_id'];
            if ( $label && $user_id ) {
                $new_key = TAP_Auth::generate_key( $label, $user_id );
                $message = '<div class="notice notice-success"><p><strong>New API key (shown once):</strong> <code>' . esc_html( $new_key ) . '</code></p></div>';
            }
        }

        $keys  = TAP_Auth::get_all_keys();
        $users = get_users( [ 'capability' => 'edit_posts', 'fields' => [ 'ID', 'display_name' ] ] );
        ?>
        <div class="wrap">
            <h1>Translation Assistant Publisher</h1>
            <?php echo wp_kses_post( $message ); ?>

            <h2>Active Keys</h2>
            <table class="widefat striped">
                <thead><tr><th>Label</th><th>Author</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ( empty( $keys ) ) : ?>
                    <tr><td colspan="4">No keys yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $keys as $hash => $key ) :
                        $user = get_userdata( $key['user_id'] );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $key['label'] ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : '(deleted user)' ); ?></td>
                        <td><?php echo esc_html( $key['created'] ); ?></td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field( 'tap_revoke', 'tap_revoke_nonce' ); ?>
                                <input type="hidden" name="tap_revoke_hash" value="<?php echo esc_attr( $hash ); ?>">
                                <button type="submit" class="button button-small">Revoke</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2>Add New Key</h2>
            <form method="post">
                <?php wp_nonce_field( 'tap_add', 'tap_add_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_label">Label</label></th>
                        <td><input type="text" id="tap_label" name="tap_label" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="tap_user_id">Author</label></th>
                        <td>
                            <select id="tap_user_id" name="tap_user_id">
                                <?php foreach ( $users as $u ) : ?>
                                    <option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" class="button button-primary" value="Generate Key"></p>
            </form>
        </div>
        <?php
    }
}
```

- [ ] **Step 2: Verify settings page loads**

Visit `http://localhost:8080/wp-admin/options-general.php?page=ta-publisher`.
Confirm: table shows "No keys yet", Add New Key form is visible with author dropdown.

- [ ] **Step 3: Generate a key via UI, note it**

Fill Label = "test", select an author, click Generate Key. Confirm raw key appears in green notice. Keep this key for Task 8 integration tests.

- [ ] **Step 4: Commit**

```bash
git add plugins/includes/class-settings.php
git commit -m "feat: add Settings page for API key management"
```

---

## Task 4: Publisher — Block Converter

**Files:**
- Create: `plugins/includes/class-publisher.php` (partial — converter method only)

**Interfaces:**
- Produces: `TAP_Publisher::convert_to_blocks( string $html ): string`

- [ ] **Step 1: Create Publisher class with convert_to_blocks**

```php
<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Publisher {

    private const NAV_BLOCK = "<!-- wp:html -->\n<div id=\"textbox\">\n<p class=\"alignleft\">[previous_page]</p>\n<p class=\"alignright\">[next_page]</p>\n</div>\n<!-- /wp:html -->";
    private const SEPARATOR = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

    public function convert_to_blocks( string $html ): string {
        $parts  = preg_split( '/(<p[^>]*>.*?<\/p>)/s', trim( $html ), -1, PREG_SPLIT_DELIM_CAPTURE );
        $blocks = [];

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( $part === '' ) continue;

            if ( preg_match( '/^<p[^>]*>.*<\/p>$/s', $part ) ) {
                $blocks[] = "<!-- wp:paragraph -->\n{$part}\n<!-- /wp:paragraph -->";
            } else {
                $blocks[] = "<!-- wp:html -->\n{$part}\n<!-- /wp:html -->";
            }
        }

        $body = implode( "\n\n", $blocks );
        return self::NAV_BLOCK . "\n\n" . self::SEPARATOR . "\n\n" . $body . "\n\n" . self::SEPARATOR . "\n\n" . self::NAV_BLOCK;
    }

    // Remaining methods added in Tasks 5–7
    public function publish( array $data, int $user_id ): array|WP_Error {
        return new WP_Error( 'not_implemented', 'Not yet implemented' );
    }
}
```

- [ ] **Step 2: Smoke-test converter via WP CLI**

```bash
docker exec $(docker compose -f /home/pun/workspace/wp-dev/docker-compose.yml ps -q wordpress) \
  wp eval '
    $p = new TAP_Publisher();
    $out = $p->convert_to_blocks("<p>Line one.</p><p>Line two.</p>");
    echo $out . "\n";
    echo (str_contains($out, "<!-- wp:paragraph -->") ? "PASS paragraphs" : "FAIL paragraphs") . "\n";
    echo (substr_count($out, "[previous_page]") === 2 ? "PASS nav x2" : "FAIL nav x2") . "\n";
    echo (substr_count($out, "wp:separator") === 2 ? "PASS sep x2" : "FAIL sep x2") . "\n";
  ' --allow-root
```

Expected:
```
<!-- wp:html -->
...
PASS paragraphs
PASS nav x2
PASS sep x2
```

- [ ] **Step 3: Commit**

```bash
git add plugins/includes/class-publisher.php
git commit -m "feat: add Publisher class with Gutenberg block converter"
```

---

## Task 5: Publisher — Index Page + Synopsis (chapter_index = 0)

**Files:**
- Modify: `plugins/includes/class-publisher.php`

**Interfaces:**
- Consumes: `TAP_Publisher::convert_to_blocks()`
- Produces:
  - `TAP_Publisher::find_or_create_index_page( string $series_slug, string $series_title, string $series_link, int $user_id ): int|WP_Error` — returns index page ID
  - `TAP_Publisher::handle_synopsis( int $index_page_id, string $series_title, string $series_link, string $chapter_body ): int|WP_Error` — updates index page content, returns page ID
  - `TAP_Publisher::publish()` handles `chapter_index=0` case end-to-end

- [ ] **Step 1: Add find_or_create_index_page and handle_synopsis to Publisher**

Replace the stub `publish()` method and add new methods. The full updated class:

```php
<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Publisher {

    private const NAV_BLOCK = "<!-- wp:html -->\n<div id=\"textbox\">\n<p class=\"alignleft\">[previous_page]</p>\n<p class=\"alignright\">[next_page]</p>\n</div>\n<!-- /wp:html -->";
    private const SEPARATOR = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

    public function publish( array $data, int $user_id ): array|WP_Error {
        $series_slug  = sanitize_title( $data['series_slug'] );
        $chapter_idx  = (int) $data['chapter_index'];

        $index_id = $this->find_or_create_index_page(
            $series_slug, $data['series_title'], $data['series_link'], $user_id
        );
        if ( is_wp_error( $index_id ) ) return $index_id;

        if ( $chapter_idx === 0 ) {
            $result = $this->handle_synopsis( $index_id, $data['series_title'], $data['series_link'], $data['chapter_body'] );
            if ( is_wp_error( $result ) ) return $result;
            return [
                'status'   => 'ok',
                'page_url' => get_permalink( $index_id ),
                'post_url' => null,
                'created'  => true,
            ];
        }

        return new WP_Error( 'not_implemented', 'Chapter publishing not yet implemented' );
    }

    public function find_or_create_index_page( string $series_slug, string $series_title, string $series_link, int $user_id ): int|WP_Error {
        $existing = get_page_by_path( $series_slug, OBJECT, 'page' );
        if ( $existing ) return $existing->ID;

        $id = wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $series_title,
            'post_name'    => $series_slug,
            'post_author'  => $user_id,
            'post_content' => $this->build_index_content( $series_title, $series_link, '' ),
        ], true );

        return $id;
    }

    public function handle_synopsis( int $index_page_id, string $series_title, string $series_link, string $chapter_body ): int|WP_Error {
        $content = $this->build_index_content( $series_title, $series_link, $chapter_body );
        $result  = wp_update_post( [
            'ID'           => $index_page_id,
            'post_content' => $content,
        ], true );

        return $result;
    }

    private function build_index_content( string $series_title, string $series_link, string $synopsis_body ): string {
        return '<div style="text-align: center;"><a href="' . esc_url( $series_link ) . '"><strong>' . esc_html( $series_title ) . '</strong></a></div>'
            . "\n\n"
            . $synopsis_body
            . "\n\n<hr />\n\n"
            . "<div>Table of Contents:</div>\n"
            . '<ul class="ta-toc"></ul>';
    }

    public function convert_to_blocks( string $html ): string {
        $parts  = preg_split( '/(<p[^>]*>.*?<\/p>)/s', trim( $html ), -1, PREG_SPLIT_DELIM_CAPTURE );
        $blocks = [];

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( $part === '' ) continue;

            if ( preg_match( '/^<p[^>]*>.*<\/p>$/s', $part ) ) {
                $blocks[] = "<!-- wp:paragraph -->\n{$part}\n<!-- /wp:paragraph -->";
            } else {
                $blocks[] = "<!-- wp:html -->\n{$part}\n<!-- /wp:html -->";
            }
        }

        $body = implode( "\n\n", $blocks );
        return self::NAV_BLOCK . "\n\n" . self::SEPARATOR . "\n\n" . $body . "\n\n" . self::SEPARATOR . "\n\n" . self::NAV_BLOCK;
    }
}
```

- [ ] **Step 2: Test synopsis via curl**

First get your API key from the settings page (Task 3 Step 3). Replace `YOUR_API_KEY` below.

```bash
curl -s -X POST http://localhost:8080/wp-json/ta-publisher/v1/publish \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "YOUR_API_KEY",
    "series_title": "Isekai Survival",
    "series_slug": "isekai-survival",
    "series_title_short": "IS",
    "series_link": "https://novel18.syosetu.com/n4903fq/",
    "chapter_index": 0,
    "chapter_title": "Synopsis",
    "chapter_body": "<p>I am Shinomiya Hokage, a 3rd-year high school student.</p><p>One day we were all transported to a desert island.</p>"
  }' | python3 -m json.tool
```

Expected:
```json
{
  "status": "ok",
  "page_url": "http://localhost:8080/isekai-survival/",
  "post_url": null,
  "created": true
}
```

Visit `http://localhost:8080/isekai-survival/` — confirm: centered linked title, synopsis paragraphs, HR, "Table of Contents:" heading, empty list.

- [ ] **Step 3: Test idempotency — send same request again**

Same curl command. Expect `200 ok` again (no duplicate page created). Verify only one "isekai-survival" page exists in WP Admin → Pages.

- [ ] **Step 4: Commit**

```bash
git add plugins/includes/class-publisher.php
git commit -m "feat: add index page management and synopsis publishing (chapter_index=0)"
```

---

## Task 6: Publisher — Chapter Page + ToC Append

**Files:**
- Modify: `plugins/includes/class-publisher.php`

**Interfaces:**
- Consumes: `TAP_Publisher::convert_to_blocks()`, `TAP_Publisher::find_or_create_index_page()`
- Produces:
  - `TAP_Publisher::chapter_exists( string $series_slug, int $chapter_index ): WP_Post|false`
  - `TAP_Publisher::create_chapter_page( array $data, int $index_id, int $user_id ): int|WP_Error` — returns chapter page ID
  - `TAP_Publisher::append_toc_entry( int $index_id, int $chapter_index, string $chapter_title, string $chapter_url ): void`

- [ ] **Step 1: Add chapter page methods to Publisher**

Add these three methods to the `TAP_Publisher` class (before the closing `}`), and update `publish()` to handle `chapter_index > 0`:

```php
    public function chapter_exists( string $series_slug, int $chapter_index ): WP_Post|false {
        $slug = "{$series_slug}-c{$chapter_index}";
        $page = get_page_by_path( $slug, OBJECT, 'page' );
        return $page ?: false;
    }

    public function create_chapter_page( array $data, int $index_id, int $user_id ): int|WP_Error {
        $series_slug = sanitize_title( $data['series_slug'] );
        $chapter_idx = (int) $data['chapter_index'];
        $slug        = "{$series_slug}-c{$chapter_idx}";
        $content     = $this->convert_to_blocks( $data['chapter_body'] );

        return wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $data['chapter_title'],
            'post_name'    => $slug,
            'post_parent'  => $index_id,
            'post_author'  => $user_id,
            'post_content' => $content,
        ], true );
    }

    public function append_toc_entry( int $index_id, int $chapter_index, string $chapter_title, string $chapter_url ): void {
        $page    = get_post( $index_id );
        $content = $page->post_content;
        $entry   = '<li><a href="' . esc_url( $chapter_url ) . '">Chapter ' . $chapter_index . ' — ' . esc_html( $chapter_title ) . '</a></li>';

        if ( str_contains( $content, '<ul class="ta-toc">' ) ) {
            // Append inside existing list
            $content = str_replace( '<ul class="ta-toc"></ul>', '<ul class="ta-toc">' . $entry . '</ul>', $content );
            // If list already has entries, append before closing tag
            if ( ! str_contains( $content, $entry ) ) {
                $content = str_replace( '</ul>', $entry . '</ul>', $content );
            }
        } else {
            // ToC block missing — append fresh at end
            $content .= "\n\n<h2>Table of Contents</h2>\n<ul class=\"ta-toc\">\n{$entry}\n</ul>";
        }

        wp_update_post( [ 'ID' => $index_id, 'post_content' => $content ] );
    }
```

Replace the stub `publish()` method with the full implementation:

```php
    public function publish( array $data, int $user_id ): array|WP_Error {
        $series_slug = sanitize_title( $data['series_slug'] );
        $chapter_idx = (int) $data['chapter_index'];

        $index_id = $this->find_or_create_index_page(
            $series_slug, $data['series_title'], $data['series_link'], $user_id
        );
        if ( is_wp_error( $index_id ) ) return $index_id;

        if ( $chapter_idx === 0 ) {
            $result = $this->handle_synopsis( $index_id, $data['series_title'], $data['series_link'], $data['chapter_body'] );
            if ( is_wp_error( $result ) ) return $result;
            return [
                'status'   => 'ok',
                'page_url' => get_permalink( $index_id ),
                'post_url' => null,
                'created'  => true,
            ];
        }

        // Idempotency check
        $existing = $this->chapter_exists( $series_slug, $chapter_idx );
        if ( $existing ) {
            return [
                'status'   => 'ok',
                'page_url' => get_permalink( $existing->ID ),
                'post_url' => null, // post_url resolved in Task 7
                'created'  => false,
            ];
        }

        $chapter_id = $this->create_chapter_page( $data, $index_id, $user_id );
        if ( is_wp_error( $chapter_id ) ) return new WP_Error( 'page_failed', 'Failed to create page' );

        $chapter_url = get_permalink( $chapter_id );
        $this->append_toc_entry( $index_id, $chapter_idx, $data['chapter_title'], $chapter_url );

        return [
            'status'      => 'ok',
            'page_url'    => $chapter_url,
            'post_url'    => null, // filled in Task 7
            'created'     => true,
            '_chapter_id' => $chapter_id, // internal, removed in Task 7
        ];
    }
```

- [ ] **Step 2: Test chapter page creation via curl**

```bash
curl -s -X POST http://localhost:8080/wp-json/ta-publisher/v1/publish \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "YOUR_API_KEY",
    "series_title": "Isekai Survival",
    "series_slug": "isekai-survival",
    "series_title_short": "IS",
    "series_link": "https://novel18.syosetu.com/n4903fq/",
    "chapter_index": 1,
    "chapter_title": "The Cave",
    "chapter_body": "<p>I woke up in a dark cave.</p><p>The sound of water dripped somewhere nearby.</p>",
    "first_line": "I woke up in a dark cave."
  }' | python3 -m json.tool
```

Expected:
```json
{
  "status": "ok",
  "page_url": "http://localhost:8080/isekai-survival/isekai-survival-c1/",
  "post_url": null,
  "created": true
}
```

- [ ] **Step 3: Verify chapter page content**

Visit `http://localhost:8080/isekai-survival/isekai-survival-c1/`. Confirm:
- Nav block with `[previous_page]` / `[next_page]` at top
- HR separator
- Two paragraphs
- HR separator
- Nav block at bottom

- [ ] **Step 4: Verify ToC updated**

Visit `http://localhost:8080/isekai-survival/`. Confirm "Chapter 1 — The Cave" link appears under "Table of Contents:".

- [ ] **Step 5: Test idempotency**

Re-send same curl. Expect `"created": false`. Verify no duplicate page in WP Admin → Pages.

- [ ] **Step 6: Commit**

```bash
git add plugins/includes/class-publisher.php
git commit -m "feat: add chapter page creation and ToC append logic"
```

---

## Task 7: Publisher — Accompanying Post

**Files:**
- Modify: `plugins/includes/class-publisher.php`

**Interfaces:**
- Consumes: `chapter_url` from `create_chapter_page()`
- Produces: `TAP_Publisher::create_post( array $data, string $chapter_url, int $user_id ): int|WP_Error`
- Updates: `publish()` returns complete response including `post_url`

- [ ] **Step 1: Add create_post method and complete publish()**

Add to `TAP_Publisher`:

```php
    public function create_post( array $data, string $chapter_url, int $user_id ): int|WP_Error {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;
        $content     = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                     . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">Read Chapter ' . $chapter_idx . '</a></p>';

        return wp_insert_post( [
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_author'  => $user_id,
            'post_content' => $content,
        ], true );
    }
```

Update `publish()` — replace the chapter branch (after `append_toc_entry`) with:

```php
        $post_id = $this->create_post( $data, $chapter_url, $user_id );
        if ( is_wp_error( $post_id ) ) return new WP_Error( 'post_failed', 'Failed to create post' );

        return [
            'status'   => 'ok',
            'page_url' => $chapter_url,
            'post_url' => get_permalink( $post_id ),
            'created'  => true,
        ];
```

Also remove the `'_chapter_id'` key from the idempotency-return block and note that `post_url` remains `null` on `created: false` (idempotent retry does not re-create the post — the post already exists from the first publish).

- [ ] **Step 2: Full integration test**

```bash
curl -s -X POST http://localhost:8080/wp-json/ta-publisher/v1/publish \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "YOUR_API_KEY",
    "series_title": "Isekai Survival",
    "series_slug": "isekai-survival",
    "series_title_short": "IS",
    "series_link": "https://novel18.syosetu.com/n4903fq/",
    "chapter_index": 2,
    "chapter_title": "First Light",
    "chapter_body": "<p>Dawn broke through the cave entrance.</p><p>I counted my supplies.</p>",
    "first_line": "Dawn broke through the cave entrance."
  }' | python3 -m json.tool
```

Expected:
```json
{
  "status": "ok",
  "page_url": "http://localhost:8080/isekai-survival/isekai-survival-c2/",
  "post_url": "http://localhost:8080/is-chapter-2/",
  "created": true
}
```

- [ ] **Step 3: Verify post**

Visit the `post_url`. Confirm: title "IS Chapter 2", content shows first line + "Read Chapter 2" link pointing to the chapter page.

- [ ] **Step 4: Verify ToC has both chapters**

Visit `http://localhost:8080/isekai-survival/`. Confirm Chapter 1 and Chapter 2 both appear in the list.

- [ ] **Step 5: Test error cases**

```bash
# Missing api_key
curl -s -X POST http://localhost:8080/wp-json/ta-publisher/v1/publish \
  -H "Content-Type: application/json" \
  -d '{"series_slug":"test"}' | python3 -m json.tool
# Expected: {"error": "Missing field: api_key"} with HTTP 400

# Invalid api_key
curl -s -X POST http://localhost:8080/wp-json/ta-publisher/v1/publish \
  -H "Content-Type: application/json" \
  -d '{"api_key":"bad","series_title":"T","series_slug":"t","series_title_short":"T","series_link":"http://x.com","chapter_index":1,"chapter_title":"T","chapter_body":"<p>x</p>","first_line":"x"}' \
  | python3 -m json.tool
# Expected: {"error": "Invalid API key"} with HTTP 401
```

- [ ] **Step 6: Commit**

```bash
git add plugins/includes/class-publisher.php
git commit -m "feat: add post creation and complete publish flow"
```

---

## Task 8: Uninstall

**Files:**
- Create: `plugins/uninstall.php`

**Interfaces:**
- Produces: `ta_publisher_keys` option deleted from WP options on plugin removal

- [ ] **Step 1: Create uninstall.php**

```php
<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'ta_publisher_keys' );
```

- [ ] **Step 2: Verify uninstall hook registered**

WP automatically calls `uninstall.php` when the plugin is deleted (not deactivated) from WP Admin → Plugins → Delete. No additional registration needed — WP detects the file.

To verify the option exists before uninstall:
```bash
docker exec $(docker compose -f /home/pun/workspace/wp-dev/docker-compose.yml ps -q wordpress) \
  wp option get ta_publisher_keys --allow-root
```

Deactivate + Delete the plugin from WP Admin. Verify option is gone:
```bash
docker exec $(docker compose -f /home/pun/workspace/wp-dev/docker-compose.yml ps -q wordpress) \
  wp option get ta_publisher_keys --allow-root
# Expected: Error: Could not get 'ta_publisher_keys' option.
```

Re-activate for continued use.

- [ ] **Step 3: Final commit**

```bash
git add plugins/uninstall.php
git commit -m "feat: add uninstall cleanup for ta_publisher_keys option"
```

---

## Self-Review Checklist

- [x] REST endpoint registered and handles all required fields — Task 1
- [x] `api_key` validated via SHA-256 — Task 2
- [x] Settings page: generate / list / revoke keys — Task 3
- [x] `chapter_body` converted to Gutenberg blocks with nav + separators top/bottom — Task 4
- [x] `chapter_index=0` populates Index page with title link + synopsis + HR + ToC anchor — Task 5
- [x] `chapter_index>0` creates child Page with correct slug, parent, author, block content — Task 6
- [x] ToC appended to Index page; fallback if `<ul class="ta-toc">` missing — Task 6
- [x] Idempotency: duplicate chapter returns `created: false` without re-creating — Task 6
- [x] Existing page with `series_slug` adopted as Index page rather than duplicate — Task 5
- [x] Accompanying Post: title format, first_line + chapter link — Task 7
- [x] `uninstall.php` deletes option — Task 8
- [x] `400` on missing field, `401` on bad key, `500` on WP failure — Task 1 + Task 7
- [x] No external dependencies, no composer
