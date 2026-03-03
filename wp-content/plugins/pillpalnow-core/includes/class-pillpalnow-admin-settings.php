<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Admin Settings
 *
 * Handles admin settings for notification controls and email selection.
 *
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Admin_Settings
{
    /**
     * Option name for storing settings
     */
    const OPTION_NAME = 'pillpalnow_notification_settings';

    /**
     * Default settings
     */
    private static $defaults = array(
        'enable_reminders' => '1',
        'enable_refill_alerts' => '1',
        'enable_admin_refill_alerts' => '0', // Send refill alerts to admin
        'enable_missed_notifications' => '1',
        'enable_postponed_notifications' => '1',
        'email_provider' => 'onesignal', // onesignal, pillpalnow, both
        'notification_priority' => 'normal', // low, normal, high
        'enable_logging' => '1',
        'log_retention_days' => '30',
        'enable_custom_service_worker' => '0',
        'custom_service_worker_file' => '',
        // OneSignal Configuration
        'onesignal_app_id' => '',
        'onesignal_api_key' => '',
        'onesignal_user_auth_key' => '',
        // Debug Settings
        'debug_mode' => '0',
        'log_level' => 'error', // error, warning, info, debug
        // Family Settings
        'enable_proxy_logging' => '1', // Allow parents to log doses for family members
        'enable_auto_create_accounts' => '1', // Auto-create WP accounts for new family members
        // Family Permissions
        'pillpalnow_allow_add' => '0',
        'pillpalnow_allow_edit' => '0',
        'pillpalnow_allow_delete' => '0',
        'pillpalnow_allow_history' => '1',
        'pillpalnow_allow_refill_logs' => '1',
        'pillpalnow_allow_notifications' => '1',
        // Stripe Subscription Notifications
        'stripe_sub_expiring_email' => '1',
        'stripe_sub_expiring_push' => '1',
        'stripe_payment_failed_email' => '1',
        'stripe_payment_failed_push' => '1',
        'stripe_sub_cancelled_email' => '1',
        'stripe_sub_cancelled_push' => '1',
    );

    /**
     * Initialize the admin settings
     */
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('admin_post_upload_service_worker', array(__CLASS__, 'handle_service_worker_upload'));
        add_action('admin_post_pillpalnow_save_family_permissions', array('PillPalNow_Form_Handlers', 'handle_save_family_permissions')); // NEW
        add_action('init', array(__CLASS__, 'add_service_worker_rewrite_rule'));
        add_action('template_redirect', array(__CLASS__, 'serve_service_worker'));
        add_action('update_option_' . self::OPTION_NAME, array(__CLASS__, 'flush_rewrite_rules_on_update'));

        // Frontend Scripts
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_scripts'));

        // AJAX handlers
        add_action('wp_ajax_pillpalnow_bulk_action', array(__CLASS__, 'ajax_handle_bulk_action'));
        add_action('wp_ajax_pillpalnow_test_send', array(__CLASS__, 'ajax_handle_test_send'));
        add_action('wp_ajax_pillpalnow_resend_single', array(__CLASS__, 'ajax_handle_resend_single'));
        add_action('wp_ajax_pillpalnow_upload_service_worker', array(__CLASS__, 'ajax_handle_file_upload'));
        add_action('wp_ajax_pillpalnow_save_settings', array(__CLASS__, 'ajax_handle_save_settings'));

        // New AJAX handlers for enhanced features
        add_action('wp_ajax_pillpalnow_test_onesignal', array(__CLASS__, 'ajax_test_onesignal_connection'));
        add_action('wp_ajax_pillpalnow_send_test_notification', array(__CLASS__, 'ajax_send_test_notification'));
        add_action('wp_ajax_pillpalnow_check_system_status', array(__CLASS__, 'ajax_check_system_status'));
        add_action('wp_ajax_pillpalnow_get_debug_info', array(__CLASS__, 'ajax_get_debug_info'));
        add_action('wp_ajax_pillpalnow_export_debug_report', array(__CLASS__, 'ajax_export_debug_report'));
        add_action('wp_ajax_pillpalnow_clear_error_logs', array(__CLASS__, 'ajax_clear_error_logs'));
        add_action('wp_ajax_pillpalnow_get_recent_logs', array(__CLASS__, 'ajax_get_recent_logs'));

        add_action('wp_ajax_store_onesignal_player_id', array(__CLASS__, 'ajax_store_onesignal_player_id'));
        add_action('wp_ajax_nopriv_store_onesignal_player_id', array(__CLASS__, 'ajax_store_onesignal_player_id'));

        // Hook admin refill alert filter based on setting
        add_filter('pillpalnow_send_admin_refill_alert', array(__CLASS__, 'filter_admin_refill_alert'), 10, 2);

        self::flush_rewrite_rules_on_update();
    }

    /**
     * Filter: Enable/disable admin refill alerts based on settings
     * 
     * @param bool $send_admin     Whether to send admin email
     * @param int  $medication_id  Medication ID
     * @return bool
     */
    public static function filter_admin_refill_alert($send_admin, $medication_id)
    {
        $settings = self::get_settings();
        return $settings['enable_admin_refill_alerts'] === '1';
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu()
    {
        add_menu_page(
            __('PillPalNow Dashboard', 'pillpalnow'),
            __('PillPalNow', 'pillpalnow'),
            'manage_options',
            'pillpalnow-dashboard',
            array(__CLASS__, 'dashboard_page'),
            'dashicons-bell',
            30
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Overview/Dashboard', 'pillpalnow'),
            __('Dashboard', 'pillpalnow'),
            'manage_options',
            'pillpalnow-dashboard',
            array(__CLASS__, 'dashboard_page')
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Medications', 'pillpalnow'),
            __('Medications', 'pillpalnow'),
            'manage_options',
            'edit.php?post_type=medication'
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Dose Logs', 'pillpalnow'),
            __('Dose Logs', 'pillpalnow'),
            'manage_options',
            'edit.php?post_type=dose_log'
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Refill Requests', 'pillpalnow'),
            __('Refill Requests', 'pillpalnow'),
            'manage_options',
            'edit.php?post_type=refill_request'
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Family Members', 'pillpalnow'),
            __('Family Members', 'pillpalnow'),
            'manage_options',
            'edit.php?post_type=family_member'
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Notifications', 'pillpalnow'),
            __('Notifications', 'pillpalnow'),
            'manage_options',
            'edit.php?post_type=notification'
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Notification Settings', 'pillpalnow'),
            __('Notification Settings', 'pillpalnow'),
            'read', // Allow Parents (read capability) to access
            'pillpalnow-settings',
            array(__CLASS__, 'settings_page')
        );

        add_submenu_page(
            'pillpalnow-dashboard',
            __('Notification Logs', 'pillpalnow'),
            __('Logs', 'pillpalnow'),
            'manage_options',
            'pillpalnow-notification-logs',
            array(__CLASS__, 'logs_page')
        );
    }

    /**
     * Register settings
     */
    public static function register_settings()
    {
        register_setting(
            'pillpalnow_settings',
            self::OPTION_NAME,
            array(__CLASS__, 'sanitize_settings')
        );

        add_settings_section(
            'pillpalnow_notification_section',
            __('Notification Settings', 'pillpalnow'),
            array(__CLASS__, 'notification_section_callback'),
            'pillpalnow_settings'
        );

        add_settings_field(
            'enable_reminders',
            __('Enable Reminders', 'pillpalnow'),
            array(__CLASS__, 'enable_reminders_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'enable_refill_alerts',
            __('Enable Refill Alerts', 'pillpalnow'),
            array(__CLASS__, 'enable_refill_alerts_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'enable_missed_notifications',
            __('Enable Missed Dose Notifications', 'pillpalnow'),
            array(__CLASS__, 'enable_missed_notifications_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'enable_postponed_notifications',
            __('Enable Postponed Notifications', 'pillpalnow'),
            array(__CLASS__, 'enable_postponed_notifications_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'email_provider',
            __('Email Provider', 'pillpalnow'),
            array(__CLASS__, 'email_provider_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'notification_priority',
            __('Notification Priority', 'pillpalnow'),
            array(__CLASS__, 'notification_priority_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'enable_logging',
            __('Enable Notification Logging', 'pillpalnow'),
            array(__CLASS__, 'enable_logging_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'log_retention_days',
            __('Log Retention (Days)', 'pillpalnow'),
            array(__CLASS__, 'log_retention_days_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'enable_custom_service_worker',
            __('Enable Custom Service Worker', 'pillpalnow'),
            array(__CLASS__, 'enable_custom_service_worker_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );

        add_settings_field(
            'custom_service_worker_file',
            __('Custom Service Worker File', 'pillpalnow'),
            array(__CLASS__, 'custom_service_worker_file_callback'),
            'pillpalnow_settings',
            'pillpalnow_notification_section'
        );
    }

    /**
     * Sanitize settings
     */
    public static function sanitize_settings($input)
    {
        $sanitized = array();

        $sanitized['enable_reminders'] = isset($input['enable_reminders']) ? '1' : '0';
        $sanitized['enable_refill_alerts'] = isset($input['enable_refill_alerts']) ? '1' : '0';
        $sanitized['enable_admin_refill_alerts'] = isset($input['enable_admin_refill_alerts']) ? '1' : '0';
        $sanitized['enable_missed_notifications'] = isset($input['enable_missed_notifications']) ? '1' : '0';
        $sanitized['enable_postponed_notifications'] = isset($input['enable_postponed_notifications']) ? '1' : '0';

        $allowed_providers = array('onesignal', 'pillpalnow', 'both');
        $sanitized['email_provider'] = in_array($input['email_provider'], $allowed_providers) ? $input['email_provider'] : 'onesignal';

        $allowed_priorities = array('low', 'normal', 'high');
        $sanitized['notification_priority'] = in_array($input['notification_priority'], $allowed_priorities) ? $input['notification_priority'] : 'normal';

        $sanitized['enable_logging'] = isset($input['enable_logging']) ? '1' : '0';
        $sanitized['log_retention_days'] = absint($input['log_retention_days']);
        if ($sanitized['log_retention_days'] < 1) {
            $sanitized['log_retention_days'] = 30;
        }

        $sanitized['enable_custom_service_worker'] = isset($input['enable_custom_service_worker']) ? '1' : '0';
        $sanitized['custom_service_worker_file'] = sanitize_text_field($input['custom_service_worker_file'] ?? '');

        // OneSignal Configuration
        // Aggressive sanitization (strip invisible characters, BOM, spaces)
        $raw_app_id = $input['onesignal_app_id'] ?? '';
        $sanitized['onesignal_app_id'] = preg_replace('/[^a-fA-F0-9\-]/', '', $raw_app_id);

        $raw_api_key = $input['onesignal_api_key'] ?? '';
        $sanitized['onesignal_api_key'] = preg_replace('/[^a-zA-Z0-9\-_]/', '', $raw_api_key);

        $sanitized['onesignal_user_auth_key'] = sanitize_text_field($input['onesignal_user_auth_key'] ?? '');

        // Debug Settings
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? '1' : '0';
        $allowed_log_levels = array('error', 'warning', 'info', 'debug');
        $sanitized['log_level'] = in_array($input['log_level'] ?? 'error', $allowed_log_levels) ? $input['log_level'] : 'error';

        // Family Settings
        $sanitized['enable_proxy_logging'] = isset($input['enable_proxy_logging']) ? '1' : '0';
        $sanitized['enable_auto_create_accounts'] = isset($input['enable_auto_create_accounts']) ? '1' : '0';

        // Family Permissions
        $sanitized['pillpalnow_allow_add'] = isset($input['pillpalnow_allow_add']) ? '1' : '0';
        $sanitized['pillpalnow_allow_edit'] = isset($input['pillpalnow_allow_edit']) ? '1' : '0';
        $sanitized['pillpalnow_allow_delete'] = isset($input['pillpalnow_allow_delete']) ? '1' : '0';
        $sanitized['pillpalnow_allow_history'] = isset($input['pillpalnow_allow_history']) ? '1' : '0';
        $sanitized['pillpalnow_allow_refill_logs'] = isset($input['pillpalnow_allow_refill_logs']) ? '1' : '0';
        $sanitized['pillpalnow_allow_notifications'] = isset($input['pillpalnow_allow_notifications']) ? '1' : '0';

        // Stripe Subscription Notifications
        $sanitized['stripe_sub_expiring_email'] = isset($input['stripe_sub_expiring_email']) ? '1' : '0';
        $sanitized['stripe_sub_expiring_push'] = isset($input['stripe_sub_expiring_push']) ? '1' : '0';
        $sanitized['stripe_payment_failed_email'] = isset($input['stripe_payment_failed_email']) ? '1' : '0';
        $sanitized['stripe_payment_failed_push'] = isset($input['stripe_payment_failed_push']) ? '1' : '0';
        $sanitized['stripe_sub_cancelled_email'] = isset($input['stripe_sub_cancelled_email']) ? '1' : '0';
        $sanitized['stripe_sub_cancelled_push'] = isset($input['stripe_sub_cancelled_push']) ? '1' : '0';

        return $sanitized;
    }

    /**
     * Get settings with defaults
     */
    public static function get_settings()
    {
        $settings = get_option(self::OPTION_NAME, array());
        return wp_parse_args($settings, self::$defaults);
    }

    /**
     * Settings page
     */
    /**
     * Settings page
     */
    public static function settings_page()
    {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Determine if Parent (Standard User) or Admin
        $is_admin = current_user_can('manage_options');
        $user = wp_get_current_user();
        $is_family_member = in_array('family_member', (array) $user->roles);

        // Family Members should strictly NOT be here (double check)
        if ($is_family_member) {
            wp_die(__('Family members cannot access settings.', 'pillpalnow'));
        }

        // Handle file upload if submitted (fallback for non-JS)
        if (isset($_FILES['custom_service_worker_file']) && !empty($_FILES['custom_service_worker_file']['name'])) {
            self::handle_service_worker_upload_direct();
        }

        // Get current settings
        $settings = self::get_settings();

        // Get system status (with safety check for missing class)
        $system_status = array(
            'overall_status' => 'unknown',
            'message' => __('System status unavailable', 'pillpalnow'),
            'issues' => array(),
            'warnings' => array()
        );
        if (class_exists('PillPalNow_System_Status') && $is_admin) {
            $system_status = PillPalNow_System_Status::get_status_summary();
        }

        // PARENT VIEW: Force tab to 'family_permissions'
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

        ?>
        <div class="wrap pillpalnow-admin-settings">
            <h1><?php _e('PillPalNow Settings', 'pillpalnow'); ?></h1>

            <!-- System Status Widget (Admin Only) -->
            <?php if ($is_admin): ?>
                <div class="pillpalnow-system-status-widget">
                    <div class="status-header">
                        <h3><?php _e('System Status', 'pillpalnow'); ?></h3>
                        <button type="button" class="button button-small" id="refresh-system-status">
                            <span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'pillpalnow'); ?>
                        </button>
                    </div>
                    <div class="status-indicator status-<?php echo esc_attr($system_status['overall_status']); ?>">
                        <span class="status-dot"></span>
                        <span class="status-text"><?php echo esc_html($system_status['message']); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper pillpalnow-tab-wrapper">
                <?php if ($is_admin): ?>
                    <a href="?page=pillpalnow-settings&tab=general"
                        class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>" data-tab="general">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php _e('General', 'pillpalnow'); ?>
                    </a>
                    <a href="?page=pillpalnow-settings&tab=onesignal"
                        class="nav-tab <?php echo $active_tab === 'onesignal' ? 'nav-tab-active' : ''; ?>" data-tab="onesignal">
                        <span class="dashicons dashicons-smartphone"></span>
                        <?php _e('OneSignal', 'pillpalnow'); ?>
                    </a>
                    <a href="?page=pillpalnow-settings&tab=subscriptions"
                        class="nav-tab <?php echo $active_tab === 'subscriptions' ? 'nav-tab-active' : ''; ?>"
                        data-tab="subscriptions">
                        <span class="dashicons dashicons-money"></span>
                        <?php _e('Subscriptions', 'pillpalnow'); ?>
                    </a>
                    <a href="?page=pillpalnow-settings&tab=testing"
                        class="nav-tab <?php echo $active_tab === 'testing' ? 'nav-tab-active' : ''; ?>" data-tab="testing">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php _e('Testing', 'pillpalnow'); ?>
                    </a>
                    <a href="?page=pillpalnow-settings&tab=debug"
                        class="nav-tab <?php echo $active_tab === 'debug' ? 'nav-tab-active' : ''; ?>" data-tab="debug">
                        <span class="dashicons dashicons-bug"></span>
                        <?php _e('Debug', 'pillpalnow'); ?>
                    </a>
                    <a href="?page=pillpalnow-settings&tab=advanced"
                        class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>" data-tab="advanced">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php _e('Advanced', 'pillpalnow'); ?>
                    </a>
                <?php endif; ?>


            </nav>

            <?php if ($is_admin && $active_tab !== 'family_permissions'): ?>
                <form method="post" action="options.php" enctype="multipart/form-data" id="pillpalnow-settings-form">
                    <?php
                    wp_nonce_field('pillpalnow_admin_ajax', '_wpnonce');
                    settings_fields('pillpalnow_settings');
                    ?>
                <?php endif; ?>

                <!-- General Tab -->
                <div class="pillpalnow-tab-content" id="tab-general"
                    style="display: <?php echo $active_tab === 'general' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('Notification Settings', 'pillpalnow'); ?></h2>
                    <p class="description">
                        <?php _e('Configure which types of notifications are enabled for your users.', 'pillpalnow'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Enable Reminders', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_reminders]" value="1"
                                        <?php checked('1', $settings['enable_reminders']); ?> />
                                    <?php _e('Send reminder notifications for scheduled doses', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Refill Alerts', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_refill_alerts]"
                                        value="1" <?php checked('1', $settings['enable_refill_alerts']); ?> />
                                    <?php _e('Send alerts when medication is running low', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Admin Refill Alerts', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_admin_refill_alerts]"
                                        value="1" <?php checked('1', $settings['enable_admin_refill_alerts']); ?> />
                                    <?php _e('Send a copy of refill alerts to site admin', 'pillpalnow'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, the site administrator will receive an email whenever a patient\'s medication runs low.', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Missed Notifications', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_missed_notifications]"
                                        value="1" <?php checked('1', $settings['enable_missed_notifications']); ?> />
                                    <?php _e('Send notifications for missed doses', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Postponed Notifications', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                        name="<?php echo self::OPTION_NAME; ?>[enable_postponed_notifications]" value="1" <?php checked('1', $settings['enable_postponed_notifications']); ?> />
                                    <?php _e('Send notifications when postponed reminders become due', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Email Provider', 'pillpalnow'); ?></th>
                            <td>
                                <select name="<?php echo self::OPTION_NAME; ?>[email_provider]">
                                    <option value="onesignal" <?php selected($settings['email_provider'], 'onesignal'); ?>>
                                        <?php _e('OneSignal Only', 'pillpalnow'); ?>
                                    </option>
                                    <option value="pillpalnow" <?php selected($settings['email_provider'], 'pillpalnow'); ?>>
                                        <?php _e('PillPalNow API Only', 'pillpalnow'); ?>
                                    </option>
                                    <option value="both" <?php selected($settings['email_provider'], 'both'); ?>>
                                        <?php _e('Both OneSignal and PillPalNow API', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Notification Priority', 'pillpalnow'); ?></th>
                            <td>
                                <select name="<?php echo self::OPTION_NAME; ?>[notification_priority]">
                                    <option value="low" <?php selected($settings['notification_priority'], 'low'); ?>>
                                        <?php _e('Low', 'pillpalnow'); ?>
                                    </option>
                                    <option value="normal" <?php selected($settings['notification_priority'], 'normal'); ?>>
                                        <?php _e('Normal', 'pillpalnow'); ?>
                                    </option>
                                    <option value="high" <?php selected($settings['notification_priority'], 'high'); ?>>
                                        <?php _e('High', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h2><?php _e('Family Settings', 'pillpalnow'); ?></h2>
                    <p class="description">
                        <?php _e('Configure family member management options.', 'pillpalnow'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Proxy Dose Logging', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_proxy_logging]"
                                        value="1" <?php checked('1', $settings['enable_proxy_logging']); ?> />
                                    <?php _e('Allow parents/caregivers to log doses on behalf of family members', 'pillpalnow'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, the logged dose will include an audit trail showing who marked it as taken.', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Auto-Create Accounts', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_auto_create_accounts]"
                                        value="1" <?php checked('1', $settings['enable_auto_create_accounts']); ?> />
                                    <?php _e('Automatically create WordPress accounts for new family members', 'pillpalnow'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('When adding a family member with an email that does not exist, a new WordPress account will be created with a random password. The member will receive a welcome email.', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>


                </div>

                <!-- OneSignal Tab -->
                <div class="pillpalnow-tab-content" id="tab-onesignal"
                    style="display: <?php echo $active_tab === 'onesignal' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('OneSignal Configuration', 'pillpalnow'); ?></h2>
                    <p class="description">
                        <?php _e('Configure your OneSignal app credentials for push notifications.', 'pillpalnow'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('App ID', 'pillpalnow'); ?></th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_NAME; ?>[onesignal_app_id]"
                                    value="<?php echo esc_attr($settings['onesignal_app_id']); ?>" class="regular-text"
                                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                <p class="description">
                                    <?php _e('Your OneSignal App ID from the dashboard', 'pillpalnow'); ?>
                                    <br><strong><?php _e('Where to find:', 'pillpalnow'); ?></strong>
                                    <?php _e('OneSignal Dashboard → Settings → Keys & IDs', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('REST API Key', 'pillpalnow'); ?></th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_NAME; ?>[onesignal_api_key]"
                                    value="<?php echo esc_attr($settings['onesignal_api_key']); ?>" class="regular-text"
                                    placeholder="REST API Key" />
                                <p class="description">
                                    <?php _e('Your OneSignal REST API Key', 'pillpalnow'); ?>
                                    <br><strong><?php _e('Where to find:', 'pillpalnow'); ?></strong>
                                    <?php _e('OneSignal Dashboard → Settings → Keys & IDs → REST API Key', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('User Auth Key (Optional)', 'pillpalnow'); ?></th>
                            <td>
                                <input type="text" name="<?php echo self::OPTION_NAME; ?>[onesignal_user_auth_key]"
                                    value="<?php echo esc_attr($settings['onesignal_user_auth_key']); ?>" class="regular-text"
                                    placeholder="User Auth Key" />
                                <p class="description"><?php _e('Optional user auth key for advanced features', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Connection Test', 'pillpalnow'); ?></th>
                            <td>
                                <button type="button" class="button" id="test-onesignal-connection">
                                    <span class="dashicons dashicons-admin-plugins"></span>
                                    <?php _e('Test Connection', 'pillpalnow'); ?>
                                </button>
                                <p class="description">
                                    <?php _e('Verify your OneSignal credentials are correct and the connection is working', 'pillpalnow'); ?>
                                </p>
                                <div id="onesignal-test-result" style="margin-top: 10px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Subscriptions Tab -->
                <div class="pillpalnow-tab-content" id="tab-subscriptions"
                    style="display: <?php echo $active_tab === 'subscriptions' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('Subscription Notification Settings', 'pillpalnow'); ?></h2>
                    <p class="description">
                        <?php _e('Configure notifications for subscription events (Stripe SaaS).', 'pillpalnow'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Subscription Expiring', 'pillpalnow'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo self::OPTION_NAME; ?>[stripe_sub_expiring_email]" value="1" <?php checked('1', $settings['stripe_sub_expiring_email']); ?> />
                                        <?php _e('Send Email', 'pillpalnow'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[stripe_sub_expiring_push]"
                                            value="1" <?php checked('1', $settings['stripe_sub_expiring_push']); ?> />
                                        <?php _e('Send Push Notification', 'pillpalnow'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Payment Failed', 'pillpalnow'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo self::OPTION_NAME; ?>[stripe_payment_failed_email]" value="1" <?php checked('1', $settings['stripe_payment_failed_email']); ?> />
                                        <?php _e('Send Email', 'pillpalnow'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo self::OPTION_NAME; ?>[stripe_payment_failed_push]" value="1" <?php checked('1', $settings['stripe_payment_failed_push']); ?> />
                                        <?php _e('Send Push Notification', 'pillpalnow'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Subscription Cancelled', 'pillpalnow'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo self::OPTION_NAME; ?>[stripe_sub_cancelled_email]" value="1" <?php checked('1', $settings['stripe_sub_cancelled_email']); ?> />
                                        <?php _e('Send Email', 'pillpalnow'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo self::OPTION_NAME; ?>[stripe_sub_cancelled_push]" value="1" <?php checked('1', $settings['stripe_sub_cancelled_push']); ?> />
                                        <?php _e('Send Push Notification', 'pillpalnow'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Testing Tab -->
                <div class="pillpalnow-tab-content" id="tab-testing"
                    style="display: <?php echo $active_tab === 'testing' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('Test Notifications', 'pillpalnow'); ?></h2>
                    <p class="description"><?php _e('Send test notifications to verify your configuration.', 'pillpalnow'); ?></p>

                    <div class="pillpalnow-test-notification-panel">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Notification Type', 'pillpalnow'); ?></th>
                                <td>
                                    <select id="test-notification-type">
                                        <option value="test"><?php _e('Test', 'pillpalnow'); ?></option>
                                        <option value="reminder"><?php _e('Reminder', 'pillpalnow'); ?></option>
                                        <option value="refill"><?php _e('Refill Alert', 'pillpalnow'); ?></option>
                                        <option value="missed"><?php _e('Missed Dose', 'pillpalnow'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Recipient', 'pillpalnow'); ?></th>
                                <td>
                                    <input type="text" id="test-recipient" class="regular-text"
                                        placeholder="<?php _e('User ID, Email, or Player ID', 'pillpalnow'); ?>" />
                                    <p class="description">
                                        <?php _e('Enter a WordPress user ID, email address, or OneSignal player ID', 'pillpalnow'); ?>
                                        <br><strong><?php _e('Examples:', 'pillpalnow'); ?></strong>
                                        <?php _e('User ID: 1 | Email: user@example.com | Player ID: a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'pillpalnow'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Custom Message (Optional)', 'pillpalnow'); ?></th>
                                <td>
                                    <textarea id="test-message" rows="3" class="large-text"
                                        placeholder="<?php _e('Custom test message (optional)', 'pillpalnow'); ?>"></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <button type="button" class="button button-primary" id="send-test-notification">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <?php _e('Send Test Notification', 'pillpalnow'); ?>
                                    </button>
                                    <div id="test-notification-result" style="margin-top: 10px;"></div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Debug Tab -->
                <div class="pillpalnow-tab-content" id="tab-debug"
                    style="display: <?php echo $active_tab === 'debug' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('Debug & System Information', 'pillpalnow'); ?></h2>
                    <p class="description"><?php _e('View system diagnostics and debug information.', 'pillpalnow'); ?></p>

                    <div class="pillpalnow-debug-actions">
                        <button type="button" class="button" id="refresh-debug-info">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh Info', 'pillpalnow'); ?>
                        </button>
                        <button type="button" class="button" id="export-debug-report">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Export Diagnostic Report', 'pillpalnow'); ?>
                        </button>
                        <button type="button" class="button button-destructive" id="clear-logs">
                            <span class="dashicons dashicons-trash"></span>
                            <?php _e('Clear Logs', 'pillpalnow'); ?>
                        </button>
                    </div>

                    <div id="debug-info-container" class="debug-info-container">
                        <p><?php _e('Click "Refresh Info" to load system diagnostics.', 'pillpalnow'); ?></p>
                    </div>

                    <h3><?php _e('Recent Logs', 'pillpalnow'); ?></h3>
                    <div id="recent-logs-container" class="recent-logs-container">
                        <p><?php _e('Loading...', 'pillpalnow'); ?></p>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div class="pillpalnow-tab-content" id="tab-advanced"
                    style="display: <?php echo $active_tab === 'advanced' ? 'block' : 'none'; ?>;">
                    <h2><?php _e('Advanced Settings', 'pillpalnow'); ?></h2>
                    <p class="description">
                        <?php _e('Configure advanced options for logging and service workers.', 'pillpalnow'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php _e('Enable Logging', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_logging]" value="1"
                                        <?php checked('1', $settings['enable_logging']); ?> />
                                    <?php _e('Log all notification sends for debugging and monitoring', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Log Retention (Days)', 'pillpalnow'); ?></th>
                            <td>
                                <input type="number" name="<?php echo self::OPTION_NAME; ?>[log_retention_days]"
                                    value="<?php echo esc_attr($settings['log_retention_days']); ?>" min="1" max="365" />
                                <span><?php _e(' days', 'pillpalnow'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Debug Mode', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[debug_mode]" value="1" <?php checked('1', $settings['debug_mode']); ?> />
                                    <?php _e('Enable detailed debug logging', 'pillpalnow'); ?>
                                </label>
                                <p class="description">
                                    <strong style="color: #d63638;"><?php _e('⚠ Warning:', 'pillpalnow'); ?></strong>
                                    <?php _e('This may generate a lot of log data and impact performance. Only enable for troubleshooting.', 'pillpalnow'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Log Level', 'pillpalnow'); ?></th>
                            <td>
                                <select name="<?php echo self::OPTION_NAME; ?>[log_level]">
                                    <option value="error" <?php selected($settings['log_level'], 'error'); ?>>
                                        <?php _e('Error Only', 'pillpalnow'); ?>
                                    </option>
                                    <option value="warning" <?php selected($settings['log_level'], 'warning'); ?>>
                                        <?php _e('Warning & Above', 'pillpalnow'); ?>
                                    </option>
                                    <option value="info" <?php selected($settings['log_level'], 'info'); ?>>
                                        <?php _e('Info & Above', 'pillpalnow'); ?>
                                    </option>
                                    <option value="debug" <?php selected($settings['log_level'], 'debug'); ?>>
                                        <?php _e('All Debug Messages', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Custom Service Worker', 'pillpalnow'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[enable_custom_service_worker]"
                                        value="1" <?php checked('1', $settings['enable_custom_service_worker']); ?> />
                                    <?php _e('Enable custom service worker for push notifications', 'pillpalnow'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Custom Service Worker File', 'pillpalnow'); ?></th>
                            <td>
                                <?php echo self::render_service_worker_field($settings); ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php if ($is_admin && $active_tab !== 'family_permissions'): ?>
                    <?php submit_button(__('Save Settings', 'pillpalnow'), 'primary', 'submit', false, array('id' => 'pillpalnow-settings-submit')); ?>

                    <!-- Progress bar for file uploads -->
                    <div class="progress-container" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="progress-text"></div>
                    </div>
                <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render service worker upload field
     */
    private static function render_service_worker_field($settings)
    {
        $upload_dir = wp_upload_dir();
        $service_worker_dir = $upload_dir['basedir'] . '/pillpalnow-service-workers/';

        ob_start();
        ?>
        <input type="file" name="custom_service_worker_file" accept=".js" />
        <p class="description">
            <?php _e('Upload a custom service worker JavaScript file (.js) for push notifications.', 'pillpalnow'); ?>
        </p>

        <?php if (!empty($settings['custom_service_worker_file'])): ?>
            <?php $file_path = $service_worker_dir . $settings['custom_service_worker_file']; ?>
            <?php if (file_exists($file_path)): ?>
                <?php $file_url = home_url('/pillpalnow-service-worker.js'); ?>
                <p><strong><?php _e('Current file:', 'pillpalnow'); ?></strong>
                    <?php echo esc_html($settings['custom_service_worker_file']); ?></p>
                <p><strong><?php _e('Public URL:', 'pillpalnow'); ?></strong> <code><?php echo esc_url($file_url); ?></code></p>
            <?php else: ?>
                <p class="notice notice-warning"><strong><?php _e('Warning:', 'pillpalnow'); ?></strong>
                    <?php _e('The uploaded file no longer exists.', 'pillpalnow'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle service worker file upload
     */
    public static function handle_service_worker_upload_direct()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        // ✅ SECURITY: Validate file before upload
        if (!isset($_FILES['custom_service_worker_file']) || empty($_FILES['custom_service_worker_file']['tmp_name'])) {
            return;
        }

        $file = $_FILES['custom_service_worker_file'];

        // ✅ SECURITY: File size limit (1MB max)
        $max_file_size = 1048576; // 1MB in bytes
        if ($file['size'] > $max_file_size) {
            add_settings_error(
                'pillpalnow_settings',
                'service_worker_upload_error',
                sprintf(__('File too large. Maximum size: %s', 'pillpalnow'), '1MB')
            );
            return;
        }

        // ✅ SECURITY: MIME type validation
        $allowed_mime = 'application/javascript';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            // JavaScript files may be detected as text/plain, so we allow both
            $valid_mimes = ['application/javascript', 'text/plain', 'application/x-javascript'];
            if (!in_array($detected_mime, $valid_mimes)) {
                add_settings_error(
                    'pillpalnow_settings',
                    'service_worker_upload_error',
                    sprintf(__('Invalid file type detected: %s. Only JavaScript files are allowed.', 'pillpalnow'), $detected_mime)
                );
                return;
            }
        }

        // ✅ SECURITY: File extension validation
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'js') {
            add_settings_error(
                'pillpalnow_settings',
                'service_worker_upload_error',
                __('Invalid file extension. Only .js files are allowed.', 'pillpalnow')
            );
            return;
        }

        $upload_dir = wp_upload_dir();
        $service_worker_dir = $upload_dir['basedir'] . '/pillpalnow-service-workers/';

        // Create directory if it doesn't exist
        if (!file_exists($service_worker_dir)) {
            wp_mkdir_p($service_worker_dir);
        }

        // Set up upload overrides
        $upload_overrides = array(
            'upload_dir' => $service_worker_dir,
            'upload_url' => $upload_dir['baseurl'] . '/pillpalnow-service-workers/',
            'mimes' => array('js' => 'application/javascript'),
        );

        // Handle the upload
        $uploaded_file = wp_handle_upload($file, $upload_overrides);

        if (isset($uploaded_file['error'])) {
            add_settings_error(
                'pillpalnow_settings',
                'service_worker_upload_error',
                __('Service worker upload failed: ', 'pillpalnow') . $uploaded_file['error']
            );
            return;
        }

        if (isset($uploaded_file['file'])) {
            // Get filename
            $filename = basename($uploaded_file['file']);

            // ✅ SECURITY: Content scanning for malicious code patterns
            $file_content = file_get_contents($uploaded_file['file']);

            // Check for dangerous patterns
            $dangerous_patterns = [
                '/eval\s*\(/i',           // eval()
                '/exec\s*\(/i',           // exec()
                '/system\s*\(/i',         // system()
                '/passthru\s*\(/i',       // passthru()
                '/shell_exec\s*\(/i',     // shell_exec()
                '/\<\?php/i',              // PHP tags
                '/\<\?=/i',                // PHP short tags
                '/\<script[^>]*>.*?document\.cookie/is', // Cookie theft attempts
            ];

            foreach ($dangerous_patterns as $pattern) {
                if (preg_match($pattern, $file_content)) {
                    // Delete the uploaded file
                    @unlink($uploaded_file['file']);

                    add_settings_error(
                        'pillpalnow_settings',
                        'service_worker_security_error',
                        __('Security: Uploaded file contains potentially malicious code and has been rejected.', 'pillpalnow')
                    );
                    return;
                }
            }

            // Validate file content (basic check)
            if (strpos($file_content, 'serviceWorker') === false && strpos($file_content, 'ServiceWorker') === false) {
                // Not necessarily an error, but warn the user
                add_settings_error(
                    'pillpalnow_settings',
                    'service_worker_content_warning',
                    __('Warning: The uploaded file does not appear to contain service worker code. Please ensure it contains proper service worker registration and event handlers.', 'pillpalnow'),
                    'warning'
                );
            }

            // Update settings with filename
            $settings = self::get_settings();
            $settings['custom_service_worker_file'] = $filename;
            update_option(self::OPTION_NAME, $settings);

            add_settings_error(
                'pillpalnow_settings',
                'service_worker_upload_success',
                __('Service worker file uploaded successfully.', 'pillpalnow'),
                'success'
            );
        }
    }

    /**
     * Service worker upload error handler
     */
    public static function service_worker_upload_error($file, $message)
    {
        return new WP_Error('upload_error', $message);
    }

    /**
     * Add rewrite rule for service worker
     */
    public static function add_service_worker_rewrite_rule()
    {
        add_rewrite_rule('^pillpalnow-service-worker\.js$', 'index.php?pillpalnow_sw=1', 'top');

        // Also add rule for standard OneSignal worker path to use the same handler
        // Note: The query string (appId) is handled by WordPress automatically
        add_rewrite_rule('^OneSignalSDKWorker\.js$', 'index.php?pillpalnow_sw=1', 'top');
        add_rewrite_rule('^OneSignalSDK\.sw\.js$', 'index.php?pillpalnow_sw=1', 'top');

        add_rewrite_tag('%pillpalnow_sw%', '([^&]+)');
    }

    /**
     * Serve the custom service worker file
     */
    public static function serve_service_worker()
    {
        if (get_query_var('pillpalnow_sw') !== '1') {
            return;
        }

        $settings = self::get_settings();

        // Check if custom service worker is enabled and file exists
        if (!empty($settings['custom_service_worker_file']) && $settings['enable_custom_service_worker'] === '1') {
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/pillpalnow-service-workers/' . $settings['custom_service_worker_file'];

            if (file_exists($file_path)) {
                // Set proper headers
                header('Content-Type: application/javascript');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Service-Worker-Allowed: /');
                header('Pragma: no-cache');
                header('Expires: 0');

                readfile($file_path);
                exit;
            }
        }

        // Look for the user's manual root upload first before defaulting to the string
        $root_file_path = ABSPATH . 'OneSignalSDKWorker.js';

        // Set proper headers for Service Worker
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Service-Worker-Allowed: /');

        if (file_exists($root_file_path)) {
            readfile($root_file_path);
            exit;
        }

        // Fallback: If requesting OneSignalSDKWorker.js and no custom file is set/enabled,
        // serve the standard OneSignal import script.
        echo "importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');";
        exit;
    }

    /**
     * Logs page - Enhanced Notification Dashboard
     */
    public static function logs_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle form actions
        if (isset($_POST['action']) && !empty($_POST['action'])) {
            self::handle_notification_actions();
        }

        // Display any messages
        $admin_message = get_transient('pillpalnow_admin_message');
        if ($admin_message) {
            delete_transient('pillpalnow_admin_message');
            echo '<div class="notice notice-' . esc_attr($admin_message['type']) . '"><p>' . esc_html($admin_message['message']) . '</p></div>';
        }

        // Determine active tab
        $active_tab = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'user_logs';

        // Data fetching based on tab
        $user_groups = array();
        $system_logs = array();
        $stats = array();

        if ($active_tab === 'user_logs') {
            // Get filters from request
            $filters = array();
            if (!empty($_GET['user_id']))
                $filters['user_id'] = intval($_GET['user_id']);
            if (!empty($_GET['notification_type']))
                $filters['notification_type'] = sanitize_text_field($_GET['notification_type']);
            if (!empty($_GET['provider']))
                $filters['provider'] = sanitize_text_field($_GET['provider']);
            if (!empty($_GET['status']))
                $filters['status'] = sanitize_text_field($_GET['status']);
            if (!empty($_GET['date_from']))
                $filters['date_from'] = sanitize_text_field($_GET['date_from']);
            if (!empty($_GET['date_to']))
                $filters['date_to'] = sanitize_text_field($_GET['date_to']);

            // Get grouped logs
            $user_groups = PillPalNow_Notification_Logger::get_logs_grouped_by_user(50, $filters);

            // Get statistics
            $stats = PillPalNow_Notification_Logger::get_statistics(30);
        } else {
            // System Logs (user_id = 0)
            $system_logs = PillPalNow_Notification_Logger::get_logs(100, array('user_id' => 0));
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Notification Management Dashboard', 'pillpalnow'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=pillpalnow-notification-logs&view=user_logs"
                    class="nav-tab <?php echo $active_tab === 'user_logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('User Logs', 'pillpalnow'); ?>
                </a>
                <a href="?page=pillpalnow-notification-logs&view=system_logs"
                    class="nav-tab <?php echo $active_tab === 'system_logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('System / Webhook Logs', 'pillpalnow'); ?>
                </a>
            </nav>
            <br>

            <?php if ($active_tab === 'user_logs'): ?>

                <!-- Statistics Overview -->
                <div class="pillpalnow-stats-grid">
                    <div class="pillpalnow-stat-card">
                        <h3><?php _e('Total Sent (30 days)', 'pillpalnow'); ?></h3>
                        <div class="stat-number sent"><?php echo number_format($stats['total_sent']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card">
                        <h3><?php _e('Total Failed (30 days)', 'pillpalnow'); ?></h3>
                        <div class="stat-number failed"><?php echo number_format($stats['total_failed']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card">
                        <h3><?php _e('Success Rate', 'pillpalnow'); ?></h3>
                        <div class="stat-number">
                            <?php
                            $total = $stats['total_sent'] + $stats['total_failed'];
                            echo $total > 0 ? round(($stats['total_sent'] / $total) * 100, 1) . '%' : '0%';
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="pillpalnow-filters">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="pillpalnow-notification-logs">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label><?php _e('User:', 'pillpalnow'); ?></label>
                                <input type="text" name="user_search"
                                    placeholder="<?php _e('Search by email or ID', 'pillpalnow'); ?>"
                                    value="<?php echo esc_attr($_GET['user_search'] ?? ''); ?>">
                            </div>
                            <div class="filter-group">
                                <label><?php _e('Type:', 'pillpalnow'); ?></label>
                                <select name="notification_type">
                                    <option value=""><?php _e('All Types', 'pillpalnow'); ?></option>
                                    <option value="reminder" <?php selected($_GET['notification_type'] ?? '', 'reminder'); ?>>
                                        <?php _e('Reminder', 'pillpalnow'); ?>
                                    </option>
                                    <option value="refill" <?php selected($_GET['notification_type'] ?? '', 'refill'); ?>>
                                        <?php _e('Refill Alert', 'pillpalnow'); ?>
                                    </option>
                                    <option value="missed" <?php selected($_GET['notification_type'] ?? '', 'missed'); ?>>
                                        <?php _e('Missed Dose', 'pillpalnow'); ?>
                                    </option>
                                    <option value="taken" <?php selected($_GET['notification_type'] ?? '', 'taken'); ?>>
                                        <?php _e('Dose Taken', 'pillpalnow'); ?>
                                    </option>
                                    <option value="postponed" <?php selected($_GET['notification_type'] ?? '', 'postponed'); ?>>
                                        <?php _e('Postponed', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><?php _e('Provider:', 'pillpalnow'); ?></label>
                                <select name="provider">
                                    <option value=""><?php _e('All Providers', 'pillpalnow'); ?></option>
                                    <option value="onesignal" <?php selected($_GET['provider'] ?? '', 'onesignal'); ?>>
                                        <?php _e('OneSignal', 'pillpalnow'); ?>
                                    </option>
                                    <option value="fluentcrm" <?php selected($_GET['provider'] ?? '', 'fluentcrm'); ?>>
                                        <?php _e('FluentCRM', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><?php _e('Status:', 'pillpalnow'); ?></label>
                                <select name="status">
                                    <option value=""><?php _e('All Statuses', 'pillpalnow'); ?></option>
                                    <option value="sent" <?php selected($_GET['status'] ?? '', 'sent'); ?>>
                                        <?php _e('Sent', 'pillpalnow'); ?>
                                    </option>
                                    <option value="failed" <?php selected($_GET['status'] ?? '', 'failed'); ?>>
                                        <?php _e('Failed', 'pillpalnow'); ?>
                                    </option>
                                    <option value="skipped" <?php selected($_GET['status'] ?? '', 'skipped'); ?>>
                                        <?php _e('Skipped', 'pillpalnow'); ?>
                                    </option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><?php _e('Date From:', 'pillpalnow'); ?></label>
                                <input type="date" name="date_from" value="<?php echo esc_attr($_GET['date_from'] ?? ''); ?>">
                            </div>
                            <div class="filter-group">
                                <label><?php _e('Date To:', 'pillpalnow'); ?></label>
                                <input type="date" name="date_to" value="<?php echo esc_attr($_GET['date_to'] ?? ''); ?>">
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="button"><?php _e('Filter', 'pillpalnow'); ?></button>
                                <a href="<?php echo admin_url('admin.php?page=pillpalnow-notification-logs'); ?>"
                                    class="button"><?php _e('Clear', 'pillpalnow'); ?></a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Bulk Actions -->
                <div class="pillpalnow-bulk-actions">
                    <form method="post" id="bulk-action-form">
                        <?php wp_nonce_field('pillpalnow_admin_ajax', '_wpnonce'); ?>
                        <select name="action">
                            <option value=""><?php _e('Bulk Actions', 'pillpalnow'); ?></option>
                            <option value="resend_failed"><?php _e('Resend Failed Notifications', 'pillpalnow'); ?></option>
                            <option value="clear_logs"><?php _e('Clear All Logs', 'pillpalnow'); ?></option>
                        </select>
                        <button type="submit" class="button button-primary">
                            <?php _e('Apply', 'pillpalnow'); ?>
                        </button>

                        <!-- Progress bar for bulk actions -->
                        <div class="progress-container" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <div class="progress-text"></div>
                        </div>
                    </form>
                </div>

                <!-- Test Send -->
                <div class="pillpalnow-test-send">
                    <h3><?php _e('Test Notification', 'pillpalnow'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('pillpalnow_admin_ajax', '_wpnonce'); ?>
                        <div class="test-send-row">
                            <select name="test_provider" required>
                                <option value=""><?php _e('Select Provider', 'pillpalnow'); ?></option>
                                <option value="onesignal"><?php _e('OneSignal', 'pillpalnow'); ?></option>
                                <option value="fluentcrm"><?php _e('FluentCRM', 'pillpalnow'); ?></option>
                            </select>
                            <input type="email" name="test_email" placeholder="<?php _e('Test Email Address', 'pillpalnow'); ?>"
                                required>
                            <button type="submit" class="button"><?php _e('Send Test', 'pillpalnow'); ?></button>
                        </div>

                        <!-- Progress bar for test send -->
                        <div class="progress-container" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <div class="progress-text"></div>
                        </div>
                    </form>
                </div>

                <!-- User Grouped Logs -->
                <div class="pillpalnow-user-groups">
                    <?php if (empty($user_groups)): ?>
                        <div class="notice notice-info">
                            <p><?php _e('No notifications found matching your filters.', 'pillpalnow'); ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_groups as $user_group): ?>
                            <div class="pillpalnow-user-group">
                                <div class="user-group-header">
                                    <div class="user-info">
                                        <strong><?php echo esc_html($user_group->user_email ?: 'User ID: ' . $user_group->user_id); ?></strong>
                                        <span class="user-stats">
                                            <?php _e('Total:', 'pillpalnow'); ?>                     <?php echo $user_group->total_notifications; ?> |
                                            <?php _e('Sent:', 'pillpalnow'); ?> <span
                                                class="sent"><?php echo $user_group->sent_count; ?></span> |
                                            <?php _e('Failed:', 'pillpalnow'); ?> <span
                                                class="failed"><?php echo $user_group->failed_count; ?></span>
                                        </span>
                                    </div>
                                    <div class="user-actions">
                                        <button
                                            class="button button-small toggle-details"><?php _e('Toggle Details', 'pillpalnow'); ?></button>
                                    </div>
                                </div>
                                <div class="user-group-details" style="display: none;">
                                    <table class="wp-list-table widefat fixed striped">
                                        <thead>
                                            <tr>
                                                <th><?php _e('Date', 'pillpalnow'); ?></th>
                                                <th><?php _e('Type', 'pillpalnow'); ?></th>
                                                <th><?php _e('Provider', 'pillpalnow'); ?></th>
                                                <th><?php _e('Status', 'pillpalnow'); ?></th>
                                                <th><?php _e('Message', 'pillpalnow'); ?></th>
                                                <th><?php _e('Error Details', 'pillpalnow'); ?></th>
                                                <th><?php _e('Actions', 'pillpalnow'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($user_group->logs as $log): ?>
                                                <tr class="log-row <?php echo esc_attr($log->status); ?>">
                                                    <td><?php echo esc_html(date('M j, Y H:i', strtotime($log->created_at))); ?></td>
                                                    <td><?php echo esc_html(ucfirst($log->notification_type)); ?></td>
                                                    <td><?php echo esc_html(ucfirst($log->provider)); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                                                            <?php echo esc_html(ucfirst($log->status)); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo esc_html($log->message); ?></td>
                                                    <td><?php echo $log->status === 'failed' ? esc_html($log->response) : '-'; ?></td>
                                                    <td>
                                                        <?php if ($log->status === 'failed'): ?>
                                                            <form method="post" style="display: inline;">
                                                                <?php wp_nonce_field('pillpalnow_admin_ajax', '_wpnonce'); ?>
                                                                <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                                                <button type="submit"
                                                                    class="button button-small"><?php _e('Resend', 'pillpalnow'); ?></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- System Logs View -->
                    <div class="pillpalnow-system-logs">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 150px;"><?php _e('Date', 'pillpalnow'); ?></th>
                                    <th style="width: 200px;"><?php _e('Event Type', 'pillpalnow'); ?></th>
                                    <th style="width: 100px;"><?php _e('Status', 'pillpalnow'); ?></th>
                                    <th><?php _e('Message / Details', 'pillpalnow'); ?></th>
                                    <th><?php _e('Payload / Response', 'pillpalnow'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($system_logs)): ?>
                                    <tr>
                                        <td colspan="5"><?php _e('No system logs found.', 'pillpalnow'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($system_logs as $log): ?>
                                        <tr>
                                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log->created_at))); ?></td>
                                            <td><strong><?php echo esc_html($log->notification_type); ?></strong></td>
                                            <td>
                                                <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                                                    <?php echo esc_html(ucfirst($log->status)); ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html($log->message); ?></td>
                                            <td>
                                                <?php if ($log->response): ?>
                                                    <details>
                                                        <summary><?php _e('View Payload', 'pillpalnow'); ?></summary>
                                                        <pre
                                                            style="background: #f0f0f1; padding: 10px; overflow-x: auto; max-width: 400px;"><?php echo esc_html(print_r(json_decode($log->response), true) ?: $log->response); ?></pre>
                                                    </details>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    $('.toggle-details').on('click', function () {
                        $(this).closest('.user-group-header').next('.user-group-details').slideToggle();
                        $(this).text($(this).text() === '<?php _e('Toggle Details', 'pillpalnow'); ?>' ? '<?php _e('Hide Details', 'pillpalnow'); ?>' : '<?php _e('Toggle Details', 'pillpalnow'); ?>');
                    });
                });
            </script>
            <?php
    }

    /**
     * Dashboard page
     */
    public static function dashboard_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get some stats for dashboard
        $medication_count = wp_count_posts('medication')->publish;
        $family_member_count = wp_count_posts('family_member')->publish;
        $dose_log_count = wp_count_posts('dose_log')->publish;
        $refill_request_count = wp_count_posts('refill_request')->publish;

        // Get today's refill triggers
        $today = date('Y-m-d');
        $todays_refills = get_posts(array(
            'post_type' => 'refill_request',
            'posts_per_page' => 20,
            'meta_query' => array(
                array(
                    'key' => 'requested_date',
                    'value' => $today,
                ),
            ),
        ));

        ?>
            <div class="wrap">
                <h1><?php _e('PillPalNow Dashboard', 'pillpalnow'); ?></h1>

                <div class="dashboard-widgets-wrap">
                    <div class="metabox-holder">
                        <div class="postbox-container" style="width: 100%;">
                            <div class="meta-box-sortables">
                                <div class="postbox">
                                    <h2 class="hndle"><span><?php _e('Overview', 'pillpalnow'); ?></span></h2>
                                    <div class="inside">
                                        <div class="main">
                                            <ul>
                                                <li><strong><?php echo $medication_count; ?></strong>
                                                    <?php _e('Medications', 'pillpalnow'); ?></li>
                                                <li><strong><?php echo $family_member_count; ?></strong>
                                                    <?php _e('Family Members', 'pillpalnow'); ?></li>
                                                <li><strong><?php echo $dose_log_count; ?></strong>
                                                    <?php _e('Dose Logs', 'pillpalnow'); ?></li>
                                                <li><strong><?php echo $refill_request_count; ?></strong>
                                                    <?php _e('Refill Requests', 'pillpalnow'); ?></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Today's Refill Triggers Widget -->
                                <div class="postbox">
                                    <h2 class="hndle"><span><?php _e("Today's Refill Triggers", 'pillpalnow'); ?></span></h2>
                                    <div class="inside">
                                        <?php if (empty($todays_refills)): ?>
                                            <p><?php _e('No refill requests triggered today.', 'pillpalnow'); ?></p>
                                        <?php else: ?>
                                            <table class="widefat striped">
                                                <thead>
                                                    <tr>
                                                        <th><?php _e('User', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Medication', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Qty', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Source', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Email', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Push', 'pillpalnow'); ?></th>
                                                        <th><?php _e('Provider', 'pillpalnow'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($todays_refills as $refill):
                                                        $user_id = get_post_meta($refill->ID, 'user_id', true);
                                                        $user = get_userdata($user_id);
                                                        $med_id = get_post_meta($refill->ID, 'medication_id', true);
                                                        $qty = get_post_meta($refill->ID, 'remaining_qty', true);
                                                        $source = get_post_meta($refill->ID, 'trigger_source', true) ?: 'manual';
                                                        $email_status = get_post_meta($refill->ID, 'notification_email_status', true) ?: '-';
                                                        $push_status = get_post_meta($refill->ID, 'notification_push_status', true) ?: '-';
                                                        $email_provider = get_post_meta($refill->ID, 'email_provider', true) ?: '-';
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $user ? esc_html($user->display_name) : 'Unknown'; ?></td>
                                                            <td><a
                                                                    href="<?php echo admin_url('post.php?post=' . $med_id . '&action=edit'); ?>"><?php echo esc_html(get_the_title($med_id)); ?></a>
                                                            </td>
                                                            <td><?php echo intval($qty); ?></td>
                                                            <td><code><?php echo esc_html($source); ?></code></td>
                                                            <td
                                                                style="color: <?php echo $email_status === 'sent' ? 'green' : ($email_status === 'failed' ? 'red' : 'gray'); ?>;">
                                                                <?php echo esc_html($email_status); ?>
                                                            </td>
                                                            <td
                                                                style="color: <?php echo $push_status === 'sent' ? 'green' : ($push_status === 'failed' ? 'red' : 'gray'); ?>;">
                                                                <?php echo esc_html($push_status); ?>
                                                            </td>
                                                            <td><code><?php echo esc_html($email_provider); ?></code></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <p><a href="<?php echo admin_url('edit.php?post_type=refill_request'); ?>"
                                                    class="button"><?php _e('View All Refill Requests', 'pillpalnow'); ?></a></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- End Today's Refill Triggers Widget -->

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
    }

    /**
     * Section callback
     */
    public static function notification_section_callback()
    {
        echo '<p>' . __('Configure notification settings for PillPalNow.', 'pillpalnow') . '</p>';
    }

    /**
     * Field callbacks
     */
    public static function enable_reminders_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_reminders]" value="1" ' . checked('1', $settings['enable_reminders'], false) . ' />';
        echo '<label>' . __('Send reminder notifications for scheduled doses', 'pillpalnow') . '</label>';
    }

    public static function enable_refill_alerts_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_refill_alerts]" value="1" ' . checked('1', $settings['enable_refill_alerts'], false) . ' />';
        echo '<label>' . __('Send alerts when medication is running low', 'pillpalnow') . '</label>';
    }

    public static function enable_missed_notifications_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_missed_notifications]" value="1" ' . checked('1', $settings['enable_missed_notifications'], false) . ' />';
        echo '<label>' . __('Send notifications for missed doses', 'pillpalnow') . '</label>';
    }

    public static function enable_postponed_notifications_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_postponed_notifications]" value="1" ' . checked('1', $settings['enable_postponed_notifications'], false) . ' />';
        echo '<label>' . __('Send notifications when postponed reminders become due', 'pillpalnow') . '</label>';
    }

    public static function email_provider_callback()
    {
        $settings = self::get_settings();
        ?>
            <select name="<?php echo self::OPTION_NAME; ?>[email_provider]">
                <option value="onesignal" <?php selected($settings['email_provider'], 'onesignal'); ?>>
                    <?php _e('OneSignal Only', 'pillpalnow'); ?>
                </option>
                <option value="fluentcrm" <?php selected($settings['email_provider'], 'fluentcrm'); ?>>
                    <?php _e('FluentCRM Only', 'pillpalnow'); ?>
                </option>
                <option value="both" <?php selected($settings['email_provider'], 'both'); ?>>
                    <?php _e('Both OneSignal and FluentCRM', 'pillpalnow'); ?>
                </option>
            </select>
            <?php
    }

    public static function notification_priority_callback()
    {
        $settings = self::get_settings();
        ?>
            <select name="<?php echo self::OPTION_NAME; ?>[notification_priority]">
                <option value="low" <?php selected($settings['notification_priority'], 'low'); ?>>
                    <?php _e('Low', 'pillpalnow'); ?>
                </option>
                <option value="normal" <?php selected($settings['notification_priority'], 'normal'); ?>>
                    <?php _e('Normal', 'pillpalnow'); ?>
                </option>
                <option value="high" <?php selected($settings['notification_priority'], 'high'); ?>>
                    <?php _e('High', 'pillpalnow'); ?>
                </option>
            </select>
            <?php
    }

    public static function enable_logging_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_logging]" value="1" ' . checked('1', $settings['enable_logging'], false) . ' />';
        echo '<label>' . __('Log all notification sends for debugging and monitoring', 'pillpalnow') . '</label>';
    }

    public static function log_retention_days_callback()
    {
        $settings = self::get_settings();
        echo '<input type="number" name="' . self::OPTION_NAME . '[log_retention_days]" value="' . esc_attr($settings['log_retention_days']) . '" min="1" max="365" />';
        echo '<label>' . __(' days', 'pillpalnow') . '</label>';
    }

    public static function enable_custom_service_worker_callback()
    {
        $settings = self::get_settings();
        echo '<input type="checkbox" name="' . self::OPTION_NAME . '[enable_custom_service_worker]" value="1" ' . checked('1', $settings['enable_custom_service_worker'], false) . ' />';
        echo '<label>' . __('Enable custom service worker for push notifications as an alternative to OneSignal', 'pillpalnow') . '</label>';
    }

    public static function custom_service_worker_file_callback()
    {
        $settings = self::get_settings();
        $upload_dir = wp_upload_dir();
        $service_worker_dir = $upload_dir['basedir'] . '/pillpalnow-service-workers/';

        // Create directory if it doesn't exist
        if (!file_exists($service_worker_dir)) {
            wp_mkdir_p($service_worker_dir);
        }

        echo '<input type="file" name="custom_service_worker_file" accept=".js" />';
        echo '<p class="description">' . __('Upload a custom service worker JavaScript file (.js) for push notifications.', 'pillpalnow') . '</p>';

        if (!empty($settings['custom_service_worker_file'])) {
            $file_path = $service_worker_dir . $settings['custom_service_worker_file'];
            if (file_exists($file_path)) {
                $file_url = home_url('/pillpalnow-service-worker.js');
                echo '<p><strong>' . __('Current file:', 'pillpalnow') . '</strong> ' . esc_html($settings['custom_service_worker_file']) . '</p>';
                echo '<p><strong>' . __('Public URL:', 'pillpalnow') . '</strong> <code>' . esc_url($file_url) . '</code></p>';
                echo '<p><em>' . __('Use this URL to register the service worker in your frontend JavaScript.', 'pillpalnow') . '</em></p>';
            } else {
                echo '<p class="notice notice-warning"><strong>' . __('Warning:', 'pillpalnow') . '</strong> ' . __('The uploaded file no longer exists.', 'pillpalnow') . '</p>';
            }
        }

        echo '<h4>' . __('Frontend Implementation Instructions:', 'pillpalnow') . '</h4>';
        echo '<ol>';
        echo '<li>' . __('Register the service worker in your frontend JavaScript:', 'pillpalnow') . '<br>';
        echo '<code>if (\'serviceWorker\' in navigator) {<br>';
        echo '&nbsp;&nbsp;navigator.serviceWorker.register(\'' . esc_url(home_url('/pillpalnow-service-worker.js')) . '\')<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;.then(function(registration) { console.log(\'SW registered\'); })<br>';
        echo '&nbsp;&nbsp;&nbsp;&nbsp;.catch(function(error) { console.log(\'SW registration failed\'); });<br>';
        echo '}</code></li>';
        echo '<li>' . __('Request notification permission from users:', 'pillpalnow') . '<br>';
        echo '<code>Notification.requestPermission().then(function(permission) {<br>';
        echo '&nbsp;&nbsp;if (permission === \'granted\') { console.log(\'Permission granted\'); }<br>';
        echo '});</code></li>';
        echo '<li>' . __('Handle push events in your service worker to display notifications', 'pillpalnow') . '</li>';
        echo '<li>' . __('Use the Push API or custom endpoints to send notifications to users', 'pillpalnow') . '</li>';
        echo '</ol>';
    }

    /**
     * Enqueue scripts
     */
    public static function enqueue_scripts($hook)
    {
        // Fix: The correct hook is 'pillpalnow_page_pillpalnow-settings' not 'toplevel_page_pillpalnow-settings'
        // because this settings page is a submenu under the pillpalnow-dashboard toplevel menu
        if ($hook === 'pillpalnow_page_pillpalnow-settings' || $hook === 'pillpalnow_page_pillpalnow-notification-logs') {
            wp_enqueue_style('pillpalnow-admin', plugin_dir_url(__FILE__) . '../assets/css/admin.css', array(), '1.0.0');
            wp_enqueue_script('jquery');

            // Enqueue admin JavaScript
            wp_enqueue_script(
                'pillpalnow-admin-js',
                plugin_dir_url(__FILE__) . '../assets/js/admin.js',
                array('jquery'),
                '1.0.0',
                true
            );

            // Localize script with AJAX data
            wp_localize_script('pillpalnow-admin-js', 'pillpalnow_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pillpalnow_admin_ajax')
            ));
        }
    }

    /**
     * Enqueue frontend scripts
     */
    public static function enqueue_frontend_scripts()
    {
        $settings = self::get_settings();
        $app_id = $settings['onesignal_app_id'];

        if (!empty($app_id) && $app_id !== 'YOUR_APP_ID_HERE') {
            wp_enqueue_script(
                'pillpalnow-onesignal',
                plugin_dir_url(__FILE__) . '../assets/js/pillpalnow-onesignal.js',
                array(), // No dependency on jQuery/OneSignal for the loader itself usually, but we load OneSignal via CDN in the script or header
                '1.0.2',
                true
            );

            wp_localize_script('pillpalnow-onesignal', 'pillpalnowOneSignal', array(
                'appId' => $app_id,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('store_onesignal_player_id_nonce'),
                'workerPath' => 'OneSignalSDKWorker.js', // Let OneSignal JS specify root scope natively
            ));
        }
    }

    /**
     * Check if a notification type is enabled
     */
    public static function is_notification_enabled($type)
    {
        $settings = self::get_settings();

        switch ($type) {
            case 'reminder':
                return $settings['enable_reminders'] === '1';
            case 'refill':
                return $settings['enable_refill_alerts'] === '1';
            case 'missed':
                return $settings['enable_missed_notifications'] === '1';
            case 'postponed':
                return $settings['enable_postponed_notifications'] === '1';
            default:
                return true;
        }
    }

    /**
     * Handle notification actions (AJAX/bulk)
     */
    public static function handle_notification_actions()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $action = sanitize_text_field($_POST['action']);
        $message = '';
        $type = 'success';

        switch ($action) {
            case 'resend_single':
                check_admin_referer('resend_notification');
                $log_id = intval($_POST['log_id']);
                $success = PillPalNow_Notification_Logger::resend_notification($log_id);
                $message = $success ? __('Notification resent successfully.', 'pillpalnow') : __('Failed to resend notification.', 'pillpalnow');
                $type = $success ? 'success' : 'error';
                break;

            case 'resend_failed':
                check_admin_referer('bulk_notification_actions');
                $failed_logs = PillPalNow_Notification_Logger::get_failed_notifications();
                $resent_count = 0;
                foreach ($failed_logs as $log) {
                    if (PillPalNow_Notification_Logger::resend_notification($log->id)) {
                        $resent_count++;
                    }
                }
                $message = sprintf(__('Resent %d failed notifications.', 'pillpalnow'), $resent_count);
                break;

            case 'clear_logs':
                check_admin_referer('bulk_notification_actions');
                $cleared = PillPalNow_Notification_Logger::clear_old_logs(0);
                $message = sprintf(__('Cleared %d log entries.', 'pillpalnow'), $cleared);
                break;

            case 'test_send':
                check_admin_referer('test_notification');
                $provider = sanitize_text_field($_POST['test_provider']);
                $email = sanitize_email($_POST['test_email']);
                $success = PillPalNow_Notification_Logger::send_test_notification($provider, $email);
                $message = $success ? __('Test notification sent successfully.', 'pillpalnow') : __('Failed to send test notification.', 'pillpalnow');
                $type = $success ? 'success' : 'error';
                break;

            default:
                return;
        }

        // Store message for display
        if (!empty($message)) {
            set_transient('pillpalnow_admin_message', array('message' => $message, 'type' => $type), 30);
        }
    }

    /**
     * Get email providers to use
     */
    public static function get_email_providers()
    {
        $settings = self::get_settings();
        $provider = $settings['email_provider'];

        $providers = array();
        if ($provider === 'onesignal' || $provider === 'both') {
            $providers[] = 'onesignal';
        }
        if ($provider === 'fluentcrm' || $provider === 'both') {
            $providers[] = 'fluentcrm';
        }

        return $providers;
    }

    /**
     * Get notification priority
     */
    public static function get_notification_priority()
    {
        $settings = self::get_settings();
        return $settings['notification_priority'];
    }

    /**
     * Check if logging is enabled
     */
    public static function is_logging_enabled()
    {
        $settings = self::get_settings();
        return $settings['enable_logging'] === '1';
    }

    /**
     * Check if custom service worker is enabled
     */
    public static function is_custom_service_worker_enabled()
    {
        $settings = self::get_settings();
        return $settings['enable_custom_service_worker'] === '1' && !empty($settings['custom_service_worker_file']);
    }

    /**
     * Get service worker URL
     */
    public static function get_service_worker_url()
    {
        if (!self::is_custom_service_worker_enabled()) {
            return false;
        }
        return home_url('/pillpalnow-service-worker.js');
    }

    /**
     * Flush rewrite rules when settings are updated
     */
    public static function flush_rewrite_rules_on_update()
    {
        if (get_option('pillpalnow_rewrite_rules_flushed') !== '1') {
            flush_rewrite_rules();
            update_option('pillpalnow_rewrite_rules_flushed', '1');
        }
    }

    /**
     * AJAX handler for bulk actions
     */
    public static function ajax_handle_bulk_action()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $action = sanitize_text_field($_POST['bulk_action']);
        $message = '';
        $type = 'success';

        switch ($action) {
            case 'resend_failed':
                $failed_logs = PillPalNow_Notification_Logger::get_failed_notifications();
                $resent_count = 0;
                foreach ($failed_logs as $log) {
                    if (PillPalNow_Notification_Logger::resend_notification($log->id)) {
                        $resent_count++;
                    }
                }
                $message = sprintf(__('Successfully resent %d failed notifications.', 'pillpalnow'), $resent_count);
                break;

            case 'clear_logs':
                $cleared = PillPalNow_Notification_Logger::clear_old_logs(0);
                $message = sprintf(__('Successfully cleared %d log entries.', 'pillpalnow'), $cleared);
                break;

            default:
                wp_send_json_error(array('message' => 'Invalid bulk action.'));
                return;
        }

        wp_send_json_success(array('message' => $message, 'type' => $type));
    }

    /**
     * AJAX handler for test send
     */
    public static function ajax_handle_test_send()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $provider = sanitize_text_field($_POST['provider']);
        $email = sanitize_email($_POST['email']);

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
        }

        $success = PillPalNow_Notification_Logger::send_test_notification($provider, $email);

        if ($success) {
            wp_send_json_success(array('message' => 'Test notification sent successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to send test notification.'));
        }
    }

    /**
     * AJAX handler for single resend
     */
    public static function ajax_handle_resend_single()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $log_id = intval($_POST['log_id']);

        if (!$log_id) {
            wp_send_json_error(array('message' => 'Invalid log ID.'));
        }

        $success = PillPalNow_Notification_Logger::resend_notification($log_id);

        if ($success) {
            wp_send_json_success(array('message' => 'Notification resent successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to resend notification.'));
        }
    }

    /**
     * AJAX handler for file upload
     */
    public static function ajax_handle_file_upload()
    {
        error_log('PillPalNow Upload: Starting upload...');
        error_log('PillPalNow Upload: POST: ' . print_r($_POST, true));
        error_log('PillPalNow Upload: FILES: ' . print_r($_FILES, true));

        // Verify nonce manually to see if it fails
        if (!wp_verify_nonce($_POST['nonce'], 'pillpalnow_admin_ajax')) {
            error_log('PillPalNow Upload: Nonce verification FAILED. Sent: ' . $_POST['nonce']);
            wp_send_json_error(array('message' => 'Security check failed (Invalid Nonce).'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $upload_dir = wp_upload_dir();
        $service_worker_dir = $upload_dir['basedir'] . '/pillpalnow-service-workers/';

        // Create directory if it doesn't exist
        if (!file_exists($service_worker_dir)) {
            wp_mkdir_p($service_worker_dir);
        }

        // Bypass strict type checking for this specific JS upload
        add_filter('wp_check_filetype_and_ext', array(__CLASS__, 'bypass_js_type_check'), 10, 4);

        // Force custom upload directory
        add_filter('upload_dir', array(__CLASS__, 'custom_upload_dir'));

        $upload_overrides = array(
            'test_form' => false,
        );

        // Handle the upload
        $uploaded_file = wp_handle_upload($_FILES['file'], $upload_overrides);

        // Remove filters immediately
        remove_filter('wp_check_filetype_and_ext', array(__CLASS__, 'bypass_js_type_check'), 10);
        remove_filter('upload_dir', array(__CLASS__, 'custom_upload_dir'));

        if (isset($uploaded_file['error'])) {
            wp_send_json_error(array('message' => 'Upload failed: ' . $uploaded_file['error']));
        }

        if (isset($uploaded_file['file'])) {
            // Get filename
            $filename = basename($uploaded_file['file']);

            // Validate file content (basic check)
            $file_content = file_get_contents($uploaded_file['file']);
            if (
                strpos($file_content, 'serviceWorker') === false &&
                strpos($file_content, 'ServiceWorker') === false &&
                strpos($file_content, 'importScripts') === false &&
                strpos($file_content, 'OneSignal') === false
            ) {
                wp_send_json_error(array('message' => 'Warning: The uploaded file does not appear to contain service worker code. Please ensure it contains proper service worker registration and event handlers.'));
            }

            // Update settings with filename
            $settings = self::get_settings();
            $settings['custom_service_worker_file'] = $filename;
            update_option(self::OPTION_NAME, $settings);

            wp_send_json_success(array('message' => 'Service worker file uploaded successfully.'));
        }

        wp_send_json_error(array('message' => 'Upload failed for unknown reason.'));
    }

    /**
     * AJAX handler for saving settings
     */
    public static function ajax_handle_save_settings()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        // Handle settings update
        $settings = $_POST;
        unset($settings['action'], $settings['_wpnonce'], $settings['_wp_http_referer']);

        // Sanitize settings
        $sanitized = self::sanitize_settings($settings);

        // Update option
        update_option(self::OPTION_NAME, $sanitized);

        wp_send_json_success(array('message' => 'Settings saved successfully.'));
    }

    /**
     * AJAX handler for testing OneSignal connection
     */
    public static function ajax_test_onesignal_connection()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $app_id = sanitize_text_field(trim($_POST['app_id'] ?? ''));
        $api_key = sanitize_text_field(trim($_POST['api_key'] ?? ''));

        if (empty($app_id) || empty($api_key)) {
            wp_send_json_error(array(
                'message' => 'Please provide both App ID and API Key.',
            ));
        }

        if (!class_exists('PillPalNow_System_Status')) {
            wp_send_json_error(array('message' => 'System status class not available.'));
        }

        $result = PillPalNow_System_Status::test_onesignal_connection($app_id, $api_key);

        if ($result['connected']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for sending test notification
     */
    public static function ajax_send_test_notification()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $args = array(
            'provider' => sanitize_text_field($_POST['provider'] ?? 'onesignal'),
            'type' => sanitize_text_field($_POST['type'] ?? 'test'),
            'recipient' => sanitize_text_field($_POST['recipient'] ?? ''),
            'heading' => sanitize_text_field($_POST['heading'] ?? 'Test Notification'),
            'message' => sanitize_text_field($_POST['message'] ?? 'This is a test notification from PillPalNow.'),
        );

        $result = PillPalNow_Notification_Tester::send_test_notification($args);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for storing OneSignal Player ID
     */
    public static function ajax_store_onesignal_player_id()
    {
        check_ajax_referer('store_onesignal_player_id_nonce', 'nonce');

        $user_id = get_current_user_id();
        $player_id = sanitize_text_field($_POST['player_id'] ?? '');

        if (empty($player_id)) {
            wp_send_json_error(array('message' => 'Player ID is missing.'));
        }

        if (!$user_id) {
            wp_send_json_success(array('message' => 'Guest player ID acknowledged.'));
        }

        if (class_exists('PillPalNow_OneSignal_Service')) {
            $onesignal_service = PillPalNow_OneSignal_Service::get_instance();
            $result = $onesignal_service->store_player_id($user_id, $player_id);
            
            if ($result) {
                wp_send_json_success(array('message' => 'Player ID stored successfully'));
            } else {
                wp_send_json_error(array('message' => 'Failed to store player ID'));
            }
        } else {
             wp_send_json_error(array('message' => 'OneSignal service not available'));
        }
    }

    /**
     * AJAX handler for checking system status
     */
    public static function ajax_check_system_status()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        if (!class_exists('PillPalNow_System_Status')) {
            wp_send_json_error(array('message' => 'System status class not available.'));
        }

        $status = PillPalNow_System_Status::get_system_status();
        $summary = PillPalNow_System_Status::get_status_summary();

        wp_send_json_success(array(
            'status' => $status,
            'summary' => $summary,
        ));
    }

    /**
     * AJAX handler for getting debug info
     */
    public static function ajax_get_debug_info()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        $diagnostics = PillPalNow_System_Status::export_diagnostics();

        wp_send_json_success($diagnostics);
    }

    /**
     * AJAX handler for exporting debug report
     */
    public static function ajax_export_debug_report()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        if (!class_exists('PillPalNow_System_Status')) {
            wp_send_json_error(array('message' => 'System status class not available.'));
        }

        $diagnostics = PillPalNow_System_Status::export_diagnostics();

        // Set headers for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="pillpalnow-diagnostic-' . date('Y-m-d-H-i-s') . '.json"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode($diagnostics, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * AJAX handler for clearing error logs
     */
    public static function ajax_clear_error_logs()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        // Clear notification logs
        if (class_exists('PillPalNow_Notification_Logger')) {
            $cleared = PillPalNow_Notification_Logger::clear_old_logs(0);
            wp_send_json_success(array(
                'message' => sprintf('Cleared %d log entries.', $cleared),
                'count' => $cleared,
            ));
        } else {
            wp_send_json_error(array('message' => 'Notification logger not available.'));
        }
    }

    /**
     * AJAX handler for getting recent logs
     */
    public static function ajax_get_recent_logs()
    {
        check_ajax_referer('pillpalnow_admin_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'));
        }

        if (!class_exists('PillPalNow_System_Status')) {
            wp_send_json_error(array('message' => 'System status class not available.'));
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        $logs = PillPalNow_System_Status::get_recent_logs($limit);

        wp_send_json_success($logs);
    }

    public static function custom_upload_dir($uploads)
    {
        $uploads['subdir'] = '/pillpalnow-service-workers';
        $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
        $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
        return $uploads;
    }

    /**
     * Bypass JS type checking for service worker upload
     */
    public static function bypass_js_type_check($data, $file, $filename, $mimes)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'js') {
            return array(
                'ext' => 'js',
                'type' => 'text/javascript', // Force a valid type
                'proper_filename' => $filename
            );
        }
        return $data;
    }
}

// Initialize
PillPalNow_Admin_Settings::init();
