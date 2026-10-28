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

    /**
     * The paragraph block 1.5.5 writes for a ToC link.
     */
    public static function entry_block( string $href, string $text ): string {
        return "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $href ) . '">' . esc_html( $text ) . "</a></p>\n<!-- /wp:paragraph -->";
    }

    /**
     * Insert, rewrite or move one ToC entry. Only the entry being written is
     * touched; other entries keep their order and bytes.
     *
     * $entry: href, text, volume_title, index (?int), illustrations (bool),
     * anchor_index (?int; first_chapter_index for illustrations, else index).
     *
     * $resolve maps an href to a page ID (0 when unknown); null compares the
     * esc_url forms only.
     */
    public static function upsert( string $content, string $series_slug, array $entry, ?string $old_href = null, ?callable $resolve = null ): string {
        $block   = self::entry_block( (string) $entry['href'], (string) $entry['text'] );
        $end_pos = strpos( $content, self::END );

        if ( false === $end_pos ) {
            // 1.5.5's two no-END branches.
            $ul = strpos( $content, self::START_UL );
            if ( false !== $ul ) {
                $ul_end = strpos( $content, '</ul>', $ul );
                if ( false !== $ul_end ) {
                    $at = $ul_end + strlen( '</ul>' );
                    return substr_replace( $content, "\n\n" . $block . "\n\n" . self::END, $at, 0 );
                }
            }
            return $content . "\n\n<h2>Table of Contents</h2>\n" . $block . "\n\n" . self::END;
        }

        $region = self::region( $content );
        if ( null === $region ) {
            // END exists but there is no marker and no <hr />: keep parsing
            // empty, insert before the end marker as 1.5.5 did.
            return substr_replace( $content, $block . "\n\n", $end_pos, 0 );
        }

        $length = $region['end'] - $region['start'];
        $inner  = substr( $content, $region['start'], $length );
        $layout = self::layout( $inner, $series_slug );

        $target_href = null !== $old_href ? $old_href : (string) $entry['href'];
        $found       = self::find_link( $layout['links'], $target_href, $resolve );
        if ( null === $found && null !== $old_href ) {
            $found = self::find_link( $layout['links'], (string) $entry['href'], $resolve );
        }

        $target_heading = null;

        if ( null !== $found ) {
            $link = $layout['links'][ $found ];

            if ( self::can_stay( $layout['links'], $found, $entry ) ) {
                $inner = self::rewrite_anchor( $inner, $link, $entry );
                return substr_replace( $content, $inner, $region['start'], $length );
            }

            if ( null === $link['block_start'] ) {
                // A legacy <li> link: rewriting in place is safer than moving
                // a list item this class did not create.
                $inner = self::rewrite_anchor( $inner, $link, $entry );
                return substr_replace( $content, $inner, $region['start'], $length );
            }

            $volume = isset( $entry['volume_title'] ) ? (string) $entry['volume_title'] : '';
            if ( empty( $entry['illustrations'] ) && '' === $volume ) {
                // Order-only move: stay in the section the link is in.
                $target_heading = $link['heading'];
            } else {
                $target_heading = $volume;
            }

            $inner  = substr_replace( $inner, '', $link['block_start'], $link['block_end'] - $link['block_start'] );
            $layout = self::layout( $inner, $series_slug );
        }

        if ( null === $target_heading ) {
            $target_heading = isset( $entry['volume_title'] ) ? (string) $entry['volume_title'] : '';
        }

        $inner = self::insert_entry( $inner, $target_heading, $entry, $layout );

        return substr_replace( $content, $inner, $region['start'], $length );
    }

    /**
     * Spec E's exact byte rule: replace everything above the ToC and keep the
     * ToC itself untouched. $head is build_index_content() cut just before its
     * <div>Table of Contents:</div>.
     */
    public static function replace_head( string $content, string $head ): string {
        $end_pos = strpos( $content, self::END );
        $limit   = false === $end_pos ? strlen( $content ) : $end_pos;
        $before  = substr( $content, 0, $limit );

        $last = null;
        foreach ( [ self::START_DIV, self::START_H2 ] as $marker ) {
            $pos = strrpos( $before, $marker );
            if ( false !== $pos && ( null === $last || $pos > $last ) ) {
                $last = $pos;
            }
        }
        if ( null !== $last ) {
            return $head . substr( $content, $last );
        }

        $ul = strrpos( $before, self::START_UL );
        if ( false !== $ul ) {
            return $head . substr( $content, $ul );
        }

        if ( false !== $end_pos ) {
            $carry = '';
            $hr    = strrpos( $before, '<hr />' );
            if ( false !== $hr ) {
                $from  = $hr + strlen( '<hr />' );
                $carry = substr( $content, $from, $end_pos - $from );
            }
            return $head . self::START_DIV . "\n" . $carry . self::END;
        }

        return $head . self::START_DIV . "\n" . self::END;
    }

    /**
     * Headings and links with the byte offsets and enclosing block bounds the
     * placement rules need.
     */
    private static function layout( string $inner, string $series_slug ): array {
        $headings = [];

        if ( preg_match_all( '/<h3[^>]*>(.*?)<\/h3>/s', $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $h3_start = $match[0][1];
                $h3_end   = $h3_start + strlen( $match[0][0] );
                $start    = $h3_start;
                $end      = $h3_end;

                $wp = strrpos( substr( $inner, 0, $h3_start ), '<!-- wp:heading' );
                if ( false !== $wp && false === strpos( substr( $inner, $wp, $h3_start - $wp ), '<!-- /wp:heading -->' ) ) {
                    $close = strpos( $inner, '<!-- /wp:heading -->', $h3_end );
                    if ( false !== $close ) {
                        $start = $wp;
                        $end   = $close + strlen( '<!-- /wp:heading -->' );
                    }
                }

                $headings[] = [
                    'text'        => self::plain( $match[1][0] ),
                    'block_start' => $start,
                    'block_end'   => $end,
                ];
            }
        }

        $links = [];

        if ( preg_match_all( '/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $start = $match[0][1];
                $end   = $start + strlen( $match[0][0] );
                $href  = trim( html_entity_decode( $match[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

                $heading = '';
                foreach ( $headings as $candidate ) {
                    if ( $candidate['block_start'] < $start ) {
                        $heading = $candidate['text'];
                    } else {
                        break;
                    }
                }

                [ $block_start, $block_end ] = self::block_bounds( $inner, $start, $end );

                $links[] = [
                    'href'          => $href,
                    'text'          => self::plain( $match[2][0] ),
                    'start'         => $start,
                    'end'           => $end,
                    'heading'       => $heading,
                    'block_start'   => $block_start,
                    'block_end'     => $block_end,
                    'index'         => self::chapter_index_from_href( $href, $series_slug ),
                    'illustrations' => self::is_illustrations_href( $href, $series_slug ),
                ];
            }
        }

        return [ 'headings' => $headings, 'links' => $links ];
    }

    /**
     * Bounds of the block that contains the anchor, or [null, null] for a
     * legacy <li> link.
     */
    private static function block_bounds( string $inner, int $start, int $end ): array {
        $before = substr( $inner, 0, $start );

        $wp = strrpos( $before, '<!-- wp:paragraph -->' );
        if ( false !== $wp && false === strpos( substr( $inner, $wp, $start - $wp ), '<!-- /wp:paragraph -->' ) ) {
            $close = strpos( $inner, '<!-- /wp:paragraph -->', $end );
            if ( false !== $close ) {
                return [ $wp, $close + strlen( '<!-- /wp:paragraph -->' ) ];
            }
        }

        $p = strrpos( $before, '<p' );
        if ( false !== $p && ! preg_match( '/<\/p\s*>/i', substr( $inner, $p, $start - $p ) ) ) {
            $close = strpos( $inner, '</p>', $end );
            if ( false !== $close ) {
                return [ $p, $close + strlen( '</p>' ) ];
            }
        }

        return [ null, null ];
    }

    private static function find_link( array $links, string $target, ?callable $resolve ): ?int {
        // Exact href first: it is the common case and costs no queries. Only
        // a drifted href (http, no trailing slash, old site URL) needs the
        // resolver, which hits the database once per link.
        $target_url = esc_url( $target );
        foreach ( $links as $i => $link ) {
            if ( esc_url( $link['href'] ) === $target_url ) {
                return $i;
            }
        }

        if ( null === $resolve ) return null;

        $target_id = (int) $resolve( $target );
        if ( $target_id <= 0 ) return null;

        foreach ( $links as $i => $link ) {
            if ( (int) $resolve( $link['href'] ) === $target_id ) {
                return $i;
            }
        }

        return null;
    }

    private static function can_stay( array $links, int $pos, array $entry ): bool {
        $link    = $links[ $pos ];
        $volume  = isset( $entry['volume_title'] ) ? (string) $entry['volume_title'] : '';
        $index   = isset( $entry['index'] ) && is_int( $entry['index'] ) ? $entry['index'] : null;
        $is_illu = ! empty( $entry['illustrations'] );

        if ( $is_illu ) {
            if ( ! self::heading_matches( $link['heading'], $volume ) ) {
                return false;
            }
            // Illustrations must be first in their section.
            foreach ( $links as $i => $other ) {
                if ( $i === $pos ) break;
                if ( $other['heading'] === $link['heading'] ) return false;
            }
            return true;
        }

        if ( '' !== $volume && ! self::heading_matches( $link['heading'], $volume ) ) {
            return false;
        }
        if ( null === $index ) return true;

        $prev = null;
        $next = null;
        for ( $i = $pos - 1; $i >= 0; $i-- ) {
            if ( $links[ $i ]['heading'] === $link['heading'] ) {
                $prev = $links[ $i ];
                break;
            }
        }
        for ( $i = $pos + 1, $n = count( $links ); $i < $n; $i++ ) {
            if ( $links[ $i ]['heading'] === $link['heading'] ) {
                $next = $links[ $i ];
                break;
            }
        }

        if ( null !== $prev && isset( $prev['index'] ) && is_int( $prev['index'] ) && $prev['index'] > $index ) {
            return false;
        }
        if ( null !== $next && isset( $next['index'] ) && is_int( $next['index'] ) && $next['index'] < $index ) {
            return false;
        }

        return true;
    }

    private static function rewrite_anchor( string $inner, array $link, array $entry ): string {
        $anchor = '<a href="' . esc_url( (string) $entry['href'] ) . '">' . esc_html( (string) $entry['text'] ) . '</a>';
        return substr_replace( $inner, $anchor, $link['start'], $link['end'] - $link['start'] );
    }

    /**
     * Insert $entry in the section named $target_heading ('' = leading
     * section), creating that section when it does not exist yet.
     */
    private static function insert_entry( string $inner, string $target_heading, array $entry, array $layout ): string {
        $block         = self::entry_block( (string) $entry['href'], (string) $entry['text'] );
        $is_illu       = ! empty( $entry['illustrations'] );
        $index         = isset( $entry['index'] ) && is_int( $entry['index'] ) ? $entry['index'] : null;
        $anchor        = isset( $entry['anchor_index'] ) && is_int( $entry['anchor_index'] ) ? $entry['anchor_index'] : $index;

        if ( '' === $target_heading ) {
            if ( $is_illu ) {
                return $block . "\n\n" . $inner;
            }
            $at = isset( $layout['headings'][0] ) ? $layout['headings'][0]['block_start'] : strlen( $inner );
            foreach ( $layout['links'] as $link ) {
                if ( '' === $link['heading'] && null !== $index && is_int( $link['index'] ) && $link['index'] > $index ) {
                    $at = null !== $link['block_start'] ? $link['block_start'] : $link['start'];
                    break;
                }
            }
            return substr_replace( $inner, $block . "\n\n", $at, 0 );
        }

        foreach ( $layout['headings'] as $i => $heading ) {
            if ( ! self::heading_matches( $heading['text'], $target_heading ) ) continue;

            if ( $is_illu ) {
                return substr_replace( $inner, "\n\n" . $block, $heading['block_end'], 0 );
            }

            $section_end = isset( $layout['headings'][ $i + 1 ] ) ? $layout['headings'][ $i + 1 ]['block_start'] : strlen( $inner );
            foreach ( $layout['links'] as $link ) {
                if ( $link['heading'] !== $heading['text'] ) continue;
                if ( null !== $index && is_int( $link['index'] ) && $link['index'] > $index ) {
                    $section_end = null !== $link['block_start'] ? $link['block_start'] : $link['start'];
                    break;
                }
            }
            return substr_replace( $inner, $block . "\n\n", $section_end, 0 );
        }

        // New volume section: before the first section with a higher chapter
        // index, else at the end of the region.
        $new = "<!-- wp:heading {\"level\":3} -->\n<h3>" . esc_html( $target_heading ) . "</h3>\n<!-- /wp:heading -->\n\n" . $block;

        if ( null !== $anchor ) {
            foreach ( $layout['headings'] as $heading ) {
                foreach ( $layout['links'] as $link ) {
                    if ( $link['heading'] !== $heading['text'] ) continue;
                    if ( is_int( $link['index'] ) && $link['index'] > $anchor ) {
                        return substr_replace( $inner, $new . "\n\n", $heading['block_start'], 0 );
                    }
                }
            }
        }

        return substr_replace( $inner, $new . "\n\n", strlen( $inner ), 0 );
    }

    private static function path( string $href ): string {
        $path = wp_parse_url( $href, PHP_URL_PATH );
        return is_string( $path ) && '' !== $path ? $path : $href;
    }
}
