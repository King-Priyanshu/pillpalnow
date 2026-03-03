<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Stripe Webhook Handler
 * 
 * Handles incoming webhook events from Stripe.
 * Verifies signatures and routes events to appropriate handlers.
 * 
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Stripe_Webhook_Handler
{
    /**
     * Handle incoming webhook request
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request($request)
    {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        $endpoint_secret = self::get_webhook_secret();

        $event = null;

        // Initialize Stripe API Key for subsequent calls
        $api_key = defined('STRIPE_SAAS_SECRET_KEY') ? STRIPE_SAAS_SECRET_KEY : (defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
        if ($api_key && class_exists('\Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($api_key);
        }

        try {
            // Verify Signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            self::log_error('Invalid Payload: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Invalid Payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            self::log_error('Invalid Signature: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Invalid Signature'], 400);
        }

        // ── P0 Fix: Cross-plugin global deduplication ──
        // Both stripe-saas and pillpalnow-core receive the same Stripe webhooks.
        // This shared transient key ensures only the first handler to reach
        // this point processes the event, preventing duplicate notifications
        // (2× email, 2× push, 2× in-app). 24-hour TTL covers Stripe's retry
        // window. The plugin-specific check below is kept as a secondary guard.
        $global_dedup_key = 'pillpalnow_global_event_' . $event->id;
        if (get_transient($global_dedup_key) !== false) {
            self::log_info('Event already processed globally, skipping: ' . $event->id);
            return new WP_REST_Response(['status' => 'already_processed'], 200);
        }
        // Claim this event for processing (24-hour TTL)
        set_transient($global_dedup_key, current_time('timestamp'), DAY_IN_SECONDS);

        // Per-plugin deduplication (secondary guard against self-retries)
        if (self::is_duplicate_event($event->id)) {
            self::log_info("Duplicate event ignored: " . $event->id);
            return new WP_REST_Response(['status' => 'ignored', 'reason' => 'duplicate'], 200);
        }

        // Handle the event
        self::route_event($event);

        // Mark event as processed
        self::mark_event_processed($event->id);

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * Check if event is duplicate
     */
    private static function is_duplicate_event($event_id)
    {
        return get_transient('pillpalnow_stripe_event_' . $event_id) !== false;
    }

    /**
     * Mark event as processed (expiry 24 hours)
     */
    private static function mark_event_processed($event_id)
    {
        set_transient('pillpalnow_stripe_event_' . $event_id, true, 24 * HOUR_IN_SECONDS);
    }

    /**
     * Route event to specific handler
     * 
     * @param \Stripe\Event $event
     */
    private static function route_event($event)
    {
        $type = $event->type;
        $object = $event->data->object;

        // Log receipt
        self::log_info("Received Webhook: $type | ID: " . $event->id);

        switch ($type) {
            case 'checkout.session.completed':
                self::handle_checkout_session_completed($object);
                break;

            case 'invoice.paid':
                self::handle_invoice_paid($object);
                break;

            case 'invoice.payment_failed':
                self::handle_invoice_payment_failed($object);
                break;

            case 'customer.subscription.created':
                self::handle_subscription_created($object);
                break;

            case 'customer.subscription.updated':
                self::handle_subscription_updated($object);
                break;

            case 'customer.subscription.deleted':
                self::handle_subscription_deleted($object);
                break;

            case 'payment_intent.succeeded':
                self::handle_payment_intent_succeeded($object);
                break;

            case 'payment_intent.payment_failed':
                // Optional: handled via invoice.payment_failed usually for subscriptions
                self::log_info("Payment Intent Failed: " . $object->id);
                break;

            case 'checkout.session.expired':
                self::handle_checkout_session_expired($object);
                break;

            case "invoice.created":
                // Send invoice created notification
                self::handle_invoice_created($object);
                break;
                
            case "invoice.finalized":
                // Send invoice finalized notification
                self::handle_invoice_finalized($object);
                break;

            default:
                // Unhandled event type
                break;
        }
    }

    /**
     * Handle Checkout Session Completed
     * - Triggers initial subscription setup if not already done via frontend
     */
    private static function handle_checkout_session_completed($session)
    {
        $user_id = 0;

        // 1. Try Metadata
        if (isset($session->metadata->user_id)) {
            $user_id = intval($session->metadata->user_id);
        }

        // 2. Try Email Match if Metadata fail
        if (!$user_id && isset($session->customer)) {
            $user = self::find_and_sync_user($session->customer);
            if ($user) {
                $user_id = $user->ID;
            }
        }

        if ($user_id && isset($session->subscription)) {
            $subscription_id = $session->subscription;
            $customer_id = $session->customer;

            // Activate Subscription
            // Retrieve subscription to check status (active/trialing)
            try {
                $sub = \Stripe\Subscription::retrieve($subscription_id);
                $status = $sub->status; // active, trialing, incomplete

                Subscription_Manager::activate_subscription($user_id, 'pro_monthly', $customer_id, $subscription_id, $status);

                // Notifications
                self::send_notifications($user_id, 'subscription_welcome');

            } catch (\Exception $e) {
                self::log_error("Error retrieving subscription in checkout: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle Checkout Session Expired
     * - Notify user that their checkout session expired and they need to try again
     */
    private static function handle_checkout_session_expired($session)
    {
        $user_id = 0;

        // 1. Try Metadata
        if (isset($session->metadata->user_id)) {
            $user_id = intval($session->metadata->user_id);
        }

        // 2. Try Email Match logic (if we can find a user by email in the session/customer)
        if (!$user_id) {
            // Sometimes session has customer, sometimes only customer_email
            if (!empty($session->customer)) {
                $user = self::find_and_sync_user($session->customer);
                if ($user)
                    $user_id = $user->ID;
            } elseif (!empty($session->customer_email)) {
                $user = get_user_by('email', $session->customer_email);
                if ($user)
                    $user_id = $user->ID;
            }
        }

        if ($user_id) {
            self::log_info("Processing checkout.session.expired for User ID: $user_id");
            self::send_notifications($user_id, 'checkout_expired');
        } else {
            self::log_info("Could not find user for checkout.session.expired: " . $session->id);
        }
    }

    /**
     * Handle Invoice Paid
     * - Successful payment or renewal
     */
    private static function handle_invoice_paid($invoice)
    {
        if ($invoice->subscription) {
            try {
                $sub = \Stripe\Subscription::retrieve($invoice->subscription);
                self::update_local_subscription($sub);

                // If this is a renewal (subscription_cycle), send notification
                if ($invoice->billing_reason == 'subscription_cycle') {
                    $user = self::find_and_sync_user($invoice->customer);
                    if ($user) {
                        self::send_notifications($user->ID, 'subscription_renewal');
                    }
                }
            } catch (\Exception $e) {
                self::log_error("Error processing invoice.paid: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle Invoice Payment Failed
     */
    private static function handle_invoice_payment_failed($invoice)
    {
        $user = self::find_and_sync_user($invoice->customer);
        if ($user) {
            // Update subscription to PAST_DUE if strictly enforced here, 
            // otherwise updated via handle_subscription_updated

            // Send Notifications
            self::send_notifications($user->ID, 'payment_failed');
        }
    }
    /**
     * Handle Invoice Created
     */
    private static function handle_invoice_created($invoice)
    {
        $user = self::find_and_sync_user($invoice->customer);
        if ($user) {
            self::send_notifications($user->ID, "invoice_created");
        }
    }

    /**
     * Handle Invoice Finalized
     */
    private static function handle_invoice_finalized($invoice)
    {
        $user = self::find_and_sync_user($invoice->customer);
        if ($user) {
            self::send_notifications($user->ID, "invoice_finalized");
        }
    }


    /**
     * Handle Subscription Created
     */
    private static function handle_subscription_created($subscription)
    {
        self::update_local_subscription($subscription);
    }

    /**
     * Handle Subscription Updated
     */
    private static function handle_subscription_updated($subscription)
    {
        self::update_local_subscription($subscription);
    }

    /**
     * Handle Subscription Deleted (Cancelled/Expired)
     */
    private static function handle_subscription_deleted($subscription)
    {
        $user = self::find_and_sync_user($subscription->customer);
        if ($user) {
            Subscription_Manager::cancel_subscription($user->ID);
            self::send_notifications($user->ID, 'subscription_cancelled');
        }
    }

    /**
     * Handle Payment Intent Succeeded
     */
    private static function handle_payment_intent_succeeded($payment_intent)
    {
        // Mostly for logs, subscription logic handled by invoice/subscription events.
        self::log_info("Payment Succeeded: " . $payment_intent->id);
    }

    /**
     * Helper: Update Local Subscription Data
     */
    private static function update_local_subscription($stripe_sub)
    {
        $user = self::find_and_sync_user($stripe_sub->customer);
        if (!$user)
            return;

        Subscription_Manager::update_subscription_details(
            $user->ID,
            $stripe_sub->status,
            $stripe_sub->current_period_end,
            $stripe_sub->cancel_at_period_end
        );

        // Store Trial Dates if present
        if (!empty($stripe_sub->trial_start)) {
            update_user_meta($user->ID, 'pillpalnow_trial_start', $stripe_sub->trial_start);
        }
        if (!empty($stripe_sub->trial_end)) {
            update_user_meta($user->ID, 'pillpalnow_trial_end', $stripe_sub->trial_end);
        }
    }

    /**
     * Helper: Find User by Stripe Customer ID or Email
     */
    private static function find_and_sync_user($stripe_customer_id)
    {
        // 1. Try Meta Lookup
        $users = get_users([
            'meta_key' => 'stripe_customer_id',
            'meta_value' => $stripe_customer_id,
            'number' => 1
        ]);

        if (!empty($users)) {
            return $users[0];
        }

        // 2. Try Email Lookup (Fetch from Stripe)
        try {
            $customer = \Stripe\Customer::retrieve($stripe_customer_id);
            if (!empty($customer->email)) {
                $user = get_user_by('email', $customer->email);
                if ($user) {
                    // Start Syncing for future
                    update_user_meta($user->ID, 'stripe_customer_id', $stripe_customer_id);
                    return $user;
                }
            }
        } catch (\Exception $e) {
            self::log_error("Could not retrieve customer for lookup: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Centralized Notification Sender
     */
    private static function send_notifications($user_id, $type)
    {
        $heading = '';
        $message = '';
        $msg_type = 'subscription'; // Default for OneSignal

        // Define Messages
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
                $message = 'We could not process your payment. Please update your details.';
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
            case "invoice_created":
                $heading = "New Invoice Generated";
                $message = "A new invoice has been generated for your subscription.";
                $msg_type = "billing";
                break;
                
            case "invoice_finalized":
                $heading = "Invoice Ready for Payment";
                $message = "Your invoice has been finalized and is ready for payment.";
                $msg_type = "billing";
                break;
        }

        if (!$heading || !$message) {
            return;
        }

        // 1. In-App Notification (Bell Icon)
        if (class_exists('PillPalNow_Notifications')) {
            PillPalNow_Notifications::create(
                $user_id,
                'system_alert', // Generic system type
                $heading,
                $message
            );
        }

        // 2. Email (via PillPalNow_Email_Service)
        if (class_exists('PillPalNow_Email_Service')) {
            // Ensure Email Service supports these types or falls back gracefully
            PillPalNow_Email_Service::send_template($type, $user_id);
        }

        // 3. Push (via PillPalNow_OneSignal_Service)
        if (class_exists('PillPalNow_OneSignal_Service')) {
            // P0 Fix: Use singleton accessor — constructor is private.
            // Direct instantiation via `new` would cause a fatal error.
            $onesignal = PillPalNow_OneSignal_Service::get_instance();
            $onesignal->send_notification($user_id, $heading, $message, $msg_type);
        }

        // Log the attempt
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log($user_id, $type, 'all', 'sent', "$heading - $message");
        }
    }

    /**
     * Helper: Get Webhook Secret
     */
    private static function get_webhook_secret()
    {
        return defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';
    }

    /**
     * Logging Helpers
     */
    private static function log_error($message)
    {
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log(0, 'webhook_error', 'stripe', 'failed', $message);
        }
        error_log("[PillPalNow Webhook Error] " . $message);
    }

    private static function log_info($message)
    {
        // Optional: verbose logging
        error_log("[PillPalNow Webhook] " . $message);
    }
}
