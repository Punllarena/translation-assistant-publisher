<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
    require '/app/plugins/translation-assistant-publisher/translation-assistant-publisher.php';
} );

require $_tests_dir . '/includes/bootstrap.php';

// The base case is not named test-*.php, so PHPUnit does not load it itself.
require_once __DIR__ . '/class-tap-testcase.php';
