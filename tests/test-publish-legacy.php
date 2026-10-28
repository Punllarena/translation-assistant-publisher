<?php

/**
 * Characterisation tests: these pin the behaviour of plugin 1.5.5 before the
 * 1.6.0 changes. They must pass against unmodified 1.5.5 code.
 */
class TAP_Publish_Legacy_Test extends TAP_TestCase {

    private function page( string $slug ): WP_Post {
        $page = get_page_by_path( "test-series/{$slug}", OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }

    private function teaser_count(): int {
        $category = get_category_by_slug( 'ts' );
        $this->assertNotFalse( $category );
        return count( get_posts( [
            'post_type'   => 'post',
            'post_status' => 'any',
            'category'    => $category->term_id,
            'numberposts' => -1,
            'fields'      => 'ids',
        ] ) );
    }

    public function test_republish_updates_not_duplicates() {
        $first = $this->post_json( '/publish', $this->chapter() );
        $this->assertSame( 200, $first->get_status() );
        $this->assertTrue( $first->get_data()['created'] );

        $second = $this->post_json( '/publish', $this->chapter() );
        $this->assertSame( 200, $second->get_status() );
        $this->assertFalse( $second->get_data()['created'] );
        $this->assertTrue( $second->get_data()['updated'] );

        $children = get_posts( [
            'post_type'   => 'page',
            'post_parent' => $this->index_page()->ID,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ] );
        $this->assertCount( 1, $children, 'one chapter page, not two' );

        $this->assertSame( 1, $this->teaser_count() );
    }

    public function test_renumber_moves_slug() {
        $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => 3, 'chapter_title' => 'Chapter 3' ] ) );
        $this->page( 'test-series-c3' );

        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index'          => 4,
            'previous_chapter_index' => 3,
            'chapter_title'          => 'Chapter 4',
            'first_line'             => 'Four.',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $moved = $this->page( 'test-series-c4' );
        $this->assertSame( 4, (int) $moved->menu_order );
        $this->assertNull( get_page_by_path( 'test-series/test-series-c3', OBJECT, 'page' ) );

        $content = $this->index_page()->post_content;
        $this->assertStringContainsString( get_permalink( $moved->ID ), $content );
        $this->assertStringNotContainsString( 'test-series-c3', $content );
    }

    public function test_renumber_conflict_rejected_unchanged() {
        $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => 3, 'chapter_title' => 'Chapter 3' ] ) );
        $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => 4, 'chapter_title' => 'Chapter 4' ] ) );

        $three_before = $this->page( 'test-series-c3' )->post_content;
        $four_before  = $this->page( 'test-series-c4' )->post_content;
        $toc_before   = $this->index_page()->post_content;

        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index'          => 4,
            'previous_chapter_index' => 3,
            'chapter_title'          => 'Chapter 4',
            'first_line'             => 'Four.',
        ] ) );
        $this->assertNotSame( 200, $r->get_status() );

        $this->assertSame( $three_before, $this->page( 'test-series-c3' )->post_content );
        $this->assertSame( $four_before, $this->page( 'test-series-c4' )->post_content );
        $this->assertSame( $toc_before, $this->index_page()->post_content );
    }

    public function test_password_applied_and_unlock_clears() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Chapter 2',
            'password'      => 'secret',
        ] ) );
        $this->assertSame( 'secret', $this->page( 'test-series-c2' )->post_password );

        $this->post_json( '/publish', $this->chapter( [
            'chapter_index'        => 3,
            'chapter_title'        => 'Chapter 3',
            'unlock_chapter_index' => 2,
        ] ) );
        $this->assertSame( '', $this->page( 'test-series-c2' )->post_password );
    }

    public function test_schedule_then_unschedule() {
        $this->post_json( '/publish', $this->chapter( [
            'publish_date' => '2030-01-01T00:00:00Z',
        ] ) );

        $page_id = $this->page( 'test-series-c1' )->ID;
        $post_id = (int) get_post_meta( $page_id, '_tap_linked_post_id', true );
        $this->assertSame( 'future', get_post_status( $page_id ) );
        $this->assertSame( 'future', get_post_status( $post_id ) );

        $this->post_json( '/publish', $this->chapter() );

        $this->assertSame( 'publish', get_post_status( $page_id ) );
        $this->assertSame( 'publish', get_post_status( $post_id ) );
    }

    public function test_synopsis_on_fresh_index() {
        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 0,
            'chapter_body'  => '<p>Blurb</p>',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $content = $this->index_page()->post_content;
        $this->assertStringContainsString( 'Blurb', $content );
        $this->assertStringContainsString( 'Table of Contents:', $content );
        $this->assertStringContainsString( '<!-- ta-toc-end -->', $content );
    }

    public function test_teaser_adopted_when_meta_missing() {
        $this->post_json( '/publish', $this->chapter() );

        $page_id = $this->page( 'test-series-c1' )->ID;
        $post_id = (int) get_post_meta( $page_id, '_tap_linked_post_id', true );
        $this->assertGreaterThan( 0, $post_id );

        delete_post_meta( $page_id, '_tap_linked_post_id' );

        $this->post_json( '/publish', $this->chapter() );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $post_id, (int) get_post_meta( $page_id, '_tap_linked_post_id', true ) );
    }

    public function test_volume_heading_written_once() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'volume_title'  => 'Volume 1',
            'chapter_title' => 'Chapter 1',
        ] ) );
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'volume_title'  => 'Volume 1',
            'chapter_title' => 'Chapter 2',
        ] ) );

        $this->assertSame( 1, substr_count( $this->index_page()->post_content, '<h3>Volume 1</h3>' ) );
    }
}
