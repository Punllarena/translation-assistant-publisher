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
