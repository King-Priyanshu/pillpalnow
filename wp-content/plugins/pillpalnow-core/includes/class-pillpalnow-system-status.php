<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow System Status
 * 
 * Handles system diagnostics, status checks, and connection tests.
 * Recreated to fix missing class error in admin settings.
 * 
 * @package PillPalNow
 */
class PillPalNow_System_Status
{
    /**
     * Test OneSignal Connection
     * 
     * @param string $app_id
     * @param string $api_key
     * @return array
     */
    public static function test_onesignal_connection($app_id, $api_key)
    {
        if (empty($app_id) || empty($api_key)) {
            return array('connected' => false, 'message' => 'Missing App ID or API Key.');
        }

        $url = "https://onesignal.com/api/v1/apps/" . trim($app_id);

        $api_key = trim($api_key);
        $auth_header = (strpos($api_key, 'os_v2_app_') === 0) 
            ? 'Bearer ' . $api_key 
            : 'Basic ' . $api_key;

        $args = array(
            'headers' => array(
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return array('connected' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            $name = isset($body['name']) ? $body['name'] : 'Unknown App';
            return array('connected' => true, 'message' => "Successfully connected to app: $name", 'data' => $body);
        } else {
            $error_msg = isset($body['errors'][0]) ? $body['errors'][0] : "HTTP $code";
            return array('connected' => false, 'message' => "Connection failed: $error_msg");
        }
    }

    /**
     * Get System Status
     * 
     * @return string 'operational', 'warning', 'error'
     */
    public static function get_system_status()
    {
        // Simple check: do we have DB tables?
        if (!PillPalNow_Notification_Logger::table_exists()) {
            return 'warning';
        }

        // Are API keys set?
        // Check for OneSignal OR PillPalNow Smart API (provider priority list)
        $smart_api_configured = !empty(get_option('pillpalnow_provider_priority'));

        if (!defined('ONESIGNAL_APP_ID') && !$smart_api_configured) {
            return 'warning';
        }

        return 'operational';
    }

    /**
     * Get Status Summary
     * 
     * @return array
     */
    public static function get_status_summary()
    {
        $status = self::get_system_status();
        $message = 'System is experiencing errors.';

        if ($status === 'operational') {
            $message = 'System is operational. All checks passed.';
        } elseif ($status === 'warning') {
            $message = 'System has warnings. Please check configuration.';
        }

        return array(
            'overall_status' => $status,
            'message' => $message
        );
    }

    /**
     * Export Diagnostics
     * 
     * @return array
     */
    public static function export_diagnostics()
    {
        global $wp_version;

        return array(
            'generated_at' => current_time('mysql'),
            'site_url' => site_url(),
            'wordpress_version' => $wp_version,
            'php_version' => phpversion(),
            'pillpalnow_version' => defined('PILLPALNOW_VERSION') ? PILLPALNOW_VERSION : 'Unknown',
            'server_software' => $_SERVER['SERVER_SOFTWARE'],
            'onesignal_configured' => defined('ONESIGNAL_APP_ID') && !empty(ONESIGNAL_APP_ID),
            'fluentcrm_configured' => defined('FLUENTCRM_WEBHOOK_URL'),
            'db_tables' => array(
                'notification_logs' => PillPalNow_Notification_Logger::table_exists(),
            ),
            'recent_stats' => PillPalNow_Notification_Logger::get_statistics(7)
        );
    }

    /**
     * Get Recent Logs
     * 
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs($limit = 50)
    {
        return PillPalNow_Notification_Logger::get_logs($limit);
    }
}
