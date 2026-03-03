<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized OneSignal Service
 *
 * Handles all OneSignal-related operations including sending notifications,
 * managing player IDs, testing connections, and processing webhooks.
 *
 * @package PillPalNow
 */
class PillPalNow_OneSignal_Service
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * OneSignal API endpoint
     */
    const API_URL = 'https://onesignal.com/api/v1/notifications';

    /**
     * Apps API endpoint
     */
    const APPS_API_URL = 'https://onesignal.com/api/v1/apps/';

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
        // Initialize if needed
    }

    /**
     * Send notification with retry logic
     *
     * @param int    $user_id          User ID
     * @param string $heading          Notification heading
     * @param string $message          Notification message
     * @param string $notification_type Type of notification
     * @param string $priority         Priority: 'high', 'normal', 'low'
     * @return bool Success status
     */
    public function send_notification($user_id, $heading, $message, $notification_type, $priority = 'normal')
    {
        // ACCESS CONTROL: OneSignal is a Pro-only feature
        // Exception: Allow 'subscription', 'billing', 'system' types for non-pro access (e.g. payment failed)
        $allowed_types = array('subscription', 'billing', 'system', 'system_alert', 'stripe_webhook');

        if (!in_array($notification_type, $allowed_types) && class_exists('Subscription_Manager') && !Subscription_Manager::is_pro_user($user_id)) {
            PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'blocked', $message, 'Pro subscription required');
            return false;
        }
        // Check if OneSignal is configured
        if (!$this->is_configured()) {
            PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'skipped', $message, 'OneSignal not configured');
            return false;
        }

        // PERMISSION CHECK
        if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::can_user($user_id, PillPalNow_Permissions::CAN_RECEIVE_NOTIFICATIONS)) {
            PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'blocked', $message, 'User permission revoked');
            return false;
        }

        // Rate limiting
        if (!$this->check_rate_limit()) {
            PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'rate_limited', $message, 'Rate limit exceeded');
            // Trigger email fallback
            pillpalnow_send_email_fallback($user_id, $heading, $message, $notification_type, 'Rate limit exceeded');
            return false;
        }

        // Get user info
        $user_info = get_userdata($user_id);
        if (!$user_info) {
            return false;
        }

        // Prepare fields
        $fields = $this->prepare_notification_fields($user_id, $user_info->user_email, $heading, $message, $priority);

        // Send with retry
        return $this->send_with_retry($user_id, $fields, $notification_type, $message);
    }

    /**
     * Send notification directly by OneSignal player ID
     * 
     * This method bypasses user-based targeting and sends directly to a player ID.
     * Useful for webhook integrations where we already have the player ID mapped.
     *
     * @param string $player_id OneSignal player ID
     * @param string $heading   Notification heading
     * @param string $message   Notification message
     * @param string $priority  Priority: 'high', 'normal', 'low'
     * @return bool Success status
     */
    public function send_notification_by_player_id($player_id, $heading, $message, $priority = 'high')
    {
        // Check if OneSignal is configured
        if (!$this->is_configured()) {
            error_log('OneSignal: Not configured, skipping player ID notification');
            return false;
        }

        // Validate player ID
        if (empty($player_id)) {
            error_log('OneSignal: Invalid player ID provided');
            return false;
        }

        // Rate limiting
        if (!$this->check_rate_limit()) {
            error_log('OneSignal: Rate limit exceeded for player ID notification');
            return false;
        }

        // Prepare fields for player ID targeting
        $app_id_clean = $this->get_app_id();
        $fields = array(
            'app_id' => $app_id_clean,
            'include_player_ids' => array($player_id),
            'headings' => array('en' => $heading),
            'contents' => array('en' => $message),
        );

        // Add priority fields
        switch ($priority) {
            case 'high':
                $fields = array_merge($fields, array(
                    'priority' => 10,
                    'ttl' => 86400, // 24 hours
                ));
                break;
            case 'low':
                $fields = array_merge($fields, array(
                    'priority' => 5,
                    'ttl' => 604800, // 7 days
                ));
                break;
            default: // normal
                $fields = array_merge($fields, array(
                    'priority' => 6,
                    'ttl' => 259200, // 3 days
                ));
                break;
        }

        // Send with retry (use 0 as user_id since this is player-based)
        return $this->send_with_retry(0, $fields, 'stripe_webhook', $message);
    }

    /**
     * Store player ID for user
     *
     * @param int    $user_id  User ID
     * @param string $player_id OneSignal player ID
     * @return bool Success
     */
    public function store_player_id($user_id, $player_id)
    {
        if (!is_numeric($user_id) || empty($player_id)) {
            return false;
        }

        return update_user_meta($user_id, 'onesignal_player_id', sanitize_text_field($player_id));
    }

    /**
     * Get player ID for user
     *
     * @param int $user_id User ID
     * @return string|false Player ID or false if not found
     */
    public function get_player_id($user_id)
    {
        return get_user_meta($user_id, 'onesignal_player_id', true);
    }

    /**
     * Test OneSignal connection
     *
     * @param string $app_id  App ID
     * @param string $api_key API Key
     * @return array Result array with 'connected' and 'message'
     */
    public function test_connection($app_id, $api_key)
    {
        if (empty($app_id) || empty($api_key)) {
            return array('connected' => false, 'message' => 'Missing App ID or API Key.');
        }

        $url = self::APPS_API_URL . trim($app_id);

        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . trim($api_key),
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
     * Process webhook data
     *
     * @param array $data Webhook data
     * @return bool Success
     */
    public function process_webhook($data)
    {
        // Placeholder for webhook processing
        // Implement based on OneSignal webhook documentation
        // This could handle delivery receipts, clicks, etc.

        if (!is_array($data)) {
            return false;
        }

        // Log webhook receipt
        error_log('OneSignal Webhook received: ' . json_encode($data));

        // Process based on event type
        if (isset($data['event']) && isset($data['user_id'])) {
            // Handle different event types
            switch ($data['event']) {
                case 'delivered':
                    // Handle delivery confirmation
                    break;
                case 'clicked':
                    // Handle click event
                    break;
                default:
                    // Unknown event
                    break;
            }
        }

        return true;
    }

    /**
     * Check if OneSignal is properly configured
     *
     * @return bool
     */
    /**
     * Get valid App ID
     * Prioritizes valid constant, falls back to DB settings.
     * Ignores placeholders like 'YOUR_PRODUCTION_APP_ID'.
     * @return string|false
     */
    private function get_app_id()
    {
        $valid_pattern = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

        // 1. Check Constant
        if (defined('ONESIGNAL_APP_ID')) {
            $val = ONESIGNAL_APP_ID;
            // Clean it just in case
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
    private function get_api_key()
    {
        // 1. Check Constant
        if (defined('ONESIGNAL_REST_KEY')) {
            $val = ONESIGNAL_REST_KEY;
            // Basic length check (OneSignal keys are usually 48 chars)
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
     * Check if OneSignal is properly configured
     *
     * @return bool
     */
    public function is_configured()
    {
        return $this->get_app_id() && $this->get_api_key();
    }

    /**
     * Check rate limit
     *
     * @return bool True if under limit
     */
    private function prepare_notification_fields($user_id, $email, $heading, $message, $priority)
    {
        // Aggressive sanitization just in case constant has garbage
        $app_id_clean = $this->get_app_id();

        $fields = array(
            'app_id' => $app_id_clean,
            'headings' => array('en' => $heading),
            'contents' => array('en' => $message),
        );

        // Try to use player ID first (more reliable than email)
        $player_id = $this->get_player_id($user_id);
        if (!empty($player_id)) {
            $fields['include_player_ids'] = array($player_id);
        } else {
            // Fallback to email if player ID not available
            $fields['include_email_tokens'] = array($email);
        }

        // Add priority fields
        switch ($priority) {
            case 'high':
                $fields = array_merge($fields, array(
                    'priority' => 10,
                    'ttl' => 86400, // 24 hours
                ));
                break;
            case 'low':
                $fields = array_merge($fields, array(
                    'priority' => 5,
                    'ttl' => 604800, // 7 days
                ));
                break;
            default: // normal
                $fields = array_merge($fields, array(
                    'priority' => 6,
                    'ttl' => 259200, // 3 days
                ));
                break;
        }

        return $fields;
    }
    private function check_rate_limit()
    {
        $rate_limit_key = 'onesignal_api_calls_' . date('Y-m-d-H-i');
        $calls = get_transient($rate_limit_key);
        if ($calls === false) {
            $calls = 0;
        }
        $max_calls_per_minute = 10;
        return $calls < $max_calls_per_minute;
    }

    /**
     * Prepare notification fields
     *
     * @param string $email   User email
     * @param string $heading Notification heading
     * @param string $message Notification message
     * @param string $priority Priority level
     * @return array Fields array
     */

    /**
     * Send notification with retry logic
     *
     * @param int    $user_id          User ID
     * @param array  $fields           Notification fields
     * @param string $notification_type Type
     * @param string $message          Original message
     * @return bool Success
     */
    private function send_with_retry($user_id, $fields, $notification_type, $message)
    {
        $max_retries = 3;
        $retry_delay = 1; // Initial delay in seconds
        $rate_limit_key = 'onesignal_api_calls_' . date('Y-m-d-H-i');

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // Increment rate limit counter
            $calls = get_transient($rate_limit_key);
            if ($calls === false) {
                $calls = 0;
            }
            set_transient($rate_limit_key, $calls + 1, 60);

            $result = $this->make_api_call($fields);

            if ($result['success']) {
                PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'sent', $message, "Success on attempt $attempt");
                return true;
            } else {
                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    $retry_delay *= 2; // Exponential backoff
                }
            }
        }

        // All attempts failed
        PillPalNow_Notification_Logger::log($user_id, $notification_type, 'onesignal', 'failed', $message, "Failed after $max_retries attempts - " . $result['error']);
        // Trigger email fallback
        pillpalnow_send_email_fallback($user_id, $fields['headings']['en'], $message, $notification_type, $result['error']);
        return false;
    }

    /**
     * Make secure API call to OneSignal
     *
     * @param array $fields Notification fields
     * @return array Result with 'success' and 'error'
     */
    private function make_api_call($fields)
    {
        $fields_json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // DEBUG: Log the exact payload and App ID encoding
        error_log('[OneSignal Service] App ID Raw: ' . $fields['app_id']);
        error_log('[OneSignal Service] App ID Hex: ' . bin2hex($fields['app_id']));
        error_log('[OneSignal Service] Full Payload: ' . $fields_json);

        // Check for v2 key format (starts with os_v2_app_)
        $api_key = $this->get_api_key();
        $auth_header = (strpos($api_key, 'os_v2_app_') === 0) 
            ? 'Authorization: Bearer ' . $api_key 
            : 'Authorization: Basic ' . $api_key;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            $auth_header
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_json);
        // Enable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Use system's CA certificate bundle if available
        $ca_bundle = ini_get('curl.cainfo');
        if ($ca_bundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
        }
        // Set timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return array('success' => true);
        } else {
            // Categorize error
            if ($curl_error) {
                $error = "cURL Error: $curl_error";
            } elseif ($http_code == 401) {
                $error = "HTTP $http_code - Invalid API credentials";
            } elseif ($http_code == 429) {
                $error = "HTTP $http_code - Rate limit exceeded";
            } elseif ($http_code >= 400 && $http_code < 500) {
                $error = "HTTP $http_code - Client error";
            } elseif ($http_code >= 500) {
                $error = "HTTP $http_code - Server error";
            } else {
                $error = "HTTP $http_code - Unknown error";
            }

            if ($response) {
                $response_data = json_decode($response, true);
                if (isset($response_data['errors'])) {
                    $error .= " - OneSignal Errors: " . implode(', ', $response_data['errors']);
                }
            }

            return array('success' => false, 'error' => $error);
        }
    }
}