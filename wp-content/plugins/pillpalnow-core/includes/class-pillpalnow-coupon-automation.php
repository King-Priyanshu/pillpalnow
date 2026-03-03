<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Coupon_Automation
 * Handles automatic application of second-year discounts via Stripe.
 */
class PillPalNow_Coupon_Automation
{
    const META_KEY_COUPON_APPLIED = 'pillpalnow_second_year_coupon_applied';
    const COUPON_ID = 'PPN15_YEAR2'; // Should be created in Stripe Dashboard

    /**
     * Initialize the automation
     */
    public static function init()
    {
        add_action('pillpalnow_daily_event', array(__CLASS__, 'check_for_anniversaries'));
    }

    /**
     * Check for users approaching their 1-year anniversary
     */
    public static function check_for_anniversaries()
    {
        $one_year_ago_start = current_time('timestamp') - (370 * DAY_IN_SECONDS);
        $one_year_ago_end = current_time('timestamp') - (360 * DAY_IN_SECONDS);

        $users = get_users(array(
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'pillpalnow_sub_start_date',
                    'value' => array($one_year_ago_start, $one_year_ago_end),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ),
                array(
                    'key' => self::META_KEY_COUPON_APPLIED,
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => 'pillpalnow_pro_user',
                    'value' => '1',
                    'compare' => '='
                )
            )
        ));

        if (empty($users)) {
            return;
        }

        foreach ($users as $user) {
            self::apply_coupon_to_user($user->ID);
        }
    }

    /**
     * Apply the 15% coupon via Stripe API
     * 
     * @param int $user_id
     */
    public static function apply_coupon_to_user($user_id)
    {
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);
        if (!$stripe_customer_id) {
            return;
        }

        $api_key = defined('STRIPE_SAAS_SECRET_KEY') ? STRIPE_SAAS_SECRET_KEY : (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
        if (!$api_key || !class_exists('\Stripe\Stripe')) {
            return;
        }

        try {
            \Stripe\Stripe::setApiKey($api_key);

            // Ensure the year 2 coupon exists
            self::ensure_coupon_exists(self::COUPON_ID, 15);
            
            // Apply coupon to the customer in Stripe
            // This ensures the next renewing invoice automatically consumes it.
            \Stripe\Customer::update($stripe_customer_id, [
                'coupon' => self::COUPON_ID
            ]);

            update_user_meta($user_id, self::META_KEY_COUPON_APPLIED, current_time('mysql'));
            
            error_log("[PILLPALNOW_AUTOMATION] Applied 15% anniversary coupon to user $user_id (Stripe: $stripe_customer_id)");

            // Notify user via in-app notification
            if (class_exists('PillPalNow_Notifications')) {
                PillPalNow_Notifications::create(
                    $user_id,
                    'anniversary_reward',
                    'Happy 1-Year Anniversary!',
                    'As a thank you, we\'ve applied a 15% discount code to your next year of PillPalNow Pro.',
                    0,
                    home_url('/dashboard')
                );
            }

        } catch (\Exception $e) {
            error_log("[PILLPALNOW_AUTOMATION] Stripe Error applying coupon to user $user_id: " . $e->getMessage());
        }
    }

    /**
     * Ensure a coupon exists in Stripe
     * 
     * @param string $coupon_id
     * @param int $percent_off
     */
    private static function ensure_coupon_exists($coupon_id, $percent_off)
    {
        try {
            \Stripe\Coupon::retrieve($coupon_id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Not found, create it
            \Stripe\Coupon::create([
                'id' => $coupon_id,
                'percent_off' => $percent_off,
                'duration' => 'forever',
                'name' => "Year 2 Anniversary Discount ($percent_off%)",
            ]);
            error_log("[PILLPALNOW_AUTOMATION] Created missing coupon $coupon_id");
        }
    }
}

// Initialize on load
PillPalNow_Coupon_Automation::init();
