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

    public function chapter_exists( string $series_slug, int $chapter_index ): WP_Post|false {
        $slug = "{$series_slug}-c{$chapter_index}";
        // Page is a child of the index page, so the full path is parent/child.
        $page = get_page_by_path( "{$series_slug}/{$slug}", OBJECT, 'page' );
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
            // Replace empty list first
            $new_content = str_replace( '<ul class="ta-toc"></ul>', '<ul class="ta-toc">' . $entry . '</ul>', $content );
            // If nothing changed (list already had entries), append before closing tag
            if ( $new_content === $content ) {
                $new_content = str_replace( '</ul>', $entry . '</ul>', $content );
            }
            $content = $new_content;
        } else {
            // ToC block missing — append fresh at end
            $content .= "\n\n<h2>Table of Contents</h2>\n<ul class=\"ta-toc\">\n{$entry}\n</ul>";
        }

        wp_update_post( [ 'ID' => $index_id, 'post_content' => $content ] );
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
