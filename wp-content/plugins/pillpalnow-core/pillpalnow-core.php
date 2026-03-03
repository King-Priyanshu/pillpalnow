<?php
/**
 * Plugin Name: PillPalNow Core
 * Description: Core medical logic, CPTs, and API handling for the PillPalNow system.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: pillpalnow
 */

if (!defined('ABSPATH')) {
    exit;
}

// Block Admin Access for Subscribers (Family Members)
add_action('init', function () {
    if (is_user_logged_in() && !current_user_can('edit_posts')) {
        show_admin_bar(false);
    }
});

// Enqueue Stripe Scripts
add_action('wp_enqueue_scripts', function () {
    if (defined('PILLPALNOW_STRIPE_PUBLISHABLE_KEY')) {
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_enqueue_script('pillpalnow-stripe', plugin_dir_url(__FILE__) . 'assets/js/pillpalnow-stripe.js', ['stripe-js', 'jquery'], '1.0.0', true);

        wp_localize_script('pillpalnow-stripe', 'pillpalnowStripeData', [
            'publishableKey' => PILLPALNOW_STRIPE_PUBLISHABLE_KEY,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pillpalnow_stripe_nonce'),
            'restUrl' => esc_url_raw(rest_url('pillpalnow/v1/')),
            'restNonce' => wp_create_nonce('wp_rest')
        ]);
    }
});

// Block Admin Access for Subscribers (Family Members)
add_action('admin_init', function () {
    // Allow admin-post.php
    if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) == 'admin-post.php') {
        return;
    }

    if (is_user_logged_in() && !current_user_can('edit_posts') && !defined('DOING_AJAX')) {
        error_log("[PILLPALNOW_SECURITY] Blocking admin access for " . get_current_user_id());
        wp_redirect(home_url('/dashboard'));
        exit;
    }
});

// --- Constants ---
if (!defined('PILLPALNOW_VERSION'))
    define('PILLPALNOW_VERSION', '2.0.0');
if (!defined('PILLPALNOW_PLUGIN_PATH'))
    define('PILLPALNOW_PLUGIN_PATH', plugin_dir_path(__FILE__));
if (!defined('PILLPALNOW_PLUGIN_URL'))
    define('PILLPALNOW_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('PILLPALNOW_CACHE_TTL_STOCK'))
    define('PILLPALNOW_CACHE_TTL_STOCK', 3600);
if (!defined('PILLPALNOW_REMINDER_WINDOW'))
    define('PILLPALNOW_REMINDER_WINDOW', 900); // 15 mins
if (!defined('PILLPALNOW_TRANSIENT_TTL'))
    define('PILLPALNOW_TRANSIENT_TTL', 1800); // 30 mins

// Load OneSignal credentials from database settings instead of hardcoded values
// This ensures the credentials configured in the admin panel are used
$pillpalnow_settings = get_option('pillpalnow_notification_settings', array());

if (!defined('ONESIGNAL_APP_ID')) {
    $raw_app_id = isset($pillpalnow_settings['onesignal_app_id']) ? $pillpalnow_settings['onesignal_app_id'] : '';
    // Aggressive sanitization: Keep only hex and dashes
    $sub_app_id = preg_replace('/[^a-f0-9\-]/i', '', $raw_app_id);

    if ($sub_app_id && $sub_app_id !== 'YOUR_APP_ID_HERE' && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $sub_app_id)) {
        define('ONESIGNAL_APP_ID', $sub_app_id);
    }
}

if (!defined('ONESIGNAL_REST_KEY')) {
    $raw_rest_key = isset($pillpalnow_settings['onesignal_api_key']) ? $pillpalnow_settings['onesignal_api_key'] : '';
    // Aggressive sanitization: Keep only hex, dashes, and potentially base64 chars if key format changes, but typically it's alphanumeric/base64.
    // OneSignal REST keys are typically 48 chars alphanumeric. Let's allowing a-z0-9 and dashes/underscores just in case.
    $sub_rest_key = preg_replace('/[^a-zA-Z0-9\-_]/', '', $raw_rest_key);

    if ($sub_rest_key && $sub_rest_key !== 'YOUR_REST_KEY_HERE') {
        define('ONESIGNAL_REST_KEY', $sub_rest_key);
    }
}

