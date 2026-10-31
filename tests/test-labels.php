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

    private function child_page_count(): int {
        return count( get_posts( [
            'post_type'   => 'page',
            'post_parent' => $this->index_page()->ID,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ] ) );
    }

    public function test_volume_form_in_page_and_teaser_titles() {
        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Prologue: The Youngest Son',
            'chapter_label' => 'Volume 1 Chapter 2',
            'volume_title'  => 'Volume 1',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $page = $this->page( 'test-series-c2' );
        $this->assertSame( 'TS Volume 1 - Prologue: The Youngest Son', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS Volume 1 - Prologue: The Youngest Son', $teaser->post_title );
        $this->assertStringContainsString( '>Read Prologue: The Youngest Son</a>', $teaser->post_content );
        // Neither the series-wide number nor the label reaches the teaser.
        $this->assertStringNotContainsString( 'Chapter 2', $teaser->post_content );

        $this->assertSame(
            [ 'Prologue: The Youngest Son' ],
            array_column( TAP_Toc::parse( $this->index_page()->post_content, 'test-series' ), 'text' )
        );
    }

    public function test_no_label_has_no_volume_and_no_number() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'chapter_title' => 'Start',
        ] ) );

        $page = $this->page( 'test-series-c1' );
        $this->assertSame( 'TS - Start', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS - Start', $teaser->post_title );
        $this->assertStringContainsString( '>Read Start</a>', $teaser->post_content );
    }

    public function test_volume_title_needs_a_label() {
        // ta-web sends chapter_label only for a chapter in a volume, so a
        // volume_title without one carries no volume into the titles.
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'chapter_title' => 'Start',
            'volume_title'  => 'Volume 1',
        ] ) );

        $page = $this->page( 'test-series-c1' );
        $this->assertSame( 'TS - Start', $page->post_title );
        $this->assertSame( 'TS - Start', $this->teaser( $page->ID )->post_title );
    }

    public function test_blank_label_ignored() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 1,
            'chapter_title' => 'Start',
            'chapter_label' => '   ',
            'volume_title'  => 'Volume 1',
        ] ) );

        $page = $this->page( 'test-series-c1' );
        $this->assertSame( 'TS - Start', $page->post_title );
        $this->assertSame( 'TS - Start', $this->teaser( $page->ID )->post_title );
    }

    public function test_label_without_volume_title_has_no_volume() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
        ] ) );

        $page = $this->page( 'test-series-c2' );
        $this->assertSame( 'TS - Interlude', $page->post_title );
        $this->assertSame( 'TS - Interlude', $this->teaser( $page->ID )->post_title );
    }

    public function test_empty_chapter_title_falls_back_without_dangling_separator() {
        // The route requires a chapter_title, but publish() strips a leading
        // series prefix, so a payload of just "TS " reaches the titles empty.
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'TS ',
            'chapter_label' => 'Volume 2 Chapter 1',
            'volume_title'  => 'Volume 2',
        ] ) );

        $page = $this->page( 'test-series-c2' );
        $this->assertSame( 'TS Volume 2 - Chapter 2', $page->post_title );
        $this->assertStringEndsNotWith( ' - ', $page->post_title );

        $teaser = $this->teaser( $page->ID );
        $this->assertSame( 'TS Volume 2 - Chapter 2', $teaser->post_title );
        $this->assertStringContainsString( '>Read Chapter 2</a>', $teaser->post_content );
    }

    public function test_relabel_on_republish_no_duplicate_teaser() {
        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 3,
            'chapter_title' => 'Interlude',
        ] ) );

        $page     = $this->page( 'test-series-c3' );
        $original = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        $this->assertSame( 'TS - Interlude', get_post( $original )->post_title );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );

        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 3,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
            'volume_title'  => 'Volume 2',
        ] ) );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $original, (int) get_post_meta( $page->ID, '_tap_linked_post_id', true ) );
        $this->assertSame( 'TS Volume 2 - Interlude', get_post( $original )->post_title );
    }

    public function test_new_form_teaser_adopted_with_meta_missing() {
        $payload = $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
            'volume_title'  => 'Volume 2',
        ] );
        $this->post_json( '/publish', $payload );

        $page    = $this->page( 'test-series-c2' );
        $post_id = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );

        $this->post_json( '/publish', $payload );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $post_id, (int) get_post_meta( $page->ID, '_tap_linked_post_id', true ) );
        $this->assertSame( 'TS Volume 2 - Interlude', get_post( $post_id )->post_title );
    }

    public function test_old_chapter_number_teaser_adopted_and_renamed() {
        $payload = $this->chapter( [
            'chapter_index' => 3,
            'chapter_title' => 'Interlude',
        ] );
        $this->post_json( '/publish', $payload );

        $page    = $this->page( 'test-series-c3' );
        $post_id = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );
        // A teaser published by 1.5.5 carries the series-wide number.
        wp_update_post( [ 'ID' => $post_id, 'post_title' => 'TS Chapter 3' ] );

        $this->post_json( '/publish', $payload );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $post_id, (int) get_post_meta( $page->ID, '_tap_linked_post_id', true ) );
        $this->assertSame( 'TS - Interlude', get_post( $post_id )->post_title );
    }

    public function test_old_label_teaser_adopted_and_renamed() {
        $payload = $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
            'chapter_label' => 'Volume 2 Chapter 1',
            'volume_title'  => 'Volume 2',
        ] );
        $this->post_json( '/publish', $payload );

        $page    = $this->page( 'test-series-c2' );
        $post_id = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );
        // A teaser published by 1.6.0 is titled with the label.
        wp_update_post( [ 'ID' => $post_id, 'post_title' => 'TS Volume 2 Chapter 1' ] );

        $this->post_json( '/publish', $payload );

        $this->assertSame( 1, $this->teaser_count() );
        $this->assertSame( $post_id, (int) get_post_meta( $page->ID, '_tap_linked_post_id', true ) );
        $this->assertSame( 'TS Volume 2 - Interlude', get_post( $post_id )->post_title );
    }

    public function test_page_title_change_never_duplicates_the_page() {
        $payload = $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => 'Interlude',
        ] );
        $this->post_json( '/publish', $payload );

        $page = $this->page( 'test-series-c2' );
        delete_post_meta( $page->ID, '_tap_linked_post_id' );
        // A 1.5.5 page title; lookup is by slug, so it must still be updated.
        wp_update_post( [ 'ID' => $page->ID, 'post_title' => 'TS 2 - Interlude' ] );

        $this->post_json( '/publish', $payload );

        $this->assertSame( 1, $this->child_page_count() );
        $this->assertSame( 'TS - Interlude', $this->page( 'test-series-c2' )->post_title );
        $this->assertSame( 1, $this->teaser_count() );
    }

    public function test_chapter_title_escaped_in_read_link() {
        $title = '<b>x</b> & $1';

        $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 2,
            'chapter_title' => $title,
            'chapter_label' => 'Volume 2 Chapter 1',
        ] ) );

        $teaser = $this->teaser( $this->page( 'test-series-c2' )->ID );
        $this->assertStringContainsString( esc_html( 'Read ' . $title ), $teaser->post_content );
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
