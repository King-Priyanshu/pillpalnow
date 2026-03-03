<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Subscription_Manager
 * Handles all subscription-related logic for Pro Cloud Sync
 */
class Subscription_Manager
{
    /**
     * Meta keys used for subscription
     */
    const META_KEY_PRO_USER = 'pillpalnow_pro_user';
    const META_KEY_SUB_STATUS = 'pillpalnow_sub_status'; // active, trialing, past_due, canceled, expired, unpaid
    const META_KEY_START_DATE = 'pillpalnow_sub_start_date';
    const META_KEY_EXPIRY_DATE = 'pillpalnow_sub_expiry_date';
    const META_KEY_PLATFORM = 'pillpalnow_sub_platform'; // ios, android, web

    /**
     * Family member limits
     */
    const MAX_FREE_FAMILY_MEMBERS = 5;

    /**
     * Initialize the class
     */
    public static function init()
    {
        // Hook into login to re-validate subscription if needed
        add_action('wp_login', array(__CLASS__, 'check_status_on_login'), 10, 2);

        // Register REST API endpoints
        add_action('rest_api_init', array(__CLASS__, 'register_api_endpoints'));
    }

    /**
     * Check if a user is a Pro user
     * 
     * @param int $user_id User ID
     * @return bool True if Pro, False otherwise
     */
    public static function is_pro_user($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        // Check Access Status (Single Source of Truth)
        $access = self::check_access_status($user_id);

        return $access['granted'];
    }

    /**
     * Strict Access Control Check
     * 
     * @param int $user_id
     * @return array ['granted' => bool, 'reason' => string]
     */
    public static function check_access_status($user_id)
    {
        $status = get_user_meta($user_id, self::META_KEY_SUB_STATUS, true);

        // Default to free if no status
        if (!$status) {
            return ['granted' => false, 'reason' => 'free', 'status' => 'free'];
        }

        // Validate Expiry
        if (self::is_subscription_expired($user_id)) {
            // If expired, ensure status reflects it if not already
            if ($status === 'active' || $status === 'trialing') {
                // Soft expire locally if date passed but webhook didn't update yet (grace period handling could be added here)
                // For strict rules:
                return ['granted' => false, 'reason' => 'expired_date', 'status' => $status];
            }
        }

        // Strict Rules
        switch ($status) {
            case 'active':
            case 'trialing':
                return ['granted' => true, 'reason' => 'subscription_active', 'status' => $status];

            case 'cancelling': // Cancel requested but still within billing period
                return ['granted' => true, 'reason' => 'cancelling_grace_period', 'status' => $status];

            case 'past_due':
                return ['granted' => false, 'reason' => 'past_due', 'status' => $status];

            case 'unpaid':
                return ['granted' => false, 'reason' => 'unpaid', 'status' => $status];

            case 'canceled': // Stripe uses 'canceled'
            case 'cancelled':
                // Check if they are in the "grace period" until period end
                if (!self::is_subscription_expired($user_id)) {
                    return ['granted' => true, 'reason' => 'cancelled_grace_period', 'status' => $status];
                }
                return ['granted' => false, 'reason' => 'cancelled', 'status' => $status];

            case 'expired':
                return ['granted' => false, 'reason' => 'expired', 'status' => $status];

            default:
                return ['granted' => false, 'reason' => 'unknown_status', 'status' => $status];
        }
    }

    /**
     * Check if subscription is expired based on date
     * 
     * @param int $user_id
     * @return bool
     */
    public static function is_subscription_expired($user_id)
    {
        $expiry = get_user_meta($user_id, self::META_KEY_EXPIRY_DATE, true);

        if (!$expiry) {
            return true; // No expiry date means arguably not active unless lifetime (which we don't have yet)
        }

        return (current_time('timestamp') > $expiry);
    }

