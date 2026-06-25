<?php
/**
 * Plugin Name: Translation Assistant Publisher
 * Description: Receives chapter exports from Translation Assistant and publishes pages/posts.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TAP_DIR', plugin_dir_path( __FILE__ ) );

require_once TAP_DIR . 'includes/class-auth.php';
require_once TAP_DIR . 'includes/class-publisher.php';
require_once TAP_DIR . 'includes/class-settings.php';

add_action( 'rest_api_init', function () {
    register_rest_route( 'ta-publisher/v1', '/publish', [
        'methods'             => 'POST',
        'callback'            => 'tap_handle_publish',
        'permission_callback' => '__return_true',
    ] );
} );

function tap_handle_publish( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    $required = [ 'api_key', 'series_title', 'series_slug', 'series_title_short',
                  'series_link', 'chapter_index', 'chapter_title', 'chapter_body' ];

    foreach ( $required as $field ) {
        if ( empty( $data[ $field ] ) && $data[ $field ] !== 0 ) {
            return new WP_REST_Response( [ 'error' => "Missing field: {$field}" ], 400 );
        }
    }

    if ( (int) $data['chapter_index'] > 0 && empty( $data['first_line'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing field: first_line' ], 400 );
    }

    $user_id = TAP_Auth::validate_key( $data['api_key'] );
    if ( ! $user_id ) {
        return new WP_REST_Response( [ 'error' => 'Invalid API key' ], 401 );
    }

    $publisher = new TAP_Publisher();
    $result    = $publisher->publish( $data, $user_id );

    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
    }

    return new WP_REST_Response( $result, 200 );
}

$settings = new TAP_Settings();
add_action( 'admin_menu', [ $settings, 'register_menu' ] );
