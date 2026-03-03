<?php
/**
 * Stripe SaaS OneSignal Integration
 * 
 * Handles webhook-driven push notifications via OneSignal for critical subscription events.
 * Provides idempotent, real-time notifications for subscription lifecycle events.
 * 
 * @package StripeSaaS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_OneSignal_Integration
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Days before expiration to send warning
     */
    const EXPIRY_WARNING_DAYS = 3;

    /**
     * Get singleton instance
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Initialize if needed
    }

    /**
     * Send subscription expiring notification
     * 
     * @param int $user_id User ID
     * @param int $days_remaining Days until expiration
     * @param string $event_id Webhook event ID for idempotency
     * @return bool Success status
     */
    public function send_subscription_expiring_notification($user_id, $days_remaining, $event_id)
    {
        // Check idempotency
        if ($this->is_event_processed($event_id)) {
            error_log("Stripe-OneSignal: Event $event_id already processed (subscription expiring)");
            return false;
        }

        // Get OneSignal player ID
        $player_id = $this->get_onesignal_player_id($user_id);
        if (!$player_id) {
            error_log("Stripe-OneSignal: No OneSignal player ID for user $user_id");
            return false;
        }

        // Check if OneSignal service is available
        if (!class_exists('PillPalNow_OneSignal_Service')) {
            error_log('Stripe-OneSignal: PillPalNow_OneSignal_Service not available');
            return false;
        }

        // Check if push notification is allowed by external settings
        if (!apply_filters('stripe_saas_should_send_push_notification', true, 'subscription_expiring', $user_id)) {
            return false;
        }

        // Prepare message
        $heading = 'Subscription Expiring Soon';
        $message = sprintf(
            'Your subscription is about to expire in %d %s. Please ensure continued subscription to continue receiving your vital pill reminder service.',
            $days_remaining,
            $days_remaining === 1 ? 'day' : 'days'
        );

        // Send notification
        $service = PillPalNow_OneSignal_Service::get_instance();
        $result = $service->send_notification_by_player_id($player_id, $heading, $message, 'high');

        if ($result) {
            // Mark event as processed
            $this->mark_event_processed($event_id);
            error_log("Stripe-OneSignal: Sent subscription expiring notification to user $user_id");
        }

        return $result;
    }

    /**
     * Send subscription cancelled notification with win-back offer
     * 
     * @param int $user_id User ID
     * @param string $event_id Webhook event ID for idempotency
     * @return bool Success status
     */
    public function send_subscription_cancelled_notification($user_id, $event_id)
    {
        // Check idempotency
        if ($this->is_event_processed($event_id)) {
            error_log("Stripe-OneSignal: Event $event_id already processed (subscription cancelled)");
            return false;
        }

        // Get OneSignal player ID
        $player_id = $this->get_onesignal_player_id($user_id);
        if (!$player_id) {
            error_log("Stripe-OneSignal: No OneSignal player ID for user $user_id");
            return false;
        }

        // Check if OneSignal service is available
        if (!class_exists('PillPalNow_OneSignal_Service')) {
            error_log('Stripe-OneSignal: PillPalNow_OneSignal_Service not available');
            return false;
        }

        // Check if push notification is allowed by external settings
        if (!apply_filters('stripe_saas_should_send_push_notification', true, 'subscription_cancelled', $user_id)) {
            return false;
        }

        // Create win-back coupon (if eligible)
        $coupon_code = $this->create_winback_coupon($user_id);

        // Prepare message
        $heading = 'We Miss You!';
        if ($coupon_code) {
            $message = sprintf(
                'Your subscription has been aborted. We offer you a special one-time 25%% discount valid for 1 year to resume your vital pill reminder service. Use code %s when you resubscribe.',
                $coupon_code
            );
        } else {
            // User already used win-back discount
            $message = 'Your subscription has been aborted. Come back to PillPalNow and keep your vital pill reminder service active. Your health matters.';
        }

        // Send notification
        $service = PillPalNow_OneSignal_Service::get_instance();
        $result = $service->send_notification_by_player_id($player_id, $heading, $message, 'high');

        if ($result) {
            // Mark event as processed
            $this->mark_event_processed($event_id);
            error_log("Stripe-OneSignal: Sent subscription cancelled notification to user $user_id");
        }

        return $result;
    }

    /**
     * Send payment failed notification
     * 
     * @param int $user_id User ID
     * @param string $event_id Webhook event ID for idempotency
     * @return bool Success status
     */
    public function send_payment_failed_notification($user_id, $event_id)
    {
        // Check idempotency
        if ($this->is_event_processed($event_id)) {
            error_log("Stripe-OneSignal: Event $event_id already processed (payment failed)");
            return false;
        }

        // Get OneSignal player ID
        $player_id = $this->get_onesignal_player_id($user_id);
        if (!$player_id) {
            error_log("Stripe-OneSignal: No OneSignal player ID for user $user_id");
            return false;
        }

        // Check if OneSignal service is available
        if (!class_exists('PillPalNow_OneSignal_Service')) {
            error_log('Stripe-OneSignal: PillPalNow_OneSignal_Service not available');
            return false;
        }

        // Check if push notification is allowed by external settings
        if (!apply_filters('stripe_saas_should_send_push_notification', true, 'payment_failed', $user_id)) {
            return false;
        }

        // Prepare message
        $heading = 'Payment Failed';
        $message = 'Your payment has failed. Please update your credit card to continue receiving your vital pill reminder service.';

        // Send notification
        $service = PillPalNow_OneSignal_Service::get_instance();
        $result = $service->send_notification_by_player_id($player_id, $heading, $message, 'high');

        if ($result) {
            // Mark event as processed
            $this->mark_event_processed($event_id);
            error_log("Stripe-OneSignal: Sent payment failed notification to user $user_id");
        }

        return $result;
    }

    /**
     * Send trial expiring notification (for no_cc local trials)
     * 
     * @param int $user_id User ID
     * @param int $days_remaining Days until trial expiration
     * @param string $event_id Event ID for idempotency
     * @return bool Success status
     */
    public function send_trial_expiring_notification($user_id, $days_remaining, $event_id)
    {
        // Check idempotency
        if ($this->is_event_processed($event_id)) {
            return false;
        }

        // Get OneSignal player ID
        $player_id = $this->get_onesignal_player_id($user_id);
        if (!$player_id) {
            return false;
        }

        if (!class_exists('PillPalNow_OneSignal_Service')) {
            return false;
        }

        if (!apply_filters('stripe_saas_should_send_push_notification', true, 'trial_expiring', $user_id)) {
            return false;
        }

        // Prepare message
        if ($days_remaining <= 0) {
            $heading = 'Your Free Trial Has Expired';
            $message = 'Your PillPalNow free trial has ended. Subscribe now to continue using all features and never miss a dose!';
        } else {
            $heading = 'Free Trial Ending Soon';
            $message = sprintf(
                'Your PillPalNow free trial expires in %d %s! Subscribe now to keep your pill reminders active.',
                $days_remaining,
                $days_remaining === 1 ? 'day' : 'days'
            );
        }

        $service = PillPalNow_OneSignal_Service::get_instance();
        $result = $service->send_notification_by_player_id($player_id, $heading, $message, 'high');

        if ($result) {
            $this->mark_event_processed($event_id);
            error_log("Stripe-OneSignal: Sent trial expiring notification to user $user_id ($days_remaining days left)");
        }

        return $result;
    }

    /**
     * Create win-back coupon for cancelled subscription
     * 
     * @param int $user_id User ID
     * @return string|false Coupon code or false if already used
     */
    private function create_winback_coupon($user_id)
    {
        // Check if user already received win-back discount
        if ($this->has_received_winback_discount($user_id)) {
            error_log("Stripe-OneSignal: User $user_id already received win-back discount");
            return false;
        }

        try {
            // Create Stripe coupon
            $coupon = \Stripe\Coupon::create([
                'percent_off' => 25,
                'duration' => 'repeating',
                'duration_in_months' => 12,
                'max_redemptions' => 1,
                'metadata' => Stripe_SaaS_Metadata::build([
                    'user_id' => $user_id,
                    'type' => 'winback',
                    'created_at' => current_time('mysql')
                ])
            ]);

            // Mark as used and store coupon code
            $this->mark_winback_discount_used($user_id, $coupon->id);

            error_log("Stripe-OneSignal: Created win-back coupon {$coupon->id} for user $user_id");
            return $coupon->id;
        } catch (\Exception $e) {
            error_log('Stripe-OneSignal: Failed to create win-back coupon - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user ID from Stripe customer ID
     * 
     * @param string $customer_id Stripe customer ID
     * @return int|null User ID or null if not found
     */
    public function get_user_from_stripe_customer($customer_id)
    {
        $users = get_users([
            'meta_key' => '_stripe_customer_id',
            'meta_value' => $customer_id,
            'number' => 1,
            'fields' => 'ids'
        ]);

        return !empty($users) ? $users[0] : null;
    }

    /**
     * Get OneSignal player ID for user
     * 
     * @param int $user_id User ID
     * @return string|false Player ID or false if not found
     */
    private function get_onesignal_player_id($user_id)
    {
        return get_user_meta($user_id, 'onesignal_player_id', true);
    }

    /**
     * Check if webhook event has already been processed
     * 
     * @param string $event_id Stripe webhook event ID
     * @return bool True if already processed
     */
    private function is_event_processed($event_id)
    {
        $key = 'stripe_onesignal_' . md5($event_id);
        return get_transient($key) !== false;
    }

    /**
     * Mark webhook event as processed
     * 
     * @param string $event_id Stripe webhook event ID
     */
    private function mark_event_processed($event_id)
    {
        $key = 'stripe_onesignal_' . md5($event_id);
        set_transient($key, true, DAY_IN_SECONDS);
    }

    /**
     * Check if user has already received win-back discount
     * 
     * @param int $user_id User ID
     * @return bool True if already received
     */
    private function has_received_winback_discount($user_id)
    {
        return (bool) get_user_meta($user_id, '_stripe_winback_discount_used', true);
    }

    /**
     * Mark user as having received win-back discount
     * 
     * @param int $user_id User ID
     * @param string $coupon_code Coupon code
     */
    private function mark_winback_discount_used($user_id, $coupon_code)
    {
        update_user_meta($user_id, '_stripe_winback_discount_used', true);
        update_user_meta($user_id, '_stripe_winback_coupon_code', $coupon_code);
        update_user_meta($user_id, '_stripe_winback_coupon_created_at', current_time('mysql'));
    }

    /**
     * Calculate days until subscription expiry
     * 
     * @param int $current_period_end Unix timestamp
     * @return int Days remaining
     */
    public function get_days_until_expiry($current_period_end)
    {
        return max(0, floor(($current_period_end - time()) / DAY_IN_SECONDS));
    }

    /**
     * Check if subscription is expiring soon
     * 
     * @param int $current_period_end Unix timestamp
     * @return bool True if expiring within warning threshold
     */
    public function is_subscription_expiring_soon($current_period_end)
    {
        $days_remaining = $this->get_days_until_expiry($current_period_end);
        $threshold = defined('STRIPE_SAAS_EXPIRY_WARNING_DAYS')
            ? STRIPE_SAAS_EXPIRY_WARNING_DAYS
            : self::EXPIRY_WARNING_DAYS;

        return $days_remaining > 0 && $days_remaining <= $threshold;
    }

    /**
     * Process daily notifications (Cron Job)
     * Runs twice daily to check for expiring, cancelling, or failed subscriptions
     */
    public function process_daily_notifications()
    {
        error_log('Stripe-OneSignal: Starting daily notification process...');

        // 1. Subscription Expiring Soon (Active users, expiry < 3 days)
        $this->process_expiring_soon_notifications();

        // 2. Subscription Aborted (Cancelling state - Grace Period)
        $this->process_aborted_notifications();

        // 3. Payment Failed (Past Due / Unpaid)
        $this->process_payment_failed_notifications();

        // 4. Trial Expiring Soon (no_cc mode local trials expiring within 3 days)
        $this->process_trial_expiring_notifications();

        error_log('Stripe-OneSignal: Daily notification process completed.');
    }

    private function process_expiring_soon_notifications()
    {
        // Find users with active subscriptions expiring within warning days
        // This requires complex meta query or iteration. 
        // For performance, we'll iterate active users and check expiry.
        // In a large scale system, this should be a custom DB query.

        // Get all users with Stripe Customer ID (implying they might have a sub)
        $users = get_users([
            'meta_key' => '_stripe_saas_status',
            'meta_value' => ['active', 'trialing'],
            'number' => 100, // Batch limit for safety
            'fields' => 'all'
        ]);

        foreach ($users as $user) {
            $customer_id = get_user_meta($user->ID, 'stripe_customer_id', true) ?: get_user_meta($user->ID, '_stripe_customer_id', true);
            if (!$customer_id)
                continue;

            // We need to know the expiry date.
            // Stored in `_stripe_saas_expiry` ??? (Let's assume we store it or need to fetch it)
            // The class `Stripe_SaaS_Access` has `update_expiry`. Let's see if we can get it.
            // `Stripe_SaaS_Access::get_expiry($user_id)` logic? 
            // In `Stripe_SaaS_Webhook`, we call `Stripe_SaaS_Access::update_expiry`.
            // Let's check `Stripe_SaaS_Access` to see where it stores expiry.
            // It stores in `_stripe_saas_expiry`.

            $expiry = get_user_meta($user->ID, '_stripe_saas_expiry', true);
            if (!$expiry)
                continue;

            if ($this->is_subscription_expiring_soon($expiry)) {
                $days = $this->get_days_until_expiry($expiry);
                // Create unique event ID for TODAY to avoid spamming multiple times same second, 
                // but allow twice daily if the cron runs twice daily.
                // Actually, if we want "Twice Daily", we just send it when the cron runs.
                // But we should probably not send it IF we already sent it recently (e.g. 12 hours).
                // unique key: 'expiring_' . $user->ID . '_' . date('Ymd_A'); // AM/PM distinction?

                $batch_key = 'expiring_' . $user->ID . '_' . date('Ymd_H'); // Hour granularity? 
                // Creating a simulated event ID
                $this->send_subscription_expiring_notification($user->ID, $days, $batch_key);
            }
        }
    }

    private function process_aborted_notifications()
    {
        // Users in 'cancelling' state
        $users = get_users([
            'meta_key' => '_stripe_saas_status',
            'meta_value' => 'cancelling',
            'number' => 100,
            'fields' => 'ids'
        ]);

        foreach ($users as $user_id) {
            // Check if they already have the win-back coupon or used it.
            // If they are cancelling, we want to win them back.
            // Send notification with 25% off.

            // Limit frequency? "TWICE DAILY".
            $batch_key = 'aborted_' . $user_id . '_' . date('Ymd_H');

            // Use the existing cancelled notification method which creates the coupon
            $this->send_subscription_cancelled_notification($user_id, $batch_key);
        }
    }

    private function process_payment_failed_notifications()
    {
        // Users in 'past_due' or 'unpaid' state
        $users = get_users([
            'meta_key' => '_stripe_saas_status',
            'meta_value' => ['past_due', 'unpaid'],
            'number' => 100,
            'fields' => 'ids'
        ]);

        foreach ($users as $user_id) {
            $batch_key = 'failed_' . $user_id . '_' . date('Ymd_H');
            $this->send_payment_failed_notification($user_id, $batch_key);
        }
    }

    /**
     * Process trial expiring notifications (no_cc mode only)
     * 
     * Finds users with local trials expiring within 3 days and sends push notifications.
     * Only runs when trial_mode is 'no_cc' since CC trials are managed by Stripe.
     */
    private function process_trial_expiring_notifications()
    {
        // Only for no_cc mode — CC trials are managed by Stripe
        if (!class_exists('Stripe_SaaS_Access') || Stripe_SaaS_Access::get_trial_mode() !== 'no_cc') {
            return;
        }

        // Find users with local trials (status = 'trial')
        $users = get_users([
            'meta_key' => '_stripe_saas_status',
            'meta_value' => 'trial',
            'number' => 100,
            'fields' => 'all'
        ]);

        foreach ($users as $user) {
            $trial_end = get_user_meta($user->ID, '_stripe_saas_trial_end', true);
            if (!$trial_end) continue;

            $days_remaining = max(0, ceil(($trial_end - time()) / DAY_IN_SECONDS));

            // Send notification if trial expires within 3 days (or already expired today)
            if ($days_remaining <= self::EXPIRY_WARNING_DAYS) {
                $batch_key = 'trial_expiring_' . $user->ID . '_' . date('Ymd_H');
                $this->send_trial_expiring_notification($user->ID, $days_remaining, $batch_key);
            }
        }
    }

    public function is_enabled()
    {
        // Check if explicitly enabled via constant
        if (defined('STRIPE_SAAS_ONESIGNAL_ENABLED')) {
            return STRIPE_SAAS_ONESIGNAL_ENABLED;
        }

        // Check if OneSignal service is available and configured
        if (class_exists('PillPalNow_OneSignal_Service')) {
            return true;
        }

        return false;
    }
}
