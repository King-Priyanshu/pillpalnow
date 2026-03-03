<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Email Manager
 * 
 * Central orchestrator for all PillPalNow transactional emails.
 * Registers email classes, manages sending with retry, and provides
 * template rendering with theme override support.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Manager
{
    /** @var PillPalNow_Email_Manager Singleton */
    private static $instance = null;

    /** @var array Registered email instances keyed by email ID */
    private $emails = [];

    /** @var int Max retry attempts for failed sends */
    const MAX_RETRIES = 3;

    /** @var int Retry backoff base (seconds) */
    const RETRY_BACKOFF_BASE = 60;

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
        // Define plugin path constant if not set
        if (!defined('PILLPALNOW_PLUGIN_PATH')) {
            define('PILLPALNOW_PLUGIN_PATH', dirname(dirname(__DIR__)) . '/');
        }

        // Defer email class loading to 'init' to avoid triggering __() before
        // translations are available (WordPress 6.7+ requirement).
        if (did_action('init')) {
            $this->load_email_classes();
        } else {
            add_action('init', [$this, 'deferred_load_email_classes'], 5);
        }

        // Register email classes with WooCommerce (if active)
        add_filter('woocommerce_email_classes', [$this, 'register_wc_emails']);

        // Register retry cron handler
        add_action('pillpalnow_email_retry', [$this, 'handle_retry'], 10, 4);

        // Hooks for triggering emails from webhooks
        $this->register_triggers();
    }

    /**
     * Deferred loading of email classes (called at init)
     */
    public function deferred_load_email_classes()
    {
        $this->load_email_classes();
    }

    /**
     * Load all individual email classes
     */
    private function load_email_classes()
    {
        $email_dir = __DIR__ . '/';

        // Load base class first
        $base_file = $email_dir . 'class-pillpalnow-email-base.php';
        if (!file_exists($base_file)) {
            error_log('[PillPalNow Email] CRITICAL: Base email class not found at: ' . $base_file);
            return;
        }
        require_once $base_file;

        // Load individual email classes
        $email_files = [
            'class-email-subscription-confirm.php',
            'class-email-payment-success.php',
            'class-email-payment-failed.php',
            'class-email-renewal-reminder.php',
            'class-email-cancellation-confirm.php',
            'class-email-refund-confirm.php',
            'class-email-admin-notification.php',
        ];

        foreach ($email_files as $file) {
            $path = $email_dir . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        // Instantiate email classes
        $email_classes = [
            'pillpalnow_subscription_confirm' => 'PillPalNow_Email_Subscription_Confirm',
            'pillpalnow_payment_success' => 'PillPalNow_Email_Payment_Success',
            'pillpalnow_payment_failed' => 'PillPalNow_Email_Payment_Failed',
            'pillpalnow_renewal_reminder' => 'PillPalNow_Email_Renewal_Reminder',
            'pillpalnow_cancellation_confirm' => 'PillPalNow_Email_Cancellation_Confirm',
            'pillpalnow_refund_confirm' => 'PillPalNow_Email_Refund_Confirm',
            'pillpalnow_admin_notification' => 'PillPalNow_Email_Admin_Notification',
        ];

        foreach ($email_classes as $id => $class) {
            if (class_exists($class)) {
                $this->emails[$id] = new $class();
            }
        }
    }

    /**
     * Register email classes with WooCommerce
     * 
     * @param array $email_classes Existing WC email classes
     * @return array Modified email classes
     */
    public function register_wc_emails($email_classes)
    {
        foreach ($this->emails as $id => $email) {
            if ($email instanceof \WC_Email) {
                $email_classes[get_class($email)] = $email;
            }
        }
        return $email_classes;
    }

    /**
     * Register action hooks that trigger emails
     */
    private function register_triggers()
    {
        // Subscription lifecycle hooks from stripe-saas
        add_action('stripe_saas_subscription_created', [$this, 'on_subscription_created'], 20, 2);
        add_action('stripe_saas_subscription_deleted', [$this, 'on_subscription_deleted'], 20, 2);
        add_action('stripe_saas_payment_failed', [$this, 'on_payment_failed'], 20, 2);

        // Custom hooks for new email types
        add_action('pillpalnow_payment_succeeded', [$this, 'on_payment_succeeded'], 10, 2);
        add_action('pillpalnow_charge_refunded', [$this, 'on_charge_refunded'], 10, 2);
        add_action('pillpalnow_subscription_cancellation_requested', [$this, 'on_cancellation_requested'], 10, 2);
        add_action('pillpalnow_renewal_reminder_due', [$this, 'on_renewal_reminder'], 10, 2);
    }

    /**
     * Send an email by type
     * 
     * @param string $email_type Email type identifier
     * @param int    $user_id    User ID
     * @param array  $data       Template data
     * @param bool   $admin_copy Whether to also send admin notification
     * @return bool Success
     */
    public function send($email_type, $user_id, $data = [], $admin_copy = false)
    {
        // Fire pre-send action
        do_action('pillpalnow_before_email_send', $email_type, $user_id, $data);

        $success = false;

        if (isset($this->emails[$email_type])) {
            $email = $this->emails[$email_type];
            $success = $email->trigger($user_id, $data);

            // Log the attempt
            $this->log_send($user_id, $email_type, $success ? 'sent' : 'failed');

            // Queue retry if failed
            if (!$success) {
                $this->schedule_retry($email_type, $user_id, $data, 1);
            }
        } else {
            // Fallback to legacy email service
            $success = $this->send_legacy($email_type, $user_id, $data);
        }

        // Send admin copy if requested
        if ($admin_copy && isset($this->emails['pillpalnow_admin_notification'])) {
            $admin_data = array_merge($data, [
                'notification_type' => $email_type,
                'affected_user_id' => $user_id,
            ]);
            $this->emails['pillpalnow_admin_notification']->trigger(0, $admin_data);
        }

        // Fire post-send action
        do_action('pillpalnow_after_email_send', $email_type, $user_id, $data, $success);

        return $success;
    }

    /**
     * Legacy fallback — delegates to old email service
     */
    private function send_legacy($email_type, $user_id, $data)
    {
        if (!class_exists('PillPalNow_Email_Service')) {
            return false;
        }

        // Map new types to old template names
        $legacy_map = [
            'pillpalnow_subscription_confirm' => 'subscription_welcome',
            'pillpalnow_payment_success' => 'subscription_renewal',
            'pillpalnow_payment_failed' => 'payment_failed',
            'pillpalnow_cancellation_confirm' => 'subscription_cancelled',
        ];

        $legacy_name = isset($legacy_map[$email_type]) ? $legacy_map[$email_type] : $email_type;
        return PillPalNow_Email_Service::send_template($legacy_name, $user_id, $data);
    }

    /**
     * Schedule a retry for a failed email
     */
    private function schedule_retry($email_type, $user_id, $data, $attempt)
    {
        if ($attempt > self::MAX_RETRIES) {
            $this->log_send($user_id, $email_type, 'permanently_failed', "Exhausted all {$attempt} retry attempts");
            // Notify admin of persistent failure
            $this->notify_admin_of_failure($email_type, $user_id);
            return;
        }

        $delay = self::RETRY_BACKOFF_BASE * pow(2, $attempt - 1); // 60s, 120s, 240s
        wp_schedule_single_event(
            time() + $delay,
            'pillpalnow_email_retry',
            [$email_type, $user_id, $data, $attempt]
        );

        $this->log_send($user_id, $email_type, 'retry_scheduled', "Attempt {$attempt}, delay {$delay}s");
    }

    /**
     * Handle retry cron callback
     */
    public function handle_retry($email_type, $user_id, $data, $attempt)
    {
        if (!isset($this->emails[$email_type])) {
            return;
        }

        $success = $this->emails[$email_type]->trigger($user_id, $data);
        $this->log_send($user_id, $email_type, $success ? 'sent' : 'failed', "Retry attempt {$attempt}");

        if (!$success) {
            $this->schedule_retry($email_type, $user_id, $data, $attempt + 1);
        }
    }

    /**
     * Notify admin of persistent email failure
     */
    private function notify_admin_of_failure($email_type, $user_id)
    {
        $admin_email = get_option('admin_email');
        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : "User #{$user_id}";

        wp_mail(
            $admin_email,
            "[PillPalNow Alert] Email delivery failed permanently",
            sprintf(
                "Email type '%s' to %s (%s) failed after %d attempts.\n\nPlease check the notification logs for details.",
                $email_type,
                $user_name,
                $user ? $user->user_email : 'unknown',
                self::MAX_RETRIES
            )
        );
    }

    /**
     * Log email send attempt
     */
    private function log_send($user_id, $type, $status, $details = '')
    {
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log(
                $user_id,
                $type,
                'email_manager',
                $status,
                $details ?: "Email {$type} {$status}",
                ''
            );
        }

        error_log(sprintf(
            '[PillPalNow Email] %s: user=%d, type=%s%s',
            $status,
            $user_id,
            $type,
            $details ? ", {$details}" : ''
        ));
    }

    // -------------------------------------------------------
    // Event Handlers (triggered by action hooks)
    // -------------------------------------------------------

    public function on_subscription_created($user_id, $subscription = null)
    {
        $data = [];
        if ($subscription) {
            $data['plan_name'] = $this->get_plan_name_from_subscription($subscription);
            $data['amount'] = $this->format_amount($subscription);
            $data['next_billing_date'] = $this->format_date($subscription->current_period_end ?? 0);
        }
        $this->send('pillpalnow_subscription_confirm', $user_id, $data, true);
    }

    public function on_subscription_deleted($user_id, $subscription = null)
    {
        $data = [];
        if ($subscription) {
            $data['plan_name'] = $this->get_plan_name_from_subscription($subscription);
            $data['end_date'] = $this->format_date($subscription->current_period_end ?? 0);
        }
        $this->send('pillpalnow_cancellation_confirm', $user_id, $data, true);
    }

    public function on_payment_failed($user_id, $payment_intent = null)
    {
        $data = [];
        if ($payment_intent) {
            $data['amount'] = isset($payment_intent->amount) ? number_format($payment_intent->amount / 100, 2) : '0.00';
            $data['currency'] = strtoupper($payment_intent->currency ?? 'USD');
        }
        $this->send('pillpalnow_payment_failed', $user_id, $data, true);
    }

    public function on_payment_succeeded($user_id, $invoice = null)
    {
        $data = [];
        if ($invoice) {
            $data['amount'] = isset($invoice->amount_paid) ? number_format($invoice->amount_paid / 100, 2) : '0.00';
            $data['currency'] = strtoupper($invoice->currency ?? 'USD');
            $data['invoice_id'] = $invoice->id ?? '';
            $data['invoice_url'] = $invoice->hosted_invoice_url ?? '';
            $data['invoice_pdf'] = $invoice->invoice_pdf ?? '';
            $data['next_billing_date'] = '';
            if (isset($invoice->lines->data[0]->period->end)) {
                $data['next_billing_date'] = $this->format_date($invoice->lines->data[0]->period->end);
            }
        }
        $this->send('pillpalnow_payment_success', $user_id, $data);
    }

    public function on_charge_refunded($user_id, $data = [])
    {
        $this->send('pillpalnow_refund_confirm', $user_id, $data, true);
    }

    public function on_cancellation_requested($user_id, $data = [])
    {
        $this->send('pillpalnow_cancellation_confirm', $user_id, $data, true);
    }

    public function on_renewal_reminder($user_id, $data = [])
    {
        $this->send('pillpalnow_renewal_reminder', $user_id, $data);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function get_plan_name_from_subscription($subscription)
    {
        if (isset($subscription->items->data[0]->price->nickname)) {
            return $subscription->items->data[0]->price->nickname;
        }
        if (isset($subscription->items->data[0]->plan->nickname)) {
            return $subscription->items->data[0]->plan->nickname;
        }
        return 'Pro Plan';
    }

    private function format_amount($subscription)
    {
        if (isset($subscription->items->data[0]->price->unit_amount)) {
            $amount = $subscription->items->data[0]->price->unit_amount / 100;
            $currency = strtoupper($subscription->currency ?? 'USD');
            return '$' . number_format($amount, 2) . ' ' . $currency;
        }
        return '';
    }

    private function format_date($timestamp)
    {
        if (!$timestamp) {
            return '';
        }
        return date_i18n(get_option('date_format'), $timestamp);
    }

    /**
     * Get a registered email instance
     */
    public function get_email($email_id)
    {
        return isset($this->emails[$email_id]) ? $this->emails[$email_id] : null;
    }

    /**
     * Get all registered emails
     */
    public function get_emails()
    {
        return $this->emails;
    }
}
