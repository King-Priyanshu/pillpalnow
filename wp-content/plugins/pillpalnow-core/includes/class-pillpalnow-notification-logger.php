<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Notification Logger
 *
 * Handles logging of notification sends for monitoring and debugging.
 *
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Notification_Logger
{
    /**
     * Table name
     */
    private static $table_name = 'pillpalnow_notification_logs';

    /**
     * Initialize the logger
     */
    public static function init()
    {
        add_action('plugins_loaded', array(__CLASS__, 'create_table'));
        add_action('pillpalnow_daily_cleanup', array(__CLASS__, 'cleanup_old_logs'));
    }

    /**
     * Create the logs table
     */
    public static function create_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_email varchar(100) NOT NULL,
            notification_type varchar(50) NOT NULL,
            provider varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text NOT NULL,
            response text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY notification_type (notification_type),
            KEY provider (provider),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log a notification send
     *
     * @param int $user_id User ID
     * @param string $notification_type Type of notification (reminder, refill, missed, etc.)
     * @param string $provider Email provider used (onesignal, fluentcrm)
     * @param string $status Status (sent, failed, skipped)
     * @param string $message Notification message
     * @param string $response API response (optional)
     * @return bool Success
     */
    public static function log($user_id, $notification_type, $provider, $status, $message, $response = '')
    {
        if (!PillPalNow_Admin_Settings::is_logging_enabled()) {
            return true;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $user_info = get_userdata($user_id);
        $user_email = $user_info ? $user_info->user_email : '';

        $data = array(
            'user_id' => $user_id,
            'user_email' => $user_email,
            'notification_type' => $notification_type,
            'provider' => $provider,
            'status' => $status,
            'message' => $message,
            'response' => $response,
            'created_at' => current_time('mysql'),
        );

        $result = $wpdb->insert($table_name, $data);

        return $result !== false;
    }

    /**
     * Log a webhook event (System Log)
     *
     * @param string $event_type Event type (e.g., customer.subscription.updated)
     * @param mixed $payload Webhook payload (array or object)
     * @param string $status Status (received, processed, failed)
     * @param string $message Log message
     * @return bool Success
     */
    public static function log_webhook($event_type, $payload, $status, $message)
    {
        $response = is_string($payload) ? $payload : json_encode($payload);
        // Log with user_id = 0 for system logs
        return self::log(0, $event_type, 'stripe_webhook', $status, $message, $response);
    }

    /**
     * Get logs
     *
     * @param int $limit Number of logs to retrieve
     * @param array $filters Optional filters (user_id, notification_type, provider, status)
     * @return array Log entries
     */
    public static function get_logs($limit = 50, $filters = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $where = array();
        $where_values = array();

        if (isset($filters['user_id']) && is_numeric($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $where_values[] = $filters['user_id'];
        }

        if (!empty($filters['notification_type'])) {
            $where[] = 'notification_type = %s';
            $where_values[] = $filters['notification_type'];
        }

        if (!empty($filters['provider'])) {
            $where[] = 'provider = %s';
            $where_values[] = $filters['provider'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $filters['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = $wpdb->prepare(
            "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d",
            array_merge($where_values, array($limit))
        );

        return $wpdb->get_results($query);
    }

    /**
     * Cleanup old logs (Cron Callback)
     */
    public static function cleanup_old_logs()
    {
        $settings = PillPalNow_Admin_Settings::get_settings();
        $days = isset($settings['log_retention_days']) ? intval($settings['log_retention_days']) : 30;
        self::clear_old_logs($days);
    }

    /**
     * Clear old logs
     *
     * @param int $days_old Delete logs older than this many days (0 = all)
     * @return int Number of logs deleted
     */
    public static function clear_old_logs($days_old = 30)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        if ($days_old > 0) {
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < %s",
                $cutoff_date
            ));
        } else {
            $result = $wpdb->query("DELETE FROM $table_name");
        }

        return $result;
    }

    /**
     * Get logs grouped by user
     *
     * @param int $limit Number of users to retrieve
     * @param array $filters Optional filters
     * @return array Logs grouped by user
     */
    public static function get_logs_grouped_by_user($limit = 50, $filters = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $where = array();
        $where_values = array();

        if (isset($filters['user_id']) && is_numeric($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $where_values[] = $filters['user_id'];
        }

        if (!empty($filters['notification_type'])) {
            $where[] = 'notification_type = %s';
            $where_values[] = $filters['notification_type'];
        }

        if (!empty($filters['provider'])) {
            $where[] = 'provider = %s';
            $where_values[] = $filters['provider'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $where_values[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $where_values[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $where_values[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = $wpdb->prepare(
            "SELECT user_id, user_email,
                    COUNT(*) as total_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    MAX(created_at) as last_notification
             FROM $table_name
             $where_clause
             GROUP BY user_id, user_email
             ORDER BY last_notification DESC
             LIMIT %d",
            array_merge($where_values, array($limit))
        );

        $user_groups = $wpdb->get_results($query);

        // Get detailed logs for each user
        foreach ($user_groups as $user_group) {
            $user_filters = array_merge($filters, array('user_id' => $user_group->user_id));
            $user_group->logs = self::get_logs(100, $user_filters); // Get last 100 logs for this user
        }

        return $user_groups;
    }

    /**
     * Get failed notifications for resend
     *
     * @param array $filters Optional filters
     * @return array Failed notification logs
     */
    public static function get_failed_notifications($filters = array())
    {
        $filters['status'] = 'failed';
        return self::get_logs(0, $filters); // 0 = no limit
    }

    /**
     * Resend a failed notification
     *
     * @param int $log_id Log ID to resend
     * @return bool Success
     */
    public static function resend_notification($log_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $log_id
        ));

        if (!$log) {
            return false;
        }

        // Get user info
        $user_info = get_userdata($log->user_id);
        if (!$user_info) {
            return false;
        }

        // Resend based on provider
        if ($log->provider === 'onesignal') {
            return self::resend_onesignal_notification($log, $user_info);
        } elseif ($log->provider === 'fluentcrm') {
            return self::resend_fluentcrm_notification($log, $user_info);
        }

        return false;
    }

    /**
     * Get valid App ID
     * @return string|false
     */
    private static function get_app_id()
    {
        $valid_pattern = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

        // 1. Check Constant
        if (defined('ONESIGNAL_APP_ID')) {
            $val = ONESIGNAL_APP_ID;
            $clean = preg_replace('/[^a-f0-9\-]/i', '', $val);
            if (preg_match($valid_pattern, $clean) && $val !== 'YOUR_APP_ID_HERE' && $val !== 'YOUR_PRODUCTION_APP_ID') {
                return $clean;
            }
        }

        // 2. Check DB
        $settings = get_option('pillpalnow_notification_settings', array());
        if (!empty($settings['onesignal_app_id'])) {
            $val = $settings['onesignal_app_id'];
            $clean = preg_replace('/[^a-f0-9\-]/i', '', $val);
            if (preg_match($valid_pattern, $clean)) {
                return $clean;
            }
        }

        return false;
    }

    /**
     * Get valid API Key
     * @return string|false
     */
    private static function get_api_key()
    {
        // 1. Check Constant
        if (defined('ONESIGNAL_REST_KEY')) {
            $val = ONESIGNAL_REST_KEY;
            if (strlen($val) > 20 && $val !== 'YOUR_REST_KEY_HERE' && $val !== 'YOUR_PRODUCTION_REST_KEY') {
                return trim($val);
            }
        }

        // 2. Check DB
        $settings = get_option('pillpalnow_notification_settings', array());
        if (!empty($settings['onesignal_api_key'])) {
            $val = $settings['onesignal_api_key'];
            if (strlen($val) > 20) {
                return trim($val);
            }
        }

        return false;
    }

    /**
     * Resend OneSignal notification
     */
    private static function resend_onesignal_notification($log, $user_info)
    {
        $app_id = self::get_app_id();
        $api_key = self::get_api_key();

        if (!$app_id || !$api_key) {
            return false;
        }

        // Parse original message to extract heading and content
        $message_parts = explode(' - ', $log->message, 2);
        $heading = count($message_parts) > 1 ? $message_parts[0] : 'PillPalNow Notification';
        $message = count($message_parts) > 1 ? $message_parts[1] : $log->message;

        $fields = array(
            'app_id' => $app_id,
            'include_email_tokens' => array($user_info->user_email),
            'headings' => array('en' => $heading),
            'contents' => array('en' => $message),
        );

        $fields_json = json_encode($fields);

        // Debug Log
        error_log('[PillPalNow OneSignal Resend] Payload: ' . $fields_json);

        // Check for v2 key format (starts with os_v2_app_)
        $auth_header = (strpos($api_key, 'os_v2_app_') === 0) 
            ? 'Authorization: Bearer ' . $api_key 
            : 'Authorization: Basic ' . $api_key;

        error_log('[PillPalNow Resend Debug] API Key Start: ' . substr($api_key, 0, 10));
        error_log('[PillPalNow Resend Debug] Auth Header: ' . substr($auth_header, 0, 25) . '...');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            $auth_header
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_json);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $status = ($http_code >= 200 && $http_code < 300) ? 'resent' : 'failed';

        // Append debug info if failed
        if ($status === 'failed') {
             $debug_info = "\nDebug: KeyStart=" . substr($api_key, 0, 15) . ", Header=" . $auth_header;
             $response .= $debug_info;
        }

        // Log the resend attempt
        self::log($log->user_id, $log->notification_type, 'onesignal', $status, $log->message . ' (Resent)', $response);

        return $status === 'resent';
    }

    /**
     * Resend FluentCRM notification
     */
    private static function resend_fluentcrm_notification($log, $user_info)
    {
        if (!defined('FLUENTCRM_WEBHOOK_URL') || FLUENTCRM_WEBHOOK_URL === 'YOUR_WEBHOOK_URL_HERE') {
            return false;
        }

        // Parse original message to extract heading and content
        $message_parts = explode(' - ', $log->message, 2);
        $heading = count($message_parts) > 1 ? $message_parts[0] : 'PillPalNow Notification';
        $message = count($message_parts) > 1 ? $message_parts[1] : $log->message;

        $webhook_data = array(
            'email' => $user_info->user_email,
            'trigger' => $log->notification_type,
            'heading' => $heading,
            'message' => $message,
            'priority' => 'normal',
        );

        $response = wp_remote_post(FLUENTCRM_WEBHOOK_URL, array(
            'body' => $webhook_data,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            $status = 'failed';
            $response_body = $response->get_error_message();
        } else {
            $status = ($response['response']['code'] >= 200 && $response['response']['code'] < 300) ? 'resent' : 'failed';
            $response_body = wp_remote_retrieve_body($response);
        }

        // Log the resend attempt
        self::log($log->user_id, $log->notification_type, 'fluentcrm', $status, $log->message . ' (Resent)', $response_body);

        return $status === 'resent';
    }

    /**
     * Send test notification
     *
     * @param string $provider Provider to test
     * @param string $email Test email
     * @param string $type Notification type
     * @return bool Success
     */
    public static function send_test_notification($provider, $email, $type = 'test')
    {
        $heading = 'PillPalNow Test Notification';
        $message = 'This is a test notification to verify your notification settings are working correctly.';

        if ($provider === 'onesignal') {
            $app_id = self::get_app_id();
            $api_key = self::get_api_key();

            if (!$app_id || !$api_key) {
                return false;
            }

            $fields = array(
                'app_id' => $app_id,
                'include_email_tokens' => array($email),
                'headings' => array('en' => $heading),
                'contents' => array('en' => $message),
            );

            $fields_json = json_encode($fields);

            // Debug Log
            error_log('[PillPalNow OneSignal Test] Payload: ' . $fields_json);

            // Check for v2 key format (starts with os_v2_app_)
            $auth_header = (strpos($api_key, 'os_v2_app_') === 0) 
                ? 'Authorization: Bearer ' . $api_key 
                : 'Authorization: Basic ' . $api_key;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8',
                $auth_header
            ));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_json);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $status = ($http_code >= 200 && $http_code < 300) ? 'sent' : 'failed';

            // Log test notification (use admin user ID)
            $admin_user = wp_get_current_user();
            self::log($admin_user->ID, $type, 'onesignal', $status, "$heading - $message (Test)", $response);

            return $status === 'sent';

        } elseif ($provider === 'fluentcrm') {
            if (!defined('FLUENTCRM_WEBHOOK_URL') || FLUENTCRM_WEBHOOK_URL === 'YOUR_WEBHOOK_URL_HERE') {
                return false;
            }

            $webhook_data = array(
                'email' => $email,
                'trigger' => $type,
                'heading' => $heading,
                'message' => $message,
                'priority' => 'normal',
            );

            $response = wp_remote_post(FLUENTCRM_WEBHOOK_URL, array(
                'body' => $webhook_data,
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $status = 'failed';
                $response_body = $response->get_error_message();
            } else {
                $status = ($response['response']['code'] >= 200 && $response['response']['code'] < 300) ? 'sent' : 'failed';
                $response_body = wp_remote_retrieve_body($response);
            }

            // Log test notification
            $admin_user = wp_get_current_user();
            self::log($admin_user->ID, $type, 'fluentcrm', $status, "$heading - $message (Test)", $response_body);

            return $status === 'sent';
        }

        return false;
    }

    /**
     * Get log statistics
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function get_statistics($days = 30)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = array(
            'total_sent' => 0,
            'total_failed' => 0,
            'by_type' => array(),
            'by_provider' => array(),
        );

        // Total sent and failed
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY status",
            $cutoff_date
        ));

        foreach ($results as $result) {
            if ($result->status === 'sent') {
                $stats['total_sent'] = $result->count;
            } elseif ($result->status === 'failed') {
                $stats['total_failed'] = $result->count;
            }
        }

        // By notification type
        $type_results = $wpdb->get_results($wpdb->prepare(
            "SELECT notification_type, status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY notification_type, status",
            $cutoff_date
        ));

        foreach ($type_results as $result) {
            if (!isset($stats['by_type'][$result->notification_type])) {
                $stats['by_type'][$result->notification_type] = array('sent' => 0, 'failed' => 0);
            }
            $stats['by_type'][$result->notification_type][$result->status] = $result->count;
        }

        // By provider
        $provider_results = $wpdb->get_results($wpdb->prepare(
            "SELECT provider, status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY provider, status",
            $cutoff_date
        ));

        foreach ($provider_results as $result) {
            if (!isset($stats['by_provider'][$result->provider])) {
                $stats['by_provider'][$result->provider] = array('sent' => 0, 'failed' => 0);
            }
            $stats['by_provider'][$result->provider][$result->status] = $result->count;
        }

        return $stats;
    }

    /**
     * Check if table exists
     */
    public static function table_exists()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }
}

// Initialize
PillPalNow_Notification_Logger::init();