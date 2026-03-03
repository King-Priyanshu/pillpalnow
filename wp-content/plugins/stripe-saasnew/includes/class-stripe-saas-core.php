<?php
/**
 * Stripe SaaS Core
 * 
 * Singleton class that orchestrates all plugin components
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Core
{

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
        $this->load_dependencies();
        $this->init_stripe();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies()
    {
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-metadata.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-access.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-admin.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-checkout.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-webhook.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-onesignal-integration.php';
        require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-dashboard.php';
    }

    /**
     * Initialize Stripe API
     */
    private function init_stripe()
    {
        if (STRIPE_SAAS_SECRET_KEY && class_exists('\Stripe\Stripe')) {
            try {
                \Stripe\Stripe::setApiKey(STRIPE_SAAS_SECRET_KEY);
                \Stripe\Stripe::setApiVersion('2024-11-20.acacia');
            } catch (\Exception $e) {
                error_log('Stripe SaaS: Failed to initialize Stripe API - ' . $e->getMessage());
            }
        }
    }

    /**
     * Register hooks
     */
    private function init_hooks()
    {
        // Initialize components
        Stripe_SaaS_Admin::instance();
        Stripe_SaaS_Checkout::instance();
        Stripe_SaaS_Webhook::instance();
        Stripe_SaaS_Dashboard::instance();

        // Register shortcodes
        add_shortcode('stripe_saas_subscribe', [$this, 'subscribe_shortcode']);
        add_shortcode('choose_plan', [$this, 'choose_plan_shortcode']);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // Free trial auto-start for free_first mode
        add_action('wp_login', [$this, 'maybe_start_free_trial'], 10, 2);

        // Access control middleware (optional - enable if needed)
        // add_action('template_redirect', [$this, 'check_saas_access']);
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action('init', [$this, 'schedule_cron_jobs']);
        add_action('stripe_saas_daily_notifications', [$this, 'run_daily_notifications']);
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_intervals($schedules)
    {
        $schedules['twice_daily'] = [
            'interval' => 43200, // 12 hours
            'display' => __('Twice Daily', 'stripe-saas')
        ];
        return $schedules;
    }

    /**
     * Schedule Cron Jobs
     */
    public function schedule_cron_jobs()
    {
        if (!wp_next_scheduled('stripe_saas_daily_notifications')) {
            wp_schedule_event(time(), 'twice_daily', 'stripe_saas_daily_notifications');
        }
    }

    /**
     * Run Daily Notifications
     */
    public function run_daily_notifications()
    {
        if (class_exists('Stripe_SaaS_OneSignal_Integration')) {
            Stripe_SaaS_OneSignal_Integration::instance()->process_daily_notifications();
        }
    }

    /**
     * Access control middleware
     * Add this hook to enforce access control on front-end pages
     */
    public function check_saas_access()
    {
        // Skip for admin, login, and AJAX requests
        if (is_admin() || is_user_logged_in() === false || wp_doing_ajax()) {
            return;
        }

        // Skip for specific pages (customize as needed)
        $allowed_pages = ['login', 'register', 'choose-plan', 'pricing'];
        if (is_page($allowed_pages)) {
            return;
        }

        $user_id = get_current_user_id();
        $access_mode = Stripe_SaaS_Access::get_access_mode();
        $has_access = Stripe_SaaS_Access::user_has_access($user_id);

        // Block based on access mode
        if (!$has_access) {
            $trial_status = Stripe_SaaS_Access::get_trial_status($user_id);

            // subscribe_first: Block if no subscription
            // free_first: Block if trial expired AND no subscription
            if ($access_mode === 'subscribe_first' || ($access_mode === 'free_first' && $trial_status === 'expired')) {
                // Redirect to plan selection page
                wp_redirect(home_url('/choose-plan/'));
                exit;
            }
        }
    }

    /**
     * Maybe start free trial on login (free_first mode only)
     */
    public function maybe_start_free_trial($user_login, $user)
    {
        $access_mode = Stripe_SaaS_Access::get_access_mode();

        // Only for free_first mode
        if ($access_mode !== 'free_first') {
            return;
        }

        // Check if trial already granted
        $trial_granted = get_user_meta($user->ID, '_stripe_saas_trial_granted', true);
        if ($trial_granted) {
            return;
        }

        // Check if user already has subscription
        $status = Stripe_SaaS_Access::get_status($user->ID);
        if (in_array($status, ['active', 'trialing', 'permanent'])) {
            return;
        }

        // Start trial
        Stripe_SaaS_Access::start_free_trial($user->ID);
    }

    /**
     * Subscribe shortcode
     */
    public function subscribe_shortcode($atts)
    {
        $atts = shortcode_atts([
            'tier' => 'monthly',
            'button_text' => __('Subscribe Now', 'stripe-saas')
        ], $atts);

        if (!is_user_logged_in()) {
            return '<p class="stripe-saas-message">' . __('Please log in to subscribe.', 'stripe-saas') . '</p>';
        }

        $plans = get_option('stripe_saas_plans', []);
        if (!isset($plans[$atts['tier']]) || empty($plans[$atts['tier']]['enabled'])) {
            return '<p class="stripe-saas-message">' . __('This plan is not available.', 'stripe-saas') . '</p>';
        }

        $plan = $plans[$atts['tier']];
        $price = number_format($plan['price_cents'] / 100, 2);

        ob_start();
        ?>
        <div class="stripe-saas-subscribe-wrapper">
            <div class="stripe-saas-plan-info">
                <div class="stripe-saas-price">$
                    <?php echo esc_html($price); ?>
                </div>
                <div class="stripe-saas-interval">per
                    <?php echo esc_html($plan['interval']); ?>
                </div>
                <?php if ($plan['trial_days'] > 0): ?>
                    <div class="stripe-saas-trial">
                        <?php echo esc_html($plan['trial_days']); ?> day free trial
                    </div>
                <?php endif; ?>
            </div>
            <button class="stripe-saas-subscribe-btn" data-tier="<?php echo esc_attr($atts['tier']); ?>"
                data-loading-text="<?php esc_attr_e('Processing...', 'stripe-saas'); ?>">
                <?php echo esc_html($atts['button_text']); ?>
            </button>
            <div class="stripe-saas-error" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Choose Plan shortcode - displays all enabled plans
     */
    public function choose_plan_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return '<p class="stripe-saas-message">' . __('Please log in to view plans.', 'stripe-saas') . '</p>';
        }

        $user_id = get_current_user_id();
        $access_mode = Stripe_SaaS_Access::get_access_mode();
        $trial_status = Stripe_SaaS_Access::get_trial_status($user_id);
        $has_access = Stripe_SaaS_Access::user_has_access($user_id);

        // Get trial configuration
        $global_settings = get_option('stripe_saas_global_settings', []);
        $trial_days = isset($global_settings['trial_days']) ? (int) $global_settings['trial_days'] : 14;
        $trial_days_remaining = Stripe_SaaS_Access::get_trial_days_remaining($user_id);

        // In free_first mode, only show plans if trial expired or about to expire
        if ($access_mode === 'free_first' && $trial_status === 'active') {
            $days_remaining = Stripe_SaaS_Access::get_trial_days_remaining($user_id);

            ob_start();
            ?>
            <div class="stripe-saas-trial-active">
                <h3><?php _e('Your Free Trial is Active', 'stripe-saas'); ?></h3>
                <p><?php printf(__('You have %d days remaining in your free trial.', 'stripe-saas'), $days_remaining); ?></p>
                <p><?php _e('Choose a plan below to continue after your trial ends.', 'stripe-saas'); ?></p>
            </div>
            <?php
        }

        // Load plans
        $plans = get_option('stripe_saas_plans', []);
        $enabled_plans = array_filter($plans, function ($plan) {
            return !empty($plan['enabled']);
        });

        if (empty($enabled_plans)) {
            return '<p class="stripe-saas-message">' . __('No plans available at this time.', 'stripe-saas') . '</p>';
        }

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

        // Check for redirect reason
        $reason = isset($_GET['reason']) ? sanitize_text_field($_GET['reason']) : '';

        ob_start();
        ?>
        <div class="stripe-saas-plans-wrapper">
            <?php if ($reason): ?>
                <?php if ($reason === 'trial_expired'): ?>
                    <div class="stripe-saas-trial-status">
                        <h3><?php _e('Your Free Trial Has Ended', 'stripe-saas'); ?></h3>
                        <p><?php printf(__('Your %d-day trial has expired. Choose a plan below to continue enjoying full access!', 'stripe-saas'), $trial_days); ?>
                        </p>
                    </div>
                <?php elseif ($reason === 'subscription_required'): ?>
                    <div class="stripe-saas-trial-status">
                        <h3><?php _e('Subscription Required', 'stripe-saas'); ?></h3>
                        <p><?php _e('Please choose a plan to get started with full access to all features.', 'stripe-saas'); ?></p>
                    </div>
                <?php elseif ($reason === 'no_access'): ?>
                    <div class="stripe-saas-trial-status">
                        <h3><?php _e('Get Started', 'stripe-saas'); ?></h3>
                        <p><?php _e('Choose the perfect plan for your needs and start your journey today!', 'stripe-saas'); ?></p>
                    </div>
                <?php endif; ?>
            <?php elseif ($access_mode === 'free_first' && $trial_status === 'active'): ?>
                <div class="stripe-saas-trial-status">
                    <h3><?php printf(__('%d Days Left in Your Free Trial', 'stripe-saas'), $trial_days_remaining); ?></h3>
                    <p><?php _e('Upgrade now to lock in your plan and ensure uninterrupted access!', 'stripe-saas'); ?></p>
                </div>
            <?php endif; ?>
            
            
            

            <?php if (!empty($subscription_plans)): ?>
                <div class="stripe-saas-subscription-plans">
                    <h2><?php _e('Subscription Plans', 'stripe-saas'); ?></h2>
                    <div class="stripe-saas-plans-grid">
                        <?php foreach ($subscription_plans as $slug => $plan): ?>
                            <?php
                            // Backward compatibility
                            $plan = array_merge([
                                'display_name' => ucfirst(str_replace('_', ' ', $slug)),
                                'access_level' => 'individual',
                                'trial_days' => 0
                            ], $plan);

                            $price = number_format($plan['price_cents'] / 100, 2);
                            $interval_display = $plan['interval_count'] > 1
                                ? $plan['interval_count'] . ' ' . $plan['interval'] . 's'
                                : $plan['interval'];
                            $trial_text = $plan['trial_days'] > 0
                                ? sprintf(__('%d-day free trial', 'stripe-saas'), $plan['trial_days'])
                                : '';
                            $cta_text = $plan['trial_days'] > 0 ? __('Start Free Trial', 'stripe-saas') : __('Subscribe Now', 'stripe-saas');
                            $btn_class = '';
                            $is_disabled = false;

                            // Check current status
                            if (is_user_logged_in()) {
                                $current_tier_slug = Stripe_SaaS_Access::get_tier($user_id);
                                $status = Stripe_SaaS_Access::get_status($user_id);
                                $has_active_sub = in_array($status, ['active', 'trialing']);

                                if ($current_tier_slug === $slug && $has_active_sub) {
                                    $cta_text = __('Current Plan', 'stripe-saas');
                                    $btn_class = 'current-plan-btn active-plan';
                                    $is_disabled = true;
                                } elseif ($current_tier_slug && $has_active_sub && isset($plans[$current_tier_slug])) {
                                    $current_price = $plans[$current_tier_slug]['price_cents'];
                                    if ($plan['price_cents'] > $current_price) {
                                        $cta_text = __('Upgrade Now', 'stripe-saas');
                                        $btn_class = 'upgrade-btn premium-btn';
                                    } else {
                                        $cta_text = __('Switch to this Plan', 'stripe-saas');
                                        $btn_class = 'downgrade-btn';
                                    }
                                }
                            }

                            // Override CTA for expired trials - but keep current plan as is
                            if ($access_mode === 'free_first' && $trial_status === 'expired' && !($current_tier_slug === $slug && $has_active_sub)) {
                                $cta_text = __('Upgrade Now', 'stripe-saas');
                                $btn_class = 'upgrade-btn'; // Make it pop
                                $is_disabled = false;
                            }
                            ?>
                            <div
                                class="stripe-saas-plan-card <?php echo ($current_tier_slug === $slug && $has_active_sub) ? 'is-current' : ''; ?> <?php echo $plan['access_level'] === 'individual' ? 'recommended' : ''; ?>">
                                <?php if ($current_tier_slug === $slug && $has_active_sub): ?>
                                    <div class="plan-badge current-plan-badge"><?php _e('Your Active Plan', 'stripe-saas'); ?></div>
                                <?php elseif ($plan['access_level'] === 'individual'): ?>
                                    <div class="plan-badge"><?php _e('Most Popular', 'stripe-saas'); ?></div>
                                <?php endif; ?>

                                <h3 class="plan-name"><?php echo esc_html($plan['display_name']); ?></h3>
                                <div class="plan-price">
                                    <span class="amount">$<?php echo esc_html($price); ?></span>
                                    <span class="interval">/ <?php echo esc_html($interval_display); ?></span>
                                </div>

                                <?php if ($trial_text): ?>
                                    <div class="plan-trial"><?php echo esc_html($trial_text); ?></div>
                                <?php endif; ?>

                                <div class="plan-features">
                                    <?php if ($plan['access_level'] === 'group'): ?>
                                        <div class="feature"><?php _e('✓ Multiple user accounts', 'stripe-saas'); ?></div>
                                        <div class="feature"><?php _e('✓ Shared access', 'stripe-saas'); ?></div>
                                        <div class="feature"><?php _e('✓ 20% volume discount', 'stripe-saas'); ?></div>
                                    <?php else: ?>
                                        <div class="feature"><?php _e('✓ Full platform access', 'stripe-saas'); ?></div>
                                        <div class="feature"><?php _e('✓ Premium support', 'stripe-saas'); ?></div>
                                        <div class="feature"><?php _e('✓ Regular updates', 'stripe-saas'); ?></div>
                                    <?php endif; ?>
                                </div>

                                <button class="stripe-saas-subscribe-btn plan-cta <?php echo esc_attr($btn_class); ?>"
                                    data-tier="<?php echo esc_attr($slug); ?>"
                                    data-loading-text="<?php esc_attr_e('Processing...', 'stripe-saas'); ?>" <?php echo $is_disabled ? 'disabled' : ''; ?>>
                                    <?php echo esc_html($cta_text); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($enterprise_plans)): ?>
                <div class="stripe-saas-enterprise-plans">
                    <h2><?php _e('Enterprise Solutions', 'stripe-saas'); ?></h2>
                    <?php foreach ($enterprise_plans as $slug => $plan): ?>
                        <?php
                        // Backward compatibility
                        $plan = array_merge([
                            'display_name' => ucfirst(str_replace('_', ' ', $slug))
                        ], $plan);
                        $price = number_format($plan['price_cents'] / 100, 2);
                        ?>
                        <div class="stripe-saas-plan-card enterprise">
                            <h3 class="plan-name"><?php echo esc_html($plan['display_name']); ?></h3>
                            <div class="plan-price">
                                <span class="amount">$<?php echo esc_html($price); ?></span>
                                <span class="interval"><?php _e('one-time', 'stripe-saas'); ?></span>
                            </div>
                            <div class="plan-features">
                                <div class="feature"><?php _e('✓ Lifetime access', 'stripe-saas'); ?></div>
                                <div class="feature"><?php _e('✓ Priority support', 'stripe-saas'); ?></div>
                                <div class="feature"><?php _e('✓ Custom onboarding', 'stripe-saas'); ?></div>
                                <div class="feature"><?php _e('✓ Dedicated account manager', 'stripe-saas'); ?></div>
                            </div>
                            <button class="stripe-saas-subscribe-btn plan-cta enterprise-cta" data-tier="<?php echo esc_attr($slug); ?>"
                                data-loading-text="<?php esc_attr_e('Processing...', 'stripe-saas'); ?>">
                                <?php _e('Purchase Now', 'stripe-saas'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="stripe-saas-error" style="display:none;"></div>
        </div>
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
     * Check if Stripe is configured
     */
    public static function is_configured()
    {
        return !empty(STRIPE_SAAS_SECRET_KEY) && !empty(STRIPE_SAAS_WEBHOOK_SECRET);
    }
}
