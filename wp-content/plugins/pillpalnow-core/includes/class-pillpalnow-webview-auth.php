<?php
/**
 * WebView Authentication Handler
 * 
 * Ensures persistent sessions in AppMySite WebView
 * 
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

class PillPalNow_WebView_Auth
{

    /**
     * Initialize WebView auth hooks
     */
    public static function init()
    {
        // Extend cookie lifetime for WebView
        add_filter('auth_cookie_expiration', [__CLASS__, 'extend_cookie_lifetime'], 10, 3);

        // Add WebView detection header
        add_action('send_headers', [__CLASS__, 'add_webview_headers']);

        // Prevent session timeout in WebView
        add_action('wp_loaded', [__CLASS__, 'refresh_session_if_webview']);

        // Add WebView body class via filter
        add_filter('body_class', [__CLASS__, 'add_webview_body_class']);
    }

    /**
     * Detect if current request is from a WebView
     * 
     * @return bool
     */
    public static function is_webview()
    {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        return (
            // AppMySite specific detection
            strpos($user_agent, 'AppMySite') !== false ||
            // Generic WebView detection
            strpos($user_agent, 'wv') !== false ||
            strpos($user_agent, 'WebView') !== false ||
                // Android WebView pattern
            (strpos($user_agent, 'Android') !== false && strpos($user_agent, 'Version/') !== false) ||
            // URL parameter for testing
            isset($_GET['webview']) ||
            // Cookie set by client-side JS
            isset($_COOKIE['pillpalnow_webview'])
        );
    }

    /**
     * Extend cookie lifetime for WebView and "Remember Me" logins
     * 
     * @param int $expiration Default expiration
     * @param int $user_id User ID
     * @param bool $remember Whether "Remember Me" was checked
     * @return int
     */
    public static function extend_cookie_lifetime($expiration, $user_id, $remember)
    {
        // Extend to 1 year for WebView or remember me
        if (self::is_webview() || $remember) {
            return 365 * DAY_IN_SECONDS;
        }
        return $expiration;
    }

    /**
     * Add WebView detection headers and cookies
     */
    public static function add_webview_headers()
    {
        if (!self::is_webview()) {
            return;
        }

        // Add header for debugging/middleware
        if (!headers_sent()) {
            header('X-PillPalNow-WebView: 1');
        }

        // Set persistent cookie for server-side detection on subsequent requests
        if (!isset($_COOKIE['pillpalnow_webview'])) {
            $secure = is_ssl();
            $httponly = false; // Allow JS access for client-side detection

            setcookie(
                'pillpalnow_webview',
                '1',
                time() + YEAR_IN_SECONDS,
                COOKIEPATH,
                COOKIE_DOMAIN,
                $secure,
                $httponly
            );
        }
    }

    /**
     * Refresh session for WebView users to prevent timeout
     */
    public static function refresh_session_if_webview()
    {
        if (!self::is_webview() || !is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $last_activity = get_user_meta($user_id, 'pillpalnow_last_activity', true);
        $now = time();

        // Refresh auth cookies if last activity was more than 24 hours ago
        if (!$last_activity || ($now - (int) $last_activity) > DAY_IN_SECONDS) {
            // Refresh the auth cookie with remember=true for extended lifetime
            wp_set_auth_cookie($user_id, true);
            update_user_meta($user_id, 'pillpalnow_last_activity', $now);
        }
    }

    /**
     * Add webview-mode class to body element
     * 
     * @param array $classes Existing body classes
     * @return array
     */
    public static function add_webview_body_class($classes)
    {
        if (self::is_webview()) {
            $classes[] = 'webview-mode';
        }
        return $classes;
    }

    /**
     * Check if user is authenticated (for API endpoints)
     * 
     * @return bool
     */
    public static function is_authenticated()
    {
        return is_user_logged_in();
    }

    /**
     * Get current session info for debugging
     * 
     * @return array
     */
    public static function get_session_info()
    {
        if (!is_user_logged_in()) {
            return [
                'authenticated' => false,
                'is_webview' => self::is_webview()
            ];
        }

        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        return [
            'authenticated' => true,
            'is_webview' => self::is_webview(),
            'user_id' => $user_id,
            'user_email' => $user->user_email,
            'display_name' => $user->display_name,
            'last_activity' => get_user_meta($user_id, 'pillpalnow_last_activity', true)
        ];
    }
}

// Initialize
PillPalNow_WebView_Auth::init();
