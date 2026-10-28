<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The chapter navigation bar, drawn on view from the index page's ToC.
 *
 * Nothing is stored in page content (except the legacy block this class
 * strips); the ToC is parsed once per index-page content hash and cached in
 * post meta.
 */
class TAP_Navigation {

    private const CACHE_META = '_tap_toc_cache';

    private static bool $legacy_seen = false;

    public static function register(): void {
        // 8, not 9: do_blocks also runs at 9 and default-filters.php registers
        // it first, so at 9 the legacy block markup has already been rendered.
        add_filter( 'the_content', [ self::class, 'strip' ], 8 );
        add_filter( 'the_content', [ self::class, 'wrap' ], 20 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function enqueue(): void {
        if ( ! is_singular( 'page' ) ) return;

        $page_id  = (int) get_queried_object_id();
        $index_id = (int) wp_get_post_parent_id( $page_id );
        if ( ! $index_id ) return;

        // Same pages wrap() draws a bar on: listed in the ToC, or carrying the
        // legacy block (the sibling fallback).
        $listed = in_array( $page_id, array_column( self::entries( $index_id ), 'id' ), true );
        if ( ! $listed && ! str_contains( (string) get_post_field( 'post_content', $page_id ), TAP_Publisher::LEGACY_NAV_BLOCK ) ) {
            return;
        }

        wp_enqueue_style(
            'tap-nav',
            plugins_url( 'assets/nav.css', TAP_DIR . 'translation-assistant-publisher.php' ),
            [],
            '1.6.0'
        );
    }

    /**
     * Priority 9: drop 1.5.5's nav block before wpautop runs. The separators
     * around it stay, so an old page renders like a re-published one.
     */
    public static function strip( string $content ): string {
        self::$legacy_seen = false;

        if ( ! self::should_act() ) return $content;
        if ( ! str_contains( $content, TAP_Publisher::LEGACY_NAV_BLOCK ) ) return $content;

        self::$legacy_seen = true;
        return str_replace( TAP_Publisher::LEGACY_NAV_BLOCK, '', $content );
    }

    /**
     * Priority 20: after wpautop and do_shortcode, prepend and append the bar.
     */
    public static function wrap( string $content ): string {
        if ( ! self::should_act() ) return $content;

        $current_id = (int) get_the_ID();
        $index_id   = (int) wp_get_post_parent_id( $current_id );
        if ( ! $index_id ) return $content;

        $entries = self::visible( self::entries( $index_id ) );
        if ( ! in_array( $current_id, array_column( $entries, 'id' ), true ) ) {
            // A 1.5.5 synopsis may have wiped the ToC while old pages still
            // carry the shortcode block: fall back to page order.
            if ( ! self::$legacy_seen ) return $content;
            $entries = self::visible( self::siblings( $index_id ) );
            if ( ! in_array( $current_id, array_column( $entries, 'id' ), true ) ) {
                return $content;
            }
        }

        $top = self::render( $entries, $current_id, 'top' );
        if ( '' === $top ) return $content;

        return $top . $content . self::render( $entries, $current_id, 'bottom' );
    }

    /**
     * Parsed ToC entries: ['id' => int, 'text' => string, 'heading' => string].
     * Cached against md5(post_content) so edits — plugin or hand — re-parse.
     */
    public static function entries( int $index_id ): array {
        $index = get_post( $index_id );
        if ( ! $index ) return [];

        $hash  = md5( $index->post_content );
        $cache = get_post_meta( $index_id, self::CACHE_META, true );
        if ( is_array( $cache ) && isset( $cache['hash'], $cache['entries'] ) && $cache['hash'] === $hash ) {
            return $cache['entries'];
        }

        $entries = [];
        $seen    = [];

        foreach ( TAP_Toc::parse( $index->post_content, $index->post_name ) as $entry ) {
            $id = url_to_postid( $entry['href'] );
            if ( ! $id || isset( $seen[ $id ] ) ) continue;

            $post = get_post( $id );
            if ( ! $post || (int) $post->post_parent !== $index_id ) continue;

            $seen[ $id ] = true;
            $entries[]   = [
                'id'      => (int) $id,
                'text'    => $entry['text'],
                'heading' => $entry['heading'],
            ];
        }

        update_post_meta( $index_id, self::CACHE_META, wp_slash( [ 'hash' => $hash, 'entries' => $entries ] ) );

        return $entries;
    }

    /**
     * Published child pages by menu_order, post_title: 1.5.5's fallback.
     */
    public static function siblings( int $index_id ): array {
        $pages = get_posts( [
            'post_type'   => 'page',
            'post_parent' => $index_id,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order title',
            'order'       => 'ASC',
            'exclude'     => [ $index_id ],
        ] );

        $entries = [];
        foreach ( $pages as $page ) {
            $entries[] = [
                'id'      => (int) $page->ID,
                'text'    => $page->post_title,
                'heading' => '',
            ];
        }
        return $entries;
    }

    /**
     * Drop anything not published, priming the post cache in one query so a
     * scheduled page going live appears without invalidating anything.
     */
    public static function visible( array $entries ): array {
        if ( ! $entries ) return [];

        _prime_post_caches( array_column( $entries, 'id' ) );

        return array_values( array_filter( $entries, static function ( array $entry ): bool {
            return 'publish' === get_post_status( $entry['id'] );
        } ) );
    }

    /**
     * One bar, $where = 'top' or 'bottom'. No newlines inside, so wpautop
     * cannot inject <p>/<br /> into it.
     */
    public static function render( array $visible, int $current_id, string $where ): string {
        $current = null;
        $position = null;
        foreach ( $visible as $i => $entry ) {
            if ( $entry['id'] === $current_id ) {
                $current  = $entry;
                $position = $i;
                break;
            }
        }
        if ( null === $current ) return '';

        $prev = $position > 0 ? $visible[ $position - 1 ] : null;
        $next = isset( $visible[ $position + 1 ] ) ? $visible[ $position + 1 ] : null;

        $prev_html = null !== $prev
            ? '<a href="' . esc_url( get_permalink( $prev['id'] ) ) . '" rel="prev">‹ Previous</a>'
            : '';
        $next_html = null !== $next
            ? '<a href="' . esc_url( get_permalink( $next['id'] ) ) . '" rel="next">Next ›</a>'
            : '';

        return '<nav class="tap-nav" aria-label="' . esc_attr( 'Chapter navigation, ' . $where ) . '">'
            . '<span class="tap-nav__prev">' . $prev_html . '</span>'
            . '<details class="tap-nav__picker">'
            . self::summary( $current )
            . '<nav class="tap-nav__list" aria-label="Chapters">' . self::grouped_lists( $visible, $current_id ) . '</nav>'
            . '</details>'
            . '<span class="tap-nav__next">' . $next_html . '</span>'
            . '</nav>';
    }

    private static function summary( array $current ): string {
        $heading = '' !== $current['heading']
            ? '<span class="tap-nav__volume">' . esc_html( $current['heading'] ) . '</span>'
            : '';
        return '<summary><span class="tap-nav__current">' . esc_html( $current['text'] ) . '</span>' . $heading . '</summary>';
    }

    private static function grouped_lists( array $visible, int $current_id ): string {
        $groups = [];
        foreach ( $visible as $entry ) {
            $groups[ $entry['heading'] ][] = $entry;
        }

        $html = '';
        foreach ( $groups as $heading => $items ) {
            if ( '' !== (string) $heading ) {
                $html .= '<span class="tap-nav__heading">' . esc_html( $heading ) . '</span>';
            }
            $html .= '<ul class="tap-nav__items">';
            foreach ( $items as $item ) {
                $current = $item['id'] === $current_id ? ' aria-current="page"' : '';
                $html   .= '<li><a href="' . esc_url( get_permalink( $item['id'] ) ) . '"' . $current . '>'
                    . esc_html( $item['text'] ) . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    private static function should_act(): bool {
        if ( ! is_singular( 'page' ) || is_feed() ) return false;
        if ( ! in_the_loop() || ! is_main_query() ) return false;
        if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'the_excerpt' ) ) return false;

        $id = get_the_ID();
        return $id && (int) $id === (int) get_queried_object_id();
    }
}
