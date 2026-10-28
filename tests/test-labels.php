<?php

class TAP_Labels_Test extends TAP_TestCase {

    private function page( string $slug ): WP_Post {
        $page = get_page_by_path( "test-series/{$slug}", OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }

    private function teaser( int $page_id ): WP_Post {
        $post_id = (int) get_post_meta( $page_id, '_tap_linked_post_id', true );
        $this->assertGreaterThan( 0, $post_id );
        return get_post( $post_id );
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

    public function test_label_in_page_and_teaser_titles() {
        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $page = $this->page( 'test-series-c2' );
        $this->assertSame( 'TS Volume 2 Chapter 1 - Interlude', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS Volume 2 Chapter 1', $teaser->post_title );
        $this->assertStringContainsString( '>Read Volume 2 Chapter 1</a>', $teaser->post_content );

        $this->assertSame( [ 'Interlude' ], array_column( TAP_Toc::parse( $this->index_page()->post_content, 'test-series' ), 'text' ) );
    }

    public function test_no_label_matches_1_5_5() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'chapter_title' => 'Start',
        ] ) );

        $page = $this->page( 'test-series-c1' );
        $this->assertSame( 'TS 1 - Start', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS Chapter 1', $teaser->post_title );
        $this->assertStringContainsString( '>Read Chapter 1</a>', $teaser->post_content );
    }

    public function test_blank_label_ignored() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'chapter_title' => 'Start',
            'chapter_label' => '   ',
        ] ) );

        $page = $this->page( 'test-series-c1' );
        $this->assertSame( 'TS 1 - Start', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS Chapter 1', $teaser->post_title );
    }

    public function test_relabel_on_republish_no_duplicate_teaser() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 3,
            'chapter_title' => 'Interlude',
        ] ) );

        $page     = $this->page( 'test-series-c3' );
        $original = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );

        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 3,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
        ] ) );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $original, (int) get_post_meta( $page->ID, '_tap_linked_post_id', true ) );
        $this->assertSame( 'TS Volume 2 Chapter 1', get_post( $original )->post_title );
    }

    public function test_label_escaped() {
        $label = '<b>x</b> & $1';

        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
            'chapter_label' => $label,
        ] ) );

        $teaser = $this->teaser( $this->page( 'test-series-c2' )->ID );
        $this->assertStringContainsString( esc_html( 'Read ' . $label ), $teaser->post_content );
    }

    public function test_toc_text_ignores_label() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'The Forest Road',
            'chapter_label' => 'Volume 2 Chapter 1',
        ] ) );

        $entries = TAP_Toc::parse( $this->index_page()->post_content, 'test-series' );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'The Forest Road', $entries[0]['text'] );
    }
}
