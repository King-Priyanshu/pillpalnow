<?php
/**
 * Stripe SaaS Dashboard & Cancellation Workflow
 * 
 * Handles the user-facing "Manage Subscription" dashboard,
 * secure cancellation workflow, and Stripe Portal integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Dashboard
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
        add_shortcode('stripe_saas_manage', [$this, 'render_dashboard']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('init', [$this, 'handle_magic_link']);
    }

    /**
     * Generate Secure Magic Link
     * Returns a URL that logs the user in (if needed) and redirects to dashboard.
     */
    public static function generate_magic_link($user_id, $expiry_seconds = 3600)
    {
        $token = wp_generate_password(32, false);
        $expiry = time() + $expiry_seconds;

        // Hash token for storage
        update_user_meta($user_id, '_stripe_saas_magic_token', wp_hash($token));
        update_user_meta($user_id, '_stripe_saas_magic_expiry', $expiry);

        return add_query_arg([
            'saas_action' => 'manage',
            'u' => $user_id,
            't' => $token
        ], home_url('/'));
    }

    /**
     * Handle Magic Link Request
     */
    public function handle_magic_link()
    {
        if (!isset($_GET['saas_action']) || $_GET['saas_action'] !== 'manage') {
            return;
        }

        if (!isset($_GET['u']) || !isset($_GET['t'])) {
            return;
        }

        $user_id = intval($_GET['u']);
        $token = sanitize_text_field($_GET['t']);

        // 1. Verify User Exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_die('Invalid user link.');
        }

        // 2. Verify Token
        $stored_hash = get_user_meta($user_id, '_stripe_saas_magic_token', true);
        $expiry = get_user_meta($user_id, '_stripe_saas_magic_expiry', true);

        if (!$stored_hash || !$expiry || time() > $expiry) {
            wp_die('This link has expired. Please request a new one.');
        }

        if (!wp_check_password($token, $stored_hash, $user_id)) {
            // wp_check_password works because we used wp_hash to store it
            // Actually wp_hash uses valid salt, so pure comparison is better if we used wp_hash
            // Let's just compare hashes to be safe with simple storage
            if ($stored_hash !== wp_hash($token)) {
                wp_die('Invalid access token.');
            }
        }

        // 3. Clear Token (One-time use)
        delete_user_meta($user_id, '_stripe_saas_magic_token');
        delete_user_meta($user_id, '_stripe_saas_magic_expiry');

        // 4. Log User In (if not already)
        if (!is_user_logged_in() || get_current_user_id() !== $user_id) {
            wp_set_auth_cookie($user_id);
        }

        // 5. Redirect to Dashboard
        // Find page with [stripe_saas_manage] or fallback
        $dashboard_url = home_url('/dashboard'); // Default

        // Try to find page logic from Core if accessible, or just search here
        $pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => 1,
            's' => '[stripe_saas_manage]',
            'post_status' => 'publish'
        ]);

        if (!empty($pages)) {
            $dashboard_url = get_permalink($pages[0]->ID);
        }

        wp_redirect($dashboard_url);
        exit;
    }

    /**
     * Enqueue Dashboard Assets
     */
     public function enqueue_assets()
    {
        // Check if we're on a page that uses the shortcode
        global $post;
        $is_dashboard_page = false;
        
        if ($post && has_shortcode($post->post_content, 'stripe_saas_manage')) {
            $is_dashboard_page = true;
        }
        
        // Also check by page slug to be safe
        if (is_page() && get_post_field('post_name') === 'subscription') {
            $is_dashboard_page = true;
        }
        
        if ($is_dashboard_page) {
            // Enqueue dashboard specific assets
            wp_enqueue_style(
                'stripe-saas-dashboard',
                STRIPE_SAAS_URL . 'assets/css/dashboard.css',
                [],
                STRIPE_SAAS_VERSION
            );

            wp_enqueue_script(
                'stripe-saas-dashboard',
                STRIPE_SAAS_URL . 'assets/js/dashboard.js',
                ['jquery'],
                STRIPE_SAAS_VERSION,
                true
            );

            // Enqueue frontend assets for plan cards
            wp_enqueue_style(
                'stripe-saas-frontend',
                STRIPE_SAAS_URL . 'assets/css/frontend.css',
                [],
                STRIPE_SAAS_VERSION
            );

            wp_enqueue_script(
                'stripe-saas-checkout',
                STRIPE_SAAS_URL . 'assets/js/checkout-redirect.js',
                ['jquery'],
                STRIPE_SAAS_VERSION,
                true
            );

            wp_localize_script('stripe-saas-checkout', 'stripeSaasData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('stripe_saas_nonce')
            ]);

            wp_localize_script('stripe-saas-dashboard', 'stripeSaasDashboard', [
                'root' => esc_url_raw(rest_url('stripe-saas/v1')),
                'nonce' => wp_create_nonce('wp_rest'),
                'i18n' => [
                    'confirm_cancel' => __('Are you sure you want to cancel?', 'stripe-saas'),
                    'cancellation_success' => __('Subscription cancelled successfully.', 'stripe-saas'),
                    'error_generic' => __('An error occurred. Please try again.', 'stripe-saas'),
                    'no_invoices' => __('No billing history available.', 'stripe-saas')
                ]
            ]);
        }

        // Enqueue Success CSS if payment=success is present
        if (isset($_GET['payment']) && $_GET['payment'] === 'success') {
            wp_enqueue_style(
                'stripe-saas-success',
                STRIPE_SAAS_URL . 'assets/css/success.css',
                [],
                STRIPE_SAAS_VERSION
            );
        }
    }

    /**
     * Render Dashboard Container (Frontend)
     */
    public function render_dashboard($atts)
    {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to manage your subscription.', 'stripe-saas') . '</p>';
        }

        $user_id = get_current_user_id();
        $current_tier_slug = Stripe_SaaS_Access::get_tier($user_id);
        $status = Stripe_SaaS_Access::get_status($user_id);
        $has_active_sub = in_array($status, ['active', 'trialing']);
        
        // Load plans
        $plans = get_option('stripe_saas_plans', []);
        $enabled_plans = array_filter($plans, function ($plan) {
            return !empty($plan['enabled']);
        });

        // Group plans by type
        $subscription_plans = [];
        $enterprise_plans = [];

        foreach ($enabled_plans as $slug => $plan) {
            if ($plan['plan_type'] === 'one_time') {
                $enterprise_plans[$slug] = $plan;
            } else {
                $subscription_plans[$slug] = $plan;
            }
        }

        ob_start();
        ?>
        <div id="stripe-saas-dashboard-container">
            <div class="stripe-saas-loading">
                <div class="spinner"></div>
                <p>
                    <?php _e('Loading subscription details...', 'stripe-saas'); ?>
                </p>
            </div>
            <!-- Content injected via JS -->
            <div id="stripe-saas-dashboard-content" style="display:none;">
                <!-- Section: Current Plan -->
                <div class="dashboard-section plan-details">
                    <h3>
                        <?php _e('Current Subscription', 'stripe-saas'); ?>
                    </h3>
                    <div class="detail-row">
                        <span class="label">
                            <?php _e('Plan:', 'stripe-saas'); ?>
                        </span>
                        <span class="value" id="saas-plan-name">--</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">
                            <?php _e('Status:', 'stripe-saas'); ?>
                        </span>
                        <span class="value" id="saas-plan-status">--</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">
                            <?php _e('Renewal/Expiry Date:', 'stripe-saas'); ?>
                        </span>
                        <span class="value" id="saas-next-date">--</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">
                            <?php _e('Amount:', 'stripe-saas'); ?>
                        </span>
                        <span class="value" id="saas-plan-amount">--</span>
                    </div>
                </div>


                <!-- Section: Actions -->
                <div class="dashboard-actions">
                    <button id="btn-update-payment" class="button button-secondary">
                        <?php _e('Update Payment Method', 'stripe-saas'); ?>
                    </button>
                    <button id="btn-cancel-subscription" class="button button-danger">
                        <?php _e('Cancel Subscription', 'stripe-saas'); ?>
                    </button>
                </div>

                <!-- Section: Pricing -->
                <div class="dashboard-section pricing-section">
                    <h3>
                        <?php _e('Pricing Plans', 'stripe-saas'); ?>
                    </h3>
                    <?php echo do_shortcode('[choose_plan]'); ?>
                </div>

                <!-- Section: Billing History -->
                <div class="dashboard-section billing-history">
                    <h3>
                        <?php _e('Billing History', 'stripe-saas'); ?>
                    </h3>
                    <ul id="saas-billing-history-list">
                        <!-- Injected via JS -->
                    </ul>
                </div>
            </div>

            <!-- Cancellation Modal -->
            <div id="saas-cancel-modal" class="saas-modal" style="display:none;">
                <div class="saas-modal-content">
                    <span class="close-modal">&times;</span>
                    <h2>
                        <?php _e('Cancel Subscription', 'stripe-saas'); ?>
                    </h2>
                    <p>
                        <?php _e('Are you sure you want to cancel? You will lose access to premium features at the end of your current billing period.', 'stripe-saas'); ?>
                    </p>

                    <div class="cancellation-details">
                        <p><strong>
                                <?php _e('Access until:', 'stripe-saas'); ?>
                            </strong> <span id="modal-access-until">--</span></p>
                    </div>

                    <div class="modal-actions">
                        <button id="btn-confirm-cancel" class="button button-danger">
                            <?php _e('Confirm Cancellation', 'stripe-saas'); ?>
                        </button>
                        <button id="btn-abort-cancel" class="button button-secondary">
                            <?php _e('Keep Subscription', 'stripe-saas'); ?>
                        </button>
                    </div>
                </div>
            </div>
            </div>
        </div>
        
        <!-- Success Overlay -->
        <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
        <div id="stripe-saas-success-overlay">
            <div class="success-content">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2><?php _e('Payment Successful!', 'stripe-saas'); ?></h2>
                <p><?php _e('Thank you for your subscription. Your account has been upgraded to Pro.', 'stripe-saas'); ?></p>
                
                <div class="success-details">
                    <div class="row">
                        <span class="label"><?php _e('Status', 'stripe-saas'); ?></span>
                        <span class="value" style="color:#10b981"><?php _e('Active', 'stripe-saas'); ?></span>
                    </div>
                </div>

                <button class="btn-continue" onclick="document.getElementById('stripe-saas-success-overlay').style.display='none'; window.history.replaceState({}, document.title, window.location.pathname);">
                    <?php _e('Continue to Dashboard', 'stripe-saas'); ?>
                </button>
            </div>
            <!-- Simple JS for Confetti Effect -->
            <script>
            (function() {
                const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
                for (let i = 0; i < 50; i++) {
                    const confetti = document.createElement('div');
                    confetti.classList.add('confetti');
                    confetti.style.left = Math.random() * 100 + 'vw';
                    confetti.style.animationDuration = (Math.random() * 3 + 2) + 's';
                    confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                    document.getElementById('stripe-saas-success-overlay').appendChild(confetti);
                }
            })();
            </script>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets()
    {
        wp_enqueue_script(
            'stripe-saas-checkout',
            STRIPE_SAAS_URL . 'assets/js/checkout-redirect.js',
            ['jquery'],
            STRIPE_SAAS_VERSION,
            true
        );

        wp_localize_script('stripe-saas-checkout', 'stripeSaasData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stripe_saas_nonce')
        ]);

        wp_enqueue_style(
            'stripe-saas-frontend',
            STRIPE_SAAS_URL . 'assets/css/frontend.css',
            [],
            STRIPE_SAAS_VERSION
        );
    }

    /**
     * Register REST Routes
     */
    public function register_routes()
    {
        $namespace = 'stripe-saas/v1';

        // GET /subscription - Fetch details
        register_rest_route($namespace, '/subscription', [
            'methods' => 'GET',
            'callback' => [$this, 'get_subscription_details'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);

        // POST /subscription/cancel - Cancel
        register_rest_route($namespace, '/subscription/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancel_subscription'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);

        // POST /portal-session - Redirect to Stripe Customer Portal
        register_rest_route($namespace, '/portal-session', [
            'methods' => 'POST',
            'callback' => [$this, 'create_portal_session'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);

        // POST /subscription/change - Change Plan
        register_rest_route($namespace, '/subscription/change', [
            'methods' => 'POST',
            'callback' => [$this, 'change_subscription'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);
    }

    /**
     * Get available plans for upgrade/downgrade
     */
    private function get_available_plans($current_tier, $active_sub = null)
    {
        $all_plans = get_option('stripe_saas_plans', []);
        $available_plans = [];
        
        foreach ($all_plans as $slug => $plan) {
            if ($plan['enabled'] && $plan['plan_type'] !== 'one_time') { // Only subscriptions
                // Calculate price label
                $interval_display = $plan['interval_count'] > 1 
                    ? $plan['interval_count'] . ' ' . $plan['interval'] . 's' 
                    : $plan['interval'];
                $price_label = '$' . number_format($plan['price_cents'] / 100, 2) . ' / ' . $interval_display;
                $available_plans[] = [
                    'slug' => $slug,
                    'name' => $plan['display_name'],
                    'price_label' => $price_label,
                    // Check via metadata if available, otherwise fallback to current tier
                    'current' => (!empty($active_sub->plan->metadata->tier_slug) && $slug === $active_sub->plan->metadata->tier_slug) ||
                                 (!$active_sub && $slug === $current_tier)
                ];
            }
        }
        
        return $available_plans;
    }

    /**
     * API: Get Subscription Details
     * Fetches live data from Stripe to ensure single source of truth.
     */
    public function get_subscription_details($request)
    {
        $user_id = get_current_user_id();
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true); // Using primary key

        if (!$stripe_customer_id) {
            // Check legacy key
            $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        }

        // Get local subscription data if no Stripe customer ID
        $local_tier = Stripe_SaaS_Access::get_tier($user_id);
        $local_status = Stripe_SaaS_Access::get_status($user_id);
        $expiry = Stripe_SaaS_Access::get_expiry($user_id);
        $plans = get_option('stripe_saas_plans', []);
        
        if (!$stripe_customer_id) {
            // If we have local subscription data but no Stripe customer ID, return that
            if ($local_tier && isset($plans[$local_tier])) {
                $plan = $plans[$local_tier];
                $plan_name = $plan['display_name'];
                $amount = '$' . number_format($plan['price_cents'] / 100, 2) . ' / ' . $plan['interval'];
                $status_label = $local_status ? ucfirst($local_status) : 'Unknown';
                $next_date = $expiry ? date('Y-m-d', $expiry) : 'Unknown';
                
                return rest_ensure_response([
                    'plan_name' => $plan_name,
                    'status' => $status_label,
                    'status_raw' => $local_status,
                    'cancel_at_period_end' => false,
                    'next_payment_date' => $next_date,
                    'amount' => $amount,
                    'invoices' => []
                ]);
            }
            
            // If user is trialing but hasn't picked a plan yet (No-CC mode)
            if ($local_status === 'trialing') {
                return rest_ensure_response([
                    'plan_name' => __('No Plan Selected', 'stripe-saas'),
                    'status' => __('Trialing', 'stripe-saas'),
                    'status_raw' => 'trialing',
                    'cancel_at_period_end' => false,
                    'next_payment_date' => $expiry ? date('Y-m-d', $expiry) : '--',
                    'amount' => '--',
                    'invoices' => []
                ]);
            }
            
            return new WP_Error('no_customer', __('No active plan found. Please choose a plan below.', 'stripe-saas'), ['status' => 200]);
        }

        try {
            // Retrieve customer with expanded subscriptions and invoices
            $customer = \Stripe\Customer::retrieve([
                'id' => $stripe_customer_id,
                'expand' => ['subscriptions', 'invoice_settings.default_payment_method']
            ]);

            $active_sub = null;
            if (!empty($customer->subscriptions->data)) {
                // Find the first active or trialing subscription
                foreach ($customer->subscriptions->data as $sub) {
                    if (in_array($sub->status, ['active', 'trialing'])) {
                        $active_sub = $sub;
                        break;
                    }
                }

                // If no active/trialing, check for canceled but still in grace period? 
                // Stripe marks canceled-at-period-end as "active" usually, 
                // but true "canceled" status means it's done. 
                // Let's just take the first one if we haven't found an active one, for history.
                if (!$active_sub) {
                    $active_sub = $customer->subscriptions->data[0];
                }
            }

            if (!$active_sub) {
                return new WP_Error('no_active_sub', __('No active subscription found on Stripe.', 'stripe-saas'), ['status' => 404]);
            }

            // Prepare Response Data
            $plan_name = $active_sub->plan->interval; // Default fallback
            
            // Try to get plan name from metadata or internal config
            if (!empty($active_sub->plan->metadata->tier_slug)) {
                $plans = get_option('stripe_saas_plans', []);
                $tier_slug = $active_sub->plan->metadata->tier_slug;
                if (isset($plans[$tier_slug])) {
                    $plan_name = $plans[$tier_slug]['display_name'];
                }
            } elseif (isset($active_sub->plan->nickname)) {
                $plan_name = $active_sub->plan->nickname;
            }
            $amount = strtoupper($active_sub->plan->currency) . ' ' . number_format($active_sub->plan->amount / 100, 2);
            $next_date = date('Y-m-d', $active_sub->current_period_end);
            $status_label = ucfirst($active_sub->status);

            if ($active_sub->cancel_at_period_end) {
                $status_label = 'Cancelling (Ends ' . $next_date . ')';
            }

            // Fetch recent invoices
            $invoices = \Stripe\Invoice::all([
                'customer' => $stripe_customer_id,
                'limit' => 5
            ]);

            $invoice_history = [];
            foreach ($invoices->data as $inv) {
                // Skip $0.00 invoices (trial invoices) as they don't represent actual payments
                if ($inv->total === 0) {
                    continue;
                }
                
                $invoice_history[] = [
                    'date' => date('Y-m-d', $inv->created),
                    'amount' => strtoupper($inv->currency) . ' ' . number_format($inv->total / 100, 2),
                    'status' => $inv->status,
                    'pdf_url' => $inv->invoice_pdf
                ];
            }

            // Get Available Plans for Upgrade/Downgrade
            $available_plans = $this->get_available_plans($local_tier, $active_sub);
            
            // Fallback for current flag if metadata missing
            if (empty($active_sub->plan->metadata->tier_slug)) {
                foreach ($available_plans as &$p) {
                    if ($p['name'] === $plan_name)
                        $p['current'] = true;
                }
            }

            return rest_ensure_response([
                'plan_name' => $plan_name,
                'status' => $status_label,
                'status_raw' => $active_sub->status,
                'cancel_at_period_end' => $active_sub->cancel_at_period_end,
                'next_payment_date' => $next_date,
                'amount' => $amount,
                'invoices' => $invoice_history
            ]);

        } catch (\Exception $e) {
            error_log('Stripe SaaS Dashboard Error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * API: Cancel Subscription
     * Sets cancel_at_period_end = true
     */
    public function cancel_subscription($request)
    {
        $user_id = get_current_user_id();
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);

        if (!$stripe_customer_id) {
            $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        }

        if (!$stripe_customer_id) {
            return new WP_Error('no_customer', __('No subscription found to cancel.', 'stripe-saas'), ['status' => 404]);
        }

        try {
            // Get active subscription ID
            $customer = \Stripe\Customer::retrieve([
                'id' => $stripe_customer_id,
                'expand' => ['subscriptions']
            ]);

            $sub_id_to_cancel = null;
            foreach ($customer->subscriptions->data as $sub) {
                if ($sub->status === 'active' || $sub->status === 'trialing') {
                    $sub_id_to_cancel = $sub->id;
                    break;
                }
            }

            if (!$sub_id_to_cancel) {
                return new WP_Error('no_sub', __('No active subscription to cancel.', 'stripe-saas'), ['status' => 404]);
            }

            // Execute Cancellation (At Period End)
            // Idempotency Key: cancel_{sub_id}_{date}
            $idempotency_key = 'cancel_' . $sub_id_to_cancel . '_' . date('Ymd');

            $updated_sub = \Stripe\Subscription::update(
                $sub_id_to_cancel,
                ['cancel_at_period_end' => true],
                ['idempotency_key' => $idempotency_key]
            );

            // Log the Action
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log(
                    $user_id,
                    'subscription_cancel_request',
                    'system',
                    'processed',
                    'User requested cancellation via dashboard. Stripe updated.'
                );
            }

            // Sync Local Meta Immediately (Optimization)
            update_user_meta($user_id, '_stripe_saas_cancel_at_period_end', '1');
            update_user_meta($user_id, '_stripe_saas_status', 'cancelling');
            update_user_meta($user_id, '_stripe_saas_canceled_at', time());
            update_user_meta($user_id, '_stripe_saas_cancellation_type', 'user_dashboard');

            return rest_ensure_response([
                'success' => true,
                'message' => __('Subscription will be cancelled at the end of the billing period.', 'stripe-saas'),
                'period_end' => date('Y-m-d', $updated_sub->current_period_end)
            ]);

        } catch (\Exception $e) {
            error_log('Stripe SaaS Cancel Error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * API: Create Portal Session
     * Redirects user to Stripe Customer Portal to update payment method/billing info.
     */
    public function create_portal_session($request)
    {
        $user_id = get_current_user_id();
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true);

        if (!$stripe_customer_id) {
            $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        }

        if (!$stripe_customer_id) {
            return new WP_Error('no_customer', __('No customer record found.', 'stripe-saas'), ['status' => 404]);
        }

        try {
            $return_url = wp_get_referer() ?: home_url('/dashboard');

            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $stripe_customer_id,
                'return_url' => $return_url,
            ]);

            return rest_ensure_response(['url' => $session->url]);

        } catch (\Exception $e) {
            error_log('Stripe SaaS Portal Error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * API: Change Subscription (Upgrade/Downgrade)
     */
    public function change_subscription($request)
    {
        $user_id = get_current_user_id();
        $new_tier_slug = $request->get_param('tier_slug');

        if (empty($new_tier_slug)) {
            return new WP_Error('missing_param', __('Tier slug is required.', 'stripe-saas'), ['status' => 400]);
        }

        // Get Plan Details
        $plans = get_option('stripe_saas_plans', []);
        if (!isset($plans[$new_tier_slug])) {
            return new WP_Error('invalid_tier', __('Invalid plan selected.', 'stripe-saas'), ['status' => 400]);
        }
        $new_plan = $plans[$new_tier_slug];

        // Get Customer & Active Sub
        $stripe_customer_id = get_user_meta($user_id, 'stripe_customer_id', true) ?: get_user_meta($user_id, '_stripe_customer_id', true);
        if (!$stripe_customer_id) {
            return new WP_Error('no_customer', __('No customer found.', 'stripe-saas'), ['status' => 404]);
        }

        try {
            $customer = \Stripe\Customer::retrieve([
                'id' => $stripe_customer_id,
                'expand' => ['subscriptions']
            ]);

            $active_sub = null;
            foreach ($customer->subscriptions->data as $sub) {
                if ($sub->status === 'active' || $sub->status === 'trialing') {
                    $active_sub = $sub;
                    break;
                }
            }

            if (!$active_sub) {
                return new WP_Error('no_sub', __('No active subscription to change.', 'stripe-saas'), ['status' => 404]);
            }

            // We need to find the price ID for the *Stripe* price object corresponding to this plan.
            // Since we store price_cents/interval but maybe not the price ID directly in 'stripe_saas_plans',
            // we have to be careful. The Checkout class creates prices dynamically or uses stored ones?
            // Checking Checkout class... it creates 'unit_amount' on the fly in session.
            // This means we might NOT have a persistent Price ID to swap to easily unless we created one.
            // BUT for Subscription Update, we need a Price ID (plan ID).
            // If Checkout creates one-off prices, we can't easily swap without creating a new price.
            // Let's create a new Price for this user/update.

            // Actually, best practice is to have Products/Prices in Stripe. 
            // If the plugin defines plans in options, we should try to lookup/create a Price.

            // Re-use logic: Retrieve or Create a Price.
            // Since we don't have a helper for that yet, let's just create a new inline Price item if possible?
            // No, update() requires 'items' with 'price' (ID). You cannot pass 'price_data' to update().

            // Workaround: We must create a Price object first.
            $price = \Stripe\Price::create([
                'unit_amount' => $new_plan['price_cents'],
                'currency' => 'usd',
                'recurring' => ['interval' => $new_plan['interval'], 'interval_count' => $new_plan['interval_count']],
                'product_data' => ['name' => $new_plan['display_name']],
                'nickname' => $new_plan['display_name']
            ]);

            // Now update the subscription
            $updated_sub = \Stripe\Subscription::update($active_sub->id, [
                'cancel_at_period_end' => false, // Reset cancellation if any
                'proration_behavior' => 'always_invoice', // Charge immediately for upgrade difference
                'items' => [
                    [
                        'id' => $active_sub->items->data[0]->id,
                        'price' => $price->id,
                    ],
                ],
                'metadata' => ['tier_slug' => $new_tier_slug]
            ]);

            // Sync local user metadata with new plan information
            Stripe_SaaS_Access::grant($user_id, $new_tier_slug);
            Stripe_SaaS_Access::update_expiry($user_id, $updated_sub->current_period_end);

            return rest_ensure_response([
                'success' => true,
                'message' => __('Subscription updated successfully.', 'stripe-saas'),
                'new_plan' => $new_plan['display_name']
            ]);

        } catch (\Exception $e) {
            error_log('Stripe SaaS Update Error: ' . $e->getMessage());
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }
}
