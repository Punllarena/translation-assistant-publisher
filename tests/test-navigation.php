<?php

class TAP_Navigation_Test extends TAP_TestCase {

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function page( string $slug ): WP_Post {
        $page = get_page_by_path( "test-series/{$slug}", OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }

    private function publish_chapter( int $index, string $title, array $over = [] ): int {
        $r = $this->post_json( '/publish', $this->chapter( array_merge( [
            'chapter_index' => $index,
            'chapter_title' => $title,
            'first_line'    => $title,
        ], $over ) ) );
        $this->assertSame( 200, $r->get_status() );
        return $this->page( "test-series-c{$index}" )->ID;
    }

    private function publish_illustrations( array $over = [] ): void {
        $r = $this->post_json( '/illustrations', array_merge( [
            'series_title'       => 'Test Series',
            'series_slug'        => 'test-series',
            'series_title_short' => 'TS',
            'images'             => [ [
                'filename'    => 'pixel.png',
                'mime'        => 'image/png',
                'data_base64' => self::PNG_1X1,
                'position'    => 0,
            ] ],
        ], $over ) );
        $this->assertSame( 200, $r->get_status() );
    }

    private function view( int $id ): string {
        $this->go_to( get_permalink( $id ) );
        $this->assertTrue( have_posts() );
        the_post();
        return apply_filters( 'the_content', get_the_content() );
    }

    private function bar( string $html, string $where = 'top' ): string {
        $pos = strpos( $html, 'aria-label="Chapter navigation, ' . $where . '"' );
        $this->assertNotFalse( $pos, "no {$where} bar" );
        $start = strrpos( substr( $html, 0, $pos ), '<nav' );
        $this->assertNotFalse( $start );

        $depth = 0;
        $i     = $start;
        $len   = strlen( $html );
        while ( $i < $len ) {
            $open  = strpos( $html, '<nav', $i );
            $close = strpos( $html, '</nav>', $i );
            if ( false === $close ) break;
            if ( false !== $open && $open < $close ) {
                $depth++;
                $i = $open + 4;
            } else {
                $depth--;
                $i = $close + 6;
                if ( 0 === $depth ) {
                    return substr( $html, $start, $i - $start );
                }
            }
        }

        $this->fail( 'unterminated nav' );
        return '';
    }

    private function slot( string $bar, string $name ): string {
        if ( preg_match( '#<span class="tap-nav__' . $name . '">(.*?)</span>#s', $bar, $match ) ) {
            return $match[1];
        }
        return '';
    }

    public function test_prev_next_follow_toc_not_menu_order() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $c2 = $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_illustrations( [ 'volume_title' => 'Volume 1', 'first_chapter_index' => 5 ] );

        $illustrations = $this->page( 'test-series-illustrations-volume-1' );
        $this->assertSame( 5, $illustrations->menu_order );

        $bar = $this->bar( $this->view( $c1 ) );
        $this->assertStringContainsString( get_permalink( $illustrations->ID ), $this->slot( $bar, 'prev' ) );
        $this->assertStringContainsString( get_permalink( $c2 ), $this->slot( $bar, 'next' ) );
    }

    public function test_picker_grouped_with_current_marked() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 2' ] );

        $bar = $this->bar( $this->view( $c1 ) );

