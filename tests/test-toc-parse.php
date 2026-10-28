<?php

class TAP_Toc_Parse_Test extends TAP_TestCase {

    private function heading( string $text, string $attributes = '' ): string {
        return "<!-- wp:heading {\"level\":3} -->\n<h3{$attributes}>{$text}</h3>\n<!-- /wp:heading -->";
    }

    private function entry( string $slug, string $text ): string {
        return "<!-- wp:paragraph -->\n<p><a href=\"https://example.org/test-series/{$slug}/\">{$text}</a></p>\n<!-- /wp:paragraph -->";
    }

    private function toc( string $inner ): string {
        return '<div>Table of Contents:</div>' . "\n" . $inner . "\n" . '<!-- ta-toc-end -->';
    }

    public function test_region_after_div_marker() {
        $start_marker = '<div>Table of Contents:</div>';
        $content      = "Above\n{$start_marker}\nentry\n<!-- ta-toc-end -->\nBelow";

        $region = TAP_Toc::region( $content );

        $this->assertNotNull( $region );
        $this->assertSame( strpos( $content, $start_marker ) + strlen( $start_marker ), $region['start'] );
        $this->assertSame( strpos( $content, '<!-- ta-toc-end -->' ), $region['end'] );
    }

    public function test_region_after_h2_marker() {
        $start_marker = '<h2>Table of Contents</h2>';
        $content      = "Above\n{$start_marker}\nentry\n<!-- ta-toc-end -->";

        $region = TAP_Toc::region( $content );

        $this->assertNotNull( $region );
        $this->assertSame( strpos( $content, $start_marker ) + strlen( $start_marker ), $region['start'] );
    }

    public function test_region_after_legacy_ul() {
        $start_marker = '<ul class="ta-toc">';
        $content      = "Above\n{$start_marker}<li><a href=\"/x/\">X</a></li></ul>\n<!-- ta-toc-end -->";

        $region = TAP_Toc::region( $content );

        $this->assertNotNull( $region );
        $this->assertSame( strpos( $content, $start_marker ) + strlen( $start_marker ), $region['start'] );
    }

    public function test_region_falls_back_to_last_hr() {
        $content = "A\n<hr />\nB\n<hr />\nC\n<!-- ta-toc-end -->";

        $region = TAP_Toc::region( $content );

        $this->assertNotNull( $region );
        $this->assertSame( strrpos( $content, '<hr />' ) + strlen( '<hr />' ), $region['start'] );
    }

    public function test_region_null_without_end_marker() {
        $this->assertNull( TAP_Toc::region( '<div>Table of Contents:</div> entry' ) );
    }

    public function test_synopsis_h3_and_links_ignored() {
        $content = '<h3>Volume 9</h3>'
            . '<p><a href="https://example.org/test-series/test-series-c9/">Synopsis link</a></p>'
            . "\n"
            . $this->toc( $this->entry( 'test-series-c1', 'Chapter 1' ) );

        $entries = TAP_Toc::parse( $content, 'test-series' );

        $this->assertCount( 1, $entries );
        $this->assertSame( 'Chapter 1', $entries[0]['text'] );
        $this->assertSame( 1, $entries[0]['index'] );
    }

    public function test_heading_match_block_editor_class() {
        $this->assertTrue( TAP_Toc::heading_matches( 'Volume 1', 'Volume 1' ) );
        $this->assertTrue( TAP_Toc::heading_matches( '<h3 class="wp-block-heading">Volume 1</h3>', 'Volume 1' ) );
        $this->assertTrue( TAP_Toc::heading_matches( '<span>Volume</span> 1', 'Volume 1' ) );
        $this->assertFalse( TAP_Toc::heading_matches( 'Volume 10', 'Volume 1' ) );
    }

    public function test_heading_match_special_chars() {
        $title = "Vol \$1 \\& \"Ω\" 第二巻";
        $this->assertTrue( TAP_Toc::heading_matches( esc_html( $title ), $title ) );
        $this->assertTrue( TAP_Toc::heading_matches( 'Vol $1 \&amp; &quot;Ω&quot; 第二巻', $title ) );
    }

    public function test_index_from_href() {
        $this->assertSame( 12, TAP_Toc::chapter_index_from_href( 'https://x/test-series/test-series-c12/', 'test-series' ) );
        $this->assertSame( 12, TAP_Toc::chapter_index_from_href( 'https://x/test-series/test-series-c12', 'test-series' ) );
        $this->assertNull( TAP_Toc::chapter_index_from_href( 'https://x/foo/foo-bar-c3/', 'foo' ) );
        $this->assertSame( 3, TAP_Toc::chapter_index_from_href( 'https://x/foo/foo-c3/', 'foo' ) );
    }

    public function test_parse_sections_and_order() {
        $content = '<p>Synopsis</p>'
            . $this->toc(
                $this->entry( 'test-series-notes', 'Notes' )
                . "\n" . $this->heading( 'Volume 1' )
                . "\n" . $this->entry( 'test-series-illustrations-volume-1', 'Illustrations' )
                . "\n" . $this->entry( 'test-series-c1', 'Chapter 1' )
                . "\n" . $this->entry( 'test-series-c2', 'Chapter 2' )
                . "\n" . $this->heading( 'Volume 2' )
                . "\n" . $this->entry( 'test-series-c3', 'Chapter 3' )
            );

        $entries = TAP_Toc::parse( $content, 'test-series' );

        $this->assertCount( 5, $entries );
        $this->assertSame( [ '', 'Volume 1', 'Volume 1', 'Volume 1', 'Volume 2' ], array_column( $entries, 'heading' ) );
        $this->assertSame( [ null, null, 1, 2, 3 ], array_column( $entries, 'index' ) );
        $this->assertSame( [ false, true, false, false, false ], array_column( $entries, 'illustrations' ) );
        $this->assertSame( [ 'Notes', 'Illustrations', 'Chapter 1', 'Chapter 2', 'Chapter 3' ], array_column( $entries, 'text' ) );
    }
}
