<?php
/**
 * PillPalNow Cron Health Monitor
 *
 * Bulletproofs WP Cron by:
 * 1. Recording heartbeats every time critical cron jobs run
 * 2. Self-healing: re-scheduling dead crons on every admin page load
 * 3. Admin dashboard widget showing cron health status
 * 4. Email alert to admin when a cron job hasn't run in 2× its interval
 *
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

class PillPalNow_Cron_Monitor
{
    /** @var string Option key for heartbeat data */
    const HEARTBEAT_OPTION = 'pillpalnow_cron_heartbeats';

    /** @var string Option key for alert cooldown */
    const ALERT_COOLDOWN_OPTION = 'pillpalnow_cron_alert_cooldown';

    /** @var int Alert cooldown in seconds (6 hours — avoids spam) */
    const ALERT_COOLDOWN = 21600;

    /**
     * Critical cron jobs to monitor
     * hook_name => [label, expected_interval_seconds]
     */
    private static $monitored_crons = [
        'pillpalnow_daily_event'          => ['Dose Reminders',        86400],
        'pillpalnow_daily_cleanup'        => ['Daily Cleanup',         86400],
        'pillpalnow_refill_check'         => ['Refill Check',          86400],
        'pillpalnow_missed_dose_check'    => ['Missed Dose Check',     900],  // 15 min
        'pillpalnow_loyalty_coupon_check'=> ['Loyalty Coupon Check', 86400],
    ];

    /**
     * Initialize — hooks into WordPress
     */
    public static function init()
    {
        // Record heartbeat after each monitored cron runs
        foreach (array_keys(self::$monitored_crons) as $hook) {
            add_action($hook, [__CLASS__, 'record_heartbeat'], 999);
        }

        // Self-heal: check & re-schedule on admin_init (admin page loads only)
        add_action('admin_init', [__CLASS__, 'self_heal']);

        // Dashboard widget
        add_action('wp_dashboard_setup', [__CLASS__, 'add_dashboard_widget']);

        // AJAX endpoint for manual re-schedule
        add_action('wp_ajax_pillpalnow_reschedule_cron', [__CLASS__, 'ajax_reschedule_cron']);

        // Register our own heartbeat cron (runs every 6 hours as a safety net)
        if (!wp_next_scheduled('pillpalnow_cron_health_check')) {
            wp_schedule_event(time(), 'twicedaily', 'pillpalnow_cron_health_check');
        }
        add_action('pillpalnow_cron_health_check', [__CLASS__, 'self_heal']);
    }

    /**
     * Record that a cron job just ran
     */
    public static function record_heartbeat()
    {
        $current_hook = current_filter();
        $heartbeats = get_option(self::HEARTBEAT_OPTION, []);
        $heartbeats[$current_hook] = time();
        update_option(self::HEARTBEAT_OPTION, $heartbeats, false);
    }

    /**
     * Self-heal: check all monitored crons, re-schedule any that are missing
     */
    public static function self_heal()
    {
        $rescheduled = [];

        foreach (self::$monitored_crons as $hook => $config) {
            if (!wp_next_scheduled($hook)) {
                // Determine interval name
                $interval = self::get_wp_interval_name($config[1]);
                wp_schedule_event(time(), $interval, $hook);
                $rescheduled[] = $config[0];
                error_log("[PillPalNow Cron Monitor] Re-scheduled dead cron: $hook ({$config[0]})");
            }
        }

        // Send admin alert if any crons were rescheduled
        if (!empty($rescheduled)) {
            self::send_alert(
                'Cron Jobs Auto-Recovered',
                sprintf(
                    "The following cron jobs were found dead and have been automatically rescheduled:\n\n• %s\n\nNo action is needed — they are running again.",
                    implode("\n• ", $rescheduled)
                )
            );
        }

        // Also check for overdue crons (scheduled but haven't run in 2× interval)
        self::check_overdue_crons();
    }

    /**
     * Check for crons that are scheduled but haven't run in 2× their expected interval
     */
    private static function check_overdue_crons()
    {
        $heartbeats = get_option(self::HEARTBEAT_OPTION, []);
        $overdue = [];

        foreach (self::$monitored_crons as $hook => $config) {
            $last_run = isset($heartbeats[$hook]) ? $heartbeats[$hook] : 0;
            $max_age = $config[1] * 2; // 2× the expected interval

            if ($last_run > 0 && (time() - $last_run) > $max_age) {
                $overdue[] = sprintf(
                    '%s — last ran %s ago (expected every %s)',
                    $config[0],
                    human_time_diff($last_run, time()),
                    human_time_diff(0, $config[1])
                );
            }
        }

        if (!empty($overdue)) {
            self::send_alert(
                'Cron Jobs Overdue Warning',
                sprintf(
                    "The following cron jobs appear to be running but are overdue:\n\n• %s\n\nThis may indicate WP-Cron is not firing reliably. Consider setting up a real system cron:\n\nwget -q -O /dev/null https://%s/wp-cron.php",
                    implode("\n• ", $overdue),
                    parse_url(home_url(), PHP_URL_HOST)
                )
            );
        }
    }

    /**
     * Send admin alert email (with cooldown to avoid spam)
     */
    private static function send_alert($subject, $message)
    {
        $cooldown = get_option(self::ALERT_COOLDOWN_OPTION, []);
        $key = md5($subject);

        if (isset($cooldown[$key]) && (time() - $cooldown[$key]) < self::ALERT_COOLDOWN) {
            return; // Still in cooldown
        }

        $admin_email = get_option('admin_email');
        $full_subject = '[PillPalNow] ' . $subject;
        $full_message = $message . "\n\n—\nPillPalNow Cron Monitor\n" . home_url();

        wp_mail($admin_email, $full_subject, $full_message);

        $cooldown[$key] = time();
        update_option(self::ALERT_COOLDOWN_OPTION, $cooldown, false);
    }

    /**
     * Add dashboard widget showing cron health
     */
    public static function add_dashboard_widget()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'pillpalnow_cron_health',
            '💊 PillPalNow Cron Health',
            [__CLASS__, 'render_dashboard_widget']
        );
    }

    /**
     * Render the dashboard widget
     */
    public static function render_dashboard_widget()
    {
        $heartbeats = get_option(self::HEARTBEAT_OPTION, []);
        $all_healthy = true;

        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<tr style="border-bottom:1px solid #ddd;"><th style="text-align:left;padding:6px;">Job</th><th style="text-align:left;padding:6px;">Status</th><th style="text-align:left;padding:6px;">Last Run</th><th style="text-align:left;padding:6px;">Next Run</th></tr>';

        foreach (self::$monitored_crons as $hook => $config) {
            $last_run = isset($heartbeats[$hook]) ? $heartbeats[$hook] : 0;
            $next_run = wp_next_scheduled($hook);
            $max_age = $config[1] * 2;

            // Determine status
            if (!$next_run) {
                $status = '<span style="color:#d63638;">⛔ Dead</span>';
                $all_healthy = false;
            } elseif ($last_run > 0 && (time() - $last_run) > $max_age) {
                $status = '<span style="color:#dba617;">⚠️ Overdue</span>';
                $all_healthy = false;
            } elseif ($last_run > 0) {
                $status = '<span style="color:#00a32a;">✅ Healthy</span>';
            } else {
                $status = '<span style="color:#787c82;">🔄 Pending</span>';
            }

            $last_display = $last_run ? human_time_diff($last_run) . ' ago' : 'Never';
            $next_display = $next_run ? human_time_diff(time(), $next_run) : '—';

            echo "<tr style='border-bottom:1px solid #f0f0f0;'>";
            echo "<td style='padding:6px;'><strong>{$config[0]}</strong></td>";
            echo "<td style='padding:6px;'>$status</td>";
            echo "<td style='padding:6px;'>$last_display</td>";
            echo "<td style='padding:6px;'>$next_display</td>";
            echo "</tr>";
        }

        echo '</table>';

        if (!$all_healthy) {
            echo '<p style="margin-top:10px;"><button type="button" class="button button-primary" onclick="jQuery.post(ajaxurl, {action:\'pillpalnow_reschedule_cron\',_wpnonce:\'' . wp_create_nonce('pillpalnow_reschedule') . '\'}, function(r){location.reload();});">🔧 Fix All Cron Jobs</button></p>';
        }

        echo '<p class="description" style="margin-top:8px;font-size:11px;">Self-healing runs automatically on every admin page load and twice daily.</p>';
    }

    /**
     * AJAX handler to manually re-schedule all cron jobs
     */
    public static function ajax_reschedule_cron()
    {
        check_ajax_referer('pillpalnow_reschedule', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $fixed = 0;
        foreach (self::$monitored_crons as $hook => $config) {
            // Unschedule existing (if stuck)
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }

            // Re-schedule fresh
            $interval = self::get_wp_interval_name($config[1]);
            wp_schedule_event(time(), $interval, $hook);
            $fixed++;
        }

        error_log("[PillPalNow Cron Monitor] Manual reschedule: $fixed cron jobs reset.");
        wp_send_json_success(['fixed' => $fixed]);
    }

    /**
     * Map seconds to WP cron interval name
     */
    private static function get_wp_interval_name($seconds)
    {
        if ($seconds <= 900) {
            return 'fifteen_minutes';
        }
        if ($seconds <= 3600) {
            return 'hourly';
        }
        if ($seconds <= 43200) {
            return 'twicedaily';
        }
        return 'daily';
    }

    /**
     * Get health summary for system status page
     *
     * @return array ['status' => 'healthy|warning|critical', 'details' => [...]]
     */
    public static function get_health_summary()
    {
        $heartbeats = get_option(self::HEARTBEAT_OPTION, []);
        $details = [];
        $worst_status = 'healthy';

        foreach (self::$monitored_crons as $hook => $config) {
            $last_run = isset($heartbeats[$hook]) ? $heartbeats[$hook] : 0;
            $next_run = wp_next_scheduled($hook);

            if (!$next_run) {
                $details[$hook] = ['status' => 'dead', 'label' => $config[0]];
                $worst_status = 'critical';
            } elseif ($last_run > 0 && (time() - $last_run) > ($config[1] * 2)) {
                $details[$hook] = ['status' => 'overdue', 'label' => $config[0]];
                if ($worst_status !== 'critical') {
                    $worst_status = 'warning';
                }
            } else {
                $details[$hook] = ['status' => 'healthy', 'label' => $config[0]];
            }
        }

        return ['status' => $worst_status, 'details' => $details];
    }
}
