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