if (!defined('FLUENTCRM_WEBHOOK_URL')) {
    // define('FLUENTCRM_WEBHOOK_URL', 'YOUR_WEBHOOK_URL_HERE'); // REMOVED: Insecure default
}

// --- Includes ---
if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
} else {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>PillPalNow Core Error:</strong> Composer dependencies are missing. Please run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return; // Stop execution to prevent fatal errors
}

$validator_file = plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-data-validator.php';
if (file_exists($validator_file)) {
    require_once $validator_file;
}
require_once plugin_dir_path(__FILE__) . 'includes/setup.php';
require_once plugin_dir_path(__FILE__) . 'includes/cpt-registry.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-magic-login.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-auth-flow.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-permissions.php'; // Load Permissions
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-action-logger.php'; // Load Action Logger
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-form-handlers.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-notifications.php'; // Load Notifications before API endpoints
require_once plugin_dir_path(__FILE__) . 'includes/api-endpoints.php';
require_once plugin_dir_path(__FILE__) . 'includes/cron-jobs.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-refill-alerts.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-refill-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-auto-refill.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-notification-tester.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-onesignal-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-email-service.php'; // Email Service Wrapper
require_once plugin_dir_path(__FILE__) . 'includes/class-subscription-manager.php'; // Subscription Manager
require_once plugin_dir_path(__FILE__) . 'includes/class-pro-middleware.php'; // Pro Middleware
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-pdf-reports.php'; // PDF Reports
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-meta-registry.php'; // Load Meta Registry
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-medication-metabox.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-system-status.php'; // System Status & Diagnostics
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-notification-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/redirects.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-webview-auth.php'; // WebView compatibility
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-family-share.php'; // Family Share / Email History
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-stripe-webhook-handler.php'; // Stripe Webhook Handler
require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-stripe-integration.php'; // Stripe SaaS Integration

// --- Email Template Engine & Subscription Management (v2.0) ---
require_once plugin_dir_path(__FILE__) . 'includes/subscription/class-pillpalnow-secure-token.php'; // Secure Token System
require_once plugin_dir_path(__FILE__) . 'includes/emails/class-pillpalnow-email-manager.php'; // Email Manager (loads base + all email classes)
require_once plugin_dir_path(__FILE__) . 'includes/subscription/class-pillpalnow-subscription-dashboard.php'; // Subscription Dashboard

// Initialize
add_action('plugins_loaded', function () {
    Subscription_Manager::init();
    PillPalNow_PDF_Reports::init();
    PillPalNow_Meta_Registry::init(); // Initialize Meta Registry
    PillPalNow_Stripe_Integration::instance(); // Initialize Stripe Integration

    // Initialize Email Manager & Subscription Dashboard (v2.0)
    PillPalNow_Email_Manager::instance();
    PillPalNow_Subscription_Dashboard::instance();
    
    // Initialize Notifications System
    PillPalNow_Notifications::init();
});

// Flush rewrite rules when plugin is activated or settings are saved
function pillpalnow_flush_rewrite_rules_on_update() {
    // Add service worker rewrite rules if not already present
    add_rewrite_rule('^OneSignalSDKWorker\.js', 'index.php?pillpalnow_sw=1', 'top');
    add_rewrite_rule('^OneSignalSDK\.sw\.js', 'index.php?pillpalnow_sw=1', 'top');
    add_rewrite_tag('%pillpalnow_sw%', '([^&]+)');
    flush_rewrite_rules();
}

// Handle service worker request
add_action('parse_request', function($query) {
    if (isset($query->query_vars['pillpalnow_sw']) && $query->query_vars['pillpalnow_sw'] === '1') {
        // Serve the OneSignal SDK worker
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Service-Worker-Allowed: /');
        
        $root_file_path = ABSPATH . 'OneSignalSDKWorker.js';
        if (file_exists($root_file_path)) {
            readfile($root_file_path);
        } else {
            echo "importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');";
        }
        exit;
    }
});

