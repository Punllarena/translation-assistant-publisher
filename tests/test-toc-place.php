<?php

class TAP_Toc_Place_Test extends TAP_TestCase {

    private function block( string $slug, string $text ): string {
        return "<!-- wp:paragraph -->\n<p><a href=\"https://example.org/test-series/{$slug}/\">{$text}</a></p>\n<!-- /wp:paragraph -->";
    }

    private function heading( string $title ): string {
        return "<!-- wp:heading {\"level\":3} -->\n<h3>{$title}</h3>\n<!-- /wp:heading -->";
    }

    private function page( array $parts ): string {
        return "<p>Synopsis</p>\n\n<hr />\n\n<div>Table of Contents:</div>\n\n"
            . implode( "\n\n", $parts )
            . "\n\n<!-- ta-toc-end -->";
    }

    private function entry( string $slug, string $text, ?int $index = null, bool $illustrations = false, string $volume = '', ?int $anchor = null ): array {
        return [
            'href'          => "https://example.org/test-series/{$slug}/",
            'text'          => $text,
            'volume_title'  => $volume,
            'index'         => $index,
            'illustrations' => $illustrations,
            'anchor_index'  => null !== $anchor ? $anchor : $index,
        ];
    }

    private function texts( string $content ): array {
        return array_column( TAP_Toc::parse( $content, 'test-series' ), 'text' );
    }

    private function headings( string $content ): array {
        return array_column( TAP_Toc::parse( $content, 'test-series' ), 'heading' );
    }

