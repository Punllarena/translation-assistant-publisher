<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Publisher {

    private const NAV_BLOCK = "<!-- wp:html -->\n<div id=\"textbox\">\n<p class=\"alignleft\">[previous_page]</p>\n<p class=\"alignright\">[next_page]</p>\n</div>\n<!-- /wp:html -->";
    private const SEPARATOR = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

    public function publish( array $data, int $user_id ): array|WP_Error {
        $series_slug  = sanitize_title( $data['series_slug'] );
        $chapter_idx  = (int) $data['chapter_index'];

        // Snapshot before find_or_create so we can tell if this is a first-time synopsis
        $pre_existing        = get_page_by_path( $series_slug, OBJECT, 'page' );
        $already_has_content = $pre_existing && ! empty( trim( $pre_existing->post_content ) );

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
                'created'  => ! $already_has_content,
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
        $this->append_toc_entry( $index_id, $data['series_title_short'] . ' ' . $data['chapter_title'], $chapter_url );

        $post_id = $this->create_post( $data, $chapter_url, $user_id );
        if ( is_wp_error( $post_id ) ) return new WP_Error( 'post_failed', 'Failed to create post' );

        if ( ! empty( $data['password'] ) ) {
            update_post_meta( $chapter_id, '_tap_linked_post_id', $post_id );
        }

        if ( isset( $data['unlock_chapter_index'] ) ) {
            $this->unlock_chapter( $series_slug, (int) $data['unlock_chapter_index'] );
        }

        return [
            'status'    => 'ok',
            'page_url'  => get_permalink( $chapter_id ),
            'post_url'  => get_permalink( $post_id ),
            'created'   => true,
            'scheduled' => ! empty( $data['publish_date'] ),
        ];
    }

    public function create_post( array $data, string $chapter_url, int $user_id ): int|WP_Error {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;
        $content     = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                     . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">Read Chapter ' . $chapter_idx . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'post_type'     => 'post',
            'post_status'   => empty( $data['password'] ) ? 'publish' : 'draft',
            'post_title'    => $title,
            'post_author'   => $user_id,
            'post_content'  => $content,
            'post_category' => $child_cat ? [ $child_cat ] : [],
        ];

        if ( ! empty( $data['publish_date'] ) ) {
            $dt = new DateTime( $data['publish_date'], new DateTimeZone( 'UTC' ) );
            $post_args['post_date_gmt'] = $dt->format( 'Y-m-d H:i:s' );
            $dt->setTimezone( wp_timezone() );
            $post_args['post_date']   = $dt->format( 'Y-m-d H:i:s' );
            $post_args['post_status'] = 'future';
        }

        return wp_insert_post( $post_args, true );
    }

    private function get_or_create_category( string $name, int $parent = 0 ): int {
        $term = term_exists( $name, 'category', $parent );
        if ( $term ) return (int) $term['term_id'];
        $result = wp_insert_term( $name, 'category', [ 'parent' => $parent ] );
        return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    }

    public function chapter_exists( string $series_slug, int $chapter_index ): WP_Post|false {
        $slug = "{$series_slug}-c{$chapter_index}";
        // Page is a child of the index page, so the full path is parent/child.
        $page = get_page_by_path( "{$series_slug}/{$slug}", OBJECT, 'page' );
        return $page ?: false;
    }

    public function get_chapter_status( string $series_slug, int $chapter_idx ): array {
        if ( $chapter_idx === 0 ) {
            $page = get_page_by_path( $series_slug, OBJECT, 'page' );
        } else {
            $page = $this->chapter_exists( $series_slug, $chapter_idx );
        }

        if ( ! $page ) {
            return [ 'status' => 'not_found', 'post_url' => null ];
        }

        return [
            'status'   => $page->post_status,
            'post_url' => get_permalink( $page->ID ),
        ];
    }

    public function create_chapter_page( array $data, int $index_id, int $user_id ): int|WP_Error {
        $series_slug = sanitize_title( $data['series_slug'] );
        $chapter_idx = (int) $data['chapter_index'];
        $slug        = "{$series_slug}-c{$chapter_idx}";
        $content     = $this->convert_to_blocks( $data['chapter_body'] );

        $args = [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => $data['series_title_short'] . ' ' . $chapter_idx . ' - ' . $data['chapter_title'],
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

        if ( ! empty( $data['publish_date'] ) ) {
            $dt = new DateTime( $data['publish_date'], new DateTimeZone( 'UTC' ) );
            $args['post_date_gmt'] = $dt->format( 'Y-m-d H:i:s' );
            $dt->setTimezone( wp_timezone() );
            $args['post_date']   = $dt->format( 'Y-m-d H:i:s' );
            $args['post_status'] = 'future';
        }

        return wp_insert_post( $args, true );
    }

    public function unlock_chapter( string $series_slug, int $chapter_index ): void {
        $page = $this->chapter_exists( $series_slug, $chapter_index );
        if ( ! $page ) return;
        if ( empty( $page->post_password ) ) return;
        wp_update_post( [
            'ID'            => $page->ID,
            'post_password' => '',
        ] );
        $post_id = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        if ( $post_id ) {
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => 'publish',
            ] );
        }
    }

    public function append_toc_entry( int $index_id, string $chapter_title, string $chapter_url ): void {
        $page    = get_post( $index_id );
        if ( ! $page ) return;
        $content = $page->post_content;
        $entry   = "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $chapter_url ) . '">' . esc_html( $chapter_title ) . "</a></p>\n<!-- /wp:paragraph -->";

        if ( str_contains( $content, '<!-- ta-toc-end -->' ) ) {
            $content = str_replace( '<!-- ta-toc-end -->', $entry . "\n\n<!-- ta-toc-end -->", $content );
        } elseif ( str_contains( $content, '<ul class="ta-toc">' ) ) {
            // Legacy list-based ToC: keep existing <li> entries, add new paragraph entries after the list
            $content = preg_replace(
                '/(<ul class="ta-toc">.*?<\/ul>)/s',
                '$1' . "\n\n" . $entry . "\n\n<!-- ta-toc-end -->",
                $content,
                1
            );
        } else {
            // ToC block missing — append fresh at end
            $content .= "\n\n<h2>Table of Contents</h2>\n{$entry}\n\n<!-- ta-toc-end -->";
        }

        $result = wp_update_post( [ 'ID' => $index_id, 'post_content' => $content ], true );
        if ( is_wp_error( $result ) ) {
            error_log( 'TAP: append_toc_entry failed for post ' . $index_id . ': ' . $result->get_error_message() );
        }
    }

    public function find_or_create_index_page( string $series_slug, string $series_title, string $series_link, int $user_id ): int|WP_Error {
        $existing = get_page_by_path( $series_slug, OBJECT, 'page' );
        if ( $existing ) return $existing->ID;

        $id = wp_insert_post( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => $series_title,
            'post_name'      => $series_slug,
            'post_author'    => $user_id,
            'post_content'   => $this->build_index_content( $series_title, $series_link, '' ),
            'comment_status' => 'open',
            'menu_order'     => 0,
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
            . '<!-- ta-toc-end -->';
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