    /**
     * Activate Subscription (Stripe or Manual)
     * 
     * @param int $user_id
     * @param string $plan_id
     * @param string $stripe_customer_id
     * @param string $stripe_subscription_id
     * @param string $status
     */
    public static function activate_subscription($user_id, $plan_id = 'pro_monthly', $stripe_customer_id = '', $stripe_subscription_id = '', $status = 'active')
    {
        update_user_meta($user_id, self::META_KEY_PRO_USER, true);
        update_user_meta($user_id, self::META_KEY_SUB_STATUS, $status);
        update_user_meta($user_id, 'pillpalnow_plan_id', $plan_id);

        if ($stripe_customer_id) {
            update_user_meta($user_id, 'stripe_customer_id', $stripe_customer_id);
        }

        if ($stripe_subscription_id) {
            update_user_meta($user_id, 'stripe_subscription_id', $stripe_subscription_id);
        }

        update_user_meta($user_id, self::META_KEY_START_DATE, current_time('timestamp'));

        // Default 30 days if no update follows immediately
        // Ideally this is overwritten by update_subscription_details right after
        if (!get_user_meta($user_id, self::META_KEY_EXPIRY_DATE, true)) {
            $expiry_date = current_time('timestamp') + (30 * DAY_IN_SECONDS);
            update_user_meta($user_id, self::META_KEY_EXPIRY_DATE, $expiry_date);
        }

        // Trigger action for other components (e.g., ads, groups)
        do_action('pillpalnow_pro_activated', $user_id);
    }

    /**
     * Update Subscription Details (from Webhook)
     * 
     * @param int $user_id
     * @param string $status
     * @param int $current_period_end (Timestamp)
     * @param bool $cancel_at_period_end
     */
    public static function update_subscription_details($user_id, $status, $current_period_end, $cancel_at_period_end)
    {
        update_user_meta($user_id, self::META_KEY_SUB_STATUS, $status);
        update_user_meta($user_id, self::META_KEY_EXPIRY_DATE, $current_period_end);
        update_user_meta($user_id, 'pillpalnow_cancel_at_period_end', $cancel_at_period_end);

        // Ensure Pro status is accurate based on status
        if ($status === 'active' || $status === 'trialing') {
            update_user_meta($user_id, self::META_KEY_PRO_USER, true);
        } else if ($status === 'past_due' || $status === 'unpaid' || $status === 'expired') {
            update_user_meta($user_id, self::META_KEY_PRO_USER, false);
        } else if ($status === 'canceled') {
            // If canceled, we might still be pro if within period
            // The check_access_status handles this dynamic check.
            // We can leave the PRO_USER flag as TRUE until actual expiry, or switch it to false and rely on check.
            // Best practice: Flag mainly reflects "Has paid for access".
            // Let's rely on check_access_status logic at runtime.
        }
    }

    /**
     * Cancel Subscription
     * 
     * @param int $user_id
     */
    public static function cancel_subscription($user_id)
    {
        update_user_meta($user_id, self::META_KEY_SUB_STATUS, 'canceled'); // Stripe uses 'canceled'

        // We don't necessarily clear expiry date, so they keep access until end of period
        // But trigger deactivation action
        do_action('pillpalnow_pro_deactivated', $user_id);
    }

    /**
     * Deactivate Pro Subscription (Manual/Legacy)
     * 
     * @param int $user_id
     */
    public static function deactivate_pro($user_id)
    {
        self::cancel_subscription($user_id);
        update_user_meta($user_id, self::META_KEY_PRO_USER, false);
    }

    /**
     * Get Family Member Count for a User
     * 
     * @param int $user_id User ID
     * @return int Count of family members
     */
    public static function get_family_member_count($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return 0;
        }

        // Optimization: Use get_posts count directly or cache it
        $posts = get_posts(array(
            'post_type' => 'family_member',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish'
        ));

