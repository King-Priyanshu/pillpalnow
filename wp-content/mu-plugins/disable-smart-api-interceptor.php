<?php
/**
 * Plugin Name: Disable Smart API Email Interceptor
 * Description: Disables the PillPalNow Smart API email interceptor and forces PHPMailer usage
 * Version: 1.0.0
 */

// Hook into plugins_loaded to ensure we run after Smart API is initialized
add_action('plugins_loaded', 'disable_smart_api_interceptor', 20);

function disable_smart_api_interceptor() {
    if (class_exists('PillPalNowSmartAPI')) {
        $api = PillPalNowSmartAPI::get_instance();
        remove_filter('pre_wp_mail', [$api, 'intercept']);
        error_log('PillPalNow Smart API interceptor disabled');
    }
}
?>