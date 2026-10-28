<?php

/**
 * Characterisation tests for the 1.5.5 illustrations flow.
 */
class TAP_Illustrations_Legacy_Test extends TAP_TestCase {

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function illustrations( array $over = [] ): array {
        return array_merge( [
            'series_title'       => 'Test Series',
            'series_slug'        => 'test-series',
            'series_title_short' => 'TS',
            'images'             => [ $this->image() ],
        ], $over );
    }

    private function image(): array {
        return [
            'filename'    => 'pixel.png',
            'mime'        => 'image/png',
            'data_base64' => self::PNG_1X1,
            'position'    => 0,
        ];
    }

    private function gallery(): WP_Post {
        $page = get_page_by_path( 'test-series/test-series-illustrations', OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }

    private function attachment_count( int $parent_id ): int {
        return count( get_posts( [
            'post_type'   => 'attachment',
            'post_parent' => $parent_id,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ] ) );
    }

    public function test_replace_creates_gallery_with_one_toc_link() {
        $r = $this->post_json( '/illustrations', $this->illustrations() );
        $this->assertSame( 200, $r->get_status() );
        $this->assertTrue( $r->get_data()['created'] );

        $gallery = $this->gallery();
        $this->assertSame( $this->index_page()->ID, $gallery->post_parent );
        $this->assertStringContainsString( 'wp-block-image', $gallery->post_content );
        $this->assertSame( 1, $this->attachment_count( $gallery->ID ) );

        $toc = $this->index_page()->post_content;
        $this->assertSame( 1, substr_count( $toc, '>Illustrations</a>' ) );
        $this->assertStringContainsString( get_permalink( $gallery->ID ), $toc );
    }

    public function test_second_replace_keeps_one_link_and_replaces_attachments() {
        $this->post_json( '/illustrations', $this->illustrations() );
        $gallery_id = $this->gallery()->ID;

        $r = $this->post_json( '/illustrations', $this->illustrations() );
        $this->assertSame( 200, $r->get_status() );
        $this->assertFalse( $r->get_data()['created'] );

        $this->assertSame( 1, $this->attachment_count( $gallery_id ) );
        $this->assertSame( 1, substr_count( $this->index_page()->post_content, '>Illustrations</a>' ) );
    }

    public function test_append_without_gallery_is_rejected() {
        $r = $this->post_json( '/illustrations', $this->illustrations( [ 'mode' => 'append' ] ) );
        $this->assertNotSame( 200, $r->get_status() );
        $this->assertNull( get_page_by_path( 'test-series/test-series-illustrations', OBJECT, 'page' ) );
    }

    public function test_cover_only_replace_accepted() {
        $r = $this->post_json( '/illustrations', $this->illustrations( [
            'images' => [],
            'cover'  => $this->image(),
        ] ) );
        $this->assertSame( 200, $r->get_status() );
        $this->assertStringContainsString( 'wp-block-image', $this->gallery()->post_content );
    }
}