        return count($posts);
    }

    /**
     * Check if User Can Add Family Member
     * Server-side validation for family member limits
     * 
     * @param int $user_id User ID
     * @return bool True if can add, False otherwise
     */
    public static function can_add_family_member($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        // Pro users have unlimited family members
        if (self::is_pro_user($user_id)) {
            return true;
        }

        // Basic users have 5 allowed.
        $count = self::get_family_member_count($user_id);
        return $count < self::MAX_FREE_FAMILY_MEMBERS;
    }

    /**
     * Run checks on login
     */
    public static function check_status_on_login($user_login, $user)
    {
        // Force a check if needed, but mostly we rely on meta
    }

    /**
     * Register API endpoints for the app to check status
     */
    public static function register_api_endpoints()
    {
        register_rest_route('pillpalnow/v1', '/subscription/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'api_get_status'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        register_rest_route('pillpalnow/v1', '/check-subscription', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'api_check_subscription'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));
    }

    /**
     * API Callback: Get Status
     */
    /**
     * API Callback: Get Status
     */
    public static function api_get_status($request)
    {
        $user_id = get_current_user_id();

        // Get raw status
        $raw_status = get_user_meta($user_id, self::META_KEY_SUB_STATUS, true);

        // Map to UPPERCASE status for API
        $api_status = 'EXPIRED'; // Default fallback
        if ($raw_status === 'active')
            $api_status = 'ACTIVE';
        elseif ($raw_status === 'trialing')
            $api_status = 'TRIAL';
        elseif ($raw_status === 'cancelling')
            $api_status = 'CANCELLING';
        elseif ($raw_status === 'past_due')
            $api_status = 'PAST_DUE';
        elseif ($raw_status === 'canceled' || $raw_status === 'cancelled')
            $api_status = 'CANCELLED';
        elseif ($raw_status === 'unpaid')
            $api_status = 'EXPIRED';
        elseif ($raw_status === 'expired')
            $api_status = 'EXPIRED';
        elseif (!$raw_status)
            $api_status = 'EXPIRED'; // Treat no status as expired/free

        // Check Access
        $access_check = self::check_access_status($user_id);
        $access = $access_check['granted'];

        // Button Logic
        $show_cancel = false;
        if (in_array($api_status, ['TRIAL', 'ACTIVE', 'CANCELLING', 'PAST_DUE'])) {
            $show_cancel = true;
        }

        return wp_send_json_success(array(
            'status' => $api_status,
            'cancel_button' => $show_cancel,
            'access' => $access,
            'raw_status' => $raw_status,
            // Legacy/Extra fields for debugging/backward compat
            'expiry_date' => get_user_meta($user_id, self::META_KEY_EXPIRY_DATE, true),
            'trial_end' => get_user_meta($user_id, 'pillpalnow_trial_end', true),
        ));
    }

    /**
     * API Callback: Check Subscription (Force Sync)
     */
    public static function api_check_subscription($request)
    {
        $user_id = get_current_user_id();

        // Force sync
        self::sync_from_stripe($user_id);

        // Return standard status response
        return self::api_get_status($request);
    }

    /**
     * Force Sync from Stripe
     */
    public static function sync_from_stripe($user_id)
    {
        // Check for either key, preferring SAAS one but falling back to generic
        $api_key = defined('STRIPE_SAAS_SECRET_KEY') ? STRIPE_SAAS_SECRET_KEY : (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');

        if (!$api_key || !class_exists('\Stripe\Stripe')) {
            return false;
        }

        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);

        // Attempt to find customer if missing
        if (!$stripe_customer_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                try {
                    \Stripe\Stripe::setApiKey($api_key);
                    $customers = \Stripe\Customer::all(['email' => $user_info->user_email, 'limit' => 1]);
                    if (!empty($customers->data)) {
                        $stripe_customer_id = $customers->data[0]->id;
                        update_user_meta($user_id, 'stripe_customer_id', $stripe_customer_id);
                    }
                } catch (\Exception $e) {
                    error_log('Stripe Sync Error (Find Customer): ' . $e->getMessage());
                    return false;
                }
            }
        }

        if (!$stripe_customer_id) {
            return false;
        }

        try {
            \Stripe\Stripe::setApiKey($api_key);
            $subscriptions = \Stripe\Subscription::all([
                'customer' => $stripe_customer_id,
                'status' => 'all',
                'limit' => 1
            ]);

            if (!empty($subscriptions->data)) {
                $sub = $subscriptions->data[0];
                self::update_subscription_details(
                    $user_id,
                    $sub->status,
                    $sub->current_period_end,
                    $sub->cancel_at_period_end
                );

                // Also update ID if needed
                update_user_meta($user_id, 'stripe_subscription_id', $sub->id);

                return true;
            }
        } catch (\Exception $e) {
            error_log('Stripe Sync Error: ' . $e->getMessage());
        }

        return false;
    }
}
