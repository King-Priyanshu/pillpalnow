<?php
/**
 * Plugin Name: Disable PillPalNow Smart API
 * Description: Disables the PillPalNow Smart API email interceptor
 * Version: 1.0.0
 */

add_action('plugins_loaded', function() {
    if (class_exists('PillPalNowSmartAPI')) {
        $api = PillPalNowSmartAPI::get_instance();
        remove_filter('pre_wp_mail', array($api, 'intercept'), 1);
        error_log('PillPalNow Smart API interceptor disabled');
    }
}, 100);
?>