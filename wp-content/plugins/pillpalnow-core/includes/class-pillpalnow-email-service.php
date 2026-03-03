<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Email Service
 * 
 * Wrapper for sending system emails using the PillPalNow Smart API context.
 * ensures that emails are correctly categorized (e.g., 'refill', 'subscription')
 * for the PillPalNow Smart API to handle failover and logging.
 * 
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Email_Service
{
    /**
     * Send an email with PillPalNow context
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email message (HTML)
     * @param string $context_type PillPalNow context type (e.g., 'general', 'refill', 'subscription', 'magic_link')
     * @param int $user_id User ID for logging context
     * @param array $headers Additional headers
     * @param array $attachments Attachments
     * @return bool True if sent successfully
     */
    public static function send($to, $subject, $message, $context_type = 'general', $user_id = 0, $headers = [], $attachments = [])
    {
        // 1. Set Context if PillPalNow API is available
        if (function_exists('pillpalnow_set_email_context')) {
            pillpalnow_set_email_context($context_type, $user_id);
        }

        // 2. Default Headers if not provided
        if (empty($headers)) {
            $headers = array('Content-Type: text/html; charset=UTF-8');
        }

        // 3. Send using standard wp_mail (which PillPalNow intercepts)
        $result = wp_mail($to, $subject, $message, $headers, $attachments);

        // 4. Log potential internal failure if wp_mail returns false
        if (!$result) {
            error_log("[PillPalNow] Email failed to send to $to using context $context_type");

            // Log to PillPalNow Notification Logger as well if available
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log(
                    $user_id,
                    'email_' . $context_type,
                    'wp_mail',
                    'failed',
                    'wp_mail returned false',
                    'Subject: ' . $subject
                );
            }
        } else {
            // Log success to internal logger for redundancy
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log(
                    $user_id,
                    'email_' . $context_type,
                    'wp_mail',
                    'sent',
                    'Passed to wp_mail (PillPalNow intercepted)',
                    'Subject: ' . $subject
                );
            }
        }

        return $result;
    }

    /**
     * Send a template-based email
     * 
     * @param string $template_name Template identifier
     * @param int $user_id User ID
     * @param array $data Data to merge into template
     * @return bool
     */
    public static function send_template($template_name, $user_id, $data = [])
    {
        $user = get_userdata($user_id);
        if (!$user)
            return false;

        $subject = '';
        $message = '';
        $context = 'general';

        switch ($template_name) {
            case 'subscription_welcome':
                $subject = 'Welcome to PillPalNow Pro!';
                $message = self::get_subscription_welcome_html($user, $data);
                $context = 'subscription';
                break;

            case 'subscription_renewal':
                $subject = 'Your PillPalNow Subscription Renewed';
                $message = self::get_subscription_renewal_html($user, $data);
                $context = 'subscription';
                break;

            case 'payment_failed':
                $subject = 'Action Required: Payment Failed';
                $message = self::get_payment_failed_html($user, $data);
                $context = 'billing';
                break;

            case 'subscription_cancelled':
                $subject = 'PillPalNow Subscription Cancelled';
                $message = self::get_subscription_cancelled_html($user, $data);
                $context = 'subscription';
                break;

            case 'subscription_cancelled_scheduled':
                $subject = 'PillPalNow Subscription Cancellation Scheduled';
                $message = self::get_subscription_cancelled_scheduled_html($user, $data);
                $context = 'subscription';
                break;

            case 'checkout_expired':
                $subject = 'Complete Your PillPalNow Subscription';
                $message = self::get_checkout_expired_html($user, $data);
                $context = 'billing';
                break;

            case 'invoice_created':
                $subject = 'New Invoice Generated - PillPalNow';
                $message = self::get_invoice_created_html($user, $data);
                $context = 'billing';
                break;

            case 'invoice_finalized':
                $subject = 'Invoice Ready for Payment - PillPalNow';
                $message = self::get_invoice_finalized_html($user, $data);
                $context = 'billing';
                break;

            case 'payment_method_added':
                $subject = 'Payment Method Added - PillPalNow';
                $message = self::get_payment_method_added_html($user, $data);
                $context = 'billing';
                break;

            default:
                return false;
        }

        return self::send($user->user_email, $subject, $message, $context, $user_id);
    }

    // --- Template Helpers (Basic inline HTML for now) ---
    // Refactor later to separate template files if needed.

    private static function get_subscription_welcome_html($user, $data)
    {
        $plan_name = isset($data['plan_name']) ? $data['plan_name'] : 'Pro Plan';
        // Simple HTML template
        return "
            <h2>Welcome to PillPalNow Pro!</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>Thank you for subscribing to the <strong>" . esc_html($plan_name) . "</strong>. You now have access to:</p>
            <ul>
                <li>Unlimited Medication Tracking</li>
                <li>Caregiver & Family Mode</li>
                <li>Advanced Refill Alerts</li>
            </ul>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_subscription_renewal_html($user, $data)
    {
        return "
            <h2>Subscription Renewed</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>Your subscription has been successfully renewed.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_payment_failed_html($user, $data)
    {
        return "
            <h2 style='color:red;'>Payment Failed</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>We were unable to process your payment for your PillPalNow subscription.</p>
            <p>Please update your payment method to avoid losing access to Pro features.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_subscription_cancelled_html($user, $data)
    {
        $end_date = isset($data['end_date']) ? $data['end_date'] : 'the end of your billing cycle';
        return "
            <h2>Subscription Cancelled</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>You have successfully cancelled your subscription. You will retain access until <strong>" . esc_html($end_date) . "</strong>.</p>
            <p>We're sorry to see you go!</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_subscription_cancelled_scheduled_html($user, $data)
    {
        // For scheduled cancellation, end_date might be passed or we can say 'billing period end'
        $end_date = isset($data['end_date']) ? $data['end_date'] : 'the end of your current billing period';
        return "
            <h2>Cancellation Scheduled</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>We have received your request to cancel your subscription.</p>
            <p>Your access will remain active until <strong>" . esc_html($end_date) . "</strong>, after which your subscription will not renew.</p>
            <p>If you change your mind, you can reactivate anytime before then.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_checkout_expired_html($user, $data)
    {
        return "
            <h2>Complete Your Subscription</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>We noticed you started a subscription checkout but didn't finish.</p>
            <p>Your session has expired, but you can easily try again to unlock PillPalNow Pro features.</p>
            <p><a href='" . home_url('/pricing') . "'>Return to Pricing</a></p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_invoice_created_html($user, $data)
    {
        return "
            <h2>New Invoice Generated</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>A new invoice has been generated for your PillPalNow subscription.</p>
            <p>No action is required — your payment method on file will be charged automatically.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_invoice_finalized_html($user, $data)
    {
        return "
            <h2>Invoice Ready for Payment</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>Your invoice has been finalized and is ready for payment.</p>
            <p>If you have a payment method on file, it will be charged automatically.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }

    private static function get_payment_method_added_html($user, $data)
    {
        return "
            <h2>Payment Method Added</h2>
            <p>Hi " . esc_html($user->display_name) . ",</p>
            <p>Your payment method has been successfully added to your PillPalNow account.</p>
            <p>If you didn't make this change, please contact support immediately.</p>
            <p>Thanks,<br>The PillPalNow Team</p>
        ";
    }
}