// Activation hook - call flush on activation
register_activation_hook(__FILE__, 'pillpalnow_flush_rewrite_rules_on_update');

// DEBUG BANNER REMOVED



// --- Admin Backend Enhancements ---
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-admin-columns.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-pillpalnow-meta-box-manager.php';
}

/**
 * Get Remaining Stock Dynamically
 * Formula: Base Quantity (set at refill) - Sum(Taken Doses since Refill Date)
 */
function pillpalnow_get_remaining_stock($med_id)
{
    // Check Cache
    $cached = wp_cache_get('pillpalnow_stock_' . $med_id, 'pillpalnow');
    if (false !== $cached) {
        return (float) $cached;
    }

    $base_qty = get_post_meta($med_id, '_refill_base_qty', true);
    $refill_date = get_post_meta($med_id, '_refill_date', true);

    // Fallback for legacy (before migration)
    if ($base_qty === '' && $refill_date === '') {
        return (float) get_post_meta($med_id, 'stock_quantity', true);
    }

    $base_qty = (float) $base_qty;
    if (!$refill_date)
        $refill_date = '2020-01-01'; // Ancient history

    // Optimized Stock Calculation using SQL Aggregation
    global $wpdb;

    // 1. Get Sum of logs with 'dosage_snapshot'
    // We strictly filter by integer/float values to avoid SQL errors
    $sql_snapshot = $wpdb->prepare(
        "SELECT SUM(pm_dosage.meta_value) 
         FROM {$wpdb->postmeta} pm_med
         JOIN {$wpdb->postmeta} pm_status ON pm_med.post_id = pm_status.post_id
         JOIN {$wpdb->postmeta} pm_date ON pm_med.post_id = pm_date.post_id
         JOIN {$wpdb->postmeta} pm_dosage ON pm_med.post_id = pm_dosage.post_id
         WHERE pm_med.meta_key = 'medication_id' AND pm_med.meta_value = %d
         AND pm_status.meta_key = 'status' AND pm_status.meta_value = 'taken'
         AND pm_date.meta_key = 'log_date' AND pm_date.meta_value >= %s
         AND pm_dosage.meta_key = 'dosage_snapshot' AND pm_dosage.meta_value > 0",
        $med_id,
        $refill_date
    );
    $total_snapshot = (float) $wpdb->get_var($sql_snapshot);

    // 2. Get Count of logs WITHOUT 'dosage_snapshot' (Legacy fallback)
    // We substract logs we already counted above
    $sql_legacy_count = $wpdb->prepare(
        "SELECT COUNT(pm_med.post_id) 
         FROM {$wpdb->postmeta} pm_med
         JOIN {$wpdb->postmeta} pm_status ON pm_med.post_id = pm_status.post_id
         JOIN {$wpdb->postmeta} pm_date ON pm_med.post_id = pm_date.post_id
         LEFT JOIN {$wpdb->postmeta} pm_dosage ON (pm_med.post_id = pm_dosage.post_id AND pm_dosage.meta_key = 'dosage_snapshot')
         WHERE pm_med.meta_key = 'medication_id' AND pm_med.meta_value = %d
         AND pm_status.meta_key = 'status' AND pm_status.meta_value = 'taken'
         AND pm_date.meta_key = 'log_date' AND pm_date.meta_value >= %s
         AND pm_dosage.post_id IS NULL",
        $med_id,
        $refill_date
    );
    $legacy_count = (int) $wpdb->get_var($sql_legacy_count);

    // Default dosage
    $med_dosage_default = 1;
    $dose_times = get_post_meta($med_id, 'dose_times', true);
    if (!empty($dose_times) && is_array($dose_times)) {
        if (isset($dose_times[0]['dosage']))
            $med_dosage_default = floatval($dose_times[0]['dosage']);
    }

    $total_taken = $total_snapshot + ($legacy_count * $med_dosage_default);

    $current = $base_qty - $total_taken;
    $final_stock = max(0, $current);

    wp_cache_set('pillpalnow_stock_' . $med_id, $final_stock, 'pillpalnow', PILLPALNOW_CACHE_TTL_STOCK);

    return $final_stock;

}