    public function test_new_chapter_appended_in_existing_volume() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c3', 'Chapter 3', 3, false, 'Volume 1' ) );

        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Chapter 3' ], $this->texts( $result ) );
        $this->assertSame( [ 'Volume 1', 'Volume 1', 'Volume 1' ], $this->headings( $result ) );
    }

    public function test_new_chapter_placed_by_index_within_section() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c3', 'Chapter 3' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', 'Chapter 2', 2, false, 'Volume 1' ) );

        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Chapter 3' ], $this->texts( $result ) );
    }

    public function test_new_volume_section_between_existing() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
            $this->heading( 'Volume 3' ),
            $this->block( 'test-series-c5', 'Chapter 5' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c3', 'Chapter 3', 3, false, 'Volume 2' ) );

        $this->assertSame( [ 'Volume 1', 'Volume 1', 'Volume 2', 'Volume 3' ], $this->headings( $result ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 5' ], $this->texts( $result ) );
    }

    public function test_new_volume_section_at_end_without_index() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c5', 'Chapter 5', 5, false, 'Volume 2' ) );

        $this->assertSame( [ 'Volume 1', 'Volume 2' ], $this->headings( $result ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 5' ], $this->texts( $result ) );
    }

    public function test_illustrations_first_after_heading() {
        $content = $this->page( [
            $this->heading( 'Volume 2' ),
            $this->block( 'test-series-c3', 'Chapter 3' ),
            $this->block( 'test-series-c4', 'Chapter 4' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-illustrations-volume-2', 'Illustrations', null, true, 'Volume 2', 3 ) );

        $this->assertSame( [ 'Illustrations', 'Chapter 3', 'Chapter 4' ], $this->texts( $result ) );
        $this->assertSame( [ 'Volume 2', 'Volume 2', 'Volume 2' ], $this->headings( $result ) );
    }

    public function test_illustrations_creates_section_by_anchor_index() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
            $this->heading( 'Volume 3' ),
            $this->block( 'test-series-c5', 'Chapter 5' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-illustrations-volume-2', 'Illustrations', null, true, 'Volume 2', 3 ) );

        $this->assertSame( [ 'Volume 1', 'Volume 1', 'Volume 2', 'Volume 3' ], $this->headings( $result ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Illustrations', 'Chapter 5' ], $this->texts( $result ) );
    }

    public function test_illustrations_without_volume_first_in_leading_section() {
        $content = $this->page( [
            $this->block( 'test-series-notes', 'Notes' ),
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-illustrations', 'Illustrations', null, true ) );

        $this->assertSame( [ 'Illustrations', 'Notes', 'Chapter 1' ], $this->texts( $result ) );
        $this->assertSame( [ '', '', 'Volume 1' ], $this->headings( $result ) );
    }

    public function test_republish_in_place_updates_text_only() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Old' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c1', 'New', 1, false, 'Volume 1' ) );

        $this->assertSame( str_replace( '>Old<', '>New<', $content ), $result );
    }

    public function test_republish_moves_between_volumes_heading_stays() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
            $this->heading( 'Volume 2' ),
            $this->block( 'test-series-c3', 'Chapter 3' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', 'Chapter 2', 2, false, 'Volume 2' ) );

        $this->assertSame( [ 'Volume 1', 'Volume 2', 'Volume 2' ], $this->headings( $result ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Chapter 3' ], $this->texts( $result ) );
        $this->assertSame( 1, substr_count( $result, '<h3>Volume 1</h3>' ) );
    }

    public function test_republish_reorders_after_renumber() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c4', 'Chapter 4' ),
            $this->block( 'test-series-c2', 'Chapter 3' ),
        ] );

        $result = TAP_Toc::upsert(
            $content,
            'test-series',
            $this->entry( 'test-series-c3', 'Chapter 3', 3, false, 'Volume 1' ),
            'https://example.org/test-series/test-series-c2/'
        );

        $this->assertSame( [ 'Chapter 1', 'Chapter 3', 'Chapter 4' ], $this->texts( $result ) );
        $this->assertSame( [ 1, 3, 4 ], array_column( TAP_Toc::parse( $result, 'test-series' ), 'index' ) );
    }

    public function test_republish_without_volume_never_changes_section() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->heading( 'Volume 2' ),
            $this->block( 'test-series-c2', 'Chapter 2' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', 'Chapter 2', 2 ) );

        $this->assertSame( [ 'Volume 1', 'Volume 2' ], $this->headings( $result ) );
        $this->assertSame( [ 'Chapter 1', 'Chapter 2' ], $this->texts( $result ) );
    }

    public function test_missing_link_readded() {
        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
            $this->block( 'test-series-c3', 'Chapter 3' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', 'Chapter 2', 2, false, 'Volume 1' ) );

        $this->assertSame( [ 'Chapter 1', 'Chapter 2', 'Chapter 3' ], $this->texts( $result ) );
    }

    public function test_special_chars_round_trip() {
        $title = "Vol \$1 \\& \"Ω\" 第二巻";
        $text  = "\$1 \\& \"Second\" 第二話";

        $content = $this->page( [
            $this->heading( 'Volume 1' ),
            $this->block( 'test-series-c1', 'Chapter 1' ),
        ] );

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', $text, 2, false, $title ) );

        $entries = TAP_Toc::parse( $result, 'test-series' );
        $this->assertCount( 2, $entries );
        $this->assertSame( $title, $entries[1]['heading'] );
        $this->assertSame( $text, $entries[1]['text'] );
        $this->assertSame( 1, substr_count( $result, '<h3>' . esc_html( $title ) . '</h3>' ) );
    }

    public function head_shapes(): array {
        $end = '<!-- ta-toc-end -->';
        return [
            'div marker' => [
                "<p>Old</p>\n\n<hr />\n\n<div>Table of Contents:</div>\n\nentry\n{$end}",
                '<div>Table of Contents:</div>',
            ],
            'h2 marker'  => [
                "<p>Old</p>\n\n<hr />\n\n<h2>Table of Contents</h2>\n\nentry\n{$end}",
                '<h2>Table of Contents</h2>',
            ],
            'legacy ul'  => [
                "<p>Old</p>\n\n<hr />\n\n<ul class=\"ta-toc\"><li><a href=\"x\">X</a></li></ul>\n{$end}",
                '<ul class="ta-toc">',
            ],
        ];
    }

    /**
     * @dataProvider head_shapes
     */
    public function test_replace_head_keeps_toc_bytes( string $old, string $marker ) {
        $head = "<p>New</p>\n\n<hr />\n\n";
        $new  = TAP_Toc::replace_head( $old, $head );

        $this->assertSame( substr( $old, strpos( $old, $marker ) ), substr( $new, strlen( $head ) ) );
        $this->assertSame( 1, substr_count( $new, $marker ) );
        $this->assertSame( 1, substr_count( $new, '<!-- ta-toc-end -->' ) );
    }

    public function test_replace_head_without_marker_carries_entries() {
        $old  = "<p>Old</p>\n\n<hr />\n\n<hr />\nentry1\nentry2\n<!-- ta-toc-end -->";
        $head = "<p>New</p>\n\n<hr />\n\n";

        $new = TAP_Toc::replace_head( $old, $head );

        $this->assertStringContainsString( 'entry1', $new );
        $this->assertStringContainsString( 'entry2', $new );
        $this->assertStringNotContainsString( 'Old', $new );
        $this->assertStringContainsString( '<div>Table of Contents:</div>', $new );
        $this->assertSame( 1, substr_count( $new, '<!-- ta-toc-end -->' ) );
    }

    public function test_legacy_ul_without_end_marker() {
        $content = '<div>Header</div>' . "\n"
            . '<ul class="ta-toc"><li><a href="https://example.org/test-series/test-series-c1/">Chapter 1</a></li></ul>';

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c2', 'Chapter 2', 2 ) );

        $this->assertSame( 1, substr_count( $result, '<!-- ta-toc-end -->' ) );
        $this->assertStringNotContainsString( '<h2>Table of Contents</h2>', $result );
        $this->assertGreaterThan( strpos( $result, '</ul>' ), strpos( $result, 'test-series-c2' ) );
    }

    public function test_existing_link_found_by_resolver() {
        $content = "<p>Synopsis</p>\n\n<hr />\n\n<div>Table of Contents:</div>\n\n"
            . "<!-- wp:paragraph -->\n<p><a href=\"http://example.org/test-series/test-series-c3\">Chapter 3</a></p>\n<!-- /wp:paragraph -->"
            . "\n\n<!-- ta-toc-end -->";

        $resolve = static function ( string $href ): int {
            return str_contains( $href, 'test-series-c3' ) ? 42 : 0;
        };

        $result = TAP_Toc::upsert(
            $content,
            'test-series',
            $this->entry( 'test-series-c3', 'Changed', 3 ),
            null,
            $resolve
        );

        $entries = TAP_Toc::parse( $result, 'test-series' );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'Changed', $entries[0]['text'] );
        $this->assertSame( 'https://example.org/test-series/test-series-c3/', $entries[0]['href'] );
    }

    public function test_exact_href_match_skips_resolver() {
        $content = "<div>Table of Contents:</div>\n\n";
        for ( $i = 1; $i <= 50; $i++ ) {
            $content .= TAP_Toc::entry_block( "https://example.org/test-series/test-series-c{$i}/", "Chapter {$i}" ) . "\n\n";
        }
        $content .= '<!-- ta-toc-end -->';

        $calls   = 0;
        $resolve = static function ( string $href ) use ( &$calls ): int {
            $calls++;
            return 0;
        };

        $result = TAP_Toc::upsert( $content, 'test-series', $this->entry( 'test-series-c50', 'Changed', 50 ), null, $resolve );

        $this->assertSame( 0, $calls );
        $this->assertCount( 50, TAP_Toc::parse( $result, 'test-series' ) );
        $this->assertStringContainsString( '>Changed</a>', $result );
    }
}
