<?php

class TAP_Illustrations_Test extends TAP_TestCase {

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function illustrations( array $over = [] ): array {
        return array_merge( [
            'series_title'       => 'Test Series',
            'series_slug'        => 'test-series',
            'series_title_short' => 'TS',
            'images'             => [ [
                'filename'    => 'pixel.png',
                'mime'        => 'image/png',
                'data_base64' => self::PNG_1X1,
                'position'    => 0,
            ] ],
        ], $over );
    }

    private function publish_illustrations( array $over = [] ): WP_REST_Response {
        return $this->post_json( '/illustrations', $this->illustrations( $over ) );
    }

    private function gallery( string $slug = 'test-series-illustrations' ): WP_Post {
        $page = get_page_by_path( "test-series/{$slug}", OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }

    private function publish_chapter( int $index, string $title, array $over = [] ): void {
        $r = $this->post_json( '/publish', $this->chapter( array_merge( [
            'chapter_index' => $index,
            'chapter_title' => $title,
            'first_line'    => $title,
        ], $over ) ) );
        $this->assertSame( 200, $r->get_status() );
    }

    private function parse(): array {
        return TAP_Toc::parse( $this->index_page()->post_content, 'test-series' );
    }

    public function test_title_volume_illustrations() {
        $r = $this->publish_illustrations( [ 'volume_title' => 'Volume 2' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'Volume 2 Illustrations', $this->gallery( 'test-series-illustrations-volume-2' )->post_title );

        $r = $this->publish_illustrations();
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'TS Illustrations', $this->gallery()->post_title );

        $index = $this->index_page();
        wp_insert_post( [
            'post_type'    => 'page',
            'post_title'   => 'Volume 3 — Illustrations',
            'post_name'    => 'test-series-illustrations-volume-3',
            'post_parent'  => $index->ID,
            'post_status'  => 'publish',
            'post_content' => '',
        ] );
        $this->publish_illustrations( [ 'volume_title' => 'Volume 3' ] );
        $this->assertSame( 'Volume 3 Illustrations', $this->gallery( 'test-series-illustrations-volume-3' )->post_title );
    }

    public function test_toc_first_when_published_after_chapters() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 2' ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 2' ] );

        $r = $this->publish_illustrations( [
            'volume_title'        => 'Volume 2',
            'first_chapter_index' => 1,
        ] );
        $this->assertSame( 200, $r->get_status() );

        $entries = $this->parse();
        $this->assertSame( [ 'Illustrations', 'Chapter 1', 'Chapter 2' ], array_column( $entries, 'text' ) );
        $this->assertSame( [ 'Volume 2', 'Volume 2', 'Volume 2' ], array_column( $entries, 'heading' ) );
        $this->assertSame( [ true, false, false ], array_column( $entries, 'illustrations' ) );
    }

    public function test_republish_does_not_duplicate_link() {
        $this->publish_illustrations( [ 'volume_title' => 'Volume 2', 'first_chapter_index' => 1 ] );
        $r = $this->publish_illustrations( [ 'volume_title' => 'Volume 2', 'first_chapter_index' => 1 ] );
        $this->assertSame( 200, $r->get_status() );

        $entries = $this->parse();
        $this->assertCount( 1, $entries );
        $this->assertTrue( $entries[0]['illustrations'] );
    }

    public function test_missing_link_readded() {
        $this->publish_illustrations( [ 'volume_title' => 'Volume 2', 'first_chapter_index' => 1 ] );
        $index   = $this->index_page();
        $gallery = $this->gallery( 'test-series-illustrations-volume-2' );
        $content = str_replace(
            TAP_Toc::entry_block( get_permalink( $gallery->ID ), 'Illustrations' ),
            '',
            $index->post_content
        );
        wp_update_post( [ 'ID' => $index->ID, 'post_content' => wp_slash( $content ) ] );

        $this->publish_illustrations( [ 'volume_title' => 'Volume 2', 'first_chapter_index' => 1 ] );

        $entries = $this->parse();
        $this->assertCount( 1, $entries );
        $this->assertTrue( $entries[0]['illustrations'] );
    }

    public function test_menu_order_first_chapter_index() {
        $this->publish_illustrations( [
            'volume_title'        => 'Volume 1',
            'first_chapter_index' => 5,
        ] );
        $this->assertSame( 5, $this->gallery( 'test-series-illustrations-volume-1' )->menu_order );

        $this->publish_illustrations();
        $this->assertSame( 0, $this->gallery()->menu_order );

        $this->publish_illustrations( [ 'volume_title' => 'Volume 1' ] );
        $this->assertSame( 5, $this->gallery( 'test-series-illustrations-volume-1' )->menu_order );
    }

    public function test_publish_date_schedules_replace_only() {
        $this->publish_illustrations( [
            'volume_title' => 'Volume 1',
            'publish_date' => '2030-01-01T00:00:00Z',
        ] );
        $gallery = $this->gallery( 'test-series-illustrations-volume-1' );
        $this->assertSame( 'future', $gallery->post_status );

        $this->publish_illustrations( [
            'volume_title' => 'Volume 1',
            'mode'         => 'append',
            'publish_date' => '2031-01-01T00:00:00Z',
        ] );
        $this->assertSame( 'future', get_post_status( $gallery->ID ) );

        $this->publish_illustrations( [ 'volume_title' => 'Volume 1' ] );
        $this->assertSame( 'publish', get_post_status( $gallery->ID ) );
    }

    public function test_status_lookup() {
        $this->publish_illustrations( [ 'volume_title' => 'Volume 2' ] );
        $gallery = $this->gallery( 'test-series-illustrations-volume-2' );

        $r = $this->get_status( [
            'series_slug'  => 'test-series',
            'illustrations' => '1',
            'volume_title' => 'Volume 2',
        ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'publish', $r->get_data()['status'] );
        $this->assertSame( get_permalink( $gallery->ID ), $r->get_data()['post_url'] );

        $r = $this->get_status( [
            'series_slug'  => 'test-series',
            'illustrations' => '1',
            'volume_title' => 'Volume 9',
        ] );
        $this->assertSame( 'not_found', $r->get_data()['status'] );
        $this->assertNull( $r->get_data()['post_url'] );

        $this->assertSame( 400, $this->get_status( [
            'series_slug'  => 'test-series',
            'illustrations' => '1',
            'chapter'      => '1',
        ] )->get_status() );
        $this->assertSame( 400, $this->get_status( [
            'series_slug'  => 'test-series',
            'illustrations' => '1',
            'chapter'      => '0',
        ] )->get_status() );

        $this->publish_illustrations();
        $no_volume = $this->gallery();
        $r = $this->get_status( [
            'series_slug'  => 'test-series',
            'illustrations' => '1',
        ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 'publish', $r->get_data()['status'] );
        $this->assertSame( get_permalink( $no_volume->ID ), $r->get_data()['post_url'] );
    }

    public function test_bad_publish_date_is_400() {
        $r = $this->publish_illustrations( [ 'publish_date' => 'nope' ] );
        $this->assertSame( 400, $r->get_status() );
    }
}
