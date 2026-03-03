<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Secure Token System
 * 
 * Generates and validates HMAC-based tokens for secure subscription
 * management and cancellation links embedded in emails.
 * 
 * Token format: base64url(user_id:action:expiry_timestamp:hmac_signature)
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Secure_Token
{
    /** @var string Token separator */
    const SEPARATOR = ':';

    /** @var int Default expiry for manage links (72 hours) */
    const MANAGE_EXPIRY_HOURS = 72;

    /** @var int Default expiry for cancel links (24 hours) */
    const CANCEL_EXPIRY_HOURS = 24;

    /** @var int Max cancellation attempts per hour per user */
    const RATE_LIMIT_MAX = 5;

    /** @var int Rate limit window in seconds (1 hour) */
    const RATE_LIMIT_WINDOW = 3600;

    /**
     * Generate a secure token
     * 
     * @param int    $user_id      User ID
     * @param string $action       Action type ('manage', 'cancel', 'reactivate')
     * @param int    $expiry_hours Token validity in hours
     * @return string URL-safe base64 encoded token
     */
    public static function generate($user_id, $action = 'manage', $expiry_hours = null)
    {
        if (!$user_id || !$action) {
            return '';
        }

        // Set default expiry based on action
        if ($expiry_hours === null) {
            $expiry_hours = ($action === 'cancel') ? self::CANCEL_EXPIRY_HOURS : self::MANAGE_EXPIRY_HOURS;
        }

        $expiry_timestamp = time() + ($expiry_hours * 3600);
        $payload = implode(self::SEPARATOR, [
            intval($user_id),
            sanitize_key($action),
            $expiry_timestamp
        ]);

        // Generate HMAC signature
        $signature = self::sign($payload);

        // Combine payload + signature
        $token_raw = $payload . self::SEPARATOR . $signature;

        // Base64url encode for URL safety
        return self::base64url_encode($token_raw);
    }

    /**
     * Validate a secure token
     * 
     * @param string $token   The token to validate
     * @param string $action  Expected action (optional, for extra validation)
     * @return array ['valid' => bool, 'user_id' => int, 'action' => string, 'error' => string]
     */
    public static function validate($token, $expected_action = null)
    {
        $result = [
            'valid' => false,
            'user_id' => 0,
            'action' => '',
            'error' => '',
        ];

        if (empty($token)) {
            $result['error'] = 'Empty token';
            return $result;
        }

        // Decode
        $token_raw = self::base64url_decode($token);
        if (!$token_raw) {
            $result['error'] = 'Invalid token encoding';
            return $result;
        }

        // Split parts
        $parts = explode(self::SEPARATOR, $token_raw);
        if (count($parts) !== 4) {
            $result['error'] = 'Invalid token structure';
            return $result;
        }

        list($user_id, $action, $expiry, $signature) = $parts;
        $user_id = intval($user_id);
        $expiry = intval($expiry);

        // Reconstruct payload and verify signature
        $payload = implode(self::SEPARATOR, [$user_id, $action, $expiry]);
        $expected_signature = self::sign($payload);

        if (!hash_equals($expected_signature, $signature)) {
            $result['error'] = 'Invalid signature';
            self::log_security_event($user_id, $action, 'invalid_signature');
            return $result;
        }

        // Check expiry
        if (time() > $expiry) {
            $result['error'] = 'Token expired';
            return $result;
        }

        // Check user exists
        $user = get_userdata($user_id);
        if (!$user) {
            $result['error'] = 'User not found';
            return $result;
        }

        // Check expected action
        if ($expected_action && $action !== $expected_action) {
            $result['error'] = 'Action mismatch';
            return $result;
        }

        // Check if token is revoked
        if (self::is_revoked($token)) {
            $result['error'] = 'Token has been revoked';
            return $result;
        }

        $result['valid'] = true;
        $result['user_id'] = $user_id;
        $result['action'] = $action;

        return $result;
    }

    /**
     * Generate a full "Manage Subscription" URL
     * 
     * @param int $user_id User ID
     * @return string Full URL with token
     */
    public static function get_manage_url($user_id)
    {
        $token = self::generate($user_id, 'manage');
        $base_url = self::get_subscription_page_url();
        return add_query_arg([
            'action' => 'manage',
            'token' => $token,
        ], $base_url);
    }

    /**
     * Generate a full "Cancel Subscription" URL
     * 
     * @param int $user_id User ID
     * @return string Full URL with token
     */
    public static function get_cancel_url($user_id)
    {
        $token = self::generate($user_id, 'cancel');
        $base_url = self::get_subscription_page_url();
        return add_query_arg([
            'action' => 'cancel',
            'token' => $token,
        ], $base_url);
    }

    /**
     * Revoke a token (add to blacklist)
     * 
     * @param string $token Token to revoke
     * @param int    $ttl   How long to keep in blacklist (seconds)
     * @return bool
     */
    public static function revoke($token, $ttl = 86400)
    {
        $hash = md5($token);
        return set_transient('pillpalnow_revoked_token_' . $hash, 1, $ttl);
    }

    /**
     * Check if a token has been revoked
     * 
     * @param string $token Token to check
     * @return bool
     */
    public static function is_revoked($token)
    {
        $hash = md5($token);
        return (bool) get_transient('pillpalnow_revoked_token_' . $hash);
    }

    /**
     * Check and enforce rate limiting for cancellation attempts
     * 
     * @param int $user_id User ID
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_in' => int]
     */
    public static function check_rate_limit($user_id)
    {
        $key = 'pillpalnow_cancel_rate_' . intval($user_id);
        $data = get_transient($key);

        if (!$data) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }

        $elapsed = time() - $data['first_attempt'];

        // Reset window if expired
        if ($elapsed >= self::RATE_LIMIT_WINDOW) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }

        $remaining = max(0, self::RATE_LIMIT_MAX - $data['count']);
        $reset_in = max(0, self::RATE_LIMIT_WINDOW - $elapsed);

        return [
            'allowed' => $data['count'] < self::RATE_LIMIT_MAX,
            'remaining' => $remaining,
            'reset_in' => $reset_in,
        ];
    }

    /**
     * Increment rate limit counter
     * 
     * @param int $user_id User ID
     */
    public static function increment_rate_limit($user_id)
    {
        $key = 'pillpalnow_cancel_rate_' . intval($user_id);
        $data = get_transient($key);

        if (!$data) {
            $data = ['count' => 0, 'first_attempt' => time()];
        }

        $elapsed = time() - $data['first_attempt'];
        if ($elapsed >= self::RATE_LIMIT_WINDOW) {
            $data = ['count' => 1, 'first_attempt' => time()];
        } else {
            $data['count']++;
        }

        set_transient($key, $data, self::RATE_LIMIT_WINDOW);
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    /**
     * Generate HMAC-SHA256 signature
     */
    private static function sign($payload)
    {
        $key = self::get_signing_key();
        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * Get the signing key (WordPress auth salt + plugin-specific pepper)
     */
    private static function get_signing_key()
    {
        $salt = wp_salt('auth');
        $pepper = 'pillpalnow_secure_token_v2';
        return hash('sha256', $salt . $pepper, true);
    }

    /**
     * Base64url encode (URL-safe, no padding)
     */
    private static function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode
     */
    private static function base64url_decode($data)
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * Get the subscription management page URL
     */
    private static function get_subscription_page_url()
    {
        // Try to find the page with our custom slug
        $page = get_page_by_path('manage-subscription');
        if ($page) {
            return get_permalink($page);
        }

        // Fallback to home URL with path
        return home_url('/manage-subscription/');
    }

    /**
     * Log security-related events
     */
    private static function log_security_event($user_id, $action, $event_type)
    {
        error_log(sprintf(
            '[PillPalNow Security] Token %s for user %d, action: %s, IP: %s',
            $event_type,
            $user_id,
            $action,
            self::get_client_ip()
        ));

        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log(
                $user_id,
                'security_' . $event_type,
                'token_system',
                'warning',
                sprintf('Token %s for action: %s from IP: %s', $event_type, $action, self::get_client_ip())
            );
        }
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip()
    {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key]);
                return trim($ip[0]);
            }
        }
        return '0.0.0.0';
    }
}
