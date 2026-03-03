<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Family Share Handler
 * 
 * Handles sharing dose history via email.
 * 
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Family_Share
{
    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_pillpalnow_share_history', array($this, 'handle_share_history'));
    }

    /**
     * AJAX Handler: Share History
     */
    public function handle_share_history()
    {
        // 1. Security Checks
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        check_ajax_referer('pillpalnow_share_nonce', 'nonce');

        if (!class_exists('PillPalNow_Permissions') || !PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_VIEW_HISTORY, true)) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        // 2. Validate Inputs
        $member_id_input = isset($_POST['member_id']) ? sanitize_text_field($_POST['member_id']) : 'all';
        $month_input = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : date('Y-m');
        $emails_input = isset($_POST['emails']) ? sanitize_text_field($_POST['emails']) : '';

        if (empty($emails_input)) {
            wp_send_json_error(array('message' => 'Please provide at least one email address.'));
        }

        // Parse Emails
        $emails = array_map('trim', explode(',', $emails_input));
        $valid_emails = [];
        foreach ($emails as $email) {
            if (is_email($email)) {
                $valid_emails[] = $email;
            }
        }

        if (empty($valid_emails)) {
            wp_send_json_error(array('message' => 'No valid email addresses found.'));
        }

        $current_user_id = get_current_user_id();

        // 3. Resolve Medications
        // Logic adapted from page-history.php "get_medications_for_history"
        $args_meds = array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'author' => $current_user_id, // Only my authored meds (includes family members I manage)
        );

        $meta_query = array('relation' => 'OR');

        if ($member_id_input === 'me') {
            $meta_query[] = array('key' => 'assigned_user_id', 'value' => $current_user_id);
        } elseif (is_numeric($member_id_input)) {
            $meta_query[] = array('key' => 'family_member_id', 'value' => $member_id_input);
        } else {
            // All: My meds OR My Family's Meds
            // Just filtering by author covers "My Managed Family" context usually, 
            // but let's be explicit like page-history.php if possible.
            // Simplest approach: Query all meds where I am author.
            // If I am a family member (managed user), I am author of my meds? 
            // In PillPalNow, typically Parent is Author of even Family Member meds.
            // The query 'author' => $current_user_id covers it.
        }

        $args_meds['meta_query'] = $meta_query;
        $medications = get_posts($args_meds);

        if (empty($medications)) {
            wp_send_json_error(array('message' => 'No medications found to share history for.'));
        }

        $med_ids = wp_list_pluck($medications, 'ID');

        // 4. Fetch Logs
        // Month range
        $ts_month = strtotime($month_input . '-01');
        $start_date = date('Y-m-01', $ts_month);
        $end_date = date('Y-m-t', $ts_month);

        $args_logs = array(
            'post_type' => 'dose_log',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'log_date',
                    'value' => array($start_date, $end_date),
                    'compare' => 'BETWEEN',
                    'type' => 'DATE'
                ),
                array(
                    'key' => 'medication_id',
                    'value' => $med_ids,
                    'compare' => 'IN'
                )
            ),
            'orderby' => 'meta_value',
            'meta_key' => 'log_date', // basic sort by date
            'order' => 'DESC'
        );

        $logs = get_posts($args_logs);

        // Sort more precisely by date + time?
        // meta_value sort above sorts by Date.
        // We can do PHP sort for Date + Time if needed, or just rely on Date.
        // Let's refine in PHP.

        usort($logs, function ($a, $b) {
            $date_a = get_post_meta($a->ID, 'log_date', true);
            $time_a = get_post_meta($a->ID, 'log_time', true);
            $date_b = get_post_meta($b->ID, 'log_date', true);
            $time_b = get_post_meta($b->ID, 'log_time', true);

            $ts_a = strtotime("$date_a $time_a");
            $ts_b = strtotime("$date_b $time_b");

            return $ts_b - $ts_a; // DESC
        });

        // 5. Build Email Content
        $subject = 'Medication Dose History Shared';
        $site_name = get_bloginfo('name');

        $month_label = date('F Y', $ts_month);

        // Inline CSS for Email Table
        $style_table = 'width: 100%; border-collapse: collapse; margin-top: 10px;';
        $style_th = 'background: #f3f4f6; padding: 10px; border: 1px solid #ddd; text-align: left;';
        $style_td = 'padding: 10px; border: 1px solid #ddd;';

        $html = "<h2>Dose History Report - {$month_label}</h2>";
        $html .= "<p>Shared from <strong>{$site_name}</strong> by " . wp_get_current_user()->display_name . ".</p>";

        if (empty($logs)) {
            $html .= "<p>No logs found for this period.</p>";
        } else {
            $html .= "<table style='{$style_table}'>";
            $html .= "<thead><tr>";
            $html .= "<th style='{$style_th}'>Date</th>";
            $html .= "<th style='{$style_th}'>Time</th>";
            $html .= "<th style='{$style_th}'>Medication</th>";
            $html .= "<th style='{$style_th}'>Status</th>";
            $html .= "</tr></thead><tbody>";

            foreach ($logs as $log) {
                $med_id = get_post_meta($log->ID, 'medication_id', true);
                $med_title = get_the_title($med_id);
                $date = get_post_meta($log->ID, 'log_date', true);
                $time = get_post_meta($log->ID, 'log_time', true);
                $status = ucfirst(get_post_meta($log->ID, 'status', true));

                // Colorize status
                $status_style = 'color: #333;';
                if ($status === 'Taken')
                    $status_style = 'color: green; font-weight: bold;';
                elseif ($status === 'Missed')
                    $status_style = 'color: red;';
                elseif ($status === 'Skipped')
                    $status_style = 'color: orange;';

                $html .= "<tr>";
                $html .= "<td style='{$style_td}'>" . date('M j, Y', strtotime($date)) . "</td>";
                $html .= "<td style='{$style_td}'>{$time}</td>";
                $html .= "<td style='{$style_td}'>{$med_title}</td>";
                $html .= "<td style='{$style_td}'><span style='{$status_style}'>{$status}</span></td>";
                $html .= "</tr>";
            }
            $html .= "</tbody></table>";
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Set PillPalNow Context if available
        if (class_exists('PillPalNowSmartAPI') && method_exists('PillPalNowSmartAPI', 'get_instance')) {
            PillPalNowSmartAPI::get_instance()->set_email_context('history_share', $current_user_id);
        }

        // Send
        $sent_count = 0;
        foreach ($valid_emails as $to) {
            if (wp_mail($to, $subject, $html, $headers)) {
                $sent_count++;
            }
        }

        if ($sent_count > 0) {
            wp_send_json_success(array('message' => "History shared successfully with {$sent_count} recipient(s)."));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email. Please check server configuration.'));
        }
    }
}

// Initialize
PillPalNow_Family_Share::instance();
