<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Stripe_Integration
 * 
 * Handles integration between PillPalNow Core and Stripe SaaS plugin.
 * - Syncs subscription status
 * - Sends email notifications based on settings
 * - Filters specific push notifications based on settings
 */
class PillPalNow_Stripe_Integration
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // specific hooks for integration
        add_action('init', array($this, 'init'));

        // Listen to Stripe SaaS events (Using the hooks we just added to Stripe SaaS)
        add_action('stripe_saas_subscription_updated', array($this, 'handle_subscription_updated'), 10, 3);
        add_action('stripe_saas_payment_failed', array($this, 'handle_payment_failed'), 10, 2);
        add_action('stripe_saas_subscription_deleted', array($this, 'handle_subscription_deleted'), 10, 2);

        // Filter Push Notifications
        add_filter('stripe_saas_should_send_push_notification', array($this, 'filter_push_notification'), 10, 3);
    }

    /**
     * Initialize integration
     */
    public function init()
    {
        // Hook for checking pro status on page load or specific actions if needed
        // For now, we rely on Subscription_Manager calling Stripe_SaaS_Access directly 
        // or we can sync meta here if we want to cache it in pillpalnow meta keys.

        // Sync Pro Status on Login
        add_action('wp_login', array($this, 'sync_pro_status_on_login'), 10, 2);
    }

    /**
     * Sync Pro Status when user logs in
     */
    public function sync_pro_status_on_login($user_login, $user)
    {
        if (!class_exists('Stripe_SaaS_Access')) {
            return;
        }

        $user_id = $user->ID;
        if (Stripe_SaaS_Access::user_has_access($user_id)) {
            // Ensure PillPalNow knows this is a pro user
            update_user_meta($user_id, 'pillpalnow_pro_user', true);
            update_user_meta($user_id, 'pillpalnow_sub_status', 'active');
        } else {
            // If Stripe SaaS says no access, we might want to respect that 
            // BUT be careful not to overwrite manual grants. 
            // For now, let's only upgrade, not downgrade automatically here 
            // unless we are sure Stripe SaaS is the authority.

            // If the user was a Stripe subscriber but is no longer
            $stripe_status = Stripe_SaaS_Access::get_status($user_id);
            if (in_array($stripe_status, ['cancelled', 'expired', 'unpaid'])) {
                update_user_meta($user_id, 'pillpalnow_pro_user', false);
                update_user_meta($user_id, 'pillpalnow_sub_status', 'expired');
            }
        }
    }

    /**
     * Handle Subscription Updated (Expiring Soon)
     */
    public function handle_subscription_updated($user_id, $status, $subscription)
    {
        // We are interested if it's expiring soon
        // This logic mimics what we saw in Stripe SaaS OneSignal integration
        if (class_exists('Stripe_SaaS_Onesignal_Integration')) {
            $integration = Stripe_SaaS_OneSignal_Integration::instance();
            if ($integration->is_subscription_expiring_soon($subscription->current_period_end)) {
                $this->send_notification('stripe_sub_expiring', $user_id, [
                    'days_remaining' => $integration->get_days_until_expiry($subscription->current_period_end)
                ]);
            }
        }
    }

    /**
     * Handle Payment Failed
     */
    public function handle_payment_failed($user_id, $payment_intent)
    {
        $this->send_notification('stripe_payment_failed', $user_id);
    }

    /**
     * Handle Subscription Deleted (Cancelled)
     */
    public function handle_subscription_deleted($user_id, $subscription)
    {
        $this->send_notification('stripe_sub_cancelled', $user_id);
    }

    /**
     * Filter Push Notifications based on settings
     */
    public function filter_push_notification($should_send, $event_type, $user_id)
    {
        $settings = PillPalNow_Admin_Settings::get_settings();

        // Map event types to setting keys
        $setting_key = '';
        switch ($event_type) {
            case 'subscription_expiring':
                $setting_key = 'stripe_sub_expiring_push';
                break;
            case 'payment_failed':
                $setting_key = 'stripe_payment_failed_push';
                break;
            case 'subscription_cancelled':
                $setting_key = 'stripe_sub_cancelled_push';
                break;
        }

        if ($setting_key && isset($settings[$setting_key]) && $settings[$setting_key] !== '1') {
            return false; // Block notification
        }

        return $should_send;
    }

    /**
     * Send Notification (Email)
     * 
     * Checks settings and sends email if enabled.
     */
    private function send_notification($type, $user_id, $data = [])
    {
        // Log attempt
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log($user_id, $type, 'system_email', 'queued', "Notification triggered via Stripe Integration");
        }

        $settings = PillPalNow_Admin_Settings::get_settings();
        $email_setting_key = $type . '_email';

        // Check if email is enabled for this type
        if (!isset($settings[$email_setting_key]) || $settings[$email_setting_key] !== '1') {
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log($user_id, $type, 'system_email', 'skipped', "Email disabled in settings");
            }
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $subject = '';
        $message = '';

        switch ($type) {
            case 'stripe_sub_expiring':
                $days = isset($data['days_remaining']) ? $data['days_remaining'] : 3;
                $subject = sprintf(__('Subscription Expiring in %d Days', 'pillpalnow'), $days);
                $message = sprintf(
                    __('Hi %s through Stripe SaaS Integration,\n\nYour PillPalNow subscription is expiring in %d days. Please renew to avoid service interruption.\n\nRegards,\nPillPalNow Team', 'pillpalnow'),
                    $user->display_name,
                    $days
                );
                break;

            case 'stripe_payment_failed':
                $subject = __('Payment Failed', 'pillpalnow');
                $message = sprintf(
                    __('Hi %s,\n\nWe were unable to process your subscription payment. Please update your payment method to keep your account active.\n\nRegards,\nPillPalNow Team', 'pillpalnow'),
                    $user->display_name
                );
                break;

            case 'stripe_sub_cancelled':
                $subject = __('We Miss You!', 'pillpalnow');
                $message = sprintf(
                    __('Hi %s,\n\nYour subscription has been cancelled. Use code WINBACK25 for 25%% off when you resubscribe!\n\nRegards,\nPillPalNow Team', 'pillpalnow'),
                    $user->display_name
                );
                break;
        }

        if ($subject && $message) {
            $sent = wp_mail($user->user_email, $subject, $message);

            if (class_exists('PillPalNow_Notification_Logger')) {
                $status = $sent ? 'sent' : 'failed';
                $log_msg = $sent ? "Email sent successfully to {$user->user_email}" : "wp_mail failed for {$user->user_email}";
                PillPalNow_Notification_Logger::log($user_id, $type, 'system_email', $status, $log_msg);
            }

            error_log("PillPalNow-Stripe: Sent email for $type to user $user_id");
        }
    }
}
