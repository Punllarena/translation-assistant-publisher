<?php

class TAP_Smoke_Test extends TAP_TestCase {

    public function test_publish_creates_page_and_post() {
        $r = $this->post_json( '/publish', $this->chapter() );
        $this->assertSame( 200, $r->get_status() );
        $this->assertTrue( $r->get_data()['created'] );
        $this->assertNotNull( get_page_by_path( 'test-series/test-series-c1', OBJECT, 'page' ) );
    }
}