        $this->assertSame( 1, substr_count( $bar, 'aria-current="page"' ) );
        $this->assertMatchesRegularExpression(
            '#<a href="' . preg_quote( get_permalink( $c1 ), '#' ) . '" aria-current="page">Chapter 1</a>#',
            $bar
        );
        $this->assertStringContainsString( 'tap-nav__current">Chapter 1</span>', $bar );
        $this->assertStringContainsString( 'tap-nav__volume">Volume 1</span>', $bar );
        $this->assertStringContainsString( 'tap-nav__heading">Volume 1</span>', $bar );
        $this->assertStringContainsString( 'tap-nav__heading">Volume 2</span>', $bar );
        $this->assertSame( 2, substr_count( $bar, '<ul' ) );
    }

    public function test_scheduled_and_draft_skipped() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 2, 'Chapter 2', [
            'volume_title' => 'Volume 1',
            'publish_date' => '2030-01-01T00:00:00Z',
        ] );
        $c3 = $this->publish_chapter( 3, 'Chapter 3', [ 'volume_title' => 'Volume 1' ] );

        $bar = $this->bar( $this->view( $c1 ) );

        $this->assertStringContainsString( get_permalink( $c3 ), $this->slot( $bar, 'next' ) );
        $this->assertStringNotContainsString( 'Chapter 2', $bar );
    }

    public function test_password_protected_page_gets_bar() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $c2 = $this->publish_chapter( 2, 'Chapter 2', [
            'volume_title' => 'Volume 1',
            'password'     => 'secret',
        ] );
        $this->publish_chapter( 3, 'Chapter 3', [ 'volume_title' => 'Volume 1' ] );

        $html = $this->view( $c2 );

        $this->assertStringContainsString( 'post-password-form', $html );
        $this->assertSame( 2, substr_count( $html, 'class="tap-nav"' ) );
    }

    public function test_href_without_trailing_slash_and_http_resolves() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $c2 = $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 3, 'Chapter 3', [ 'volume_title' => 'Volume 1' ] );

        $index   = $this->index_page();
        $content = $index->post_content;
        $drift   = 'https://' . preg_replace( '#^https?://#', '', rtrim( get_permalink( $c1 ), '/' ) );
        $content = str_replace( get_permalink( $c1 ), $drift, $content );
        $content = str_replace( get_permalink( $c2 ), rtrim( get_permalink( $c2 ), '/' ), $content );
        wp_update_post( [ 'ID' => $index->ID, 'post_content' => wp_slash( $content ) ] );

        $bar = $this->bar( $this->view( $c1 ) );
        $this->assertStringContainsString( get_permalink( $c2 ), $this->slot( $bar, 'next' ) );
    }

    public function test_no_bar_outside_main_query() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $c3 = $this->publish_chapter( 3, 'Chapter 3' );

        $this->go_to( get_permalink( $c1 ) );
        $not_in_loop = apply_filters( 'the_content', get_post( $c3 )->post_content );
        $this->assertStringNotContainsString( 'tap-nav', $not_in_loop );

        $this->go_to( get_permalink( $c1 ) );
        the_post();
        $excerpt = get_the_excerpt();
        $this->assertStringNotContainsString( 'tap-nav', $excerpt );

        $GLOBALS['wp_query']->is_singular = false;
        $feed = apply_filters( 'the_content', get_post( $c3 )->post_content );
        $GLOBALS['wp_query']->is_singular = true;
        $this->assertStringNotContainsString( 'tap-nav', $feed );
    }

    public function test_index_page_gets_no_bar() {
        $this->publish_chapter( 1, 'Chapter 1' );
        $html = $this->view( $this->index_page()->ID );
        $this->assertStringNotContainsString( 'tap-nav', $html );
    }

    public function test_hand_edited_toc_changes_order() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $c2 = $this->publish_chapter( 2, 'Chapter 2' );

        $bar = $this->bar( $this->view( $c1 ) );
        $this->assertStringContainsString( get_permalink( $c2 ), $this->slot( $bar, 'next' ) );

        $index   = $this->index_page();
        $block1  = TAP_Toc::entry_block( get_permalink( $c1 ), 'Chapter 1' );
        $block2  = TAP_Toc::entry_block( get_permalink( $c2 ), 'Chapter 2' );
        $content = str_replace( $block1 . "\n\n" . $block2, $block2 . "\n\n" . $block1, $index->post_content );
        wp_update_post( [ 'ID' => $index->ID, 'post_content' => wp_slash( $content ) ] );

        $bar = $this->bar( $this->view( $c1 ) );
        $this->assertStringContainsString( get_permalink( $c2 ), $this->slot( $bar, 'prev' ) );
        $this->assertStringNotContainsString( '<a ', $this->slot( $bar, 'next' ) );
    }

    public function test_legacy_nav_block_stripped() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $this->publish_chapter( 2, 'Chapter 2' );

        $legacy     = TAP_Publisher::LEGACY_NAV_BLOCK;
        $separator  = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
        $legacy_page = $legacy . "\n\n" . $separator . "\n\n<p>Body</p>\n\n" . $separator . "\n\n" . $legacy;
        wp_update_post( [ 'ID' => $c1, 'post_content' => wp_slash( $legacy_page ) ] );

        $html = $this->view( $c1 );

        $this->assertStringNotContainsString( 'previous_page', $html );
        $this->assertStringNotContainsString( 'next_page', $html );
        $this->assertSame( 2, substr_count( $html, 'wp-block-separator' ) );
        $this->assertSame( 2, substr_count( $html, 'class="tap-nav"' ) );
    }

    public function test_wiped_toc_falls_back_to_siblings() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $c2 = $this->publish_chapter( 2, 'Chapter 2' );
        $c3 = $this->publish_chapter( 3, 'Chapter 3' );

        $index = $this->index_page();
        wp_update_post( [
            'ID'           => $index->ID,
            'post_content' => wp_slash( '<div>Table of Contents:</div>' . "\n" . '<!-- ta-toc-end -->' ),
        ] );

        $legacy    = TAP_Publisher::LEGACY_NAV_BLOCK;
        $separator = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
        foreach ( [ $c1, $c2, $c3 ] as $id ) {
            wp_update_post( [
                'ID'           => $id,
                'post_content' => wp_slash( $legacy . "\n\n" . $separator . "\n\n<p>Body</p>\n\n" . $separator . "\n\n" . $legacy ),
            ] );
        }

        $bar = $this->bar( $this->view( $c2 ) );
        $this->assertStringContainsString( get_permalink( $c1 ), $this->slot( $bar, 'prev' ) );
        $this->assertStringContainsString( get_permalink( $c3 ), $this->slot( $bar, 'next' ) );

        $c4 = wp_insert_post( [
            'post_type'    => 'page',
            'post_title'   => 'Chapter 4',
            'post_name'    => 'test-series-c4',
            'post_parent'  => $index->ID,
            'post_status'  => 'publish',
            'post_content' => '<p>Plain</p>',
            'menu_order'   => 4,
        ] );
        $this->assertStringNotContainsString( 'tap-nav', $this->view( $c4 ) );
    }

    public function test_stylesheet_enqueued_for_toc_and_fallback_bars() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $c2 = $this->publish_chapter( 2, 'Chapter 2' );

        $this->go_to( get_permalink( $c1 ) );
        TAP_Navigation::enqueue();
        $this->assertTrue( wp_style_is( 'tap-nav', 'enqueued' ), 'ToC bar' );
        wp_dequeue_style( 'tap-nav' );

        // A 1.5.5 synopsis wiped the ToC; the page still has the old block.
        $index = $this->index_page();
        wp_update_post( [
            'ID'           => $index->ID,
            'post_content' => wp_slash( '<div>Table of Contents:</div>' . "\n" . '<!-- ta-toc-end -->' ),
        ] );
        wp_update_post( [
            'ID'           => $c2,
            'post_content' => wp_slash( TAP_Publisher::LEGACY_NAV_BLOCK . "\n\n<p>Body</p>" ),
        ] );

        $this->go_to( get_permalink( $c2 ) );
        TAP_Navigation::enqueue();
        $this->assertTrue( wp_style_is( 'tap-nav', 'enqueued' ), 'fallback bar' );
        wp_dequeue_style( 'tap-nav' );

        // Neither in the ToC nor carrying the old block: no bar, no stylesheet.
        wp_update_post( [ 'ID' => $c1, 'post_content' => '<p>Plain</p>' ] );
        $this->go_to( get_permalink( $c1 ) );
        TAP_Navigation::enqueue();
        $this->assertFalse( wp_style_is( 'tap-nav', 'enqueued' ), 'no bar' );
    }

    public function test_bar_not_mangled_by_wpautop() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $this->publish_chapter( 2, 'Chapter 2' );

        $bar = $this->bar( $this->view( $c1 ) );

        $this->assertStringNotContainsString( '<br', $bar );
        $this->assertStringNotContainsString( '<p', $bar );
    }

    public function test_landmark_labels_distinct() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );

        $html = $this->view( $c1 );

        $this->assertSame( 1, substr_count( $html, 'aria-label="Chapter navigation, top"' ) );
        $this->assertSame( 1, substr_count( $html, 'aria-label="Chapter navigation, bottom"' ) );
    }

    public function test_new_publish_has_no_shortcodes() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );

        $content = get_post( $c1 )->post_content;
        $this->assertStringNotContainsString( '[previous_page]', $content );
        $this->assertStringNotContainsString( '[next_page]', $content );
        $this->assertStringNotContainsString( TAP_Publisher::LEGACY_NAV_BLOCK, $content );
    }

    public function test_link_text_escaped() {
        $c1 = $this->publish_chapter( 1, 'Chapter 1' );
        $this->publish_chapter( 2, '<script>alert(1)</script>' );

        $bar = $this->bar( $this->view( $c1 ) );

        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $bar );
        $this->assertStringNotContainsString( '<script>alert', $bar );
    }
}
