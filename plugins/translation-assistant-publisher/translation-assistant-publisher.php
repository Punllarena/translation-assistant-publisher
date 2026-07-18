<?php
/**
 * Plugin Name: Translation Assistant Publisher
 * Description: Receives chapter exports from Translation Assistant and publishes pages/posts.
 * Version:     1.3.3
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
    register_rest_route( 'ta-publisher/v1', '/status', [
        'methods'             => 'GET',
        'callback'            => 'tap_handle_status',
        'permission_callback' => '__return_true',
    ] );
} );

function tap_handle_publish( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( [ 'error' => 'Request body must be valid JSON' ], 400 );
    }

    $required = [ 'api_key', 'series_title', 'series_slug', 'series_title_short',
                  'series_link', 'chapter_index', 'chapter_title', 'chapter_body' ];

    foreach ( $required as $field ) {
        if ( ! isset( $data[ $field ] ) || ( empty( $data[ $field ] ) && $data[ $field ] !== 0 ) ) {
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

function tap_handle_status( WP_REST_Request $request ): WP_REST_Response {
    $api_key     = $request->get_param( 'api_key' ) ?? '';
    $series_slug = sanitize_title( $request->get_param( 'series_slug' ) ?? '' );
    $chapter     = (int) ( $request->get_param( 'chapter' ) ?? -1 );

    if ( ! TAP_Auth::validate_key( $api_key ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid API key' ], 401 );
    }
    if ( $series_slug === '' || $chapter < 0 ) {
        return new WP_REST_Response( [ 'error' => 'Missing series_slug or chapter' ], 400 );
    }

    $publisher = new TAP_Publisher();
    $result    = $publisher->get_chapter_status( $series_slug, $chapter );
    return new WP_REST_Response( $result, 200 );
}

$settings = new TAP_Settings();
add_action( 'admin_menu', [ $settings, 'register_menu' ] );
