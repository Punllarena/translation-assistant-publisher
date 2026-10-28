<?php

class TAP_TestCase extends WP_UnitTestCase {

    protected string $key;
    protected int $author;

    public function set_up() {
        parent::set_up();

        $this->author = self::factory()->user->create( [ 'role' => 'editor' ] );
        $this->key    = TAP_Auth::generate_key( 'test', $this->author );

        // Pretty permalinks are what the plugin's <a href> matching assumes.
        $this->set_permalink_structure( '/%postname%/' );
    }

    protected function post_json( string $route, array $body ): WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/ta-publisher/v1' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( array_merge( [ 'api_key' => $this->key ], $body ) ) );
        return rest_do_request( $request );
    }

    protected function get_status( array $params ): WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/ta-publisher/v1/status' );
        $request->set_query_params( array_merge( [ 'api_key' => $this->key ], $params ) );
        return rest_do_request( $request );
    }

    protected function chapter( array $over = [] ): array {
        return array_merge( [
            'series_title'       => 'Test Series',
            'series_slug'        => 'test-series',
            'series_title_short' => 'TS',
            'chapter_index'      => 1,
            'chapter_title'      => 'Chapter 1: Start',
            'chapter_body'       => '<p>One.</p><p>Two.</p>',
            'first_line'         => 'One.',
        ], $over );
    }

    protected function index_page( string $slug = 'test-series' ): WP_Post {
        $page = get_page_by_path( $slug, OBJECT, 'page' );
        $this->assertInstanceOf( WP_Post::class, $page );
        return $page;
    }
}
