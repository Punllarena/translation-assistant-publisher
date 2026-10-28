<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything the plugin knows about the index page's Table of Contents.
 *
 * Pure string operations on post_content: the publisher passes content in and
 * gets content back, so the class is testable without database state. Links
 * are resolved with url_to_postid only where the caller supplies a resolver.
 */
class TAP_Toc {

    public const END = '<!-- ta-toc-end -->';

    private const START_DIV = '<div>Table of Contents:</div>';
    private const START_H2  = '<h2>Table of Contents</h2>';
    private const START_UL  = '<ul class="ta-toc">';

    /**
     * Byte offsets of the parse region: just after the last start marker
     * before END, else after the last <hr />, else null.
     */
    public static function region( string $content ): ?array {
        $end = strpos( $content, self::END );
        if ( false === $end ) return null;

        $before = substr( $content, 0, $end );
        $start  = null;

        foreach ( [ self::START_DIV, self::START_H2, self::START_UL ] as $marker ) {
            $pos = strrpos( $before, $marker );
            if ( false !== $pos && ( null === $start || $pos > $start ) ) {
                $start = $pos + strlen( $marker );
            }
        }

        if ( null !== $start ) {
            return [ 'start' => $start, 'end' => $end ];
        }

        $hr = strrpos( $before, '<hr />' );
        if ( false === $hr ) return null;

        return [ 'start' => $hr + strlen( '<hr />' ), 'end' => $end ];
    }

    /**
     * Lenient heading comparison: strip tags, decode entities, trim, then
     * compare case-sensitively. The block editor rewrites <h3> attributes.
     */
    public static function heading_matches( string $h3_inner_html, string $volume_title ): bool {
        return self::plain( $h3_inner_html ) === self::plain( $volume_title );
    }

    /**
     * The series-wide index of a chapter link, or null for anything else.
     * Anchored at the end so slug "foo" never matches "foo-bar-c3".
     */
    public static function chapter_index_from_href( string $href, string $series_slug ): ?int {
        $pattern = '#/' . preg_quote( $series_slug, '#' ) . '-c(\d+)/?$#';
        if ( preg_match( $pattern, self::path( $href ), $matches ) ) {
            return (int) $matches[1];
        }
        return null;
    }

    public static function is_illustrations_href( string $href, string $series_slug ): bool {
        $pattern = '#/' . preg_quote( $series_slug, '#' ) . '-illustrations(?:-[^/]+)?/?$#';
        return (bool) preg_match( $pattern, self::path( $href ) );
    }

    /**
     * ToC entries in document order.
     *
     * Each entry: href, text, heading (nearest preceding <h3>, '' if none),
     * index (chapter number or null), illustrations (bool). Duplicate hrefs
     * keep their first occurrence.
     */
    public static function parse( string $content, string $series_slug ): array {
        $region = self::region( $content );
        if ( null === $region ) return [];

        $inner = substr( $content, $region['start'], $region['end'] - $region['start'] );

        $events = [];

        if ( preg_match_all( '/<h3[^>]*>(.*?)<\/h3>/s', $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $events[] = [
                    'offset' => $match[0][1],
                    'type'   => 'heading',
                    'value'  => $match[1][0],
                ];
            }
        }

        if ( preg_match_all( '/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $events[] = [
                    'offset' => $match[0][1],
                    'type'   => 'link',
                    'href'   => $match[1][0],
                    'text'   => $match[2][0],
                ];
            }
        }

        usort( $events, static function ( array $a, array $b ): int {
            return $a['offset'] <=> $b['offset'];
        } );

        $entries = [];
        $seen    = [];
        $heading = '';

        foreach ( $events as $event ) {
            if ( 'heading' === $event['type'] ) {
                $heading = self::plain( $event['value'] );
                continue;
            }

            $href = trim( html_entity_decode( $event['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( isset( $seen[ $href ] ) ) continue;
            $seen[ $href ] = true;

            $entries[] = [
                'href'          => $href,
                'text'          => self::plain( $event['text'] ),
                'heading'       => $heading,
                'index'         => self::chapter_index_from_href( $href, $series_slug ),
                'illustrations' => self::is_illustrations_href( $href, $series_slug ),
            ];
        }

        return $entries;
    }

    private static function plain( string $html ): string {
        return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    private static function path( string $href ): string {
        $path = wp_parse_url( $href, PHP_URL_PATH );
        return is_string( $path ) && '' !== $path ? $path : $href;
    }
}
