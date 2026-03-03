<?php
/**
 * Stripe SaaS Admin
 * 
 * Provides WordPress admin interface for plan configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stripe_SaaS_Admin
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
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_stripe_saas_reset_plans', [$this, 'ajax_reset_plans']);
        add_action('show_user_profile', [$this, 'show_user_subscription_info']);
        add_action('edit_user_profile', [$this, 'show_user_subscription_info']);
        add_filter('manage_users_columns', [$this, 'add_subscription_column']);
        add_filter('manage_users_custom_column', [$this, 'render_subscription_column'], 10, 3);
    }
    
    /**
     * Add subscription status column to users list
     */
    public function add_subscription_column($columns)
    {
        $columns['stripe_saas_subscription'] = __('Subscription', 'stripe-saas');
        return $columns;
    }
    
    /**
     * Render subscription status column
     */
    public function render_subscription_column($content, $column_name, $user_id)
    {
        if ($column_name === 'stripe_saas_subscription') {
            $current_tier = Stripe_SaaS_Access::get_tier($user_id);
            $current_status = Stripe_SaaS_Access::get_status($user_id);
            $plans = get_option('stripe_saas_plans', []);
            
            if ($current_tier && isset($plans[$current_tier])) {
                $plan_name = $plans[$current_tier]['display_name'];
                $status = $current_status ? ucfirst($current_status) : 'Inactive';
                
                return '<div class="stripe-saas-subscription-cell">
                    <div class="plan-name">' . esc_html($plan_name) . '</div>
                    <div class="status status-' . esc_attr($current_status) . '">' . esc_html($status) . '</div>
                </div>';
            } else {
                return '<span class="status-inactive">' . __('No subscription', 'stripe-saas') . '</span>';
            }
        }
        
        return $content;
    }
    
    /**
     * Show subscription information on user profile page
     */
    public function show_user_subscription_info($user)
    {
        $user_id = $user->ID;
        $current_tier = Stripe_SaaS_Access::get_tier($user_id);
        $current_status = Stripe_SaaS_Access::get_status($user_id);
        $granted_at = get_user_meta($user_id, '_stripe_saas_granted_at', true);
        $revoked_at = get_user_meta($user_id, '_stripe_saas_revoked_at', true);
        $expiry = get_user_meta($user_id, '_stripe_saas_expiry', true);
        $is_permanent = get_user_meta($user_id, '_stripe_saas_is_permanent', true);
        $stripe_subscription_id = get_user_meta($user_id, '_stripe_subscription_id', true);
        
        $plans = get_option('stripe_saas_plans', []);
        
        ?>
        <h3><?php _e('Stripe SaaS Subscription', 'stripe-saas'); ?></h3>
        
        <table class="form-table">
            <tr>
                <th><?php _e('Current Plan', 'stripe-saas'); ?></th>
                <td>
                    <?php if ($current_tier && isset($plans[$current_tier])): ?>
                        <strong><?php echo esc_html($plans[$current_tier]['display_name']); ?></strong>
                        <p class="description"><?php echo esc_html($current_tier); ?></p>
                    <?php else: ?>
                        <span class="description"><?php _e('No active subscription', 'stripe-saas'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <tr>
                <th><?php _e('Subscription Status', 'stripe-saas'); ?></th>
                <td>
                    <?php if ($current_status): ?>
                        <span class="status-<?php echo esc_attr($current_status); ?>">
                            <?php echo esc_html(ucfirst($current_status)); ?>
                        </span>
                    <?php else: ?>
                        <span class="description"><?php _e('Not subscribed', 'stripe-saas'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <tr>
                <th><?php _e('Permanent Access', 'stripe-saas'); ?></th>
                <td>
                    <?php if ($is_permanent): ?>
                        <span class="status-active"><?php _e('Yes', 'stripe-saas'); ?></span>
                    <?php else: ?>
                        <span class="status-inactive"><?php _e('No', 'stripe-saas'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            
            <?php if ($granted_at): ?>
            <tr>
                <th><?php _e('Access Granted', 'stripe-saas'); ?></th>
                <td>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $granted_at)); ?>
                </td>
            </tr>
            <?php endif; ?>
            
            <?php if ($revoked_at): ?>
            <tr>
                <th><?php _e('Access Revoked', 'stripe-saas'); ?></th>
                <td>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $revoked_at)); ?>
                </td>
            </tr>
            <?php endif; ?>
            
            <?php if ($expiry): ?>
            <tr>
                <th><?php _e('Subscription Expiry', 'stripe-saas'); ?></th>
                <td>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expiry)); ?>
                </td>
            </tr>
            <?php endif; ?>
            
            <?php if ($stripe_subscription_id): ?>
            <tr>
                <th><?php _e('Stripe Subscription ID', 'stripe-saas'); ?></th>
                <td>
                    <code><?php echo esc_html($stripe_subscription_id); ?></code>
                </td>
            </tr>
            <?php endif; ?>
            
            <tr>
                <th><?php _e('Transactions', 'stripe-saas'); ?></th>
                <td>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=stripe-saas-transactions&user_id=' . $user->ID)); ?>">
                        <?php _e('View Transaction History', 'stripe-saas'); ?>
                    </a>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * AJAX handler to reset plans to default structure
     */
    public function ajax_reset_plans()
    {
        check_ajax_referer('stripe_saas_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        
        $defaults = $this->get_default_plans();
        update_option('stripe_saas_plans', $defaults);
        
        wp_send_json_success(['message' => 'Plans reset to default A1-D1 structure']);
    }
    
    /**
     * Get default plan structure
     */
    private function get_default_plans()
    {
        return [
            'a1_monthly_individual' => [
                'enabled' => true,
                'display_name' => 'Monthly - Individual',
                'plan_type' => 'subscription',
                'access_level' => 'individual',
                'price_cents' => 2900,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'a2_monthly_group' => [
                'enabled' => true,
                'display_name' => 'Monthly - Group',
                'plan_type' => 'subscription',
                'access_level' => 'group',
                'price_cents' => 2320,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'b1_yearly_individual' => [
                'enabled' => true,
                'display_name' => 'Yearly - Individual',
                'plan_type' => 'subscription',
                'access_level' => 'individual',
                'price_cents' => 29500,
                'interval' => 'year',
                'interval_count' => 1,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'b2_yearly_group' => [
                'enabled' => true,
                'display_name' => 'Yearly - Group',
                'plan_type' => 'subscription',
                'access_level' => 'group',
                'price_cents' => 23600,
                'interval' => 'year',
                'interval_count' => 1,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'c1_3year_individual' => [
                'enabled' => true,
                'display_name' => '3-Year - Individual',
                'plan_type' => 'subscription',
                'access_level' => 'individual',
                'price_cents' => 83500,
                'interval' => 'year',
                'interval_count' => 3,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'c2_3year_group' => [
                'enabled' => true,
                'display_name' => '3-Year - Group',
                'plan_type' => 'subscription',
                'access_level' => 'group',
                'price_cents' => 66800,
                'interval' => 'year',
                'interval_count' => 3,
                'trial_days' => 14,
                'role' => 'subscriber',
                'access_meta_key' => 'has_saas_access'
            ],
            'd1_enterprise' => [
                'enabled' => true,
                'display_name' => 'Enterprise - Custom',
                'plan_type' => 'one_time',
                'access_level' => 'enterprise',
                'price_cents' => 500000,
                'interval' => '',
                'interval_count' => 0,
                'trial_days' => 0,
                'role' => 'subscriber',
                'access_meta_key' => 'has_enterprise_access'
            ]
        ];
    }

    public function add_menu()
    {
        // Add main menu
        add_menu_page(
            __('Stripe SaaS Settings', 'stripe-saas'),
            __('Stripe SaaS', 'stripe-saas'),
            'manage_options',
            'stripe-saas',
            [$this, 'render_admin_page'],
            'dashicons-money-alt',
            50
        );
        
        // Add transactions page as submenu
        add_submenu_page(
            'stripe-saas',
            __('Transactions', 'stripe-saas'),
            __('Transactions', 'stripe-saas'),
            'manage_options',
            'stripe-saas-transactions',
            [$this, 'render_transactions_page']
        );
    }

    public function register_settings()
    {
        // Global settings
        register_setting(
            'stripe_saas_settings',
            'stripe_saas_global_settings',
            [$this, 'sanitize_global_settings']
        );
        
        // Plan settings
        register_setting(
            'stripe_saas_settings',
            'stripe_saas_plans',
            [$this, 'sanitize_plan_settings']
        );
    }

    public function sanitize_global_settings($input)
    {
        $sanitized = [];
        $sanitized['access_mode'] = in_array($input['access_mode'], ['subscribe_first', 'free_first']) 
            ? $input['access_mode'] 
            : 'subscribe_first';
        $sanitized['trial_mode'] = in_array($input['trial_mode'] ?? 'no_cc', ['no_cc', 'require_cc']) 
            ? ($input['trial_mode'] ?? 'no_cc') 
            : 'no_cc';
        $sanitized['trial_days'] = absint($input['trial_days']);
        return $sanitized;
    }

    public function sanitize_plan_settings($input)
    {
        $sanitized = [];

        foreach ($input as $tier => $plan) {
            $sanitized[$tier] = [
                'enabled' => isset($plan['enabled']) && $plan['enabled'] === '1',
                'display_name' => sanitize_text_field($plan['display_name'] ?? ucfirst($tier)),
                'plan_type' => in_array($plan['plan_type'] ?? 'subscription', ['subscription', 'one_time']) 
                    ? ($plan['plan_type'] ?? 'subscription') 
                    : 'subscription',
                'access_level' => in_array($plan['access_level'] ?? 'individual', ['individual', 'group', 'enterprise']) 
                    ? ($plan['access_level'] ?? 'individual') 
                    : 'individual',
                'price_cents' => absint($plan['price_cents'] ?? 0),
                'interval' => in_array($plan['interval'] ?? 'month', ['day', 'week', 'month', 'year', '']) 
                    ? ($plan['interval'] ?? 'month') 
                    : 'month',
                'interval_count' => max(1, absint($plan['interval_count'] ?? 1)),
                'trial_days' => absint($plan['trial_days'] ?? 0),
                'role' => sanitize_text_field($plan['role'] ?? 'subscriber'),
                'access_meta_key' => sanitize_text_field($plan['access_meta_key'] ?? 'has_saas_access')
            ];

            // Validate price
            if ($sanitized[$tier]['price_cents'] < 50) {
                $sanitized[$tier]['price_cents'] = 50; // Minimum $0.50
            }
        }

        return $sanitized;
    }

    /**
     * Render transactions page
     */
    public function render_transactions_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'stripe_saas_transactions';
        
        // Get filter parameters
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $event_type = isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : '';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        
        // Build SQL query
        $where = 'WHERE 1=1';
        $params = [];
        
        if ($user_id) {
            $where .= ' AND user_id = %d';
            $params[] = $user_id;
        }
        
        if ($event_type) {
            $where .= ' AND event_type = %s';
            $params[] = $event_type;
        }
        
        if ($start_date) {
            $where .= ' AND created_at >= %s';
            $params[] = $start_date . ' 00:00:00';
        }
        
        if ($end_date) {
            $where .= ' AND created_at <= %s';
            $params[] = $end_date . ' 23:59:59';
        }
        
        $per_page = 50;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;
        
        // Get transactions
        $transactions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, $offset])
            )
        );
        
        // Get total count for pagination
        $total_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name $where",
                $params
            )
        );
        
        // Get unique event types for filter dropdown
        $event_types = $wpdb->get_col("SELECT DISTINCT event_type FROM $table_name ORDER BY event_type");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Stripe SaaS Transactions', 'stripe-saas'); ?></h1>
            
            <!-- Filters -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('Filters', 'stripe-saas'); ?></h2>
                <div class="inside">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="stripe-saas-transactions">
                        
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row"><?php _e('User ID', 'stripe-saas'); ?></th>
                                <td>
                                    <input type="number" name="user_id" value="<?php echo esc_attr($user_id); ?>" 
                                           class="regular-text" placeholder="<?php _e('All Users', 'stripe-saas'); ?>">
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e('Event Type', 'stripe-saas'); ?></th>
                                <td>
                                    <select name="event_type" class="regular-text">
                                        <option value=""><?php _e('All Events', 'stripe-saas'); ?></option>
                                        <?php foreach ($event_types as $type): ?>
                                            <option value="<?php echo esc_attr($type); ?>" 
                                                <?php selected($event_type, $type); ?>>
                                                <?php echo esc_html($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr valign="top">
                                <th scope="row"><?php _e('Date Range', 'stripe-saas'); ?></th>
                                <td>
                                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" 
                                           class="regular-text">
                                    <span class="description"><?php _e('to', 'stripe-saas'); ?></span>
                                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" 
                                           class="regular-text">
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(__('Filter', 'stripe-saas'), 'primary', 'filter_transactions'); ?>
                    </form>
                </div>
            </div>
            
            <!-- Transactions Table -->
            <div class="postbox">
                <h2 class="hndle"><?php _e('Transactions', 'stripe-saas'); ?></h2>
                <div class="inside">
                    <?php if (empty($transactions)): ?>
                        <p><?php _e('No transactions found.', 'stripe-saas'); ?></p>
                    <?php else: ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php _e('ID', 'stripe-saas'); ?></th>
                                    <th><?php _e('User', 'stripe-saas'); ?></th>
                                    <th><?php _e('Event Type', 'stripe-saas'); ?></th>
                                    <th><?php _e('Amount', 'stripe-saas'); ?></th>
                                    <th><?php _e('Currency', 'stripe-saas'); ?></th>
                                    <th><?php _e('Created At', 'stripe-saas'); ?></th>
                                    <th><?php _e('Stripe IDs', 'stripe-saas'); ?></th>
                                    <th><?php _e('Metadata', 'stripe-saas'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo esc_html($transaction->id); ?></td>
                                        <td>
                                            <?php if ($transaction->user_id): ?>
                                                <?php $user = get_userdata($transaction->user_id); ?>
                                                <?php if ($user): ?>
                                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $transaction->user_id)); ?>">
                                                        <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($transaction->user_id); ?>)
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo esc_html($transaction->user_id); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php _e('Unknown', 'stripe-saas'); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($transaction->event_type); ?></td>
                                        <td>
                                            <?php if ($transaction->amount_cents > 0): ?>
                                                <?php echo esc_html(number_format($transaction->amount_cents / 100, 2)); ?>
                                            <?php else: ?>
                                                <span class="description"><?php _e('N/A', 'stripe-saas'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(strtoupper($transaction->currency)); ?></td>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?></td>
                                        <td>
                                            <code><?php echo esc_html($transaction->stripe_event_id); ?></code><br>
                                            <code><?php echo esc_html($transaction->stripe_object_id); ?></code>
                                        </td>
                                        <td>
                                            <?php $metadata = json_decode($transaction->metadata, true); ?>
                                            <?php if (!empty($metadata)): ?>
                                                <pre style="max-width: 300px; max-height: 150px; overflow: auto; font-size: 10px;">
                                                    <?php echo esc_html(print_r($metadata, true)); ?>
                                                </pre>
                                            <?php else: ?>
                                                <span class="description"><?php _e('No metadata', 'stripe-saas'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php
                        $pagination_args = [
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Previous', 'stripe-saas'),
                            'next_text' => __('Next &raquo;', 'stripe-saas'),
                            'total' => ceil($total_count / $per_page),
                            'current' => $paged,
                            'type' => 'list'
                        ];
                        
                        // Remove any non-pagination query parameters
                        $query_vars = $_GET;
                        unset($query_vars['paged']);
                        unset($query_vars['filter_transactions']);
                        
                        if (!empty($query_vars)) {
                            $pagination_args['add_args'] = $query_vars;
                        }
                        
                        echo paginate_links($pagination_args);
                        ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $global_settings = get_option('stripe_saas_global_settings', []);
        $plans = get_option('stripe_saas_plans', []);
        
        // Group plans by type
        $monthly_plans = [];
        $yearly_plans = [];
        $three_year_plans = [];
        $enterprise_plans = [];
        
        foreach ($plans as $slug => $plan) {
            $plan = array_merge([
                'display_name' => ucfirst(str_replace('_', ' ', $slug)),
                'plan_type' => 'subscription',
                'access_level' => 'individual'
            ], $plan);
            
            if ($plan['plan_type'] === 'one_time') {
                $enterprise_plans[$slug] = $plan;
            } elseif ($plan['interval'] === 'month' && $plan['interval_count'] == 1) {
                $monthly_plans[$slug] = $plan;
            } elseif ($plan['interval'] === 'year' && $plan['interval_count'] == 1) {
                $yearly_plans[$slug] = $plan;
            } elseif ($plan['interval'] === 'year' && $plan['interval_count'] == 3) {
                $three_year_plans[$slug] = $plan;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!Stripe_SaaS_Core::is_configured()): ?>
                <div class="notice notice-error">
                    <p><strong><?php _e('Stripe is not configured!', 'stripe-saas'); ?></strong></p>
                    <p><?php _e('Please add the following to your wp-config.php file:', 'stripe-saas'); ?></p>
                    <pre>define('STRIPE_SECRET_KEY', 'sk_test_...');\ndefine('STRIPE_WEBHOOK_SECRET', 'whsec_...');</pre>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('stripe_saas_settings'); ?>

                <!-- Global Settings -->
                <h2><?php _e('Global Settings', 'stripe-saas'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Access Mode', 'stripe-saas'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="stripe_saas_global_settings[access_mode]" value="subscribe_first" 
                                        <?php checked($global_settings['access_mode'], 'subscribe_first'); ?>>
                                    <strong><?php _e('Subscribe First', 'stripe-saas'); ?></strong>
                                    <p class="description"><?php _e('Users must choose a plan before getting any access. 14-day trial applies after subscription.', 'stripe-saas'); ?></p>
                                </label>
                                <br><br>
                                <label>
                                    <input type="radio" name="stripe_saas_global_settings[access_mode]" value="free_first" 
                                        <?php checked($global_settings['access_mode'], 'free_first'); ?>>
                                    <strong><?php _e('Free First', 'stripe-saas'); ?></strong>
                                    <p class="description"><?php _e('Users get 14-day full access WITHOUT subscribing. Subscription required after trial expires.', 'stripe-saas'); ?></p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                <tr id="trial-mode-row" style="<?php echo ($global_settings['access_mode'] ?? 'subscribe_first') !== 'free_first' ? 'display:none;' : ''; ?>">
                    <th><?php _e('Trial Mode', 'stripe-saas'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="stripe_saas_global_settings[trial_mode]" value="no_cc" 
                                    <?php checked($global_settings['trial_mode'] ?? 'no_cc', 'no_cc'); ?>>
                                <strong><?php _e('Trial without Credit Card', 'stripe-saas'); ?></strong>
                                <p class="description"><?php _e('Users get immediate trial access after registration. No Stripe interaction until trial expires. Trial runs locally in WordPress.', 'stripe-saas'); ?></p>
                            </label>
                            <br><br>
                            <label>
                                <input type="radio" name="stripe_saas_global_settings[trial_mode]" value="require_cc" 
                                    <?php checked($global_settings['trial_mode'] ?? 'no_cc', 'require_cc'); ?>>
                                <strong><?php _e('Trial with Credit Card required', 'stripe-saas'); ?></strong>
                                <p class="description"><?php _e('Users are redirected to Stripe Checkout after registration. Credit card required upfront. Stripe manages trial and auto-charges when trial ends.', 'stripe-saas'); ?></p>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                    <tr>
                        <th><?php _e('Free Trial Duration', 'stripe-saas'); ?></th>
                        <td>
                            <input type="number" name="stripe_saas_global_settings[trial_days]" 
                                value="<?php echo esc_attr($global_settings['trial_days']); ?>" 
                                min="0" max="365">
                            <p class="description"><?php _e('Days of free trial. Used for local trial (no CC mode) and as default Stripe trial period (CC mode).', 'stripe-saas'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Tabbed Plans Section -->
                <hr>
                <div class="stripe-saas-section-header">
                    <h2><?php _e('Subscription Plans', 'stripe-saas'); ?></h2>
                    <button type="button" id="stripe-saas-reset-plans" class="button">
                        <?php _e('Load Default Plans (A1-D1)', 'stripe-saas'); ?>
                    </button>
                </div>
                
                <div class="stripe-saas-tabs">
                    <ul class="stripe-saas-tabs-nav">
                        <li><button type="button" class="tab-btn active" data-tab="monthly"><?php _e('Monthly', 'stripe-saas'); ?></button></li>
                        <li><button type="button" class="tab-btn" data-tab="yearly"><?php _e('Yearly', 'stripe-saas'); ?></button></li>
                        <li><button type="button" class="tab-btn" data-tab="three-year"><?php _e('3-Year', 'stripe-saas'); ?></button></li>
                        <li><button type="button" class="tab-btn" data-tab="enterprise"><?php _e('Enterprise', 'stripe-saas'); ?></button></li>
                        <li><button type="button" class="tab-btn" data-tab="webhooks"><?php _e('Webhooks', 'stripe-saas'); ?></button></li>
                        <li><button type="button" class="tab-btn" data-tab="shortcodes"><?php _e('Shortcodes', 'stripe-saas'); ?></button></li>
                    </ul>

                    <!-- Monthly Tab -->
                    <div class="stripe-saas-tab-content active" id="tab-monthly">
                        <?php if (empty($monthly_plans)): ?>
                            <p><?php _e('No monthly plans configured. Click "Load Default Plans" to add them.', 'stripe-saas'); ?></p>
                        <?php else: ?>
                            <?php foreach ($monthly_plans as $tier => $plan): $this->render_plan_form($tier, $plan); endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Yearly Tab -->
                    <div class="stripe-saas-tab-content" id="tab-yearly">
                        <?php if (empty($yearly_plans)): ?>
                            <p><?php _e('No yearly plans configured. Click "Load Default Plans" to add them.', 'stripe-saas'); ?></p>
                        <?php else: ?>
                            <?php foreach ($yearly_plans as $tier => $plan): $this->render_plan_form($tier, $plan); endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- 3-Year Tab -->
                    <div class="stripe-saas-tab-content" id="tab-three-year">
                        <?php if (empty($three_year_plans)): ?>
                            <p><?php _e('No 3-year plans configured. Click "Load Default Plans" to add them.', 'stripe-saas'); ?></p>
                        <?php else: ?>
                            <?php foreach ($three_year_plans as $tier => $plan): $this->render_plan_form($tier, $plan); endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Enterprise Tab -->
                    <div class="stripe-saas-tab-content" id="tab-enterprise">
                        <?php if (empty($enterprise_plans)): ?>
                            <p><?php _e('No enterprise plans configured. Click "Load Default Plans" to add them.', 'stripe-saas'); ?></p>
                        <?php else: ?>
                            <?php foreach ($enterprise_plans as $tier => $plan): $this->render_plan_form($tier, $plan); endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Webhooks Tab -->
                    <div class="stripe-saas-tab-content" id="tab-webhooks">
                        <div class="stripe-saas-info tab-style">
                            <h3><?php _e('Webhook Configuration', 'stripe-saas'); ?></h3>
                            <p><?php _e('Configure this URL in your Stripe Dashboard:', 'stripe-saas'); ?></p>
                            <code><?php echo esc_html(rest_url('stripe-saas/v1/webhook')); ?></code>
                            <p style="margin-top: 20px;"><strong><?php _e('Required Events:', 'stripe-saas'); ?></strong></p>
                            <ul>
                                <li>checkout.session.completed</li>
                                <li>checkout.session.expired</li>
                                <li>invoice.created</li>
                                <li>invoice.finalized</li>
                                <li>invoice.paid</li>
                                <li>invoice.payment_failed</li>
                                <li>customer.subscription.created</li>
                                <li>customer.subscription.updated</li>
                                <li>customer.subscription.deleted</li>
                                <li>customer.subscription.trial_will_end</li>
                                <li>payment_intent.succeeded</li>
                                <li>payment_intent.payment_failed</li>
                                <li>payment_method.attached</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Shortcodes Tab -->
                    <div class="stripe-saas-tab-content" id="tab-shortcodes">
                        <div class="stripe-saas-info tab-style">
                            <h3><?php _e('Shortcode Usage', 'stripe-saas'); ?></h3>
                            <p><strong><?php _e('Display all plans on any page:', 'stripe-saas'); ?></strong></p>
                            <code>[choose_plan]</code>
                            <p style="margin-top: 20px;"><strong><?php _e('Display a specific plan:', 'stripe-saas'); ?></strong></p>
                            <code>[stripe_saas_subscribe tier="a1_monthly_individual" button_text="Subscribe Now"]</code>
                        </div>
                    </div>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render a single plan configuration form
     */
    private function render_plan_form($tier, $plan)
    {
        ?>
        <div class="stripe-saas-plan">
            <h3><?php echo esc_html($plan['display_name'] ?: ucfirst($tier)); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php _e('Enabled', 'stripe-saas'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][enabled]"
                                value="1" <?php checked($plan['enabled'], true); ?>>
                            <?php _e('Enable this plan', 'stripe-saas'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Display Name', 'stripe-saas'); ?></th>
                    <td>
                        <input type="text" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][display_name]"
                            value="<?php echo esc_attr($plan['display_name']); ?>" class="regular-text">
                        <p class="description"><?php _e('Name shown to customers', 'stripe-saas'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Plan Type', 'stripe-saas'); ?></th>
                    <td>
                        <select name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][plan_type]">
                            <option value="subscription" <?php selected($plan['plan_type'], 'subscription'); ?>>
                                <?php _e('Subscription (recurring)', 'stripe-saas'); ?>
                            </option>
                            <option value="one_time" <?php selected($plan['plan_type'], 'one_time'); ?>>
                                <?php _e('One-Time Payment (lifetime)', 'stripe-saas'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Access Level', 'stripe-saas'); ?></th>
                    <td>
                        <select name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][access_level]">
                            <option value="individual" <?php selected($plan['access_level'], 'individual'); ?>>
                                <?php _e('Individual', 'stripe-saas'); ?>
                            </option>
                            <option value="group" <?php selected($plan['access_level'], 'group'); ?>>
                                <?php _e('Group', 'stripe-saas'); ?>
                            </option>
                            <option value="enterprise" <?php selected($plan['access_level'], 'enterprise'); ?>>
                                <?php _e('Enterprise', 'stripe-saas'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Price (cents)', 'stripe-saas'); ?></th>
                    <td>
                        <input type="number" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][price_cents]"
                            value="<?php echo esc_attr($plan['price_cents']); ?>" min="50" step="1">
                        <p class="description"><?php _e('Price in cents (e.g., 2900 = $29.00)', 'stripe-saas'); ?></p>
                    </td>
                </tr>
                <?php if ($plan['plan_type'] === 'subscription'): ?>
                    <tr>
                        <th><?php _e('Billing Interval', 'stripe-saas'); ?></th>
                        <td>
                            <select name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][interval]">
                                <option value="day" <?php selected($plan['interval'], 'day'); ?>><?php _e('Day', 'stripe-saas'); ?></option>
                                <option value="week" <?php selected($plan['interval'], 'week'); ?>><?php _e('Week', 'stripe-saas'); ?></option>
                                <option value="month" <?php selected($plan['interval'], 'month'); ?>><?php _e('Month', 'stripe-saas'); ?></option>
                                <option value="year" <?php selected($plan['interval'], 'year'); ?>><?php _e('Year', 'stripe-saas'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Interval Count', 'stripe-saas'); ?></th>
                        <td>
                            <input type="number" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][interval_count]"
                                value="<?php echo esc_attr($plan['interval_count']); ?>" min="1" max="12">
                            <p class="description"><?php _e('Charge every X intervals (e.g., 3 for every 3 years)', 'stripe-saas'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Trial Days', 'stripe-saas'); ?></th>
                        <td>
                            <input type="number" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][trial_days]"
                                value="<?php echo esc_attr($plan['trial_days']); ?>" min="0" max="365">
                            <p class="description"><?php _e('Number of free trial days (0 for no trial)', 'stripe-saas'); ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <input type="hidden" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][interval]" value="">
                    <input type="hidden" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][interval_count]" value="0">
                    <input type="hidden" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][trial_days]" value="0">
                <?php endif; ?>
                <tr>
                    <th><?php _e('WordPress Role', 'stripe-saas'); ?></th>
                    <td>
                        <input type="text" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][role]"
                            value="<?php echo esc_attr($plan['role']); ?>">
                        <p class="description"><?php _e('Role to assign (e.g., subscriber)', 'stripe-saas'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Access Meta Key', 'stripe-saas'); ?></th>
                    <td>
                        <input type="text" name="stripe_saas_plans[<?php echo esc_attr($tier); ?>][access_meta_key]"
                            value="<?php echo esc_attr($plan['access_meta_key']); ?>">
                        <p class="description"><?php _e('User meta key for access check', 'stripe-saas'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'settings_page_stripe-saas') {
            return;
        }

        wp_enqueue_style(
            'stripe-saas-admin',
            STRIPE_SAAS_URL . 'assets/css/admin.css',
            [],
            STRIPE_SAAS_VERSION
        );
        
        // Inline script for reset button
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('#stripe-saas-reset-plans').on('click', function() {
                    if (!confirm('This will reset all plans to the default A1-D1 structure. Continue?')) {
                        return;
                    }
                    
                    var button = $(this);
                    button.prop('disabled', true).text('Resetting...');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'stripe_saas_reset_plans',
                            nonce: '" . wp_create_nonce('stripe_saas_admin_nonce') . "'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Plans reset successfully. Page will reload.');
                                location.reload();
                            } else {
                                alert('Error: ' + response.data.message);
                                button.prop('disabled', false).text('Load Default Plans (A1-D1)');
                            }
                        },
                        error: function() {
                            alert('Request failed. Please try again.');
                            button.prop('disabled', false).text('Load Default Plans (A1-D1)');
                        }
                    });
                });
            });
        ");
        
        // Inline script for tab switching
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('.tab-btn').on('click', function() {
                    var tab = $(this).data('tab');
                    
                    // Remove active class from all tabs and content
                    $('.tab-btn').removeClass('active');
                    $('.stripe-saas-tab-content').removeClass('active');
                    
                    // Add active class to clicked tab and corresponding content
                    $(this).addClass('active');
                    $('#tab-' + tab).addClass('active');
                });

                // Toggle Trial Mode row based on Access Mode
                $('input[name=\"stripe_saas_global_settings[access_mode]\"]').on('change', function() {
                    if ($(this).val() === 'free_first') {
                        $('#trial-mode-row').slideDown(200);
                    } else {
                        $('#trial-mode-row').slideUp(200);
                    }
                });
            });
        ");
    }
}
