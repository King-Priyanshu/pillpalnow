<?php
/**
 * Stripe SaaS Webhook
 * 
 * Processes Stripe webhook events - AUTHORITATIVE SOURCE OF TRUTH for access control.
 * Creates in-app notifications, sends emails, and triggers push notifications.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Webhook
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
        add_action('rest_api_init', [$this, 'register_route']);
    }

    /**
     * Register webhook REST endpoint
     */
    public function register_route()
    {
        register_rest_route('stripe-saas/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true' // Validation inside
        ]);
    }

    /**
     * Handle incoming webhook
     */
    /**
     * Log transaction to database
     */
    private function log_transaction($user_id, $event_type, $stripe_event_id, $stripe_object_id, $amount_cents = 0, $currency = 'usd', $metadata = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stripe_saas_transactions';

        $wpdb->insert(
            $table_name,
            [
                'user_id' => $user_id,
                'event_type' => $event_type,
                'stripe_event_id' => $stripe_event_id,
                'stripe_object_id' => $stripe_object_id,
                'amount_cents' => $amount_cents,
                'currency' => $currency,
                'metadata' => json_encode($metadata),
                'created_at' => current_time('mysql')
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s'
            ]
        );
    }

    public function handle_webhook(WP_REST_Request $request)
    {
        // Get payload
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');

        // Verify webhook secret is configured
        if (empty(STRIPE_SAAS_WEBHOOK_SECRET)) {
            error_log('Stripe SaaS: Webhook secret not configured');
            return new WP_Error('config_error', 'Webhook not configured', ['status' => 500]);
        }

        // Verify signature
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                STRIPE_SAAS_WEBHOOK_SECRET
            );
        } catch (\Exception $e) {
            error_log('Stripe SaaS: Webhook signature verification failed - ' . $e->getMessage());
            return new WP_Error('invalid_signature', 'Signature verification failed', ['status' => 400]);
        }

        // Log received
        if (class_exists('PillPalNow_Notification_Logger') && !empty($event)) {
            PillPalNow_Notification_Logger::log_webhook($event->type, $event, 'received', 'Stripe Webhook Received');
        }

        // ── P0 Fix: Cross-plugin global deduplication ──
        // Both stripe-saas and pillpalnow-core receive the same Stripe webhooks.
        // Without this guard, each event is processed twice, causing duplicate
        // notifications (2× email, 2× push, 2× in-app). This shared transient
        // key ensures only the first handler to reach this point processes the
        // event. The 24-hour TTL covers Stripe's retry window (up to 72h, but
        // retries use the same event ID so the guard still applies).
        $global_dedup_key = 'pillpalnow_global_event_' . $event->id;
        if (get_transient($global_dedup_key) !== false) {
            // Already processed by the other plugin — acknowledge receipt
            // but skip processing to prevent duplicate side-effects.
            error_log('Stripe SaaS: Event already processed globally, skipping: ' . $event->id);
            return rest_ensure_response(['received' => true, 'status' => 'already_processed']);
        }
        // Claim this event for processing (24-hour TTL)
        set_transient($global_dedup_key, current_time('timestamp'), DAY_IN_SECONDS);

        // Route to handler
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handle_checkout_completed($event->data->object);
                break;

            case 'checkout.session.expired':
                $this->handle_checkout_expired($event->data->object);
                break;

            case 'invoice.created':
                $this->handle_invoice_created($event->data->object);
                break;

            case 'invoice.finalized':
                $this->handle_invoice_finalized($event->data->object);
                break;

            case 'invoice.paid':
                $this->handle_invoice_paid($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->handle_invoice_payment_failed($event->data->object);
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $this->handle_subscription_updated($event->data->object, $event->id);
                break;

            case 'customer.subscription.deleted':
                $this->handle_subscription_deleted($event->data->object, $event->id);
                break;

            case 'customer.subscription.trial_will_end':
                $this->handle_trial_will_end($event->data->object, $event->id);
                break;

            case 'payment_intent.succeeded':
                $this->handle_payment_succeeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handle_payment_failed($event->data->object, $event->id);
                break;

            case 'payment_method.attached':
                $this->handle_payment_method_attached($event->data->object);
                break;

            default:
                error_log('Stripe SaaS: Unhandled event type - ' . $event->type);
        }

        // Log processed
        if (class_exists('PillPalNow_Notification_Logger') && !empty($event)) {
            PillPalNow_Notification_Logger::log_webhook($event->type, null, 'processed', 'Stripe Webhook Processed Successfully');
        }

        return rest_ensure_response(['received' => true]);
    }

    /**
     * Handle checkout.session.completed
     */
    private function handle_checkout_completed($session)
    {
        // Validate metadata domain
        if (!Stripe_SaaS_Metadata::validate($session->metadata, ['user_id', 'tier_slug'])) {
            error_log('Stripe SaaS: Domain validation failed for checkout session');
            return;
        }

        // Extract data
        $user_id = Stripe_SaaS_Metadata::get_user_id($session->metadata);
        $tier_slug = Stripe_SaaS_Metadata::get_tier_slug($session->metadata);
        $plan_type = isset($session->metadata->plan_type) ? $session->metadata->plan_type : 'subscription';

        // Log transaction
        $amount = 0;
        $currency = 'usd';
        if (!empty($session->amount_total)) {
            $amount = $session->amount_total;
        }
        if (!empty($session->currency)) {
            $currency = $session->currency;
        }
        
        $metadata = [
            'plan_type' => $plan_type,
            'tier_slug' => $tier_slug,
            'customer_email' => $session->customer_email
        ];
        
        $this->log_transaction(
            $user_id,
            'checkout.session.completed',
            $session->id, // Stripe event ID is $event->id, but this is the session object
            $session->id,
            $amount,
            $currency,
            $metadata
        );

        if (!$user_id || !$tier_slug) {
            error_log('Stripe SaaS: Missing user_id or tier_slug in checkout session');
            return;
        }

        // Check if one-time payment
        if ($plan_type === 'one_time') {
            // For one-time payments, grant immediately
            update_user_meta($user_id, '_stripe_customer_id', $session->customer);
            update_user_meta($user_id, 'stripe_customer_id', $session->customer); // Sync both meta keys
            update_user_meta($user_id, '_stripe_payment_intent_id', $session->payment_intent);
            Stripe_SaaS_Access::grant_permanent($user_id, $tier_slug);
            error_log('Stripe SaaS: One-time payment completed for user ' . $user_id . ', tier ' . $tier_slug);
        } else {
            // For subscriptions, store IDs and grant access
            update_user_meta($user_id, '_stripe_customer_id', $session->customer);
            update_user_meta($user_id, 'stripe_customer_id', $session->customer); // Sync both meta keys
            update_user_meta($user_id, '_stripe_subscription_id', $session->subscription);
            update_user_meta($user_id, 'stripe_subscription_id', $session->subscription); // Sync both meta keys
            Stripe_SaaS_Access::grant($user_id, $tier_slug);
            error_log('Stripe SaaS: Subscription checkout completed for user ' . $user_id . ', tier ' . $tier_slug);
        }

        // Send welcome notification
        $this->send_notifications($user_id, 'subscription_welcome');
    }

    /**
     * Handle checkout.session.expired
     * Notify user that their checkout session expired
     */
    private function handle_checkout_expired($session)
    {
        $user_id = 0;

        // 1. Try Metadata
        if (isset($session->metadata->user_id)) {
            $user_id = intval($session->metadata->user_id);
        }

        // 2. Try customer lookup
        if (!$user_id && !empty($session->customer)) {
            $user_id = $this->get_user_by_customer($session->customer);
        }

        // 3. Try email lookup
        if (!$user_id && !empty($session->customer_email)) {
            $user = get_user_by('email', $session->customer_email);
            if ($user) {
                $user_id = $user->ID;
            }
        }

        if ($user_id) {
            $this->send_notifications($user_id, 'checkout_expired');
            error_log('Stripe SaaS: Checkout expired notification sent for user ' . $user_id);
        } else {
            error_log('Stripe SaaS: Could not find user for checkout.session.expired: ' . $session->id);
        }
    }

    /**
     * Handle invoice.created
     * Notify user that a new invoice has been generated
     */
    private function handle_invoice_created($invoice)
    {
        $user_id = $this->find_user_from_invoice($invoice);
        if (!$user_id) {
            return;
        }

        $this->send_notifications($user_id, 'invoice_created');
        error_log('Stripe SaaS: Invoice created notification sent for user ' . $user_id);
    }

    /**
     * Handle invoice.finalized
     * Notify user that their invoice is ready for payment
     */
    private function handle_invoice_finalized($invoice)
    {
        $user_id = $this->find_user_from_invoice($invoice);
        if (!$user_id) {
            return;
        }

        $this->send_notifications($user_id, 'invoice_finalized');
        error_log('Stripe SaaS: Invoice finalized notification sent for user ' . $user_id);
    }

    /**
     * Handle payment_intent.succeeded (for completed one-time payments)
     */
    private function handle_payment_succeeded($payment_intent)
    {
        // payment_intent.succeeded fires for both subscription and one-time payments
        // For one-time payments, we handle it here. For subscriptions, we ignore it.
        // We primarily rely on checkout.session.completed for one-time payments
        // This is a backup handler in case checkout.session.completed fails
        error_log('Stripe SaaS: payment_intent.succeeded received - ID: ' . $payment_intent->id);
    }

    /**
     * Handle invoice.paid
     */
    private function handle_invoice_paid($invoice)
    {
        if (!$invoice->subscription) {
            return;
        }

        // Find user
        $user_id = $this->get_user_by_subscription($invoice->subscription);
        if (!$user_id) {
            // Fallback: try customer lookup
            if (!empty($invoice->customer)) {
                $user_id = $this->get_user_by_customer($invoice->customer);
            }
        }
        if (!$user_id) {
            error_log('Stripe SaaS: User not found for subscription ' . $invoice->subscription);
            return;
        }

        // Log transaction
        $amount = 0;
        $currency = 'usd';
        if (!empty($invoice->amount_paid)) {
            $amount = $invoice->amount_paid;
        }
        if (!empty($invoice->currency)) {
            $currency = $invoice->currency;
        }
        
        $metadata = [
            'invoice_number' => $invoice->number,
            'invoice_pdf' => $invoice->invoice_pdf,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end
        ];
        
        $this->log_transaction(
            $user_id,
            'invoice.paid',
            $invoice->id,
            $invoice->id,
            $amount,
            $currency,
            $metadata
        );

        // Update expiry
        $period_end = $invoice->lines->data[0]->period->end;
        Stripe_SaaS_Access::update_expiry($user_id, $period_end);
        update_user_meta($user_id, '_stripe_saas_status', 'active');

        // Send renewal notification (for subscription_cycle renewals)
        if (isset($invoice->billing_reason) && $invoice->billing_reason === 'subscription_cycle') {
            $this->send_notifications($user_id, 'subscription_renewal');
        }

        error_log('Stripe SaaS: Invoice paid for user ' . $user_id . ', expiry updated to ' . $period_end);
    }

    /**
     * Handle invoice.payment_failed
     */
    private function handle_invoice_payment_failed($invoice)
    {
        $user_id = $this->find_user_from_invoice($invoice);
        if (!$user_id) {
            return;
        }

        // Log transaction
        $amount = 0;
        $currency = 'usd';
        if (!empty($invoice->amount_due)) {
            $amount = $invoice->amount_due;
        }
        if (!empty($invoice->currency)) {
            $currency = $invoice->currency;
        }
        
        $metadata = [
            'invoice_number' => $invoice->number,
            'invoice_pdf' => $invoice->invoice_pdf,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end
        ];
        
        $this->log_transaction(
            $user_id,
            'invoice.payment_failed',
            $invoice->id,
            $invoice->id,
            $amount,
            $currency,
            $metadata
        );

        $this->send_notifications($user_id, 'payment_failed');
        error_log('Stripe SaaS: Invoice payment failed notification sent for user ' . $user_id);
    }

    /**
     * Handle subscription updated
     */
    private function handle_subscription_updated($subscription, $event_id = null)
    {
        $user_id = $this->get_user_by_subscription($subscription->id);
        if (!$user_id && !empty($subscription->customer)) {
            $user_id = $this->get_user_by_customer($subscription->customer);
        }
        if (!$user_id) {
            return;
        }

        // Log transaction
        $metadata = [
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end
        ];
        
        $this->log_transaction(
            $user_id,
            'customer.subscription.updated',
            $event_id ?: $subscription->id,
            $subscription->id,
            0,
            'usd',
            $metadata
        );

        // Update expiry
        Stripe_SaaS_Access::update_expiry($user_id, $subscription->current_period_end);

        // Extract and save tier slug from subscription metadata if available
        if (isset($subscription->metadata->tier_slug)) {
            $tier_slug_from_meta = $subscription->metadata->tier_slug;
            $current_tier = Stripe_SaaS_Access::get_tier($user_id);
            
            if ($current_tier !== $tier_slug_from_meta) {
                update_user_meta($user_id, '_stripe_saas_tier', $tier_slug_from_meta);
                error_log('Stripe SaaS: Updated tier slug for user ' . $user_id . ' from ' . ($current_tier ?: 'null') . ' to ' . $tier_slug_from_meta);
            }
        }

        // Update status and access based on subscription status
        $status = $subscription->status;
        $cancel_at_period_end = !empty($subscription->cancel_at_period_end);

        // Check if this is a NEW cancellation request
        $prev_cancel_at_period_end = get_user_meta($user_id, '_stripe_saas_cancel_at_period_end', true);
        $is_new_cancellation = $cancel_at_period_end && !$prev_cancel_at_period_end;

        // Store cancel_at_period_end flag for downstream use (dashboard, API)
        update_user_meta($user_id, '_stripe_saas_cancel_at_period_end', $cancel_at_period_end ? '1' : '0');

        if ($cancel_at_period_end) {
            // If this came from Stripe (not our dashboard), we might not have the type set yet.
            // Default to 'stripe_portal' or 'unknown' if not set.
            if (!get_user_meta($user_id, '_stripe_saas_cancellation_type', true)) {
                update_user_meta($user_id, '_stripe_saas_cancellation_type', 'external_update');
                update_user_meta($user_id, '_stripe_saas_canceled_at', $subscription->canceled_at ?: time());
            }
        }

        // Send confirmation if new cancellation schedule
        if ($is_new_cancellation) {
            $this->send_notifications($user_id, 'subscription_cancelled_scheduled');
        }

        // Grant or revoke based on status
        if (in_array($status, ['active', 'trialing'])) {
            // Active/trialing — grant access regardless of cancel_at_period_end
            update_user_meta($user_id, '_stripe_saas_status', $cancel_at_period_end ? 'cancelling' : $status);
            $tier_slug = Stripe_SaaS_Access::get_tier($user_id);
            
            // If no tier slug is set, try to get it from subscription metadata
            if (!$tier_slug && isset($subscription->metadata->tier_slug)) {
                $tier_slug = $subscription->metadata->tier_slug;
                update_user_meta($user_id, '_stripe_saas_tier', $tier_slug);
            }
            
            if ($tier_slug) {
                Stripe_SaaS_Access::grant($user_id, $tier_slug, $cancel_at_period_end ? 'cancelling' : $status);
            }
        } elseif (in_array($status, ['unpaid', 'past_due'])) {
            // Payment issues — revoke immediately
            update_user_meta($user_id, '_stripe_saas_status', $status);
            Stripe_SaaS_Access::revoke($user_id);
        } elseif ($status === 'incomplete_expired') {
            // Subscription never completed setup — revoke and notify
            update_user_meta($user_id, '_stripe_saas_status', 'cancelled');
            Stripe_SaaS_Access::revoke($user_id);
        } elseif ($status === 'canceled') {
            // Canceled — check if still within grace period
            if ($subscription->current_period_end > time()) {
                // Grace period: keep access, mark as cancelling
                update_user_meta($user_id, '_stripe_saas_status', 'cancelling');
                $tier_slug = Stripe_SaaS_Access::get_tier($user_id);
                if ($tier_slug) {
                    Stripe_SaaS_Access::grant($user_id, $tier_slug);
                }
            } else {
                // Period ended — revoke
                update_user_meta($user_id, '_stripe_saas_status', 'cancelled');
                Stripe_SaaS_Access::revoke($user_id);
            }
        } else {
            update_user_meta($user_id, '_stripe_saas_status', $status);
        }

        // Check if subscription is expiring soon (OneSignal notification)
        if (class_exists('Stripe_SaaS_OneSignal_Integration') && $event_id) {
            $integration = Stripe_SaaS_OneSignal_Integration::instance();
            if ($integration->is_enabled() && in_array($status, ['active', 'trialing'])) {
                if ($integration->is_subscription_expiring_soon($subscription->current_period_end)) {
                    $days_remaining = $integration->get_days_until_expiry($subscription->current_period_end);
                    $integration->send_subscription_expiring_notification($user_id, $days_remaining, $event_id);
                }
            }
        }

        // Send notification only when access is actually being revoked (not during grace period)
        if (in_array($status, ['unpaid', 'past_due'])) {
            $this->send_notifications($user_id, 'subscription_cancelled');
        } elseif ($status === 'incomplete_expired') {
            $this->send_notifications($user_id, 'subscription_cancelled');
            // Send win-back push notification
            if (class_exists('Stripe_SaaS_OneSignal_Integration') && $event_id) {
                $integration = Stripe_SaaS_OneSignal_Integration::instance();
                if ($integration->is_enabled()) {
                    $integration->send_subscription_cancelled_notification($user_id, $event_id);
                }
            }
        } elseif ($status === 'canceled' && $subscription->current_period_end <= time()) {
            $this->send_notifications($user_id, 'subscription_cancelled');
        }

        // Action hook for external integrations (e.g., Dosecast)
        do_action('stripe_saas_subscription_updated', $user_id, $status, $subscription);

        error_log('Stripe SaaS: Subscription updated for user ' . $user_id . ', status: ' . $status . ', cancel_at_period_end: ' . ($cancel_at_period_end ? 'yes' : 'no'));
    }

    /**
     * Handle subscription deleted
     */
    private function handle_subscription_deleted($subscription, $event_id = null)
    {
        $user_id = $this->get_user_by_subscription($subscription->id);
        if (!$user_id && !empty($subscription->customer)) {
            $user_id = $this->get_user_by_customer($subscription->customer);
        }
        if (!$user_id) {
            return;
        }

        // Log transaction
        $metadata = [
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'current_period_start' => $subscription->current_period_start,
            'current_period_end' => $subscription->current_period_end
        ];
        
        $this->log_transaction(
            $user_id,
            'customer.subscription.deleted',
            $event_id ?: $subscription->id,
            $subscription->id,
            0,
            'usd',
            $metadata
        );

        // Revoke access
        Stripe_SaaS_Access::revoke($user_id);

        // Send subscription cancelled notification (in-app, email, push)
        $this->send_notifications($user_id, 'subscription_cancelled');

        // Send win-back notification with discount offer (OneSignal)
        if (class_exists('Stripe_SaaS_OneSignal_Integration') && $event_id) {
            $integration = Stripe_SaaS_OneSignal_Integration::instance();
            if ($integration->is_enabled()) {
                $integration->send_subscription_cancelled_notification($user_id, $event_id);
            }
        }

        // Action hook for external integrations (e.g., Dosecast)
        do_action('stripe_saas_subscription_deleted', $user_id, $subscription);

        error_log('Stripe SaaS: Subscription deleted for user ' . $user_id);
    }

    /**
     * Handle customer.subscription.trial_will_end
     * 
     * Stripe sends this event 3 days before a trial ends.
     * Triggers an expiring notification via OneSignal.
     */
    private function handle_trial_will_end($subscription, $event_id = null)
    {
        $user_id = $this->get_user_by_subscription($subscription->id);
        if (!$user_id && !empty($subscription->customer)) {
            $user_id = $this->get_user_by_customer($subscription->customer);
        }
        if (!$user_id) {
            error_log('Stripe SaaS: User not found for trial_will_end event');
            return;
        }

        // Calculate days remaining
        $trial_end = $subscription->trial_end;
        $days_remaining = max(0, ceil(($trial_end - time()) / DAY_IN_SECONDS));

        // Send expiring notification via OneSignal
        if (class_exists('Stripe_SaaS_OneSignal_Integration') && $event_id) {
            $integration = Stripe_SaaS_OneSignal_Integration::instance();
            if ($integration->is_enabled()) {
                $integration->send_subscription_expiring_notification($user_id, $days_remaining, $event_id);
            }
        }

        // Also send via standard channels (in-app, email)
        $this->send_notifications($user_id, 'trial_ending_soon');

        error_log('Stripe SaaS: Trial will end in ' . $days_remaining . ' days for user ' . $user_id);
    }

    /**
     * Handle payment failed
     */
    private function handle_payment_failed($payment_intent, $event_id = null)
    {
        // Get customer from payment intent
        if (!isset($payment_intent->customer)) {
            error_log('Stripe SaaS: No customer in payment_intent.payment_failed');
            return;
        }

        $user_id = $this->get_user_by_customer($payment_intent->customer);

        if (!$user_id) {
            // Fallback: try OneSignal integration lookup
            if (class_exists('Stripe_SaaS_OneSignal_Integration')) {
                $integration = Stripe_SaaS_OneSignal_Integration::instance();
                $user_id = $integration->get_user_from_stripe_customer($payment_intent->customer);
            }
        }

        if (!$user_id) {
            error_log('Stripe SaaS: User not found for customer ' . $payment_intent->customer);
            return;
        }

        // Send payment failed notification (in-app, email, push)
        $this->send_notifications($user_id, 'payment_failed');

        // Send payment failed notification (OneSignal via Stripe SaaS integration)
        if (class_exists('Stripe_SaaS_OneSignal_Integration') && $event_id) {
            $integration = Stripe_SaaS_OneSignal_Integration::instance();
            if ($integration->is_enabled()) {
                $integration->send_payment_failed_notification($user_id, $event_id);
            }
        }

        // Action hook for external integrations (e.g., Dosecast)
        do_action('stripe_saas_payment_failed', $user_id, $payment_intent);

        error_log('Stripe SaaS: Payment failed for payment intent ' . $payment_intent->id);
    }

    /**
     * Handle payment_method.attached
     * Notify user that a payment method was added
     */
    private function handle_payment_method_attached($payment_method)
    {
        if (empty($payment_method->customer)) {
            return;
        }

        $user_id = $this->get_user_by_customer($payment_method->customer);
        if (!$user_id) {
            error_log('Stripe SaaS: User not found for payment_method.attached, customer: ' . $payment_method->customer);
            return;
        }

        $this->send_notifications($user_id, 'payment_method_added');
        error_log('Stripe SaaS: Payment method attached notification sent for user ' . $user_id);
    }

    // ============================================================
    // Centralized Notification Sender
    // Creates in-app notification, sends email, and triggers push
    // ============================================================

    /**
     * Send notifications via all channels (in-app, email, push)
     *
     * @param int    $user_id User ID
     * @param string $type    Notification type key
     */
    private function send_notifications($user_id, $type)
    {
        $heading = '';
        $message = '';
        $msg_type = 'subscription'; // Default type for push

        // Define messages for each notification type
        switch ($type) {
            case 'subscription_welcome':
                $heading = 'Welcome to Pro!';
                $message = 'Your subscription is active. Enjoy unlimited access!';
                break;
            case 'subscription_renewal':
                $heading = 'Subscription Renewed';
                $message = 'Your subscription has been successfully renewed.';
                break;
            case 'payment_failed':
                $heading = 'Payment Failed';
                $message = 'We could not process your payment. Please update your payment details.';
                $msg_type = 'billing';
                break;
            case 'subscription_cancelled':
                $heading = 'Subscription Ended';
                $message = 'Your Pro subscription has ended. You can resubscribe anytime.';
                break;
            case 'checkout_expired':
                $heading = 'Checkout Expired';
                $message = 'Your checkout session has expired. Please try again to complete your subscription.';
                $msg_type = 'billing';
                break;
            case 'invoice_created':
                $heading = 'New Invoice Generated';
                $message = 'A new invoice has been generated for your subscription.';
                $msg_type = 'billing';
                break;
            case 'invoice_finalized':
                $heading = 'Invoice Ready';
                $message = 'Your invoice has been finalized and is ready for payment.';
                $msg_type = 'billing';
                break;
            case 'subscription_cancelled_scheduled':
                $heading = 'Cancellation Scheduled';
                $message = 'Your subscription has been scheduled for cancellation at the end of the billing period.';
                break;
            case 'payment_method_added':
                $heading = 'Payment Method Added';
                $message = 'Your payment method has been successfully attached to your account.';
                $msg_type = 'billing';
                break;
            case 'trial_ending_soon':
                $heading = 'Trial Ending Soon';
                $message = 'Your free trial is ending soon. Subscribe now to continue receiving your vital pill reminder service.';
                break;
        }

        if (!$heading || !$message) {
            return;
        }

        // 1. In-App Notification (Bell Icon) via Dosecast CPT
        if (class_exists('PillPalNow_Notifications')) {
            $result = PillPalNow_Notifications::create(
                $user_id,
                'system_alert',
                $heading,
                $message
            );
            if ($result) {
                error_log("Stripe SaaS: In-app notification created for user $user_id, type: $type, post_id: $result");
            } else {
                error_log("Stripe SaaS: Failed to create in-app notification for user $user_id, type: $type");
            }
        }

        // 2. Email via Dosecast Email Service
        if (class_exists('PillPalNow_Email_Service')) {
            PillPalNow_Email_Service::send_template($type, $user_id);
        }

        // 3. Push Notification via Dosecast OneSignal Service
        if (class_exists('PillPalNow_OneSignal_Service')) {
            $onesignal = PillPalNow_OneSignal_Service::get_instance();
            $onesignal->send_notification($user_id, $heading, $message, $msg_type);
        }

        // 4. Log the notification attempt
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log($user_id, $type, 'all', 'sent', "$heading - $message");
        }

        // 5. Admin Notification (Critical Events only)
        if (in_array($type, ['subscription_cancelled', 'subscription_cancelled_scheduled', 'payment_failed'])) {
            $admin_email = get_option('admin_email');
            $subject = "Admin Alert: $heading (User $user_id)";
            $body = "User ID: $user_id\nEvent: $type\nMessage: $message\nTime: " . current_time('mysql');
            wp_mail($admin_email, $subject, $body);
        }
    }

    // ============================================================
    // User Lookup Helpers
    // ============================================================

    /**
     * Find user by Stripe subscription ID
     */
    private function get_user_by_subscription($subscription_id)
    {
        // Check _stripe_subscription_id (stripe-saas key)
        $users = get_users([
            'meta_key' => '_stripe_subscription_id',
            'meta_value' => $subscription_id,
            'number' => 1,
            'fields' => 'ids'
        ]);

        if (!empty($users)) {
            return $users[0];
        }

        // Fallback: check stripe_subscription_id (pillpalnow-core key)
        $users = get_users([
            'meta_key' => 'stripe_subscription_id',
            'meta_value' => $subscription_id,
            'number' => 1,
            'fields' => 'ids'
        ]);

        return !empty($users) ? $users[0] : null;
    }

    /**
     * Find user by Stripe customer ID
     * Checks both meta key variants and falls back to Stripe API email lookup
     */
    private function get_user_by_customer($customer_id)
    {
        if (empty($customer_id)) {
            return null;
        }

        // 1. Check _stripe_customer_id (stripe-saas key)
        $users = get_users([
            'meta_key' => '_stripe_customer_id',
            'meta_value' => $customer_id,
            'number' => 1,
            'fields' => 'ids'
        ]);

        if (!empty($users)) {
            return $users[0];
        }

        // 2. Check stripe_customer_id (pillpalnow-core key, no underscore prefix)
        $users = get_users([
            'meta_key' => 'stripe_customer_id',
            'meta_value' => $customer_id,
            'number' => 1,
            'fields' => 'ids'
        ]);

        if (!empty($users)) {
            return $users[0];
        }

        // 3. Fallback: Retrieve customer from Stripe API and match by email
        try {
            $customer = \Stripe\Customer::retrieve($customer_id);
            if (!empty($customer->email)) {
                $user = get_user_by('email', $customer->email);
                if ($user) {
                    // Sync both meta keys for future lookups
                    update_user_meta($user->ID, '_stripe_customer_id', $customer_id);
                    update_user_meta($user->ID, 'stripe_customer_id', $customer_id);
                    return $user->ID;
                }
            }
        } catch (\Exception $e) {
            error_log('Stripe SaaS: Could not retrieve customer for lookup: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Helper: Find user from an invoice object
     * Tries subscription lookup first, then customer lookup
     */
    private function find_user_from_invoice($invoice)
    {
        // 1. Try subscription lookup
        if (!empty($invoice->subscription)) {
            $user_id = $this->get_user_by_subscription($invoice->subscription);
            if ($user_id) {
                return $user_id;
            }
        }

        // 2. Try customer lookup
        if (!empty($invoice->customer)) {
            $user_id = $this->get_user_by_customer($invoice->customer);
            if ($user_id) {
                return $user_id;
            }
        }

        error_log('Stripe SaaS: Could not find user for invoice ' . $invoice->id);
        return null;
    }
}
