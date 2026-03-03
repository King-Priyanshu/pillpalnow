<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Subscription Dashboard
 * 
 * Provides a frontend subscription management page via [pillpalnow_manage_subscription] shortcode.
 * Hybrid model: read-only dashboard with secure links to Stripe Customer Portal
 * for payment operations, plus custom cancellation flow for retention.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Subscription_Dashboard
{
    /** @var PillPalNow_Subscription_Dashboard Singleton */
    private static $instance = null;

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
        // Register shortcode
        add_shortcode('pillpalnow_manage_subscription', [$this, 'render_dashboard']);

        // AJAX handlers
        add_action('wp_ajax_pillpalnow_get_portal_url', [$this, 'ajax_get_portal_url']);
        add_action('wp_ajax_pillpalnow_cancel_subscription', [$this, 'ajax_cancel_subscription']);
        add_action('wp_ajax_pillpalnow_reactivate_subscription', [$this, 'ajax_reactivate_subscription']);

        // Handle token-based access
        add_action('template_redirect', [$this, 'handle_token_access']);
    }

    /**
     * Handle token-based access from email links
     */
    public function handle_token_access()
    {
        if (!isset($_GET['token']) || !isset($_GET['action'])) {
            return;
        }

        // Only process on our subscription page
        if (!is_page('manage-subscription')) {
            return;
        }

        $token = sanitize_text_field($_GET['token']);
        $action = sanitize_key($_GET['action']);

        if (!class_exists('PillPalNow_Secure_Token')) {
            return;
        }

        $result = PillPalNow_Secure_Token::validate($token, $action);

        if (!$result['valid']) {
            // Store error for display
            set_transient('pillpalnow_token_error_' . session_id(), $result['error'], 300);
            return;
        }

        // Auto-login user if not logged in
        if (!is_user_logged_in()) {
            wp_set_auth_cookie($result['user_id'], false, is_ssl());
            wp_set_current_user($result['user_id']);
        }

        // Verify user ownership
        if (get_current_user_id() !== $result['user_id']) {
            set_transient('pillpalnow_token_error_' . session_id(), 'User mismatch', 300);
            return;
        }

        // Revoke token after use (one-time use for cancel)
        if ($action === 'cancel') {
            PillPalNow_Secure_Token::revoke($token);
        }
    }

    /**
     * Render the subscription dashboard [shortcode]
     */
    public function render_dashboard($atts = [])
    {
        // Require login
        if (!is_user_logged_in()) {
            return '<div class="pillpalnow-login-required">'
                . '<p>' . __('Please log in to manage your subscription.', 'pillpalnow') . '</p>'
                . '<a href="' . esc_url(wp_login_url(get_permalink())) . '" class="pillpalnow-btn-primary">'
                . __('Log In', 'pillpalnow') . '</a></div>';
        }

        $user_id = get_current_user_id();
        $subscription = $this->get_subscription_data($user_id);
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'dashboard';

        // Enqueue assets
        $this->enqueue_assets();

        ob_start();
        ?>
        <div id="pillpalnow-subscription-dashboard" class="pillpalnow-dashboard"
            data-nonce="<?php echo esc_attr(wp_create_nonce('pillpalnow_subscription_nonce')); ?>">

            <?php if ($action === 'cancel'): ?>
                <?php $this->render_cancellation_step($subscription); ?>
            <?php else: ?>
                <?php $this->render_main_dashboard($subscription); ?>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the main dashboard view
     */
    private function render_main_dashboard($subscription)
    {
        $status_class = $this->get_status_class($subscription['status']);
        $status_label = $this->get_status_label($subscription['status']);
        ?>
        <!-- Subscription Status Card -->
        <div class="pillpalnow-card pillpalnow-status-card">
            <div class="pillpalnow-card-header">
                <h2>
                    <?php esc_html_e('Your Subscription', 'pillpalnow'); ?>
                </h2>
                <span class="pillpalnow-status-badge <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            </div>
            <div class="pillpalnow-card-body">
                <div class="pillpalnow-info-grid">
                    <div class="pillpalnow-info-item">
                        <span class="pillpalnow-info-label">
                            <?php esc_html_e('Plan', 'pillpalnow'); ?>
                        </span>
                        <span class="pillpalnow-info-value">
                            <?php echo esc_html($subscription['plan_name']); ?>
                        </span>
                    </div>
                    <?php if ($subscription['amount']): ?>
                        <div class="pillpalnow-info-item">
                            <span class="pillpalnow-info-label">
                                <?php esc_html_e('Amount', 'pillpalnow'); ?>
                            </span>
                            <span class="pillpalnow-info-value">
                                <?php echo esc_html($subscription['amount']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($subscription['next_billing_date']): ?>
                        <div class="pillpalnow-info-item">
                            <span class="pillpalnow-info-label">
                                <?php esc_html_e('Next Billing Date', 'pillpalnow'); ?>
                            </span>
                            <span class="pillpalnow-info-value">
                                <?php echo esc_html($subscription['next_billing_date']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($subscription['start_date']): ?>
                        <div class="pillpalnow-info-item">
                            <span class="pillpalnow-info-label">
                                <?php esc_html_e('Member Since', 'pillpalnow'); ?>
                            </span>
                            <span class="pillpalnow-info-value">
                                <?php echo esc_html($subscription['start_date']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($subscription['status'] === 'cancelling' || !empty($subscription['cancel_at_period_end'])): ?>
            <!-- Cancellation Pending Banner -->
            <div class="pillpalnow-card pillpalnow-cancel-pending-card">
                <div class="pillpalnow-card-body">
                    <div class="pillpalnow-pending-banner">
                        <span class="pillpalnow-pending-icon">⏳</span>
                        <div class="pillpalnow-pending-info">
                            <h3><?php esc_html_e('Cancellation Pending', 'pillpalnow'); ?></h3>
                            <p>
                                <?php
                                printf(
                                    esc_html__('Your subscription will end on %s. You have full access until then.', 'pillpalnow'),
                                    '<strong>' . esc_html($subscription['access_end_date']) . '</strong>'
                                );
                                ?>
                            </p>
                        </div>
                    </div>
                    <button type="button" class="pillpalnow-btn pillpalnow-btn-primary" id="pillpalnow-reactivate">
                        <?php esc_html_e('Keep Subscription — Reactivate', 'pillpalnow'); ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="pillpalnow-action-buttons">
            <?php if (in_array($subscription['status'], ['active', 'trialing'])): ?>
                <button type="button" class="pillpalnow-btn pillpalnow-btn-primary" id="pillpalnow-manage-billing">
                    <?php esc_html_e('Manage Billing', 'pillpalnow'); ?>
                </button>
                <button type="button" class="pillpalnow-btn pillpalnow-btn-outline" id="pillpalnow-update-payment">
                    <?php esc_html_e('Update Payment Method', 'pillpalnow'); ?>
                </button>
                <?php if (empty($subscription['cancel_at_period_end'])): ?>
                    <a href="<?php echo esc_url(add_query_arg('action', 'cancel', get_permalink())); ?>"
                        class="pillpalnow-btn pillpalnow-btn-danger-outline">
                        <?php esc_html_e('Cancel Subscription', 'pillpalnow'); ?>
                    </a>
                <?php endif; ?>
            <?php elseif ($subscription['status'] === 'cancelling'): ?>
                <button type="button" class="pillpalnow-btn pillpalnow-btn-outline" id="pillpalnow-manage-billing">
                    <?php esc_html_e('Manage Billing', 'pillpalnow'); ?>
                </button>
            <?php elseif (in_array($subscription['status'], ['canceled', 'cancelled'])): ?>
                <button type="button" class="pillpalnow-btn pillpalnow-btn-primary" id="pillpalnow-reactivate">
                    <?php esc_html_e('Reactivate Subscription', 'pillpalnow'); ?>
                </button>
            <?php elseif ($subscription['status'] === 'past_due'): ?>
                <button type="button" class="pillpalnow-btn pillpalnow-btn-primary" id="pillpalnow-update-payment">
                    <?php esc_html_e('Update Payment Method', 'pillpalnow'); ?>
                </button>
            <?php else: ?>
                <a href="<?php echo esc_url(home_url('/choose-plan/')); ?>" class="pillpalnow-btn pillpalnow-btn-primary">
                    <?php esc_html_e('Subscribe Now', 'pillpalnow'); ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Features Section -->
        <?php if (in_array($subscription['status'], ['active', 'trialing', 'cancelling'])): ?>
            <div class="pillpalnow-card pillpalnow-features-card">
                <div class="pillpalnow-card-header">
                    <h3>
                        <?php esc_html_e('Your Pro Features', 'pillpalnow'); ?>
                    </h3>
                </div>
                <div class="pillpalnow-card-body">
                    <ul class="pillpalnow-feature-list">
                        <li class="active">✅
                            <?php esc_html_e('Unlimited Medication Tracking', 'pillpalnow'); ?>
                        </li>
                        <li class="active">✅
                            <?php esc_html_e('Caregiver & Family Mode', 'pillpalnow'); ?>
                        </li>
                        <li class="active">✅
                            <?php esc_html_e('Advanced Refill Alerts', 'pillpalnow'); ?>
                        </li>
                        <li class="active">✅
                            <?php esc_html_e('Cloud Sync Across Devices', 'pillpalnow'); ?>
                        </li>
                        <li class="active">✅
                            <?php esc_html_e('Priority Support', 'pillpalnow'); ?>
                        </li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Support Section -->
        <div class="pillpalnow-card pillpalnow-support-card">
            <div class="pillpalnow-card-body">
                <p class="pillpalnow-support-text">
                    <?php printf(
                        esc_html__('Need help? Contact us at %s', 'pillpalnow'),
                        '<a href="mailto:' . esc_attr(get_option('admin_email')) . '">' . esc_html(get_option('admin_email')) . '</a>'
                    ); ?>
                </p>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="pillpalnow-loading-overlay" id="pillpalnow-loading" style="display:none;">
            <div class="pillpalnow-spinner"></div>
            <p>
                <?php esc_html_e('Processing...', 'pillpalnow'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the cancellation step
     */
    private function render_cancellation_step($subscription)
    {
        if (!in_array($subscription['status'], ['active', 'trialing', 'cancelling'])) {
            echo '<div class="pillpalnow-card"><div class="pillpalnow-card-body">';
            echo '<p>' . esc_html__('You do not have an active subscription to cancel.', 'pillpalnow') . '</p>';
            echo '<a href="' . esc_url(remove_query_arg('action')) . '" class="pillpalnow-btn pillpalnow-btn-primary">' . esc_html__('Back to Dashboard', 'pillpalnow') . '</a>';
            echo '</div></div>';
            return;
        }
        ?>
        <!-- Cancellation Flow -->
        <div class="pillpalnow-card pillpalnow-cancel-card">
            <div class="pillpalnow-card-header">
                <h2>
                    <?php esc_html_e('Cancel Your Subscription', 'pillpalnow'); ?>
                </h2>
            </div>
            <div class="pillpalnow-card-body">
                <!-- Step 1: Confirmation -->
                <div class="pillpalnow-cancel-step" id="cancel-step-1">
                    <div class="pillpalnow-cancel-warning">
                        <h3>⚠️
                            <?php esc_html_e('Are you sure?', 'pillpalnow'); ?>
                        </h3>
                        <p>
                            <?php esc_html_e('If you cancel, you\'ll lose access to these features at the end of your current billing period:', 'pillpalnow'); ?>
                        </p>
                        <ul class="pillpalnow-feature-loss-list">
                            <li>❌
                                <?php esc_html_e('Unlimited Medication Tracking', 'pillpalnow'); ?>
                            </li>
                            <li>❌
                                <?php esc_html_e('Caregiver & Family Mode', 'pillpalnow'); ?>
                            </li>
                            <li>❌
                                <?php esc_html_e('Advanced Refill Alerts', 'pillpalnow'); ?>
                            </li>
                            <li>❌
                                <?php esc_html_e('Cloud Sync Across Devices', 'pillpalnow'); ?>
                            </li>
                        </ul>
                    </div>

                    <!-- Retention Offer (optional, admin-controlled) -->
                    <?php if ($this->has_retention_offer()): ?>
                        <div class="pillpalnow-retention-offer">
                            <div class="pillpalnow-offer-badge">🎁
                                <?php esc_html_e('Special Offer', 'pillpalnow'); ?>
                            </div>
                            <h3>
                                <?php echo esc_html($this->get_retention_offer_heading()); ?>
                            </h3>
                            <p>
                                <?php echo esc_html($this->get_retention_offer_text()); ?>
                            </p>
                            <button type="button" class="pillpalnow-btn pillpalnow-btn-primary" id="pillpalnow-accept-offer">
                                <?php esc_html_e('Accept Offer & Stay', 'pillpalnow'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Cancel Reason -->
                    <div class="pillpalnow-cancel-reason">
                        <label for="cancel-reason">
                            <?php esc_html_e('Help us improve — why are you cancelling?', 'pillpalnow'); ?>
                        </label>
                        <select id="cancel-reason" name="cancel_reason" class="pillpalnow-select">
                            <option value="">
                                <?php esc_html_e('Select a reason', 'pillpalnow'); ?>
                            </option>
                            <option value="too_expensive">
                                <?php esc_html_e('Too expensive', 'pillpalnow'); ?>
                            </option>
                            <option value="not_using">
                                <?php esc_html_e('Not using it enough', 'pillpalnow'); ?>
                            </option>
                            <option value="missing_features">
                                <?php esc_html_e('Missing features I need', 'pillpalnow'); ?>
                            </option>
                            <option value="technical_issues">
                                <?php esc_html_e('Technical issues', 'pillpalnow'); ?>
                            </option>
                            <option value="found_alternative">
                                <?php esc_html_e('Found an alternative', 'pillpalnow'); ?>
                            </option>
                            <option value="other">
                                <?php esc_html_e('Other', 'pillpalnow'); ?>
                            </option>
                        </select>
                        <textarea id="cancel-feedback" name="cancel_feedback"
                            placeholder="<?php esc_attr_e('Additional feedback (optional)', 'pillpalnow'); ?>"
                            class="pillpalnow-textarea" rows="3"></textarea>
                    </div>

                    <!-- Cancel Mode Selection -->
                    <div class="pillpalnow-cancel-mode">
                        <label class="pillpalnow-cancel-mode-label">
                            <?php esc_html_e('When should cancellation take effect?', 'pillpalnow'); ?>
                        </label>
                        <div class="pillpalnow-radio-group">
                            <label class="pillpalnow-radio-option pillpalnow-radio-selected">
                                <input type="radio" name="cancel_mode" value="at_period_end" checked>
                                <span class="pillpalnow-radio-content">
                                    <strong><?php esc_html_e('At end of billing period', 'pillpalnow'); ?></strong>
                                    <small><?php esc_html_e('Keep access until your current period ends. Recommended.', 'pillpalnow'); ?></small>
                                </span>
                            </label>
                            <label class="pillpalnow-radio-option">
                                <input type="radio" name="cancel_mode" value="immediately">
                                <span class="pillpalnow-radio-content">
                                    <strong><?php esc_html_e('Cancel immediately', 'pillpalnow'); ?></strong>
                                    <small><?php esc_html_e('Lose access right away. This cannot be undone.', 'pillpalnow'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="pillpalnow-cancel-actions">
                        <a href="<?php echo esc_url(remove_query_arg('action')); ?>" class="pillpalnow-btn pillpalnow-btn-primary">
                            <?php esc_html_e('Keep My Subscription', 'pillpalnow'); ?>
                        </a>
                        <button type="button" class="pillpalnow-btn pillpalnow-btn-danger" id="pillpalnow-confirm-cancel">
                            <?php esc_html_e('Confirm Cancellation', 'pillpalnow'); ?>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Processing / Success -->
                <div class="pillpalnow-cancel-step" id="cancel-step-2" style="display:none;">
                    <div class="pillpalnow-cancel-success">
                        <div class="pillpalnow-success-icon">✅</div>
                        <h3>
                            <?php esc_html_e('Subscription Cancelled', 'pillpalnow'); ?>
                        </h3>
                        <p id="pillpalnow-cancel-success-message">
                            <?php esc_html_e('Your subscription has been cancelled.', 'pillpalnow'); ?>
                        </p>
                        <p class="pillpalnow-access-until" id="pillpalnow-access-until"></p>
                        <a href="<?php echo esc_url(remove_query_arg('action')); ?>" class="pillpalnow-btn pillpalnow-btn-outline">
                            <?php esc_html_e('Back to Dashboard', 'pillpalnow'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="pillpalnow-loading-overlay" id="pillpalnow-loading" style="display:none;">
            <div class="pillpalnow-spinner"></div>
            <p>
                <?php esc_html_e('Processing your request...', 'pillpalnow'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get the Stripe subscription ID from user meta (handles key variants)
     */
    private function get_stripe_subscription_id($user_id)
    {
        // Try stripe-saas key first, then pillpalnow-core key
        $id = get_user_meta($user_id, '_stripe_subscription_id', true);
        if (!$id) {
            $id = get_user_meta($user_id, 'stripe_subscription_id', true);
        }
        return $id ?: '';
    }

    /**
     * Get the Stripe customer ID from user meta (handles key variants)
     */
    private function get_stripe_customer_id($user_id)
    {
        $id = get_user_meta($user_id, '_stripe_customer_id', true);
        if (!$id) {
            $id = get_user_meta($user_id, 'stripe_customer_id', true);
        }
        return $id ?: '';
    }

    /**
     * Get subscription data for a user
     */
    private function get_subscription_data($user_id)
    {
        $status = get_user_meta($user_id, Subscription_Manager::META_KEY_SUB_STATUS, true) ?: 'none';
        $expiry = get_user_meta($user_id, Subscription_Manager::META_KEY_EXPIRY_DATE, true);
        $tier = get_user_meta($user_id, 'pillpalnow_plan_id', true) ?: '';
        $subscription_id = $this->get_stripe_subscription_id($user_id);
        $customer_id = $this->get_stripe_customer_id($user_id);
        $created = get_user_meta($user_id, Subscription_Manager::META_KEY_START_DATE, true);
        $cancel_at_period_end = get_user_meta($user_id, 'pillpalnow_cancel_at_period_end', true) === true;
        // Format dates
        $next_billing = '';
        $access_end_date = '';
        if ($expiry && is_numeric($expiry)) {
            $next_billing = date_i18n(get_option('date_format'), $expiry);
            $access_end_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expiry);
        }

        $start_date = '';
        if ($created && is_numeric($created)) {
            $start_date = date_i18n(get_option('date_format'), $created);
        }

        // Get plan name from tier
        $plan_name = 'Free Plan';
        if ($tier) {
            $plan_name = ucfirst(str_replace('-', ' ', $tier));
        }
        if (in_array($status, ['active', 'trialing', 'cancelling'])) {
            $plan_name = 'Pro Cloud Sync';
        }

        // Get amount from meta or settings
        $amount = get_user_meta($user_id, 'pillpalnow_plan_amount', true) ?: '';
        if ($amount && is_numeric($amount)) {
            $amount = '$' . number_format($amount / 100, 2) . '/mo';
        }

        return [
            'status' => $status,
            'plan_name' => $plan_name,
            'amount' => $amount,
            'next_billing_date' => $next_billing,
            'start_date' => $start_date,
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'tier' => $tier,
            'cancel_at_period_end' => $cancel_at_period_end,
            'access_end_date' => $access_end_date,
        ];
    }

    /**
     * AJAX: Get Stripe Customer Portal URL
     */
    public function ajax_get_portal_url()
    {
        check_ajax_referer('pillpalnow_subscription_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated.', 'pillpalnow')]);
        }

        $user_id = get_current_user_id();
        $customer_id = $this->get_stripe_customer_id($user_id);

        if (!$customer_id) {
            wp_send_json_error(['message' => __('No billing account found.', 'pillpalnow')]);
        }

        // Create Stripe Customer Portal session
        try {
            if (!class_exists('Stripe_SaaS_Core')) {
                wp_send_json_error(['message' => __('Stripe integration not available.', 'pillpalnow')]);
            }

            $stripe_secret = get_option('stripe_saas_secret_key');
            if (!$stripe_secret) {
                wp_send_json_error(['message' => __('Stripe not configured.', 'pillpalnow')]);
            }

            \Stripe\Stripe::setApiKey($stripe_secret);

            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $customer_id,
                'return_url' => get_permalink(),
            ]);

            wp_send_json_success(['url' => $session->url]);
        } catch (\Exception $e) {
            error_log('[PillPalNow Dashboard] Portal error: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to access billing portal. Please try again.', 'pillpalnow')]);
        }
    }

    /**
     * AJAX: Cancel subscription
     */
    public function ajax_cancel_subscription()
    {
        check_ajax_referer('pillpalnow_subscription_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated.', 'pillpalnow')]);
        }

        $user_id = get_current_user_id();

        // ── Duplicate prevention: transient lock ──
        $lock_key = 'pillpalnow_cancel_lock_' . $user_id;
        if (get_transient($lock_key)) {
            wp_send_json_error([
                'message' => __('A cancellation request is already being processed. Please wait.', 'pillpalnow')
            ]);
        }
        set_transient($lock_key, true, 30); // 30-second lock

        // Rate limit check
        if (class_exists('PillPalNow_Secure_Token')) {
            $rate = PillPalNow_Secure_Token::check_rate_limit($user_id);
            if (!$rate['allowed']) {
                delete_transient($lock_key);
                wp_send_json_error([
                    'message' => sprintf(
                        __('Too many cancellation attempts. Please try again in %d minutes.', 'pillpalnow'),
                        ceil($rate['reset_in'] / 60)
                    )
                ]);
            }
            PillPalNow_Secure_Token::increment_rate_limit($user_id);
        }

        $subscription_id = $this->get_stripe_subscription_id($user_id);

        if (!$subscription_id) {
            delete_transient($lock_key);
            wp_send_json_error(['message' => __('No active subscription found.', 'pillpalnow')]);
        }

        // Check current status — prevent cancelling already-cancelled subscription
        $current_status = get_user_meta($user_id, 'pillpalnow_sub_status', true);
        if (in_array($current_status, ['cancelled', 'expired', 'none', ''])) {
            delete_transient($lock_key);
            wp_send_json_error(['message' => __('This subscription is already cancelled or inactive.', 'pillpalnow')]);
        }

        // Collect feedback
        $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';
        $cancel_mode = isset($_POST['cancel_mode']) ? sanitize_key($_POST['cancel_mode']) : 'at_period_end';

        try {
            if (!class_exists('Stripe_SaaS_Core')) {
                delete_transient($lock_key);
                wp_send_json_error(['message' => __('Stripe integration not available.', 'pillpalnow')]);
            }

            $stripe_secret = get_option('stripe_saas_secret_key');
            \Stripe\Stripe::setApiKey($stripe_secret);

            $cancel_metadata = [
                'cancel_reason' => $reason,
                'cancel_feedback' => substr($feedback, 0, 500),
                'cancelled_via' => 'dashboard',
                'cancel_mode' => $cancel_mode,
            ];

            if ($cancel_mode === 'immediately') {
                // ── Immediate cancellation: delete subscription now ──
                $subscription = \Stripe\Subscription::retrieve($subscription_id);
                $subscription->cancel(['prorate' => true]);

                // Update local status immediately (don't wait for webhook)
                update_user_meta($user_id, 'pillpalnow_sub_status', 'cancelled');
                update_user_meta($user_id, 'pillpalnow_cancel_at_period_end', '0');

                // Revoke access immediately
                if (class_exists('Stripe_SaaS_Access')) {
                    Stripe_SaaS_Access::revoke($user_id);
                }

                // Sync Subscription_Manager
                if (class_exists('Subscription_Manager')) {
                    Subscription_Manager::cancel_subscription($user_id);
                    update_user_meta($user_id, Subscription_Manager::META_KEY_PRO_USER, false);
                }

                $access_until = __('Immediately — access has been revoked', 'pillpalnow');
            } else {
                // ── Cancel at end of billing period (default, grace period) ──
                $subscription = \Stripe\Subscription::update($subscription_id, [
                    'cancel_at_period_end' => true,
                    'metadata' => $cancel_metadata,
                ]);

                // Update local status to cancelling (keep access)
                update_user_meta($user_id, 'pillpalnow_sub_status', 'cancelling');
                update_user_meta($user_id, 'pillpalnow_cancel_at_period_end', '1');

                // Sync Subscription_Manager
                if (class_exists('Subscription_Manager')) {
                    Subscription_Manager::update_subscription_details(
                        $user_id,
                        'cancelling',
                        $subscription->current_period_end,
                        true
                    );
                }

                $access_until = date_i18n(get_option('date_format'), $subscription->current_period_end);
            }

            // Store cancellation data locally
            update_user_meta($user_id, '_pillpalnow_cancel_reason', $reason);
            update_user_meta($user_id, '_pillpalnow_cancel_feedback', $feedback);
            update_user_meta($user_id, '_pillpalnow_cancel_date', time());
            update_user_meta($user_id, '_pillpalnow_cancel_mode', $cancel_mode);

            // Trigger cancellation email
            do_action('pillpalnow_subscription_cancellation_requested', $user_id, [
                'plan_name' => 'Pro Cloud Sync',
                'end_date' => $access_until,
                'reason' => $reason,
                'cancel_mode' => $cancel_mode,
            ]);

            // Log via PillPalNow_Notification_Logger
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log(
                    $user_id,
                    'subscription_cancellation',
                    'dashboard',
                    'completed',
                    sprintf('Subscription cancelled (%s). Reason: %s. Access until: %s', $cancel_mode, $reason ?: 'none', $access_until)
                );
            }

            error_log(sprintf(
                '[PillPalNow] Subscription cancelled by user %d, mode: %s, reason: %s, access_until: %s',
                $user_id,
                $cancel_mode,
                $reason,
                $access_until
            ));

            delete_transient($lock_key);

            wp_send_json_success([
                'message' => __('Subscription cancelled successfully.', 'pillpalnow'),
                'access_until' => $access_until,
                'cancel_mode' => $cancel_mode,
            ]);

        } catch (\Exception $e) {
            delete_transient($lock_key);
            error_log('[PillPalNow Dashboard] Cancel error: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to cancel subscription. Please try again or contact support.', 'pillpalnow')]);
        }
    }

    /**
     * AJAX: Reactivate subscription
     */
    public function ajax_reactivate_subscription()
    {
        check_ajax_referer('pillpalnow_subscription_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated.', 'pillpalnow')]);
        }

        $user_id = get_current_user_id();
        $subscription_id = $this->get_stripe_subscription_id($user_id);

        if (!$subscription_id) {
            wp_send_json_error(['message' => __('No subscription found.', 'pillpalnow')]);
        }

        try {
            $stripe_secret = get_option('stripe_saas_secret_key');
            \Stripe\Stripe::setApiKey($stripe_secret);

            // Remove cancel_at_period_end
            $subscription = \Stripe\Subscription::update($subscription_id, [
                'cancel_at_period_end' => false,
            ]);

            // Update local status
            update_user_meta($user_id, 'pillpalnow_sub_status', $subscription->status);
            update_user_meta($user_id, 'pillpalnow_cancel_at_period_end', '0');
            delete_user_meta($user_id, '_pillpalnow_cancel_date');
            delete_user_meta($user_id, '_pillpalnow_cancel_reason');
            delete_user_meta($user_id, '_pillpalnow_cancel_mode');

            // Sync Subscription_Manager
            if (class_exists('Subscription_Manager')) {
                Subscription_Manager::update_subscription_details(
                    $user_id,
                    $subscription->status,
                    $subscription->current_period_end,
                    false
                );
            }

            // Log
            if (class_exists('PillPalNow_Notification_Logger')) {
                PillPalNow_Notification_Logger::log(
                    $user_id,
                    'subscription_reactivation',
                    'dashboard',
                    'completed',
                    'Subscription reactivated by user'
                );
            }

            error_log(sprintf('[PillPalNow] Subscription reactivated by user %d', $user_id));

            wp_send_json_success(['message' => __('Subscription reactivated successfully!', 'pillpalnow')]);

        } catch (\Exception $e) {
            error_log('[PillPalNow Dashboard] Reactivate error: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to reactivate. Please try again or contact support.', 'pillpalnow')]);
        }
    }

    // -------------------------------------------------------
    // Retention Offer Helpers
    // -------------------------------------------------------

    private function has_retention_offer()
    {
        return get_option('pillpalnow_retention_offer_enabled', false);
    }

    private function get_retention_offer_heading()
    {
        return get_option('pillpalnow_retention_offer_heading', 'Stay and save 25%!');
    }

    private function get_retention_offer_text()
    {
        return get_option('pillpalnow_retention_offer_text', 'We\'d hate to see you go! Accept this special offer to continue with all Pro features at a discounted rate.');
    }

    // -------------------------------------------------------
    // Status Helpers
    // -------------------------------------------------------

    private function get_status_class($status)
    {
        $classes = [
            'active' => 'pillpalnow-badge-active',
            'trialing' => 'pillpalnow-badge-trial',
            'cancelling' => 'pillpalnow-badge-warning',
            'past_due' => 'pillpalnow-badge-warning',
            'canceled' => 'pillpalnow-badge-danger',
            'cancelled' => 'pillpalnow-badge-danger',
            'unpaid' => 'pillpalnow-badge-danger',
            'none' => 'pillpalnow-badge-muted',
        ];
        return isset($classes[$status]) ? $classes[$status] : 'pillpalnow-badge-muted';
    }

    private function get_status_label($status)
    {
        $labels = [
            'active' => __('Active', 'pillpalnow'),
            'trialing' => __('Trial', 'pillpalnow'),
            'cancelling' => __('Cancelling', 'pillpalnow'),
            'past_due' => __('Past Due', 'pillpalnow'),
            'canceled' => __('Cancelled', 'pillpalnow'),
            'cancelled' => __('Cancelled', 'pillpalnow'),
            'unpaid' => __('Unpaid', 'pillpalnow'),
            'none' => __('No Subscription', 'pillpalnow'),
        ];
        return isset($labels[$status]) ? $labels[$status] : __('Unknown', 'pillpalnow');
    }

    // -------------------------------------------------------
    // Assets
    // -------------------------------------------------------

    private function enqueue_assets()
    {
        wp_enqueue_style(
            'pillpalnow-subscription-dashboard',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/subscription-dashboard.css',
            [],
            PILLPALNOW_VERSION
        );
        wp_enqueue_script(
            'pillpalnow-subscription-dashboard',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/subscription-dashboard.js',
            ['jquery'],
            PILLPALNOW_VERSION,
            true
        );
        wp_localize_script('pillpalnow-subscription-dashboard', 'pillpalnowSubscription', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pillpalnow_subscription_nonce'),
            'i18n' => [
                'confirmCancel' => __('Confirm Cancellation', 'pillpalnow'),
                'processing' => __('Processing...', 'pillpalnow'),
                'error' => __('Something went wrong. Please try again.', 'pillpalnow'),
                'redirecting' => __('Redirecting to billing portal...', 'pillpalnow'),
                'immediateConfirm' => __('Are you absolutely sure? Cancelling immediately will revoke your access right now. This cannot be undone.', 'pillpalnow'),
                'cancelledImmediate' => __('Your subscription has been cancelled and access has been revoked immediately.', 'pillpalnow'),
                'cancelledGrace' => __('Your subscription has been cancelled. You\'ll retain access until the end of your billing period.', 'pillpalnow'),
            ],
        ]);
    }
}
