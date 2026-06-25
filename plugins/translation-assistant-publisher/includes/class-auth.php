<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Auth {

    private const OPTION = 'ta_publisher_keys';

    public static function validate_key( string $raw_key ): int|false {
        $keys = get_option( self::OPTION, [] );
        $hash = hash( 'sha256', $raw_key );
        if ( isset( $keys[ $hash ] ) ) {
            return (int) $keys[ $hash ]['user_id'];
        }
        return false;
    }

    public static function generate_key( string $label, int $user_id ): string {
        $raw  = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $raw );
        $keys = get_option( self::OPTION, [] );
        $keys[ $hash ] = [
            'user_id' => $user_id,
            'label'   => sanitize_text_field( $label ),
            'created' => gmdate( 'Y-m-d' ),
        ];
        update_option( self::OPTION, $keys );
        return $raw;
    }

    public static function get_all_keys(): array {
        return get_option( self::OPTION, [] );
    }

    public static function revoke_key( string $hash ): void {
        $keys = get_option( self::OPTION, [] );
        unset( $keys[ $hash ] );
        update_option( self::OPTION, $keys );
    }
}