// Invalidate cache when dose logs change
add_action('save_post_dose_log', function ($post_id) {
    // Only invalidation if medication_id is present
    // Note: get_post_meta might be empty during initial save if meta added later, 
    // but typically update_post_meta triggers updated_post_meta hook.
    // wp_insert_post triggers save_post, then metas are added.
    // So better to hook to updated_post_meta for medication_id? 
    // Or just clear if we can find it.
    // For simplicity, let's also hook to 'updated_postmeta' logic if needed, 
    // but the Reminder Action updates meta immediately after insert.
    $med_id = get_post_meta($post_id, 'medication_id', true);
    if ($med_id) {
        wp_cache_delete('pillpalnow_stock_' . $med_id, 'pillpalnow');
    }
});
add_action('updated_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key === 'medication_id' && get_post_type($post_id) === 'dose_log') {
        wp_cache_delete('pillpalnow_stock_' . $meta_value, 'pillpalnow');
    }
    // New: Invalidate when stock-related medication meta changes
    if (in_array($meta_key, ['_refill_base_qty', '_refill_date', 'stock_quantity']) && get_post_type($post_id) === 'medication') {
        wp_cache_delete('pillpalnow_stock_' . $post_id, 'pillpalnow');
    }
}, 10, 4);

// Also handle additions (first-time setting)
add_action('added_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if (in_array($meta_key, ['_refill_base_qty', '_refill_date', 'stock_quantity']) && get_post_type($post_id) === 'medication') {
        wp_cache_delete('pillpalnow_stock_' . $post_id, 'pillpalnow');
    }
}, 10, 4);


/**
 * Recalculate and Cache Stock
 * Updates the legacy 'stock_quantity' field to match the dynamic calculation.
 * Use this to ensure simple queries (sorting/filtering by stock) still work.
 */
function pillpalnow_recalculate_stock($med_id)
{
    $stock = pillpalnow_get_remaining_stock($med_id);
    update_post_meta($med_id, 'stock_quantity', $stock);
    return $stock;
}

/**
 * Clean up related dose_log and reminder_log entries when a medication is deleted.
 * This prevents orphaned logs from showing in History after medication deletion.
 */
add_action('before_delete_post', 'pillpalnow_cleanup_medication_logs');
function pillpalnow_cleanup_medication_logs($post_id)
{
    if (get_post_type($post_id) !== 'medication') {
        return;
    }

    // Delete all dose logs for this medication
    $dose_logs = get_posts([
        'post_type' => 'dose_log',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'medication_id', 'value' => $post_id]
        ],
        'fields' => 'ids'
    ]);

    foreach ($dose_logs as $log_id) {
        wp_delete_post($log_id, true);
    }

    // Delete all reminder logs for this medication
    $reminder_logs = get_posts([
        'post_type' => 'reminder_log',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'medication_id', 'value' => $post_id]
        ],
        'fields' => 'ids'
    ]);

    foreach ($reminder_logs as $rem_id) {
        wp_delete_post($rem_id, true);
    }

    // Delete all refill requests for this medication
    $refill_requests = get_posts([
        'post_type' => 'refill_request',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'medication_id', 'value' => $post_id]
        ],
        'fields' => 'ids'
    ]);

    foreach ($refill_requests as $req_id) {
        wp_delete_post($req_id, true);
    }

    // Delete all notifications for this medication
    $notifications = get_posts([
        'post_type' => 'notification',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'medication_id', 'value' => $post_id]
        ],
        'fields' => 'ids'
    ]);

    foreach ($notifications as $notif_id) {
        wp_delete_post($notif_id, true);
    }

    // Clear the stock cache
    wp_cache_delete('pillpalnow_stock_' . $post_id, 'pillpalnow');

    error_log("[PILLPALNOW] Cleaned up logs, requests, and notifications for deleted medication ID: $post_id");
}

