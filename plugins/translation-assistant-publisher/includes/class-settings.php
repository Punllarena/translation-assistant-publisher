<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Settings {

    public function register_menu(): void {
        add_options_page(
            'TA Publisher',
            'TA Publisher',
            'manage_options',
            'ta-publisher',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $message = '';

        // Handle revoke
        if ( isset( $_POST['tap_revoke_nonce'], $_POST['tap_revoke_hash'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tap_revoke_nonce'] ) ), 'tap_revoke' )
        ) {
            TAP_Auth::revoke_key( sanitize_text_field( wp_unslash( $_POST['tap_revoke_hash'] ) ) );
            $message = '<div class="notice notice-success"><p>Key revoked.</p></div>';
        }

        // Handle add key
        $new_key = '';
        if ( isset( $_POST['tap_add_nonce'], $_POST['tap_label'], $_POST['tap_user_id'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tap_add_nonce'] ) ), 'tap_add' )
        ) {
            $label   = sanitize_text_field( wp_unslash( $_POST['tap_label'] ) );
            $user_id = (int) $_POST['tap_user_id'];
            if ( $label && $user_id ) {
                $new_key = TAP_Auth::generate_key( $label, $user_id );
                $message = '<div class="notice notice-success"><p><strong>New API key (shown once):</strong> <code>' . esc_html( $new_key ) . '</code></p></div>';
            }
        }

        $keys  = TAP_Auth::get_all_keys();
        $users = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author' ], 'fields' => [ 'ID', 'display_name' ] ] );
        ?>
        <div class="wrap">
            <h1>Translation Assistant Publisher</h1>
            <?php echo wp_kses_post( $message ); ?>

            <h2>Active Keys</h2>
            <table class="widefat striped">
                <thead><tr><th>Label</th><th>Author</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php if ( empty( $keys ) ) : ?>
                    <tr><td colspan="4">No keys yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $keys as $hash => $key ) :
                        $user = get_userdata( $key['user_id'] );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $key['label'] ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : '(deleted user)' ); ?></td>
                        <td><?php echo esc_html( $key['created'] ); ?></td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field( 'tap_revoke', 'tap_revoke_nonce' ); ?>
                                <input type="hidden" name="tap_revoke_hash" value="<?php echo esc_attr( $hash ); ?>">
                                <button type="submit" class="button button-small">Revoke</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2>Add New Key</h2>
            <form method="post">
                <?php wp_nonce_field( 'tap_add', 'tap_add_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tap_label">Label</label></th>
                        <td><input type="text" id="tap_label" name="tap_label" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="tap_user_id">Author</label></th>
                        <td>
                            <select id="tap_user_id" name="tap_user_id">
                                <?php foreach ( $users as $u ) : ?>
                                    <option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( $u->display_name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><input type="submit" class="button button-primary" value="Generate Key"></p>
            </form>
        </div>
        <?php
    }
}
