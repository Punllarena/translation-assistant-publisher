<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'ta_publisher_keys' );
delete_post_meta_by_key( '_tap_toc_cache' );
