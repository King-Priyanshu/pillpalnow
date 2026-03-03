<?php
/**
 * Stripe SaaS Checkout
 * 
 * Creates Stripe Hosted Checkout Sessions server-side
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Checkout
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
        add_action('wp_ajax_stripe_saas_create_session', [$this, 'ajax_create_session']);
    }

    /**
     * AJAX handler for creating checkout session
     */
    public function ajax_create_session()
    {
        // Security check
        if (!check_ajax_referer('stripe_saas_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'stripe-saas')], 403);
        }

        // Auth check
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in', 'stripe-saas')], 401);
        }

        // Get tier
        $tier = isset($_POST['tier']) ? sanitize_text_field($_POST['tier']) : '';
        if (empty($tier)) {
            wp_send_json_error(['message' => __('Invalid tier', 'stripe-saas')], 400);
        }

        // Check if user already has this plan active
        $current_tier = Stripe_SaaS_Access::get_tier(get_current_user_id());
        $current_status = Stripe_SaaS_Access::get_status(get_current_user_id());
        if ($current_tier === $tier && in_array($current_status, ['active', 'trialing'])) {
            wp_send_json_error(['message' => __('You already have this plan active', 'stripe-saas')], 400);
        }

        // Check if this is a direct update (same or lower price)
        $is_direct_update = $this->should_process_direct_update(get_current_user_id(), $tier);

        if ($is_direct_update) {
            $result = $this->process_direct_update(get_current_user_id(), $tier);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 500);
            }
            wp_send_json_success([
                'type' => 'direct_update',
                'message' => __('Subscription updated successfully.', 'stripe-saas')
            ]);
            return;
        }

        // Create session
        $result = $this->create_session(get_current_user_id(), $tier);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        wp_send_json_success(['url' => $result]);
    }

    /**
     * Create checkout session
     */
    public function create_session($user_id, $tier_slug)
    {
        // Validate configuration
        if (!Stripe_SaaS_Core::is_configured()) {
            return new WP_Error('not_configured', __('Stripe is not configured', 'stripe-saas'));
        }

        // Get plan
        $plans = get_option('stripe_saas_plans', []);
        if (!isset($plans[$tier_slug])) {
            return new WP_Error('invalid_tier', __('Invalid subscription tier', 'stripe-saas'));
        }

        $plan = $plans[$tier_slug];
        if (!$plan['enabled']) {
            return new WP_Error('plan_disabled', __('This plan is not available', 'stripe-saas'));
        }

        // Get user
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('invalid_user', __('Invalid user', 'stripe-saas'));
        }

        // Build metadata
        $metadata = Stripe_SaaS_Metadata::build([
            'user_id' => (string) $user_id,
            'tier_slug' => $tier_slug,
            'plan_type' => $plan['plan_type']
        ]);

        try {
            // Check if one-time payment or subscription
            $is_one_time = ($plan['plan_type'] === 'one_time');

            // Backward compatibility: Fallback for display_name
            $display_name = $plan['display_name'] ?? ucfirst(str_replace('_', ' ', $tier_slug));

            // Build session arguments
            $session_args = [
                'mode' => $is_one_time ? 'payment' : 'subscription',
                'customer_email' => $user->user_email,
                'client_reference_id' => (string) $user_id,
                'metadata' => $metadata,
                'success_url' => add_query_arg('payment', 'success', site_url('/subscription')),
                'cancel_url' => add_query_arg('payment', 'cancelled', site_url('/subscription')),
            ];

            if ($is_one_time) {
                // One-time payment
                $session_args['line_items'] = [
                    [
                        'price_data' => [
                            'currency' => 'usd',
                            'unit_amount' => $plan['price_cents'],
                            'product_data' => [
                                'name' => $display_name,
                                'description' => 'Lifetime access to ' . $display_name,
                                'metadata' => $metadata
                            ]
                        ],
                        'quantity' => 1
                    ]
                ];
            } else {
                // Subscription
                $session_args['line_items'] = [
                    [
                        'price_data' => [
                            'currency' => 'usd',
                            'unit_amount' => $plan['price_cents'],
                            'recurring' => [
                                'interval' => $plan['interval'],
                                'interval_count' => $plan['interval_count']
                            ],
                            'product_data' => [
                                'name' => $display_name,
                                'metadata' => $metadata
                            ]
                        ],
                        'quantity' => 1
                    ]
                ];

                // Add trial if configured - fallback to global trial days if plan days is 0
                $trial_days = $plan['trial_days'];
                if ($trial_days <= 0) {
                    $global_settings = get_option('stripe_saas_global_settings', []);
                    $trial_days = isset($global_settings['trial_days']) ? absint($global_settings['trial_days']) : 0;
                }

                if ($trial_days > 0) {
                    $session_args['subscription_data'] = [
                        'trial_period_days' => $trial_days,
                        'metadata' => $metadata
                    ];
                }
            }

            // Create session
            $session = \Stripe\Checkout\Session::create($session_args);

            return $session->url;

        } catch (\Exception $e) {
            error_log('Stripe SaaS: Session creation failed - ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Check if we should process this update directly (bypass gateway)
     */
    private function should_process_direct_update($user_id, $new_tier_slug)
    {
        // 1. Must have active subscription
        if (!Stripe_SaaS_Access::has_access($user_id) || Stripe_SaaS_Access::get_status($user_id) !== 'active') {
            return false;
        }

        // 2. Must have a Stripe Subscription ID
        $sub_id = get_user_meta($user_id, '_stripe_subscription_id', true);
        if (!$sub_id) {
            return false;
        }

        // 3. Get current tier price
        $current_tier_slug = Stripe_SaaS_Access::get_tier($user_id);
        $plans = get_option('stripe_saas_plans', []);

        if (!isset($plans[$current_tier_slug]) || !isset($plans[$new_tier_slug])) {
            return false;
        }

        $current_plan = $plans[$current_tier_slug];
        $new_plan = $plans[$new_tier_slug];

        // 4. Compare prices (New price <= Current price)
        // We only bypass for downgrades or lateral moves. Upgrades require payment.
        if ($new_plan['price_cents'] <= $current_plan['price_cents']) {
            return true;
        }

        return false;
    }

    /**
     * Process direct subscription update via Stripe API
     */
    private function process_direct_update($user_id, $new_tier_slug)
    {
        // Validate configuration
        if (!Stripe_SaaS_Core::is_configured()) {
            return new WP_Error('not_configured', __('Stripe is not configured', 'stripe-saas'));
        }

        $payment_method_id = get_user_meta($user_id, '_stripe_payment_method_id', true);
        if (empty($payment_method_id)) {
            // Try to get payment method from subscription if not stored locally
            // This is a bit advanced, for now we rely on the subscription existing.
        }

        $sub_id = get_user_meta($user_id, '_stripe_subscription_id', true);
        if (!$sub_id) {
            return new WP_Error('no_subscription', __('No active subscription found.', 'stripe-saas'));
        }

        // Get plan details
        $plans = get_option('stripe_saas_plans', []);
        $new_plan = $plans[$new_tier_slug];

        try {
            // Retrieve subscription to get item ID
            $subscription = \Stripe\Subscription::retrieve($sub_id);
            $item_id = $subscription->items->data[0]->id;

            // Update subscription
            $updated_sub = \Stripe\Subscription::update($sub_id, [
                'items' => [
                    [
                        'id' => $item_id,
                        'price_data' => [
                            'currency' => 'usd',
                            'product' => $new_plan['product_id'] ?? null, // If using Products, otherwise we need price_id
                            'unit_amount' => $new_plan['price_cents'],
                            'recurring' => [
                                'interval' => $new_plan['interval'],
                                'interval_count' => $new_plan['interval_count']
                            ],
                            'product_data' => [
                                'name' => $new_plan['display_name'] ?? ucfirst(str_replace('_', ' ', $new_tier_slug)),
                                'metadata' => [
                                    'tier_slug' => $new_tier_slug
                                ]
                            ]
                        ],
                    ],
                ],
                'metadata' => [
                    'tier_slug' => $new_tier_slug,
                    'user_id' => $user_id
                ],
                'proration_behavior' => 'always_invoice', // Invoice immediately for changes
            ]);

            // Update local state immediately
            Stripe_SaaS_Access::grant($user_id, $new_tier_slug);

            // Log
            error_log("Stripe SaaS: Direct subscription update success for user $user_id to $new_tier_slug");

            return true;

        } catch (\Exception $e) {
            error_log('Stripe SaaS: Direct update failed - ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }
}
