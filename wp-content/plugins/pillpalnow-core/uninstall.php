<?php
/**
 * Uninstall Script for PillPalNow Plugin
 * 
 * This file is executed when the plugin is uninstalled via WordPress admin.
 * It removes all plugin data including:
 * - Custom post types and their posts
 * - Post meta data
 * - Plugin options
 * - Transients
 * - Scheduled cron events
 * 
 * @package PillPalNow
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// =====================================================
// Remove Custom Post Types and Associated Data
// =====================================================

$custom_post_types = array(
    'medication',
    'dose_log',
    'reminder_log',
    'refill_request',
    'family_member'
);

foreach ($custom_post_types as $post_type) {
    $posts = get_posts(array(
        'post_type' => $post_type,
        'numberposts' => -1,
        'post_status' => 'any'
    ));

    foreach ($posts as $post) {
        // Delete all post meta first
        $post_meta = get_post_meta($post->ID);
        if (is_array($post_meta)) {
            foreach ($post_meta as $meta_key => $meta_values) {
                delete_post_meta($post->ID, $meta_key);
            }
        }

        // Delete the post (force delete, skip trash)
        wp_delete_post($post->ID, true);
    }
}

// =====================================================
// Remove Plugin Options
// =====================================================

$plugin_options = array(
    'pillpalnow_notification_settings',
    'pillpalnow_validation_issues',
    'pillpalnow_last_validation_run',
    'pillpalnow_admin_message',
    'pillpalnow_db_version',
    'pillpalnow_plugin_version',
);

foreach ($plugin_options as $option_name) {
    delete_option($option_name);
}

// =====================================================
// Remove User Meta (if any)
// =====================================================

global $wpdb;

// Delete all user meta keys related to the plugin
$user_meta_keys = array(
    'onesignal_player_id',
    'pillpalnow_notification_preferences',
    'pillpalnow_user_settings',
);

foreach ($user_meta_keys as $meta_key) {
    $wpdb->delete(
        $wpdb->usermeta,
        array('meta_key' => $meta_key),
        array('%s')
    );
}

// =====================================================
// Clear All Transients
// =====================================================

// Delete rate limiting transients
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE '_transient_pillpalnow_%' 
     OR option_name LIKE '_transient_timeout_pillpalnow_%'"
);

// Delete specific transient patterns
$transient_patterns = array(
    'pillpalnow_rate_limit_%',
    'pillpalnow_form_limit_%',
    'pillpalnow_auth_log_%',
    'pillpalnow_auth_block_%',
    'pillpalnow_drug_search_%',
    'pillpalnow_drug_rxcui_%',
    'pillpalnow_drug_name_%',
    'pillpalnow_reminder_dedup_%',
    'pillpalnow_stock_cache_%',
);

foreach ($transient_patterns as $pattern) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_' . $pattern,
            '_transient_timeout_' . $pattern
        )
    );
}

// =====================================================
// Clear Scheduled Cron Events
// =====================================================

$cron_hooks = array(
    'pillpalnow_daily_refill_check',
    'pillpalnow_run_reminders_check',
    'pillpalnow_cleanup_old_logs',
);

foreach ($cron_hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }

    // Clear all instances of this hook (in case there are multiple)
    wp_clear_scheduled_hook($hook);
}

// =====================================================
// Remove Uploaded Files
// =====================================================

// Remove service worker files
$upload_dir = wp_upload_dir();
$service_worker_dir = $upload_dir['basedir'] . '/pillpalnow-service-workers/';

if (is_dir($service_worker_dir)) {
    // Delete all files in the directory
    $files = glob($service_worker_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // Remove the directory
    @rmdir($service_worker_dir);
}

// =====================================================
// Remove Custom Database Tables (if any exist)
// =====================================================

// If you have custom tables, drop them here
// Example:
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pillpalnow_notifications");
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pillpalnow_logs");

// =====================================================
// Clear WordPress Object Cache
// =====================================================

wp_cache_flush();

// =====================================================
// Log Uninstall Event (Optional - for debugging)
// =====================================================

// If WP_DEBUG is enabled, log the uninstall
if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    error_log('PillPalNow Plugin: Uninstall completed successfully at ' . current_time('mysql'));
}

// =====================================================
// Rewrite Rules Flush
// =====================================================

// Flush rewrite rules to remove custom service worker rewrite
flush_rewrite_rules();