/**
 * Admin action to clean up orphaned dose logs (referencing deleted medications).
 * Can be triggered via: /wp-admin/admin-post.php?action=pillpalnow_clean_orphans
 */
add_action('admin_post_pillpalnow_clean_orphans', 'pillpalnow_clean_orphaned_logs');
function pillpalnow_clean_orphaned_logs()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Checking Dose Logs
    $dose_logs = get_posts([
        'post_type' => 'dose_log',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    $cleaned = 0;
    foreach ($dose_logs as $log_id) {
        $med_id = get_post_meta($log_id, 'medication_id', true);
        if ($med_id && !get_post($med_id)) {
            wp_delete_post($log_id, true);
            $cleaned++;
        }
    }

    // Checking Reminder Logs
    $reminder_logs = get_posts([
        'post_type' => 'reminder_log',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    foreach ($reminder_logs as $rem_id) {
        $med_id = get_post_meta($rem_id, 'medication_id', true);
        if ($med_id && !get_post($med_id)) {
            wp_delete_post($rem_id, true);
            $cleaned++;
        }
    }

    // Checking Refill Requests
    $refill_requests = get_posts([
        'post_type' => 'refill_request',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    foreach ($refill_requests as $req_id) {
        $med_id = get_post_meta($req_id, 'medication_id', true);
        if ($med_id && !get_post($med_id)) {
            wp_delete_post($req_id, true);
            $cleaned++;
        }
    }

    // Checking Notifications
    $notifications = get_posts([
        'post_type' => 'notification',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    foreach ($notifications as $notif_id) {
        $med_id = get_post_meta($notif_id, 'medication_id', true);
        if ($med_id && !get_post($med_id)) {
            wp_delete_post($notif_id, true);
            $cleaned++;
        }
    }

    error_log("[PILLPALNOW] Cleaned $cleaned orphaned logs, requests, and notifications");

    wp_redirect(admin_url('options-general.php?page=pillpalnow-settings&orphans_cleaned=' . $cleaned));
    exit;
}

/**
 * Calculate Next Dose Time (Wrapper Function)
 * Provides backwards compatibility for themes calling this as a standalone function.
 * Delegates to PillPalNow_Form_Handlers::calculate_next_dose_time()
 */
function pillpalnow_calculate_next_dose_time($med_id)
{
    if (class_exists('PillPalNow_Form_Handlers') && method_exists('PillPalNow_Form_Handlers', 'calculate_next_dose_time')) {
        return PillPalNow_Form_Handlers::calculate_next_dose_time($med_id);
    }
    return false;
}

/**
 * Clean up all user data when a user is deleted from WordPress.
 * This ensures no orphaned medications, family members, or logs remain.
 */
add_action('delete_user', 'pillpalnow_cleanup_user_data');
function pillpalnow_cleanup_user_data($user_id)
{
    // 1. Delete all Medications (This triggers pillpalnow_cleanup_medication_logs for each)
    $medications = get_posts([
        'post_type' => 'medication',
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    foreach ($medications as $med_id) {
        wp_delete_post($med_id, true); // Force delete
    }

    // 2. Delete Family Members
    $family_members = get_posts([
        'post_type' => 'family_member',
        'author' => $user_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);

    foreach ($family_members as $fam_id) {
        wp_delete_post($fam_id, true);
    }

    // 3. Cleanup any remaining orphaned logs (just in case they weren't linked to a medication)
    $post_types = ['dose_log', 'refill_request', 'reminder_log', 'notification'];
    foreach ($post_types as $pt) {
        $orphans = get_posts([
            'post_type' => $pt,
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        foreach ($orphans as $orphan_id) {
            wp_delete_post($orphan_id, true);
        }
    }

    error_log("[PILLPALNOW] Cleaned up all data for deleted user ID: $user_id");
}
