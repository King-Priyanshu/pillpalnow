<?php
/**
 * Plugin Name: Stripe SaaS
 * Plugin URI: https://example.com/stripe-saas
 * Description: Self-contained Stripe Hosted Checkout subscription system with webhook-driven access control
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stripe-saas
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('STRIPE_SAAS_VERSION', '1.0.2');
define('STRIPE_SAAS_PATH', plugin_dir_path(__FILE__));
define('STRIPE_SAAS_URL', plugin_dir_url(__FILE__));
define('STRIPE_SAAS_BASENAME', plugin_basename(__FILE__));

// API Keys (must be defined in wp-config.php)
define('STRIPE_SAAS_SECRET_KEY', defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
define('STRIPE_SAAS_WEBHOOK_SECRET', defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '');

// Load Composer autoloader
if (file_exists(STRIPE_SAAS_PATH . 'vendor/autoload.php')) {
    require_once STRIPE_SAAS_PATH . 'vendor/autoload.php';
}

// Load core class
require_once STRIPE_SAAS_PATH . 'includes/class-stripe-saas-core.php';

// Initialize plugin
function stripe_saas_init()
{
    Stripe_SaaS_Core::instance();

    // Initialize access control middleware
    if (class_exists('Stripe_SaaS_Access')) {
        Stripe_SaaS_Access::init();
    }
}
add_action('plugins_loaded', 'stripe_saas_init');

// Activation hook
register_activation_hook(__FILE__, function () {
    // Flush rewrite rules for REST API endpoints
    flush_rewrite_rules();

    // Set default global settings
    if (!get_option('stripe_saas_global_settings')) {
        $global_defaults = [
            'access_mode' => 'subscribe_first', // subscribe_first | free_first
            'trial_days' => 14
        ];
        add_option('stripe_saas_global_settings', $global_defaults);
    }

    // Set default options if not exists
    if (!get_option('stripe_saas_plans')) {
        $defaults = [
            'a1_monthly_individual' => [
                'enabled' => true,
                'display_name' => 'Monthly - Individual',
                'plan_type' => 'subscription',
                'access_level' => 'individual',
                'price_cents' => 2900, // $29.00
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
                'price_cents' => 2320, // $23.20
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
                'price_cents' => 29500, // $295.00
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
                'price_cents' => 23600, // $236.00
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
                'price_cents' => 83500, // $835.00
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
                'price_cents' => 66800, // $668.00
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
                'price_cents' => 500000, // $5000.00 (customizable)
                'interval' => '',
                'interval_count' => 0,
                'trial_days' => 0,
                'role' => 'subscriber',
                'access_meta_key' => 'has_enterprise_access'
            ]
        ];
        add_option('stripe_saas_plans', $defaults);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
