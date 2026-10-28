<?php

class TAP_Errors_Test extends TAP_TestCase {

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

    private function conflict(): array {
        $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => 3, 'chapter_title' => 'Chapter 3' ] ) );
        $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => 4, 'chapter_title' => 'Chapter 4' ] ) );
        return $this->chapter( [
            'chapter_index'          => 4,
            'previous_chapter_index' => 3,
            'chapter_title'          => 'Chapter 4',
            'first_line'             => 'Four.',
        ] );
    }

    public function test_renumber_conflict_is_422() {
        $r = $this->post_json( '/publish', $this->conflict() );
        $this->assertSame( 422, $r->get_status() );
        $this->assertArrayHasKey( 'error', $r->get_data() );
    }

    public function test_append_without_gallery_is_404() {
        $r = $this->post_json( '/illustrations', $this->illustrations( [ 'mode' => 'append' ] ) );
        $this->assertSame( 404, $r->get_status() );
        $this->assertArrayHasKey( 'error', $r->get_data() );
    }

    public function test_missing_field_is_400() {
        $body = $this->chapter();
        unset( $body['series_title'] );
        $r = $this->post_json( '/publish', $body );
        $this->assertSame( 400, $r->get_status() );
    }

    public function test_bad_key_is_401() {
        $r = $this->post_json( '/publish', $this->chapter( [ 'api_key' => 'not-a-key' ] ) );
        $this->assertSame( 401, $r->get_status() );
    }

    /**
     * @dataProvider malformed_fields
     */
    public function test_malformed_fields_are_400( string $route, array $over ) {
        $body = '/publish' === $route ? $this->chapter( $over ) : $this->illustrations( $over );
        $r    = $this->post_json( $route, $body );

        $this->assertSame( 400, $r->get_status() );
        $this->assertArrayHasKey( 'error', $r->get_data() );
    }

    public function malformed_fields(): array {
        return [
            'chapter_index string'       => [ '/publish', [ 'chapter_index' => 'abc' ] ],
            'chapter_index negative'     => [ '/publish', [ 'chapter_index' => -1 ] ],
            'chapter_index float'        => [ '/publish', [ 'chapter_index' => 1.5 ] ],
            'previous_chapter_index'     => [ '/publish', [ 'previous_chapter_index' => 'x' ] ],
            'unlock_chapter_index array' => [ '/publish', [ 'unlock_chapter_index' => [] ] ],
            'chapter_label int'          => [ '/publish', [ 'chapter_label' => 5 ] ],
            'volume_title array'         => [ '/publish', [ 'volume_title' => [] ] ],
            'publish_date bad'           => [ '/publish', [ 'publish_date' => 'not a date' ] ],
            'first_chapter_index bad'    => [ '/illustrations', [ 'first_chapter_index' => 'x' ] ],
            'illus publish_date bad'     => [ '/illustrations', [ 'publish_date' => 'nope' ] ],
        ];
    }

    public function test_digit_string_index_accepted() {
        $r = $this->post_json( '/publish', $this->chapter( [ 'chapter_index' => '3', 'chapter_title' => 'Chapter 3' ] ) );
        $this->assertSame( 200, $r->get_status() );
        $this->assertNotNull( get_page_by_path( 'test-series/test-series-c3', OBJECT, 'page' ) );
    }

    public function test_never_returns_409() {
        $cases = [ [ '/publish', $this->conflict() ] ];
        $cases[] = [ '/illustrations', $this->illustrations( [ 'mode' => 'append' ] ) ];
        $missing = $this->chapter();
        unset( $missing['series_title'] );
        $cases[] = [ '/publish', $missing ];
        $cases[] = [ '/publish', $this->chapter( [ 'api_key' => 'not-a-key' ] ) ];
        foreach ( $this->malformed_fields() as $case ) {
            $cases[] = $case;
        }

        foreach ( $cases as $case ) {
            $body = '/publish' === $case[0] ? $this->chapter( $case[1] ) : $this->illustrations( $case[1] );
            if ( isset( $case[1]['api_key'] ) ) {
                $body['api_key'] = $case[1]['api_key'];
            }
            $r = $this->post_json( $case[0], $body );
            $this->assertNotSame( 409, $r->get_status() );
        }
    }
}
