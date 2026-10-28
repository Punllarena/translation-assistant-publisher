<?php
/**
 * Plugin Name: Translation Assistant Publisher
 * Description: Receives chapter exports from Translation Assistant and publishes pages/posts.
 * Version:     1.5.5
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
    register_rest_route( 'ta-publisher/v1', '/illustrations', [
        'methods'             => 'POST',
        'callback'            => 'tap_handle_illustrations',
        'permission_callback' => '__return_true',
    ] );
} );

function tap_error_response( WP_Error $error ): WP_REST_Response {
    $data   = $error->get_error_data();
    $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

    if ( ! $status ) {
        $status = match ( $error->get_error_code() ) {
            'renumber_conflict' => 422,
            'no_gallery'        => 404,
            default             => 500,
        };
    }

    return new WP_REST_Response( [ 'error' => $error->get_error_message() ], $status );
}

/**
 * Reject malformed optional fields with a 400. Valid digit strings are
 * normalised to ints in $data so the publisher can keep its (int) casts.
 */
function tap_validate_fields( array &$data ): ?WP_REST_Response {
    foreach ( [ 'chapter_index', 'previous_chapter_index', 'unlock_chapter_index', 'first_chapter_index' ] as $field ) {
        if ( ! array_key_exists( $field, $data ) ) continue;

        $value = $data[ $field ];
        if ( is_int( $value ) ) {
            if ( $value < 0 ) {
                return new WP_REST_Response( [ 'error' => "Invalid field: {$field}" ], 400 );
            }
            continue;
        }
        if ( is_string( $value ) && $value !== '' && ctype_digit( $value ) ) {
            $data[ $field ] = (int) $value;
            continue;
        }
        return new WP_REST_Response( [ 'error' => "Invalid field: {$field}" ], 400 );
    }

    foreach ( [ 'chapter_label', 'volume_title' ] as $field ) {
        if ( array_key_exists( $field, $data ) && ! is_string( $data[ $field ] ) ) {
            return new WP_REST_Response( [ 'error' => "Invalid field: {$field}" ], 400 );
        }
    }

    if ( array_key_exists( 'publish_date', $data ) && ! is_string( $data['publish_date'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Invalid field: publish_date' ], 400 );
    }
    if ( ! empty( $data['publish_date'] ) ) {
        try {
            new DateTime( $data['publish_date'], new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'error' => 'Invalid field: publish_date' ], 400 );
        }
    }

    return null;
}

function tap_handle_publish( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( [ 'error' => 'Request body must be valid JSON' ], 400 );
    }

    // series_link is optional: EPUB-imported series have no source URL.
    $required = [ 'api_key', 'series_title', 'series_slug', 'series_title_short',
                  'chapter_index', 'chapter_title', 'chapter_body' ];

    foreach ( $required as $field ) {
        if ( ! isset( $data[ $field ] ) || ( empty( $data[ $field ] ) && $data[ $field ] !== 0 ) ) {
            return new WP_REST_Response( [ 'error' => "Missing field: {$field}" ], 400 );
        }
    }

    if ( (int) $data['chapter_index'] > 0 && empty( $data['first_line'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing field: first_line' ], 400 );
    }

    $invalid = tap_validate_fields( $data );
    if ( $invalid ) {
        return $invalid;
    }

    $user_id = TAP_Auth::validate_key( $data['api_key'] );
    if ( ! $user_id ) {
        return new WP_REST_Response( [ 'error' => 'Invalid API key' ], 401 );
    }

    $publisher = new TAP_Publisher();
    $result    = $publisher->publish( $data, $user_id );

    if ( is_wp_error( $result ) ) {
        return tap_error_response( $result );
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

function tap_handle_illustrations( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    if ( ! is_array( $data ) ) {
        return new WP_REST_Response( [ 'error' => 'Request body must be valid JSON' ], 400 );
    }

    // series_link is optional: EPUB-imported series have no source URL.
    $required = [ 'api_key', 'series_title', 'series_slug', 'series_title_short' ];
    foreach ( $required as $field ) {
        if ( ! isset( $data[ $field ] ) || ( empty( $data[ $field ] ) && $data[ $field ] !== 0 ) ) {
            return new WP_REST_Response( [ 'error' => "Missing field: {$field}" ], 400 );
        }
    }
    $has_cover = isset( $data['cover'] ) && is_array( $data['cover'] );
    if ( ! is_array( $data['images'] ?? null ) || ( empty( $data['images'] ) && ! $has_cover ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing field: images' ], 400 );
    }
    $mode = $data['mode'] ?? 'replace';
    if ( ! in_array( $mode, [ 'replace', 'append' ], true ) ) {
        return new WP_REST_Response( [ 'error' => "Invalid mode: {$mode}" ], 400 );
    }

    $invalid = tap_validate_fields( $data );
    if ( $invalid ) {
        return $invalid;
    }

    $user_id = TAP_Auth::validate_key( $data['api_key'] );
    if ( ! $user_id ) {
        return new WP_REST_Response( [ 'error' => 'Invalid API key' ], 401 );
    }

    $result = ( new TAP_Publisher() )->publish_illustrations( $data, $user_id );
    if ( is_wp_error( $result ) ) {
        return tap_error_response( $result );
    }
    return new WP_REST_Response( $result, 200 );
}

$settings = new TAP_Settings();
add_action( 'admin_menu', [ $settings, 'register_menu' ] );
