<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_PDF_Reports
 * Handles generation of PDF reports (Activity/Logs)
 */
class PillPalNow_PDF_Reports
{
    public static function init()
    {
        // Endpoint for Report View
        add_action('admin_post_pillpalnow_download_report', array(__CLASS__, 'handle_download_report'));
    }

    public static function handle_download_report()
    {
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url());
            exit;
        }

        $user_id = get_current_user_id();

        // Check Pro Status - REMOVED for Free Plan Un-gating
        // $is_pro = class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user($user_id);

        // Always generate Report
        self::render_full_report($user_id);
        exit;
    }

    /* Deprecated Locked Preview - Unused after un-gating */
    private static function render_locked_preview_unused()
    {
        // No-op
    }

    private static function render_locked_preview()
    {
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <title>Report Preview - Pro Feature</title>
            <style>
                body {
                    font-family: sans-serif;
                    background: #f0f2f5;
                    text-align: center;
                    padding: 50px;
                }

                .preview-container {
                    filter: blur(4px);
                    opacity: 0.5;
                    pointer-events: none;
                    user-select: none;
                }

                .lock-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 10;
                }

                .card {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
                    max-width: 400px;
                    text-align: center;
                }

                .btn {
                    background: #6b21a8;
                    color: white;
                    text-decoration: none;
                    padding: 12px 24px;
                    border-radius: 5px;
                    display: inline-block;
                    font-weight: bold;
                    margin-top: 20px;
                }
            </style>
        </head>

        <body>
            <div class="lock-overlay">
                <div class="card">
                    <h1>🔒 Pro Feature</h1>
                    <p>Unlock detailed activity reports and usage history with PillPalNow Pro.</p>
                    <a href="<?php echo home_url('/checkout'); ?>" class="btn">Upgrade for $2.99/mo</a>
                </div>
            </div>
            <div class="preview-container">
                <h1>Medication Activity Report</h1>
                <p>Generated for: John Doe</p>
                <table border="1" cellpadding="10" style="width: 100%; margin: 20px auto; border-collapse: collapse;">
                    <tr>
                        <th>Date</th>
                        <th>Medication</th>
                        <th>Status</th>
                    </tr>
                    <tr>
                        <td>2023-10-01</td>
                        <td>Aspirin</td>
                        <td>Taken</td>
                    </tr>
                    <tr>
                        <td>2023-10-01</td>
                        <td>Vitamin C</td>
                        <td>Taken</td>
                    </tr>
                    <tr>
                        <td>2023-10-02</td>
                        <td>Aspirin</td>
                        <td>Skipped</td>
                    </tr>
                    <tr>
                        <td>2023-10-02</td>
                        <td>Vitamin C</td>
                        <td>Taken</td>
                    </tr>
                    <tr>
                        <td>2023-10-03</td>
                        <td>Aspirin</td>
                        <td>Taken</td>
                    </tr>
                </table>
            </div>
        </body>

        </html>
        <?php
    }

    private static function render_full_report($user_id)
    {
        // Check for Filter
        $member_id_filter = isset($_REQUEST['member_id']) ? sanitize_text_field($_REQUEST['member_id']) : 'all';
        $report_user_id = $user_id; // Default to Parent
        $report_name = get_user_meta($user_id, 'first_name', true) . ' ' . get_user_meta($user_id, 'last_name', true);
        if (empty(trim($report_name)))
            $report_name = get_userdata($user_id)->display_name;

        // Query Args
        $args = array(
            'post_type' => 'dose_log',
            'posts_per_page' => -1, // Export ALL
            'orderby' => 'date',
            'order' => 'DESC'
        );

        if ($member_id_filter === 'me') {
            // Logs for meds assigned to Parent ONLY.
            // Problem: Logs don't strictly have "assigned_to", they have "medication_id".
            // We need to find meds assigned to Parent.
            $report_name .= " (Self)";
            $meds = get_posts(array(
                'post_type' => 'medication',
                'meta_key' => 'assigned_user_id',
                'meta_value' => $user_id,
                'fields' => 'ids',
                'posts_per_page' => -1
            ));
            if (!empty($meds)) {
                $args['meta_query'] = array(
                    array('key' => 'medication_id', 'value' => $meds, 'compare' => 'IN')
                );
            } else {
                $args['post__in'] = [0]; // Force empty
            }

        } elseif (is_numeric($member_id_filter)) {
            // Specific Family Member
            // 1. Verify ownership
            $fm = get_post($member_id_filter);
            if ($fm && $fm->post_author == $user_id && $fm->post_type === 'family_member') {
                $report_name = $fm->post_title;

                // Find meds linked to this family member
                $meds = get_posts(array(
                    'post_type' => 'medication',
                    'meta_key' => 'family_member_id',
                    'meta_value' => $member_id_filter,
                    'fields' => 'ids',
                    'posts_per_page' => -1
                ));

                // Also could include meds assigned to their linked user ID if any
                // But simpliest is filter logs by meds attached to this family member profile

                if (!empty($meds)) {
                    $args['meta_query'] = array(
                        array('key' => 'medication_id', 'value' => $meds, 'compare' => 'IN')
                    );
                } else {
                    $args['post__in'] = [0];
                }
            } else {
                wp_die('Unauthorized access to family member report.');
            }
        } else {
            // ALL (Default)
            // Show all logs authored by parent (which covers all managed family meds usually)
            $args['author'] = $user_id;
            $report_name .= " (All Family)";
        }

        $logs = get_posts($args);

        header('Content-Type: text/html');
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <title>PillPalNow Activity Report</title>
            <style>
                body {
                    font-family: sans-serif;
                    padding: 40px;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }

                th,
                td {
                    border: 1px solid #ddd;
                    padding: 12px;
                    text-align: left;
                }

                th {
                    background-color: #f3f4f6;
                }

                .header {
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }

                .print-btn {
                    background: #333;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    cursor: pointer;
                    float: right;
                }

                @media print {
                    .print-btn {
                        display: none;
                    }
                }
            </style>
        </head>

        <body>
            <div class="header">
                <button class="print-btn" onclick="window.print()">Download / Print PDF</button>
                <h1>Medication Activity Report</h1>
                <p>User:
                    <?php echo esc_html($report_name); ?>
                </p>
                <p>Date Generated:
                    <?php echo date('Y-m-d H:i:s'); ?>
                </p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Member</th>
                        <th>Medication</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6">No active logs found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log):
                            $med_id = get_post_meta($log->ID, 'medication_id', true);
                            $date = get_post_meta($log->ID, 'log_date', true);
                            $time = get_post_meta($log->ID, 'log_time', true);
                            $status = get_post_meta($log->ID, 'status', true);
                            $notes = get_post_meta($log->ID, 'notes', true);

                            // Determine Member Name
                            $assigned_uid = get_post_meta($med_id, 'assigned_user_id', true);
                            $fam_id = get_post_meta($med_id, 'family_member_id', true);
                            $mem_name = '-';

                            if ($fam_id) {
                                $mem_name = get_the_title($fam_id);
                            } elseif ($assigned_uid) {
                                $u = get_userdata($assigned_uid);
                                $mem_name = $u ? $u->display_name : 'Self';
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($date); ?>
                                </td>
                                <td>
                                    <?php echo esc_html(date('h:i A', strtotime($time))); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($mem_name); ?>
                                </td>
                                <td>
                                    <?php echo esc_html(get_the_title($med_id)); ?>
                                </td>
                                <td>
                                    <?php echo ucfirst(esc_html($status)); ?>
                                </td>
                                <td>
                                    <?php echo esc_html($notes); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </body>

        </html>
        <?php
    }
}
