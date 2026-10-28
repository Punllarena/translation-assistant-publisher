<?php

class TAP_Publish_Toc_Test extends TAP_TestCase {

    private function texts( string $content ): array {
        return array_column( TAP_Toc::parse( $content, 'test-series' ), 'text' );
    }

    private function headings( string $content ): array {
        return array_column( TAP_Toc::parse( $content, 'test-series' ), 'heading' );
    }

    private function publish_chapter( int $index, string $title, array $over = [] ): array {
        $r = $this->post_json( '/publish', $this->chapter( array_merge( [
            'chapter_index' => $index,
            'chapter_title' => $title,
            'first_line'    => $title,
        ], $over ) ) );
        $this->assertSame( 200, $r->get_status() );
        return $r->get_data();
    }

    public function test_summary_edit_keeps_toc() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );

        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 0,
            'chapter_body'  => '<p>New blurb</p>',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $content = $this->index_page()->post_content;
        $this->assertStringContainsString( 'New blurb', $content );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2' ], $this->texts( $content ) );
        $this->assertSame( [ 'Volume 1', 'Volume 1' ], $this->headings( $content ) );
        $this->assertSame( 1, substr_count( $content, '<h3>Volume 1</h3>' ) );
        $this->assertSame( 1, substr_count( $content, '<div>Table of Contents:</div>' ) );
        $this->assertSame( 1, substr_count( $content, '<!-- ta-toc-end -->' ) );
    }

    public function test_chapter_republished_into_other_volume_moves_link() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );

        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 2' ] );

        $content = $this->index_page()->post_content;
        $this->assertSame( [ 'Volume 1', 'Volume 2' ], $this->headings( $content ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2' ], $this->texts( $content ) );
    }

    public function test_renumbered_chapter_link_reordered() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 3, 'Chapter 3', [ 'volume_title' => 'Volume 1' ] );

        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index'          => 4,
            'previous_chapter_index' => 2,
            'chapter_title'          => 'Chapter 2',
            'first_line'             => 'Chapter 2',
            'volume_title'           => 'Volume 1',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $content = $this->index_page()->post_content;
        $entries = TAP_Toc::parse( $content, 'test-series' );
        $this->assertSame( [ 1, 3, 4 ], array_column( $entries, 'index' ) );
        $this->assertStringContainsString( get_permalink( get_page_by_path( 'test-series/test-series-c4', OBJECT, 'page' )->ID ), $content );
        $this->assertStringNotContainsString( 'test-series-c2', $content );
    }

    public function test_deleted_link_readded_on_republish() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );

        $index   = $this->index_page();
        $content = str_replace(
            TAP_Toc::entry_block( get_permalink( get_page_by_path( 'test-series/test-series-c1', OBJECT, 'page' )->ID ), 'Chapter 1' ),
            '',
            $index->post_content
        );
        wp_update_post( [ 'ID' => $index->ID, 'post_content' => $content ] );
        $this->assertCount( 0, TAP_Toc::parse( get_post( $index->ID )->post_content, 'test-series' ) );

        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );

        $entries = TAP_Toc::parse( get_post( $index->ID )->post_content, 'test-series' );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'Chapter 1', $entries[0]['text'] );
    }

    public function test_out_of_order_publish_lands_in_order() {
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => 'Volume 1' ] );
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );

        $this->assertSame( [ 'Chapter 1', 'Chapter 2' ], $this->texts( $this->index_page()->post_content ) );
    }

    public function test_special_chars_volume_end_to_end() {
        $volume = "Vol \$1 \\& \"Ω\" 第二巻";

        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => $volume ] );
        $this->publish_chapter( 2, 'Chapter 2', [ 'volume_title' => $volume ] );
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => $volume ] );

        $content = $this->index_page()->post_content;
        $this->assertSame( 1, substr_count( $content, '<h3>' . esc_html( $volume ) . '</h3>' ) );
        $this->assertSame( [ $volume, $volume ], $this->headings( $content ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2' ], $this->texts( $content ) );
    }

    public function test_drifted_href_not_duplicated() {
        $this->publish_chapter( 1, 'Chapter 1' );

        $index = $this->index_page();
        $page  = get_page_by_path( 'test-series/test-series-c1', OBJECT, 'page' );
        $url   = get_permalink( $page->ID );
        $drift = rtrim( $url, '/' ); // no trailing slash: still resolves
        $content = str_replace( $url, $drift, $index->post_content );
        wp_update_post( [ 'ID' => $index->ID, 'post_content' => $content ] );

        $this->publish_chapter( 1, 'Chapter 1' );

        $entries = TAP_Toc::parse( get_post( $index->ID )->post_content, 'test-series' );
        $this->assertCount( 1, $entries );
    }

    public function test_synopsis_ignores_label_and_volume() {
        $this->publish_chapter( 1, 'Chapter 1', [ 'volume_title' => 'Volume 1' ] );

        $r = $this->post_json( '/publish', $this->chapter( [
            'chapter_index' => 0,
            'chapter_body'  => '<p>Blurb</p>',
            'chapter_label' => 'Volume 2 Chapter 1',
            'volume_title'  => 'Volume 2',
        ] ) );
        $this->assertSame( 200, $r->get_status() );

        $content = $this->index_page()->post_content;
        $this->assertStringNotContainsString( 'Volume 2', $content );
        $this->assertSame( 1, substr_count( $content, '<h3>Volume 1</h3>' ) );
    }
}
