<?php
/**
 * Stripe SaaS Access Control
 * 
 * Manages user access flags and roles based on subscription status
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Access
{

    /**
     * Grant access to user
     */
    public static function grant($user_id, $tier_slug, $status = 'active')
    {
        $plans = get_option('stripe_saas_plans', []);

        if (!isset($plans[$tier_slug])) {
            error_log('Stripe SaaS: Invalid tier slug for grant: ' . $tier_slug);
            return false;
        }

        $plan = $plans[$tier_slug];

        // Set access meta flag
        update_user_meta($user_id, $plan['access_meta_key'], 1);
        update_user_meta($user_id, '_stripe_saas_tier', $tier_slug);
        update_user_meta($user_id, '_stripe_saas_granted_at', time());
        update_user_meta($user_id, '_stripe_saas_status', $status);

        // Assign role if specified
        if (!empty($plan['role'])) {
            $user = new WP_User($user_id);
            if (!in_array($plan['role'], (array) $user->roles)) {
                $user->add_role($plan['role']);
            }
        }

        error_log('Stripe SaaS: Granted access to user ' . $user_id . ' for tier ' . $tier_slug . ' with status ' . $status);
        return true;
    }

    /**
     * Revoke access from user
     */
    public static function revoke($user_id)
    {
        $tier_slug = get_user_meta($user_id, '_stripe_saas_tier', true);
        $plans = get_option('stripe_saas_plans', []);

        // Clear access flag
        if ($tier_slug && isset($plans[$tier_slug])) {
            update_user_meta($user_id, $plans[$tier_slug]['access_meta_key'], 0);
        }

        // Update status
        update_user_meta($user_id, '_stripe_saas_status', 'cancelled');
        update_user_meta($user_id, '_stripe_saas_revoked_at', time());

        error_log('Stripe SaaS: Revoked access from user ' . $user_id);
        return true;
    }

    /**
     * Check if user has access
     */
    public static function has_access($user_id, $meta_key = 'has_pro_access')
    {
        return (bool) get_user_meta($user_id, $meta_key, true);
    }

    /**
     * Get user's current tier
     */
    public static function get_tier($user_id)
    {
        return get_user_meta($user_id, '_stripe_saas_tier', true);
    }

    /**
     * Get user's subscription status
     */
    public static function get_status($user_id)
    {
        return get_user_meta($user_id, '_stripe_saas_status', true);
    }

    /**
     * Update subscription expiry
     */
    public static function update_expiry($user_id, $timestamp)
    {
        update_user_meta($user_id, '_stripe_saas_expiry', $timestamp);
    }

    /**
     * Get subscription expiry
     */
    public static function get_expiry($user_id)
    {
        return get_user_meta($user_id, '_stripe_saas_expiry', true);
    }

    /**
     * Grant permanent access (for one-time payments)
     */
    public static function grant_permanent($user_id, $tier_slug)
    {
        $plans = get_option('stripe_saas_plans', []);

        if (!isset($plans[$tier_slug])) {
            error_log('Stripe SaaS: Invalid tier slug for permanent grant: ' . $tier_slug);
            return false;
        }

        $plan = $plans[$tier_slug];

        // Set access meta flag
        update_user_meta($user_id, $plan['access_meta_key'], 1);
        update_user_meta($user_id, '_stripe_saas_tier', $tier_slug);
        update_user_meta($user_id, '_stripe_saas_granted_at', time());
        update_user_meta($user_id, '_stripe_saas_status', 'permanent');
        update_user_meta($user_id, '_stripe_saas_is_permanent', 1);

        // Assign role if specified
        if (!empty($plan['role'])) {
            $user = new WP_User($user_id);
            if (!in_array($plan['role'], (array) $user->roles)) {
                $user->add_role($plan['role']);
            }
        }

        error_log('Stripe SaaS: Granted permanent access to user ' . $user_id . ' for tier ' . $tier_slug);
        return true;
    }

    /**
     * Start free trial for user
     */
    public static function start_free_trial($user_id)
    {
        $global_settings = get_option('stripe_saas_global_settings', []);
        $trial_days = isset($global_settings['trial_days']) ? (int) $global_settings['trial_days'] : 14;

        $trial_start = time();
        $trial_end = $trial_start + ($trial_days * DAY_IN_SECONDS);

        update_user_meta($user_id, '_stripe_saas_trial_start', $trial_start);
        update_user_meta($user_id, '_stripe_saas_trial_end', $trial_end);
        update_user_meta($user_id, '_stripe_saas_trial_granted', 1);
        update_user_meta($user_id, '_stripe_saas_status', 'trialing');

        // Grant temporary access
        update_user_meta($user_id, 'has_saas_access', 1);

        error_log('Stripe SaaS: Started ' . $trial_days . '-day free trial for user ' . $user_id);
        return true;
    }

    /**
     * Get trial status
     * 
     * @return string 'active' | 'expired' | 'none'
     */
    public static function get_trial_status($user_id)
    {
        $trial_granted = get_user_meta($user_id, '_stripe_saas_trial_granted', true);

        if (!$trial_granted) {
            return 'none';
        }

        $trial_end = get_user_meta($user_id, '_stripe_saas_trial_end', true);

        if (!$trial_end) {
            return 'none';
        }

        return (time() < $trial_end) ? 'active' : 'expired';
    }

    /**
     * Check if trial is expired
     */
    public static function is_trial_expired($user_id)
    {
        return self::get_trial_status($user_id) === 'expired';
    }

    /**
     * Get days remaining in trial
     */
    public static function get_trial_days_remaining($user_id)
    {
        $trial_end = get_user_meta($user_id, '_stripe_saas_trial_end', true);

        if (!$trial_end) {
            return 0;
        }

        $seconds_remaining = $trial_end - time();
        return max(0, ceil($seconds_remaining / DAY_IN_SECONDS));
    }

    /**
     * Check if user has any form of access
     * 
     * Considers: active subscription, trial, or permanent access
     */
    public static function user_has_access($user_id)
    {
        // Check permanent access
        if (get_user_meta($user_id, '_stripe_saas_is_permanent', true)) {
            return true;
        }

        // Check active subscription
        $status = self::get_status($user_id);
        if (in_array($status, ['active', 'trialing'])) {
            return true;
        }

        // Check trial
        if (self::get_trial_status($user_id) === 'active') {
            return true;
        }

        return false;
    }

    /**
     * Get access mode from global settings
     */
    public static function get_access_mode()
    {
        $global_settings = get_option('stripe_saas_global_settings', []);
        return isset($global_settings['access_mode']) ? $global_settings['access_mode'] : 'subscribe_first';
    }

    /**
     * Get trial mode from global settings
     * 
     * @return string 'no_cc' | 'require_cc'
     */
    public static function get_trial_mode()
    {
        $global_settings = get_option('stripe_saas_global_settings', []);
        return isset($global_settings['trial_mode']) ? $global_settings['trial_mode'] : 'no_cc';
    }

    /**
     * Initialize access control hooks
     */
    public static function init()
    {
        add_action('template_redirect', [__CLASS__, 'check_access_control']);
        add_action('wp_login', [__CLASS__, 'handle_user_login'], 10, 2);
        add_action('user_register', [__CLASS__, 'handle_user_registration']);
    }

    /**
     * Handle user login - start trial for free_first mode (no_cc only)
     */
    public static function handle_user_login($user_login, $user)
    {
        $access_mode = self::get_access_mode();
        $trial_mode = self::get_trial_mode();

        // Only for free_first mode with no CC required
        if ($access_mode !== 'free_first' || $trial_mode !== 'no_cc') {
            return;
        }

        // Check if trial already granted
        $trial_granted = get_user_meta($user->ID, '_stripe_saas_trial_granted', true);
        if ($trial_granted) {
            return;
        }

        // Check if user already has subscription
        if (self::user_has_active_subscription($user->ID)) {
            return;
        }

        // Start free trial (no CC mode - local trial)
        self::start_free_trial($user->ID);
        error_log('Stripe SaaS: Auto-started local trial for user ' . $user->ID . ' on first login (free_first, no_cc mode)');
    }

    /**
     * Handle user registration
     * 
     * For require_cc mode: flag user so they are redirected to Stripe Checkout on next page load
     * 
     * @param int $user_id Newly registered user ID
     */
    public static function handle_user_registration($user_id)
    {
        $access_mode = self::get_access_mode();
        $trial_mode = self::get_trial_mode();

        // Only for free_first mode with CC required
        if ($access_mode !== 'free_first' || $trial_mode !== 'require_cc') {
            return;
        }

        // Flag user for checkout redirect
        update_user_meta($user_id, '_stripe_saas_needs_checkout', 1);
        error_log('Stripe SaaS: Flagged user ' . $user_id . ' for Stripe Checkout redirect (require_cc mode)');
    }

    /**
     * Main access control middleware
     * 
     * Checks access based on mode and redirects to pricing page when needed
     */
    public static function check_access_control()
    {
        // Skip if not logged in
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $access_mode = self::get_access_mode();

        // Skip for admins
        if (current_user_can('manage_options')) {
            return;
        }

        // Get the pricing page URL (page with [choose_plan] shortcode)
        $pricing_page_url = self::get_pricing_page_url();

        // Skip if already on pricing page or admin pages
        if (is_admin() || self::is_pricing_page()) {
            return;
        }

        // Skip for AJAX, REST API, and cron
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
            return;
        }

        // MODE 1: Subscribe First
        if ($access_mode === 'subscribe_first') {
            // User MUST have an active subscription
            $has_subscription = self::user_has_active_subscription($user_id);

            if (!$has_subscription) {
                // Block access, redirect to pricing
                self::redirect_to_pricing($pricing_page_url, 'subscription_required');
                exit;
            }
        }

        // MODE 2: Free First
        elseif ($access_mode === 'free_first') {
            $trial_mode = self::get_trial_mode();
            $trial_status = self::get_trial_status($user_id);
            $has_subscription = self::user_has_active_subscription($user_id);

            // Allow access if user has active subscription or active trial
            if ($has_subscription) {
                return;
            }

            if ($trial_status === 'active') {
                return;
            }

            // CC Required mode: user just registered and needs to go to checkout
            if ($trial_mode === 'require_cc') {
                $needs_checkout = get_user_meta($user_id, '_stripe_saas_needs_checkout', true);
                if ($needs_checkout) {
                    delete_user_meta($user_id, '_stripe_saas_needs_checkout');
                    self::redirect_to_pricing($pricing_page_url, 'subscription_required');
                    exit;
                }
            }

            // Trial expired and no subscription = block access
            if ($trial_status === 'expired' && !$has_subscription) {
                self::redirect_to_pricing($pricing_page_url, 'trial_expired');
                exit;
            }

            // No trial started yet
            if ($trial_status === 'none' && !$has_subscription) {
                if ($trial_mode === 'no_cc') {
                    // Auto-start local trial for existing users who missed login hook
                    self::start_free_trial($user_id);
                    return;
                } else {
                    // require_cc mode — redirect to pricing
                    self::redirect_to_pricing($pricing_page_url, 'subscription_required');
                    exit;
                }
            }
        }
    }

    /**
     * Check if user has active subscription (not trial)
     */
    private static function user_has_active_subscription($user_id)
    {
        // Check permanent access
        if (get_user_meta($user_id, '_stripe_saas_is_permanent', true)) {
            return true;
        }

        // Check active subscription status
        $status = self::get_status($user_id);
        if (in_array($status, ['active', 'trialing'])) {
            return true;
        }

        return false;
    }

    /**
     * Get pricing page URL (page that contains [choose_plan] shortcode)
     */
    private static function get_pricing_page_url()
    {
        // Try to find page with [choose_plan] shortcode
        $pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            's' => '[choose_plan]',
            'post_status' => 'publish'
        ]);

        if (!empty($pages)) {
            return get_permalink($pages[0]->ID);
        }

        // Fallback: check for common page slugs
        $common_slugs = ['pricing', 'plans', 'choose-plan', 'subscribe'];
        foreach ($common_slugs as $slug) {
            $page = get_page_by_path($slug);
            if ($page) {
                return get_permalink($page->ID);
            }
        }

        // Last resort: homepage
        return home_url('/');
    }

    /**
     * Check if currently viewing pricing page
     */
    private static function is_pricing_page()
    {
        global $post;

        if (!$post) {
            return false;
        }

        // Check if page contains [choose_plan] shortcode
        return has_shortcode($post->post_content, 'choose_plan') ||
            has_shortcode($post->post_content, 'stripe_saas_subscribe');
    }

    /**
     * Redirect to pricing page with reason
     */
    private static function redirect_to_pricing($url, $reason = '')
    {
        if ($reason) {
            $url = add_query_arg('reason', $reason, $url);
        }

        wp_redirect($url);
        exit;
    }
}


