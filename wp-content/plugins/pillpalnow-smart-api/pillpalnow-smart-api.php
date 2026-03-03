<?php
/**
 * Plugin Name: PillPalNow Smart API
 * Plugin URI:  https://pillpalnow.com
 * Description: Smart email failover (SparkPost -> SES -> SendGrid) with comprehensive logging.
 * Version:     1.1.0
 * Author:      PillPalNow (Custom Lead)
 * License:     Proprietary
 */

if (!defined('ABSPATH')) {
    exit;
}

class PillPalNowSmartAPI
{
    /**
     * Table name for email logs
     */
    const LOG_TABLE = 'pillpalnow_email_logs';

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var array
     */
    private $last_logs = [];

    /**
     * @var string|null Last successful provider used (sparkpost, ses, sendgrid)
     */
    private $last_provider = null;

    /**
     * @var string Email type context for logging
     */
    private $current_email_type = 'general';

    /**
     * @var int User ID context for logging
     */
    private $current_user_id = 0;

    /**
     * @var PillPalNowSmartAPI|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return PillPalNowSmartAPI
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        // Bootstrap on plugins_loaded
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Initialize hooks and filters.
     */
    public function init()
    {
        // Create/upgrade database table
        $this->maybe_create_table();

        // CRITICAL: Intercept wp_mail
        add_filter('pre_wp_mail', [$this, 'intercept'], 1, 2);

        // Admin & Settings
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_pillpalnow_test_email', [$this, 'handle_test_email']);
        add_action('admin_post_pillpalnow_clear_logs', [$this, 'handle_clear_logs']);
        add_action('admin_post_pillpalnow_export_logs', [$this, 'handle_export_logs']);

        // Cron Actions
        add_action('pillpalnow_daily_reset', [$this, 'scheduled_reset']);

        // Admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'settings_page_pillpalnow-smart-api') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .pillpalnow-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
            .pillpalnow-stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
            .pillpalnow-stat-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
            .pillpalnow-stat-card .stat-value { font-size: 32px; font-weight: bold; color: #1e3a5f; }
            .pillpalnow-stat-card.success .stat-value { color: #10b981; }
            .pillpalnow-stat-card.failed .stat-value { color: #dc2626; }
            .pillpalnow-stat-card.pending .stat-value { color: #f59e0b; }
            .pillpalnow-log-filters { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
            .pillpalnow-log-table { width: 100%; border-collapse: collapse; background: #fff; }
            .pillpalnow-log-table th, .pillpalnow-log-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
            .pillpalnow-log-table th { background: #f8f9fa; font-weight: 600; }
            .pillpalnow-log-table tr:hover { background: #f8f9fa; }
            .status-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
            .status-badge.success { background: #d1fae5; color: #065f46; }
            .status-badge.failed { background: #fee2e2; color: #991b1b; }
            .provider-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; background: #e0e7ff; color: #3730a3; }
            .type-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; background: #fef3c7; color: #92400e; }
            .nav-tab-wrapper.pillpalnow-tabs { margin-bottom: 20px; }
            .pillpalnow-tab-content { display: none; }
            .pillpalnow-tab-content.active { display: block; }
            .pillpalnow-response-preview { max-width: 300px; max-height: 60px; overflow: hidden; text-overflow: ellipsis; font-size: 11px; color: #666; cursor: pointer; }
            .pillpalnow-response-preview:hover { background: #f0f0f0; }

            /* Smart Routing Styles */
            #pillpalnow-priority-list { list-style: none; padding: 0; margin: 0; max-width: 400px; }
            .provider-item { background: #fff; border: 1px solid #ccd0d4; padding: 10px; margin-bottom: 5px; border-radius: 4px; display: flex; align-items: center; cursor: move; }
            .provider-item .dashicons { margin-right: 10px; color: #a0a5aa; }
            .provider-item:hover { border-color: #2271b1; }
        ');

        // Calculate rule index safely
        $rules = get_option('pillpalnow_routing_rules', []);
        $rule_index = (is_array($rules) ? count($rules) : 0) + 1;

        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Drag and Drop for Priority
                if (typeof $.fn.sortable !== "undefined") {
                    $("#pillpalnow-priority-list").sortable();
                }

                // Dynamic Routing Rules
                var ruleIndex = ' . $rule_index . ';
                
                $("#add-routing-rule").on("click", function() {
                    var row = `<tr>
                        <td>
                            <select name="pillpalnow_routing_rules[${ruleIndex}][type]">
                                <option value="refill">Refill</option>
                                <option value="magic_link">Magic Link</option>
                                <option value="reminder">Reminder</option>
                                <option value="test">Test</option>
                                <option value="password_reset">Password Reset</option>
                                <option value="general">General</option>
                            </select>
                        </td>
                        <td>
                            <select name="pillpalnow_routing_rules[${ruleIndex}][provider]">
                                <option value="sparkpost">SparkPost</option>
                                <option value="ses">Amazon SES</option>
                                <option value="sendgrid">SendGrid</option>
                            </select>
                        </td>
                        <td><button type="button" class="button remove-rule">X</button></td>
                    </tr>`;
                    $("#pillpalnow-rules-table tbody").append(row);
                    ruleIndex++;
                });

                $(document).on("click", ".remove-rule", function() {
                    $(this).closest("tr").remove();
                });

                // Tab switching
                $(".pillpalnow-tabs .nav-tab").on("click", function(e) {
                    e.preventDefault();
                    var tab = $(this).data("tab");
                    $(".pillpalnow-tabs .nav-tab").removeClass("nav-tab-active");
                    $(this).addClass("nav-tab-active");
                    $(".pillpalnow-tab-content").removeClass("active");
                    $("#tab-" + tab).addClass("active");
                });

                // Response preview expand
                $(document).on("click", ".pillpalnow-response-preview", function() {
                    var full = $(this).data("full");
                    if (full) {
                        alert(full);
                    }
                });
            });
        ');

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');
    }

    /**
     * Create or upgrade database table for email logs
     */
    private function maybe_create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        // Check DB version for upgrades
        $db_version = get_option('pillpalnow_email_logs_db_version', '0');
        $current_version = '1.1';

        // If table exists but needs upgrade, or table doesn't exist
        $table_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name);

        if ($table_exists && version_compare($db_version, $current_version, '>=')) {
            return; // Table is up to date
        }

        // Create or upgrade table using dbDelta
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            recipient varchar(255) NOT NULL,
            subject varchar(500) NOT NULL,
            provider varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            response text,
            email_type varchar(50) DEFAULT 'general',
            user_id bigint(20) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY recipient (recipient),
            KEY provider (provider),
            KEY status (status),
            KEY email_type (email_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // If table already existed, manually add missing columns (dbDelta doesn't always handle this well)
        if ($table_exists) {
            // Check and add email_type column if missing
            $col = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'email_type'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN email_type varchar(50) DEFAULT 'general' AFTER response");
                $wpdb->query("ALTER TABLE $table_name ADD KEY email_type (email_type)");
            }

            // Check and add user_id column if missing
            $col = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'user_id'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN user_id bigint(20) DEFAULT 0 AFTER email_type");
            }

            // Check and add created_at column if missing
            $col = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'created_at'");
            if (empty($col)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER user_id");
                $wpdb->query("ALTER TABLE $table_name ADD KEY created_at (created_at)");
            }
        }

        update_option('pillpalnow_email_logs_db_version', $current_version);
    }

    /**
     * Set email context for logging
     *
     * @param string $type Email type (refill, magic_link, test, general, etc.)
     * @param int $user_id Associated user ID
     */
    public function set_email_context($type, $user_id = 0)
    {
        $this->current_email_type = sanitize_text_field($type);
        $this->current_user_id = intval($user_id);
    }

    /**
     * Reset email context after sending
     */
    private function reset_email_context()
    {
        $this->current_email_type = 'general';
        $this->current_user_id = 0;
    }

    /**
     * Log email to database
     *
     * @param string $recipient
     * @param string $subject
     * @param string $provider
     * @param string $status
     * @param string $response
     */
    private function log_email($recipient, $subject, $provider, $status, $response = '')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $wpdb->insert($table_name, [
            'recipient' => sanitize_email($recipient),
            'subject' => sanitize_text_field(substr($subject, 0, 500)),
            'provider' => sanitize_text_field($provider),
            'status' => sanitize_text_field($status),
            'response' => sanitize_textarea_field(substr($response, 0, 5000)),
            'email_type' => $this->current_email_type,
            'user_id' => $this->current_user_id,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get email logs with filtering
     *
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_logs($filters = [], $limit = 50, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['provider'])) {
            $where[] = 'provider = %s';
            $params[] = $filters['provider'];
        }

        if (!empty($filters['email_type'])) {
            $where[] = 'email_type = %s';
            $params[] = $filters['email_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[] = '(recipient LIKE %s OR subject LIKE %s)';
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $where_clause = implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get log statistics
     *
     * @param int $days Number of days to look back
     * @return array
     */
    public function get_stats($days = 30)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'by_provider' => [],
            'by_type' => [],
        ];

        // Total counts by status
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY status",
            $cutoff
        ));

        foreach ($results as $row) {
            $stats[$row->status] = intval($row->count);
            $stats['total'] += intval($row->count);
        }

        // By provider
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT provider, status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY provider, status",
            $cutoff
        ));

        foreach ($results as $row) {
            if (!isset($stats['by_provider'][$row->provider])) {
                $stats['by_provider'][$row->provider] = ['success' => 0, 'failed' => 0];
            }
            $stats['by_provider'][$row->provider][$row->status] = intval($row->count);
        }

        // By type
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT email_type, status, COUNT(*) as count FROM $table_name WHERE created_at >= %s GROUP BY email_type, status",
            $cutoff
        ));

        foreach ($results as $row) {
            if (!isset($stats['by_type'][$row->email_type])) {
                $stats['by_type'][$row->email_type] = ['success' => 0, 'failed' => 0];
            }
            $stats['by_type'][$row->email_type][$row->status] = intval($row->count);
        }

        return $stats;
    }

    /**
     * Clear old logs
     *
     * @param int $days_old Clear logs older than this many days (0 = all)
     * @return int Number of deleted rows
     */
    public function clear_logs($days_old = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE;

        if ($days_old > 0) {
            // Use current_time('timestamp') to match WP timezone if used in storage
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days_old} days", current_time('timestamp')));

            $sql = $wpdb->prepare("DELETE FROM $table_name WHERE created_at < %s", $cutoff);
            $result = $wpdb->query($sql);

            if ($result === false) {
                error_log("PillPalNow DB Error (Clear Logs > 0): " . $wpdb->last_error);
            }
            return $result;
        }

        $result = $wpdb->query("DELETE FROM $table_name");
        if ($result === false) {
            error_log("PillPalNow DB Error (Clear All): " . $wpdb->last_error);
        }
        return $result;
    }

    /**
     * Safe Email Interception
     *
     * @param null|bool $return Current status.
     * @param array     $atts   Mail attributes.
     * @return bool True to short-circuit (handled), False/Null to let WP handle it (or fail).
     */
    public function intercept($return, $atts)
    {
        // Prevent double sending
        if (true === $return) {
            return true;
        }

        // Required attributes
        $to = isset($atts['to']) ? $atts['to'] : '';
        $subject = isset($atts['subject']) ? $atts['subject'] : '';
        $message = isset($atts['message']) ? $atts['message'] : '';
        $headers = isset($atts['headers']) ? $atts['headers'] : [];
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : [];

        if (empty($to)) {
            return $return;
        }

        // Auto-detect email type from subject if not set
        if ($this->current_email_type === 'general') {
            $this->detect_email_type($subject);
        }

        // Check lazy reset before sending
        $this->check_lazy_reset();

        // Attempt Smart Send
        $sent = $this->smart_send($to, $subject, $message, $headers, $attachments);

        // Reset context after sending
        $this->reset_email_context();

        return $sent;
    }

    /**
     * Auto-detect email type from subject
     */
    private function detect_email_type($subject)
    {
        $subject_lower = strtolower($subject);

        if (strpos($subject_lower, 'refill') !== false) {
            $this->current_email_type = 'refill';
        } elseif (strpos($subject_lower, 'login') !== false || strpos($subject_lower, 'magic') !== false) {
            $this->current_email_type = 'magic_link';
        } elseif (strpos($subject_lower, 'test') !== false) {
            $this->current_email_type = 'test';
        } elseif (strpos($subject_lower, 'reminder') !== false) {
            $this->current_email_type = 'reminder';
        } elseif (strpos($subject_lower, 'password') !== false || strpos($subject_lower, 'reset') !== false) {
            $this->current_email_type = 'password_reset';
        }
    }

    /**
     * Smart Send Logic with Failover and Logging
     */
    private function smart_send($to, $subject, $message, $headers, $attachments)
    {
        $this->last_logs = []; // Reset logs
        $this->last_provider = null; // Reset provider
        $stats = get_option('pillpalnow_stats', []);

        // 1. Get Base Priority
        $priority_list = get_option('pillpalnow_provider_priority', ['sparkpost', 'ses', 'sendgrid']);
        if (!is_array($priority_list)) {
            $priority_list = ['sparkpost', 'ses', 'sendgrid'];
        }

        // 2. Apply Custom Routing Rules
        // If a rule matches the current email type, move that provider to the TOP of the list
        $rules = get_option('pillpalnow_routing_rules', []);
        if (!empty($this->current_email_type) && is_array($rules)) {
            foreach ($rules as $rule) {
                if (isset($rule['type'], $rule['provider']) && $rule['type'] === $this->current_email_type) {
                    $preferred_provider = $rule['provider'];

                    // Remove preferred from list and prepend
                    $key = array_search($preferred_provider, $priority_list);
                    if ($key !== false) {
                        unset($priority_list[$key]);
                    }
                    array_unshift($priority_list, $preferred_provider);

                    $this->last_logs[] = "ℹ️ Rule Matched: {$this->current_email_type} -> Prioritizing {$preferred_provider}";
                    break; // Execute first matching rule only
                }
            }
        }

        // 3. Execute Sending Chain
        foreach ($priority_list as $provider_slug) {
            $method = 'send_' . $provider_slug;
            if (method_exists($this, $method)) {

                // Pre-check quotas/keys before attempting
                if (!$this->can_use_provider($provider_slug, $stats)) {
                    continue;
                }

                // Retrieve Provider Keys (Dynamic)
                $keys = $this->get_provider_keys($provider_slug);
                if (empty($keys)) {
                    $this->last_logs[] = "⚠️ {$provider_slug}: Skipped (No Key)";
                    continue;
                }

                // Attempt Send
                // Pass appropriate args based on provider signature
                if ($provider_slug === 'ses') {
                    $result = $this->$method($to, $subject, $message, $keys['key'], $keys['secret'], $keys['region']);
                } else {
                    $result = $this->$method($to, $subject, $message, $keys['key']);
                }

                if ($result['success']) {
                    $this->increment_stats($provider_slug);
                    $this->last_provider = $provider_slug;
                    $this->last_logs[] = "✅ " . ucfirst($provider_slug) . ": Success";
                    $this->log_email($to, $subject, $provider_slug, 'success', $result['response']);
                    return true;
                } else {
                    // Log failure and continue to next provider
                    $this->last_logs[] = "❌ " . ucfirst($provider_slug) . " Failed: " . $result['response'];
                    $this->log_email($to, $subject, $provider_slug, 'failed', $result['response']);
                }
            }
        }

        // Emergency Log if ALL Failed
        $debug_info = implode(' | ', $this->last_logs);
        $this->log_emergency($to, $subject, "All providers failed. Debug logic: $debug_info");
        $this->log_email($to, $subject, 'all_failed', 'failed', $debug_info);
        $this->last_logs[] = '❌ ALL FAILED: Triggering WordPress Fallback (PHPMailer)';

        return false;
    }

    /**
     * Check if a provider can be used (quota checks)
     */
    private function can_use_provider($provider, $stats)
    {
        if ($provider === 'sparkpost') {
            $sparkpost_limit = 450;
            if (isset($stats['sparkpost']) && $stats['sparkpost'] >= $sparkpost_limit) {
                $this->last_logs[] = '⚠️ SparkPost: Skipped (Quota Exceeded)';
                return false;
            }
        }
        return true;
    }

    /**
     * Get keys for a provider
     */
    private function get_provider_keys($provider)
    {
        switch ($provider) {
            case 'sparkpost':
                $k = trim(get_option('pillpalnow_sparkpost_key'));
                return $k ? ['key' => $k] : null;
            case 'sendgrid':
                $k = trim(get_option('pillpalnow_sendgrid_key'));
                return $k ? ['key' => $k] : null;
            case 'ses':
                $k = trim(get_option('pillpalnow_ses_key'));
                $s = trim(get_option('pillpalnow_ses_secret'));
                $r = trim(get_option('pillpalnow_ses_region', 'us-east-1'));
                return ($k && $s) ? ['key' => $k, 'secret' => $s, 'region' => $r] : null;
        }
        return null;
    }

    private function increment_stats($provider)
    {
        $stats = get_option('pillpalnow_stats', []);
        if (!isset($stats[$provider])) {
            $stats[$provider] = 0;
        }
        $stats[$provider]++;
        update_option('pillpalnow_stats', $stats);
    }

    /**
     * Get the last provider used to send an email successfully.
     * Returns null if no email was sent or all providers failed.
     *
     * @return string|null Provider name (sparkpost, ses, sendgrid) or null
     */
    public function get_last_provider()
    {
        return $this->last_provider;
    }

    /**
     * Get the last logs for debugging.
     *
     * @return array Array of log messages
     */
    public function get_last_logs()
    {
        return $this->last_logs;
    }

    /**
     * Reset Logic
     */
    private function check_lazy_reset()
    {
        $today = current_time('Y-m-d');
        $stats = get_option('pillpalnow_stats', []);

        if (!isset($stats['last_reset']) || $stats['last_reset'] !== $today) {
            $this->reset_stats($stats, $today);
        }
    }

    public function scheduled_reset()
    {
        $today = current_time('Y-m-d');
        $stats = get_option('pillpalnow_stats', []);
        $this->reset_stats($stats, $today);
    }

    private function reset_stats($stats, $today)
    {
        $new_stats = [
            'last_reset' => $today,
            'sparkpost' => 0,
            'ses' => 0,
            'sendgrid' => 0
        ];
        update_option('pillpalnow_stats', $new_stats);
    }

    /**
     * Admin & Settings
     */
    public function register_admin_page()
    {
        add_options_page(
            'PillPalNow Smart API',
            'PillPalNow API',
            'manage_options',
            'pillpalnow-smart-api',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        $settings = [
            'pillpalnow_sparkpost_key',
            'pillpalnow_sendgrid_key',
            'pillpalnow_ses_key',
            'pillpalnow_ses_secret',
            'pillpalnow_ses_region',
            'pillpalnow_provider_priority',
            'pillpalnow_routing_rules',
            'pillpalnow_from_email',
        ];

        foreach ($settings as $setting) {
            register_setting('pillpalnow_smart_api_group', $setting);
        }

        // Template Settings
        register_setting('pillpalnow_smart_api_group', 'pillpalnow_tmpl_invite');
        register_setting('pillpalnow_smart_api_group', 'pillpalnow_tmpl_welcome');
        register_setting('pillpalnow_smart_api_group', 'pillpalnow_tmpl_reset');
        register_setting('pillpalnow_smart_api_group', 'pillpalnow_tmpl_refill');
        register_setting('pillpalnow_smart_api_group', 'pillpalnow_tmpl_reminder');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Explicitly show settings errors
        settings_errors('pillpalnow_smart_api_group');

        // Fallback: Check for transient logs if settings_errors didn't catch it
        $transient_logs = get_transient('pillpalnow_test_email_logs');
        if ($transient_logs) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Debug Log:</strong> ' . esc_html($transient_logs) . '</p></div>';
            delete_transient('pillpalnow_test_email_logs');
        }

        $stats = get_option('pillpalnow_stats', []);
        $today = current_time('Y-m-d');
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

        // Get log statistics
        $log_stats = $this->get_stats(30);

        // Get filters for logs
        $log_filters = [
            'status' => isset($_GET['log_status']) ? sanitize_text_field($_GET['log_status']) : '',
            'provider' => isset($_GET['log_provider']) ? sanitize_text_field($_GET['log_provider']) : '',
            'email_type' => isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
        ];

        $logs = $this->get_logs($log_filters, 100);
        ?>
        <div class="wrap">
            <h1>PillPalNow Smart API Settings</h1>

            <nav class="nav-tab-wrapper pillpalnow-tabs">
                <a href="#" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"
                    data-tab="settings">⚙️ Settings</a>
                <a href="#" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>" data-tab="logs">📋
                    Email Logs</a>
                <a href="#" class="nav-tab <?php echo $active_tab === 'stats' ? 'nav-tab-active' : ''; ?>" data-tab="stats">📊
                    Statistics</a>
                <a href="#" class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>"
                    data-tab="templates">✉️ Email Templates</a>
                <a href="#" class="nav-tab <?php echo $active_tab === 'cron' ? 'nav-tab-active' : ''; ?>" data-tab="cron">⏰ Cron
                    Status</a>
            </nav>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const tabs = document.querySelectorAll('.pillpalnow-tabs .nav-tab');
                    const contents = document.querySelectorAll('.pillpalnow-tab-content');

                    tabs.forEach(tab => {
                        tab.addEventListener('click', function (e) {
                            e.preventDefault();
                            // Remove active class from all
                            tabs.forEach(t => t.classList.remove('nav-tab-active'));
                            contents.forEach(c => c.style.display = 'none');

                            // Add active to current
                            this.classList.add('nav-tab-active');
                            const target = this.getAttribute('data-tab');
                            document.getElementById('tab-' + target).style.display = 'block';

                            // Update URL without reload
                            const url = new URL(window.location);
                            url.searchParams.set('tab', target);
                            window.history.pushState({}, '', url);
                        });
                    });

                    // Initial state check (inline styles for JS logic)
                    contents.forEach(c => {
                        if (!c.classList.contains('active')) c.style.display = 'none';
                    });
                });
            </script>

            <!-- Settings Tab -->
            <div id="tab-settings" class="pillpalnow-tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                <div class="card" style="max-width: 100%; margin-top: 20px;">
                    <h2>📊 Daily Stats (<?php echo esc_html($today); ?>)</h2>
                    <?php if (!empty($stats['last_reset']) && $stats['last_reset'] !== $today): ?>
                        <p style="color: orange;">Stats have not reset yet for today (Standard Cron check). Lazy reset will trigger
                            on
                            next email.</p>
                    <?php endif; ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Usage Today</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>SparkPost</strong></td>
                                <td><?php echo isset($stats['sparkpost']) ? (int) $stats['sparkpost'] : 0; ?> / 450</td>
                                <td><?php echo (isset($stats['sparkpost']) && $stats['sparkpost'] >= 450) ? '<span style="color:red">Quota Exceeded</span>' : '<span style="color:green">Active</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Amazon SES</strong></td>
                                <td><?php echo isset($stats['ses']) ? (int) $stats['ses'] : 0; ?></td>
                                <td><span style="color:green">Active</span></td>
                            </tr>
                            <tr>
                                <td><strong>SendGrid</strong></td>
                                <td><?php echo isset($stats['sendgrid']) ? (int) $stats['sendgrid'] : 0; ?></td>
                                <td><span style="color:blue">Backup Active</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <form method="post" action="options.php">
                    <?php settings_fields('pillpalnow_smart_api_group'); ?>
                    <?php do_settings_sections('pillpalnow_smart_api_group'); ?>

                    <?php
                    // Retrieve saved settings
                    $priority = get_option('pillpalnow_provider_priority', ['sparkpost', 'ses', 'sendgrid']);
                    $routing_rules = get_option('pillpalnow_routing_rules', []);
                    ?>

                    <h2>🛤️ Smart Routing Configuration</h2>
                    <div class="card" style="margin-top: 20px; max-width: 100%;">
                        <h3>1. Provider Priority</h3>
                        <p class="description">Drag and drop to reorder. The system will try providers in this order unless a
                            specific rule matches.</p>

                        <ul id="pillpalnow-priority-list">
                            <?php foreach ($priority as $provider): ?>
                                <li class="provider-item" data-provider="<?php echo esc_attr($provider); ?>">
                                    <span class="dashicons dashicons-sort"></span>
                                    <strong><?php echo esc_html(ucfirst($provider)); ?></strong>
                                    <input type="hidden" name="pillpalnow_provider_priority[]"
                                        value="<?php echo esc_attr($provider); ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <hr style="margin: 20px 0;">

                        <h3>2. Routing Rules</h3>
                        <p class="description">Define specific matching rules. (e.g. If "Refill", use "SendGrid" first).</p>

                        <table class="widefat fixed striped" id="pillpalnow-rules-table">
                            <thead>
                                <tr>
                                    <th>Condition (Email Type)</th>
                                    <th>Action (Prioritize Provider)</th>
                                    <th style="width: 50px;">Remove</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($routing_rules)): ?>
                                    <?php foreach ($routing_rules as $index => $rule): ?>
                                        <tr>
                                            <td>
                                                <select name="pillpalnow_routing_rules[<?php echo $index; ?>][type]">
                                                    <option value="refill" <?php selected($rule['type'], 'refill'); ?>>Refill</option>
                                                    <option value="magic_link" <?php selected($rule['type'], 'magic_link'); ?>>Magic
                                                        Link</option>
                                                    <option value="reminder" <?php selected($rule['type'], 'reminder'); ?>>Reminder
                                                    </option>
                                                    <option value="test" <?php selected($rule['type'], 'test'); ?>>Test</option>
                                                    <option value="password_reset" <?php selected($rule['type'], 'password_reset'); ?>>
                                                        Password Reset</option>
                                                    <option value="general" <?php selected($rule['type'], 'general'); ?>>General
                                                    </option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="pillpalnow_routing_rules[<?php echo $index; ?>][provider]">
                                                    <option value="sparkpost" <?php selected($rule['provider'], 'sparkpost'); ?>>
                                                        SparkPost</option>
                                                    <option value="ses" <?php selected($rule['provider'], 'ses'); ?>>Amazon SES</option>
                                                    <option value="sendgrid" <?php selected($rule['provider'], 'sendgrid'); ?>>SendGrid
                                                    </option>
                                                </select>
                                            </td>
                                            <td>
                                                <button type="button" class="button remove-rule">X</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <p>
                            <button type="button" class="button" id="add-routing-rule">+ Add Rule</button>
                        </p>
                    </div>

                    <h2>🔑 API Configuration</h2>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">SparkPost API Key</th>
                            <td>
                                <input type="password" name="pillpalnow_sparkpost_key"
                                    value="<?php echo esc_attr(get_option('pillpalnow_sparkpost_key')); ?>" class="regular-text" />
                                <p class="description">Primary Provider. Limit: 450/day.</p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">From Email Address</th>
                            <td>
                                <input type="email" name="pillpalnow_from_email"
                                    value="<?php echo esc_attr(get_option('pillpalnow_from_email')); ?>" class="regular-text"
                                    placeholder="<?php echo esc_attr(get_bloginfo('admin_email')); ?>" />
                                <p class="description">Leave empty to use WordPress Admin Email
                                    (<?php echo esc_html(get_bloginfo('admin_email')); ?>) or define DOSEMED_FROM_EMAIL in
                                    wp-config.php.</p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">Amazon SES Access Key</th>
                            <td><input type="password" name="pillpalnow_ses_key"
                                    value="<?php echo esc_attr(get_option('pillpalnow_ses_key')); ?>" class="regular-text" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Amazon SES Secret Key</th>
                            <td><input type="password" name="pillpalnow_ses_secret"
                                    value="<?php echo esc_attr(get_option('pillpalnow_ses_secret')); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Amazon SES Region</th>
                            <td>
                                <input type="text" name="pillpalnow_ses_region"
                                    value="<?php echo esc_attr(get_option('pillpalnow_ses_region', 'us-east-1')); ?>"
                                    class="regular-text" />
                                <p class="description">e.g., us-east-1, eu-west-1</p>
                            </td>
                        </tr>

                        <tr valign="top">
                            <th scope="row">SendGrid API Key</th>
                            <td>
                                <input type="password" name="pillpalnow_sendgrid_key"
                                    value="<?php echo esc_attr(get_option('pillpalnow_sendgrid_key')); ?>" class="regular-text" />
                                <p class="description">Emergency Backup.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>

                <hr>
                <h2>🧪 Test Email</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pillpalnow_test_email_action', 'pillpalnow_nonce'); ?>
                    <input type="hidden" name="action" value="pillpalnow_test_email">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Send Test To</th>
                            <td>
                                <input type="email" name="test_email_to"
                                    value="<?php echo esc_attr(get_bloginfo('admin_email')); ?>" class="regular-text"
                                    required />
                                <?php submit_button('Send Test Email', 'secondary', 'send_test', false); ?>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>

            <!-- Logs Tab -->
            <div id="tab-logs" class="pillpalnow-tab-content <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                <h2>📋 Email Logs</h2>

                <!-- Stats Cards -->
                <div class="pillpalnow-stats-grid">
                    <div class="pillpalnow-stat-card">
                        <h3>Total Emails (30 days)</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['total']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card success">
                        <h3>Successful</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['success']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card failed">
                        <h3>Failed</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['failed']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card">
                        <h3>Success Rate</h3>
                        <div class="stat-value">
                            <?php echo $log_stats['total'] > 0 ? round(($log_stats['success'] / $log_stats['total']) * 100, 1) : 0; ?>%
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <form method="get" class="pillpalnow-log-filters">
                    <input type="hidden" name="page" value="pillpalnow-smart-api">
                    <input type="hidden" name="tab" value="logs">

                    <select name="log_status">
                        <option value="">All Status</option>
                        <option value="success" <?php selected($log_filters['status'], 'success'); ?>>Success</option>
                        <option value="failed" <?php selected($log_filters['status'], 'failed'); ?>>Failed</option>
                    </select>

                    <select name="log_provider">
                        <option value="">All Providers</option>
                        <option value="sparkpost" <?php selected($log_filters['provider'], 'sparkpost'); ?>>SparkPost</option>
                        <option value="ses" <?php selected($log_filters['provider'], 'ses'); ?>>Amazon SES</option>
                        <option value="sendgrid" <?php selected($log_filters['provider'], 'sendgrid'); ?>>SendGrid</option>
                        <option value="all_failed" <?php selected($log_filters['provider'], 'all_failed'); ?>>All Failed
                        </option>
                    </select>

                    <select name="log_type">
                        <option value="">All Types</option>
                        <option value="refill" <?php selected($log_filters['email_type'], 'refill'); ?>>Refill</option>
                        <option value="magic_link" <?php selected($log_filters['email_type'], 'magic_link'); ?>>Magic Link
                        </option>
                        <option value="reminder" <?php selected($log_filters['email_type'], 'reminder'); ?>>Reminder</option>
                        <option value="test" <?php selected($log_filters['email_type'], 'test'); ?>>Test</option>
                        <option value="general" <?php selected($log_filters['email_type'], 'general'); ?>>General</option>
                    </select>

                    <input type="date" name="date_from" value="<?php echo esc_attr($log_filters['date_from']); ?>"
                        placeholder="From Date">
                    <input type="date" name="date_to" value="<?php echo esc_attr($log_filters['date_to']); ?>"
                        placeholder="To Date">
                    <input type="text" name="search" value="<?php echo esc_attr($log_filters['search']); ?>"
                        placeholder="Search recipient/subject...">

                    <button type="submit" class="button">Filter</button>
                    <a href="<?php echo admin_url('options-general.php?page=pillpalnow-smart-api&tab=logs'); ?>"
                        class="button">Reset</a>
                </form>

                <!-- Actions -->
                <div style="margin-bottom: 15px;">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                        <?php wp_nonce_field('pillpalnow_clear_logs_action', 'pillpalnow_clear_nonce'); ?>
                        <input type="hidden" name="action" value="pillpalnow_clear_logs">
                        <select name="clear_days">
                            <option value="7">Older than 7 days</option>
                            <option value="30">Older than 30 days</option>
                            <option value="90">Older than 90 days</option>
                            <option value="0">All logs</option>
                        </select>
                        <button type="submit" class="button"
                            onclick="return confirm('Are you sure you want to clear logs?');">Clear Logs</button>
                    </form>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>"
                        style="display: inline; margin-left: 10px;">
                        <?php wp_nonce_field('pillpalnow_export_logs_action', 'pillpalnow_export_nonce'); ?>
                        <input type="hidden" name="action" value="pillpalnow_export_logs">
                        <button type="submit" class="button">📥 Export CSV</button>
                    </form>
                </div>

                <!-- Logs Table -->
                <table class="pillpalnow-log-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px;">No email logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('M j, Y g:i A', strtotime($log->created_at))); ?></td>
                                    <td><?php echo esc_html($log->recipient); ?></td>
                                    <td><?php echo esc_html(substr($log->subject, 0, 50)) . (strlen($log->subject) > 50 ? '...' : ''); ?>
                                    </td>
                                    <td><span class="type-badge"><?php echo esc_html($log->email_type); ?></span></td>
                                    <td><span class="provider-badge"><?php echo esc_html($log->provider); ?></span></td>
                                    <td><span
                                            class="status-badge <?php echo $log->status === 'success' ? 'success' : 'failed'; ?>"><?php echo esc_html($log->status); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log->response)): ?>
                                            <div class="pillpalnow-response-preview" data-full="<?php echo esc_attr($log->response); ?>">
                                                <?php echo esc_html(substr($log->response, 0, 50)) . (strlen($log->response) > 50 ? '...' : ''); ?>
                                            </div>
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

            <!-- Statistics Tab -->
            <div id="tab-stats" class="pillpalnow-tab-content <?php echo $active_tab === 'stats' ? 'active' : ''; ?>">
                <h2>📊 Email Statistics (Last 30 Days)</h2>

                <div class="pillpalnow-stats-grid">
                    <div class="pillpalnow-stat-card">
                        <h3>Total Sent</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['total']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card success">
                        <h3>Successful</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['success']); ?></div>
                    </div>
                    <div class="pillpalnow-stat-card failed">
                        <h3>Failed</h3>
                        <div class="stat-value"><?php echo number_format($log_stats['failed']); ?></div>
                    </div>
                </div>

                <h3>By Provider</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Successful</th>
                            <th>Failed</th>
                            <th>Total</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_stats['by_provider'] as $provider => $provider_stats): ?>
                            <?php $provider_total = ($provider_stats['success'] ?? 0) + ($provider_stats['failed'] ?? 0); ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst($provider)); ?></strong></td>
                                <td style="color: green;"><?php echo number_format($provider_stats['success'] ?? 0); ?></td>
                                <td style="color: red;"><?php echo number_format($provider_stats['failed'] ?? 0); ?></td>
                                <td><?php echo number_format($provider_total); ?></td>
                                <td><?php echo $provider_total > 0 ? round((($provider_stats['success'] ?? 0) / $provider_total) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($log_stats['by_provider'])): ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">No data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h3 style="margin-top: 20px;">By Email Type</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Email Type</th>
                            <th>Successful</th>
                            <th>Failed</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_stats['by_type'] as $type => $type_stats): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $type))); ?></strong></td>
                                <td style="color: green;"><?php echo number_format($type_stats['success'] ?? 0); ?></td>
                                <td style="color: red;"><?php echo number_format($type_stats['failed'] ?? 0); ?></td>
                                <td><?php echo number_format(($type_stats['success'] ?? 0) + ($type_stats['failed'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($log_stats['by_type'])): ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Templates Tab -->
            <div id="tab-templates" class="pillpalnow-tab-content <?php echo $active_tab === 'templates' ? 'active' : ''; ?>">
                <h2>✉️ Email Templates</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('pillpalnow_smart_api_group'); ?>

                    <div class="card" style="max-width: 100%; margin-top: 20px;">
                        <h3>Family Invitation Email</h3>
                        <p class="description">Variables: <code>{name}</code>, <code>{inviter_name}</code>,
                            <code>{site_name}</code>, <code>{login_link}</code>
                        </p>
                        <?php
                        $content = get_option('pillpalnow_tmpl_invite', "Hi {name},\n\n{inviter_name} has invited you to join {site_name}.\n\nClick here to login: {login_link}");
                        wp_editor($content, 'pillpalnow_tmpl_invite', ['textarea_name' => 'pillpalnow_tmpl_invite', 'textarea_rows' => 10, 'media_buttons' => false]);
                        ?>
                    </div>

                    <div class="card" style="max-width: 100%; margin-top: 20px;">
                        <h3>Welcome Email</h3>
                        <p class="description">Variables: <code>{name}</code>, <code>{username}</code>, <code>{password}</code>,
                            <code>{login_url}</code>
                        </p>
                        <?php
                        $content = get_option('pillpalnow_tmpl_welcome', "Welcome {name}!\n\nYour account has been created.\nUsername: {username}\nPassword: {password}\n\nLogin here: {login_url}");
                        wp_editor($content, 'pillpalnow_tmpl_welcome', ['textarea_name' => 'pillpalnow_tmpl_welcome', 'textarea_rows' => 10, 'media_buttons' => false]);
                        ?>
                    </div>

                    <div class="card" style="max-width: 100%; margin-top: 20px;">
                        <h3>Password Reset Email</h3>
                        <p class="description">Variables: <code>{name}</code>, <code>{reset_link}</code></p>
                        <?php
                        $content = get_option('pillpalnow_tmpl_reset', "Hi {name},\n\nSomeone requested a password reset for your account.\n\nLink: {reset_link}");
                        wp_editor($content, 'pillpalnow_tmpl_reset', ['textarea_name' => 'pillpalnow_tmpl_reset', 'textarea_rows' => 10, 'media_buttons' => false]);
                        ?>
                    </div>

                    <div class="card" style="max-width: 100%; margin-top: 20px;">
                        <h3>Refill Alert Email</h3>
                        <p class="description">Variables: <code>{name}</code>, <code>{medication_name}</code>,
                            <code>{remaining}</code>
                        </p>
                        <?php
                        $content = get_option('pillpalnow_tmpl_refill', "Hello {name},\n\nYour medication '{medication_name}' is running low.\n\nRemaining: {remaining} pills\n\nPlease refill soon to avoid missing doses.");
                        wp_editor($content, 'pillpalnow_tmpl_refill', ['textarea_name' => 'pillpalnow_tmpl_refill', 'textarea_rows' => 10, 'media_buttons' => false]);
                        ?>
                    </div>

                    <div class="card" style="max-width: 100%; margin-top: 20px;">
                        <h3>Reminder / Fallback Email</h3>
                        <p class="description">Variables: <code>{name}</code>, <code>{message}</code> (e.g. "Time to take
                            Meds"), <code>{error_details}</code></p>
                        <?php
                        $content = get_option('pillpalnow_tmpl_reminder', "Hello {name},\n\n{message}\n\n(Sent via email because push notification failed or is disabled).\n\nDetails: {error_details}");
                        wp_editor($content, 'pillpalnow_tmpl_reminder', ['textarea_name' => 'pillpalnow_tmpl_reminder', 'textarea_rows' => 10, 'media_buttons' => false]);
                        ?>
                    </div>

                    <?php submit_button(); ?>
                </form>
            </div>

            <!-- Cron Status Tab -->
            <div id="tab-cron" class="pillpalnow-tab-content <?php echo $active_tab === 'cron' ? 'active' : ''; ?>">
                <h2>⏰ Cron Job Status</h2>
                <div class="card" style="max-width: 100%;">
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Hook Name</th>
                                <th>Schedule</th>
                                <th>Next Run (GMT)</th>
                                <th>Next Run (Relative)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $crons = _get_cron_array();
                            $target_hooks = ['pillpalnow_daily_reset', 'wp_scheduled_delete'];
                            $found = false;

                            foreach ($crons as $timestamp => $cronhooks) {
                                foreach ($cronhooks as $hook => $keys) {
                                    if (strpos($hook, 'pillpalnow') !== false || in_array($hook, $target_hooks)) {
                                        $found = true;
                                        foreach ($keys as $k => $v) {
                                            $schedule = isset($v['schedule']) ? $v['schedule'] : 'User/Single';
                                            echo '<tr>';
                                            echo '<td><strong>' . esc_html($hook) . '</strong></td>';
                                            echo '<td>' . esc_html($schedule) . '</td>';
                                            echo '<td>' . date('Y-m-d H:i:s', $timestamp) . '</td>';
                                            echo '<td>' . human_time_diff(time(), $timestamp) . '</td>';
                                            echo '</tr>';
                                        }
                                    }
                                }
                            }
                            if (!$found) {
                                echo '<tr><td colspan="4">No PillPalNow cron jobs found.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 10px;">Note: WordPress triggers cron jobs on page load. Times are
                        approximate.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_test_email()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('pillpalnow_test_email_action', 'pillpalnow_nonce');

        $to = sanitize_email($_POST['test_email_to']);
        $subject = 'PillPalNow Smart API Test - ' . date('Y-m-d H:i:s');
        $message = 'This is a test email from the PillPalNow Smart API plugin to verify failover configuration.';

        // Set email context for logging
        $this->set_email_context('test', get_current_user_id());

        // wp_mail will trigger our pre_wp_mail hook
        $sent = wp_mail($to, $subject, $message);

        // Retrieve logs from the instance
        $logs = $this->last_logs;
        $log_msg = implode(' | ', $logs);

        if ($sent) {
            // If sent=true, it means either Smart API handled it OR it fell back to PHPMailer
            // Check logs to see if WE handled it
            if (strpos($log_msg, 'Success') !== false) {
                add_settings_error('pillpalnow_smart_api_group', 'pillpalnow_test_success', "Test email sent successfully via Smart API! Log: $log_msg", 'success');
            } else {
                // It failed us, but WP sent it via fallback
                add_settings_error('pillpalnow_smart_api_group', 'pillpalnow_test_warning', "Smart API Failed (see logs), but WordPress sent it via fallback (PHPMailer). Log: $log_msg", 'warning');
            }
        } else {
            add_settings_error('pillpalnow_smart_api_group', 'pillpalnow_test_fail', "Test email failed completely. Log: $log_msg", 'error');
        }

        set_transient('pillpalnow_test_email_logs', $log_msg, 60); // Store for display if redirect loses it (safeguard)
        wp_redirect(add_query_arg(['msg' => $sent ? 'sent' : 'fail', 'tab' => 'logs'], admin_url('options-general.php?page=pillpalnow-smart-api')));
        exit;
    }

    /**
     * Handle clear logs action
     */
    public function handle_clear_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('pillpalnow_clear_logs_action', 'pillpalnow_clear_nonce');

        $days = isset($_POST['clear_days']) ? intval($_POST['clear_days']) : 30;
        error_log("PillPalNow Clear Logs Requested. Days: $days. POST: " . print_r($_POST, true));

        $deleted = $this->clear_logs($days);

        if ($deleted > 0) {
            add_settings_error('pillpalnow_smart_api_group', 'pillpalnow_logs_cleared', "Successfully cleared $deleted log entries.", 'success');
        } else {
            $msg = ($days > 0) ? "No logs were cleared. You selected 'Older than $days days', so recent logs were preserved." : "No logs found to clear.";
            add_settings_error('pillpalnow_smart_api_group', 'pillpalnow_logs_cleared', $msg, 'warning');
        }
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_redirect(admin_url('options-general.php?page=pillpalnow-smart-api&tab=logs&msg=cleared'));
        exit;
    }

    /**
     * Handle export logs action
     */
    public function handle_export_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('pillpalnow_export_logs_action', 'pillpalnow_export_nonce');

        $logs = $this->get_logs([], 10000); // Get up to 10k logs

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pillpalnow-email-logs-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Date/Time', 'Recipient', 'Subject', 'Provider', 'Status', 'Type', 'User ID', 'Response']);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log->id,
                $log->created_at,
                $log->recipient,
                $log->subject,
                $log->provider,
                $log->status,
                $log->email_type,
                $log->user_id,
                $log->response,
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Activation Hook
     */
    public static function activate()
    {
        if (!wp_next_scheduled('pillpalnow_daily_reset')) {
            wp_schedule_event(time(), 'daily', 'pillpalnow_daily_reset');
        }

        // Create table on activation
        $instance = self::get_instance();
        $instance->maybe_create_table();
    }

    /**
     * Deactivation Hook
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook('pillpalnow_daily_reset');
    }

    /**
     * Provider: SparkPost
     */
    private function send_sparkpost($to, $subject, $message, $key)
    {
        $url = 'https://api.sparkpost.com/api/v1/transmissions';
        $body = [
            'content' => [
                'from' => $this->get_from_email(),
                'subject' => $subject,
                'html' => $message,
            ],
            'recipients' => [
                ['address' => $to]
            ]
        ];

        $args = [
            'headers' => [
                'Authorization' => $key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'timeout' => 8
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->last_logs[] = '❌ SparkPost Connection Error: ' . $response->get_error_message();
            return ['success' => false, 'response' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code != 200) {
            $this->last_logs[] = '❌ SparkPost API Error: HTTP ' . $code;
            return ['success' => false, 'response' => "HTTP $code: $body"];
        }

        return ['success' => true, 'response' => $body];
    }

    /**
     * Provider: SendGrid
     */
    private function send_sendgrid($to, $subject, $message, $key)
    {
        $url = 'https://api.sendgrid.com/v3/mail/send';
        $body = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]]
                ]
            ],
            'from' => ['email' => $this->get_from_email()],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $message
                ]
            ]
        ];

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'timeout' => 6
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->last_logs[] = '❌ SendGrid Connection Error: ' . $response->get_error_message();
            return ['success' => false, 'response' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $this->last_logs[] = '❌ SendGrid API Error: HTTP ' . $code;
            return ['success' => false, 'response' => "HTTP $code: $body"];
        }

        return ['success' => true, 'response' => "HTTP $code: Success"];
    }

    /**
     * Provider: Amazon SES (AWS4 Signature)
     */
    private function send_ses($to, $subject, $message, $key, $secret, $region)
    {
        $host = "email.{$region}.amazonaws.com";
        $endpoint = "https://{$host}";
        $service = 'ses';
        $alg = 'AWS4-HMAC-SHA256';
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');

        $params = [
            'Action' => 'SendEmail',
            'Source' => $this->get_from_email(),
            'Destination.ToAddresses.member.1' => $to,
            'Message.Subject.Data' => $subject,
            'Message.Body.Html.Data' => $message
        ];
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $canonical_uri = '/';
        $canonical_querystring = $query;
        $canonical_headers = "host:{$host}\nx-amz-date:{$date}\n";
        $signed_headers = 'host;x-amz-date';
        $payload_hash = hash('sha256', '');
        $canonical_request = "POST\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

        $credential_scope = "{$short_date}/{$region}/{$service}/aws4_request";
        $string_to_sign = "{$alg}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

        $k_secret = "AWS4" . $secret;
        $k_date = hash_hmac('sha256', $short_date, $k_secret, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization = "{$alg} Credential={$key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

        $args = [
            'headers' => [
                'Authorization' => $authorization,
                'X-Amz-Date' => $date,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => '',
            'timeout' => 8
        ];

        // POST body override for safety
        $payload = $query;
        $payload_hash = hash('sha256', $payload);
        $canonical_querystring = '';
        $canonical_request = "POST\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
        $string_to_sign = "{$alg}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        $authorization = "{$alg} Credential={$key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

        $args['body'] = $payload;
        $args['headers']['Authorization'] = $authorization;

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            $this->last_logs[] = '❌ SES Connection Error: ' . $response->get_error_message();
            return ['success' => false, 'response' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code != 200) {
            $msg = 'HTTP ' . $code;
            if (preg_match('/<Message>(.*?)<\/Message>/', $body, $m)) {
                $msg .= ' (' . $m[1] . ')';
            }
            $this->last_logs[] = '❌ SES API Error: ' . $msg;
            return ['success' => false, 'response' => $msg];
        }

        return ['success' => true, 'response' => 'Success'];
    }

    private function log_emergency($to, $subject, $error)
    {
        $log_file = WP_CONTENT_DIR . '/pillpalnow-emergency.log';
        $entry = sprintf("[%s] FAIL: To: %s | Subject: %s | Error: %s\n", date('Y-m-d H:i:s'), $to, $subject, $error);
        @file_put_contents($log_file, $entry, FILE_APPEND);
    }

    /**
     * Get the 'From' email address.
     * Priority: Constant > Option > Admin Email
     */
    private function get_from_email()
    {
        if (defined('DOSEMED_FROM_EMAIL') && DOSEMED_FROM_EMAIL) {
            return DOSEMED_FROM_EMAIL;
        }

        $option = get_option('pillpalnow_from_email');
        if (!empty($option)) {
            return $option;
        }

        return get_bloginfo('admin_email');
    }
}

// Global Init
function pillpalnow_smart_api_init()
{
    return PillPalNowSmartAPI::get_instance();
}
// Initialize
pillpalnow_smart_api_init();

// Registration Hooks
register_activation_hook(__FILE__, ['PillPalNowSmartAPI', 'activate']);
register_deactivation_hook(__FILE__, ['PillPalNowSmartAPI', 'deactivate']);

/**
 * Helper function to set email context before sending
 *
 * @param string $type Email type (refill, magic_link, test, reminder, etc.)
 * @param int $user_id Associated user ID
 */
function pillpalnow_set_email_context($type, $user_id = 0)
{
    $api = PillPalNowSmartAPI::get_instance();
    $api->set_email_context($type, $user_id);
}
