<?php
/**
 * Plugin Name: PillPalNow OneSignal Fix
 * Plugin URI: https://pillpalnow.com
 * Description: Fixes OneSignal push notification functionality
 * Version: 1.0.0
 * Author: PillPalNow
 * Author URI: https://pillpalnow.com
 * License: GPL2
 * Text Domain: pillpalnow
 */

if (!defined('ABSPATH')) {
    exit;
}

// Activate the fix
add_action('plugins_loaded', 'pillpalnow_onesignal_fix_init');
function pillpalnow_onesignal_fix_init() {
    // Add missing API endpoint for storing player IDs
    add_action('rest_api_init', 'pillpalnow_add_onesignal_player_id_endpoint');
    
    // Fix the OneSignal service class
    if (class_exists('PillPalNow_OneSignal_Service')) {
        pillpalnow_fix_onesignal_service();
    }
}

// Add the missing player ID endpoint
function pillpalnow_add_onesignal_player_id_endpoint() {
    register_rest_route('pillpalnow/v1', '/onesignal/player-id', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_store_onesignal_player_id',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'player_id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && !empty($param);
                },
            ),
        ),
    ));
}

// Endpoint callback

// Fix the OneSignal service class
function pillpalnow_fix_onesignal_service() {
    // Make is_configured() method public using reflection
    $reflection = new ReflectionClass('PillPalNow_OneSignal_Service');
    $method = $reflection->getMethod('is_configured');
    $method->setAccessible(true);
    
    // Also fix prepare_notification_fields accessibility
    $prepare_method = $reflection->getMethod('prepare_notification_fields');
    $prepare_method->setAccessible(true);
}

// Fix prepare_notification_fields to prioritize player ID
add_action('plugins_loaded', 'pillpalnow_override_prepare_notification_fields');
function pillpalnow_override_prepare_notification_fields() {
    if (class_exists('PillPalNow_OneSignal_Service')) {
        $reflection = new ReflectionClass('PillPalNow_OneSignal_Service');
        $method = $reflection->getMethod('prepare_notification_fields');
        $method->setAccessible(false);
        
        // Override the method using reflection or a wrapper
        global $wp_filter;
        if (!isset($wp_filter['pillpalnow_prepare_notification_fields'])) {
            add_filter('pillpalnow_prepare_notification_fields', 'pillpalnow_custom_prepare_notification_fields', 10, 5);
        }
    }
}

// Custom prepare_notification_fields function
function pillpalnow_custom_prepare_notification_fields($fields, $user_id, $email, $heading, $message, $priority) {
    // Check if we have a player ID and use it instead of email
    $player_id = get_user_meta($user_id, 'onesignal_player_id', true);
    
    if (!empty($player_id)) {
        unset($fields['include_email_tokens']);
        $fields['include_player_ids'] = array($player_id);
    } else if (!isset($fields['include_email_tokens']) && !empty($email)) {
        $fields['include_email_tokens'] = array($email);
    }
    
    return $fields;
}

// Log when the fix is loaded
add_action('init', 'pillpalnow_log_onesignal_fix');
function pillpalnow_log_onesignal_fix() {
    error_log('[PillPalNow] OneSignal integration fix loaded');
}
