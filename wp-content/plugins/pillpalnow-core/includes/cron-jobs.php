<?php
if (!defined('ABSPATH')) {
    exit;
}

// Fallback constants if not defined in main plugin
if (!defined('PILLPALNOW_REMINDER_WINDOW')) {
    define('PILLPALNOW_REMINDER_WINDOW', 900); // 15 minutes
}

require_once plugin_dir_path(__FILE__) . 'class-pillpalnow-onesignal-service.php';

// Deduplication time window in seconds (1 hour)
define('PILLPALNOW_DEDUP_WINDOW', 3600);

/**
 * 3. Refill Reminder Cron
 * Logic: If (quantity / daily_dose) <= 7 days -> Trigger OneSignal & FluentCRM
 */
function pillpalnow_daily_refill_check()
{
    $paged = 1;
    $batch_size = 50;

    while (true) {
        $meds = get_posts([
            'post_type' => 'medication',
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'post_status' => 'publish'
        ]);

        if (empty($meds)) {
            break;
        }

        foreach ($meds as $med) {
            $stock = (int) get_post_meta($med->ID, 'stock_quantity', true);
            $dose_times = get_post_meta($med->ID, 'dose_times', true);
            // Estimate dosage per day
            $daily_pills = 0;
            if (is_array($dose_times)) {
                foreach ($dose_times as $dt) {
                    $dosage = isset($dt['dosage']) && is_numeric($dt['dosage']) ? floatval($dt['dosage']) : 1;
                    $daily_pills += $dosage;
                }
            }

            if ($daily_pills <= 0) {
                continue;
            }

            $days_left = floor($stock / $daily_pills);

            if ($days_left <= 7) {
                pillpalnow_trigger_refill_reminder($med->ID, $days_left);
            }
        }
        $paged++;
        // Safety break
        if ($paged > 200)
            break;
    }
}
add_action('pillpalnow_daily_event', 'pillpalnow_daily_refill_check');

// Ensure cron is scheduled
if (!wp_next_scheduled('pillpalnow_daily_event')) {
    wp_schedule_event(time(), 'daily', 'pillpalnow_daily_event');
}

// Daily cleanup for notification logs
if (!wp_next_scheduled('pillpalnow_daily_cleanup')) {
    wp_schedule_event(time(), 'daily', 'pillpalnow_daily_cleanup');
}

function pillpalnow_trigger_refill_reminder($med_id, $days_left)
{
    // Prevent duplicate daily alerts
    $today = date('Y-m-d');
    $last_sent = get_post_meta($med_id, '_refill_reminder_sent_date', true);

    if ($last_sent === $today) {
        return;
    }

    $med_title = get_the_title($med_id);
    $user_id = get_post_field('post_author', $med_id);
    $user_info = get_userdata($user_id);

    $message = sprintf(__('Refill Alert: %s running low (%d days left)', 'pillpalnow'), $med_title, $days_left);

    // Send notification using updated function
    pillpalnow_send_notification($user_id, __('Refill Needed', 'pillpalnow'), $message, 'refill');

    update_post_meta($med_id, '_refill_reminder_sent_date', $today);
}

/**
 * --- REMINDER SYSTEM LOGIC ---
 */

/**
 * 1. Add 5 Minute Interval
 */
function pillpalnow_add_cron_schedules($schedules)
{
    $schedules['every_five_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'pillpalnow')
    );
    return $schedules;
}
add_filter('cron_schedules', 'pillpalnow_add_cron_schedules');

/**
 * 2. Main Reminder Check Function
 * Runs every 5 minutes
 */
/**
 * 2. Main Reminder Check Function
 * Runs every 5 minutes
 */
function pillpalnow_run_reminders_check()
{
    // A. Check for NEW Reminders
    pillpalnow_generate_reminders();

    // B. Check for Postponed Reminders
    pillpalnow_check_postponed_reminders();

    // C. Check for MISSED Doses
    pillpalnow_process_missed_reminders();
}
add_action('pillpalnow_five_minute_event', 'pillpalnow_run_reminders_check');

// Ensure cron is scheduled
if (!wp_next_scheduled('pillpalnow_five_minute_event')) {
    wp_schedule_event(time(), 'every_five_minutes', 'pillpalnow_five_minute_event');
}

/**
 * Logic A: Generate Reminders
 * Checks 'scheduled_datetime <= current_time' for all meds
 */
function pillpalnow_generate_reminders()
{
    date_default_timezone_set(get_option('timezone_string') ?: 'UTC');
    $current_time = current_time('timestamp');
    $current_date = date('Y-m-d', $current_time);

    $paged = 1;
    $batch_size = 50;

    while (true) {
        // Get Meds (Batched)
        $meds = get_posts([
            'post_type' => 'medication',
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'post_status' => 'publish'
        ]);

        if (empty($meds)) {
            break;
        }

        foreach ($meds as $med) {
            $schedule_type = get_post_meta($med->ID, 'schedule_type', true);
            $dose_times = get_post_meta($med->ID, 'dose_times', true);

            if (!is_array($dose_times))
                continue;

            // check if med is active for today
            $is_today = false;
            if ($schedule_type === 'daily') {
                $is_today = true;
            } elseif ($schedule_type === 'weekly') {
                $today_slug = strtolower(date('D', $current_time));
                $days = get_post_meta($med->ID, 'selected_weekdays', true);
                $start_date = get_post_meta($med->ID, 'start_date', true);

                if (is_array($days) && in_array($today_slug, $days)) {
                    if (!$start_date || $current_date >= $start_date) {
                        $is_today = true;
                    }
                }
            }
            // As Needed - No automatic reminders

            if (!$is_today)
                continue;

            // Check against dose times
            foreach ($dose_times as $dt) {
                $time = isset($dt['time']) ? $dt['time'] : '';
                if (!$time)
                    continue;

                $dose_ts = strtotime("$current_date $time");

                // If dose time is in the past ( <= now ) AND within last 15 minutes
                if ($dose_ts <= $current_time && $dose_ts > ($current_time - PILLPALNOW_REMINDER_WINDOW)) {

                    // OPTIMIZATION: Check Transient First to avoid DB query
                    $transient_key = "pillpalnow_rem_{$med->ID}_{$dose_ts}";
                    if (get_transient($transient_key)) {
                        continue;
                    }

                    // CHECK IF REMINDER LOG ALREADY EXISTS
                    $existing = get_posts([
                        'post_type' => 'reminder_log',
                        'meta_query' => [
                            'relation' => 'AND',
                            ['key' => 'medication_id', 'value' => $med->ID],
                            ['key' => 'scheduled_datetime', 'value' => $dose_ts],
                            ['key' => 'status', 'compare' => 'EXISTS'] // Check strictly if this specific instance exists
                        ],
                        'posts_per_page' => 1
                    ]);

                    if (empty($existing)) {
                        // Create Reminder Log
                        $user_id = get_post_field('post_author', $med->ID);
                        $assigned_user_id = get_post_meta($med->ID, 'assigned_user_id', true);
                        if ($assigned_user_id)
                            $user_id = $assigned_user_id;

                        $log_id = wp_insert_post([
                            'post_type' => 'reminder_log',
                            'post_title' => 'Reminder: ' . $med->post_title,
                            'post_status' => 'publish',
                            'post_author' => $user_id
                        ]);

                        update_post_meta($log_id, 'medication_id', $med->ID);
                        update_post_meta($log_id, 'user_id', $user_id);
                        update_post_meta($log_id, 'scheduled_datetime', $dose_ts);
                        update_post_meta($log_id, 'status', 'pending');

                        // Create in-app notification
                        if (class_exists('PillPalNow_Notifications')) {
                            $time_display = date('g:i A', $dose_ts);
                            PillPalNow_Notifications::create(
                                $user_id,
                                PillPalNow_Notifications::TYPE_REMINDER,
                                "Time to take {$med->post_title}",
                                "Scheduled for {$time_display}",
                                $med->ID,
                                home_url('/dashboard')
                            );
                        }

                        // SEND NOTIFICATION
                        $message = sprintf(__('Time to take your medicine: %s', 'pillpalnow'), $med->post_title);
                        pillpalnow_send_notification($user_id, __('Meds Due', 'pillpalnow'), $message, 'reminder');

                        // Set Transient to avoid re-querying this dose today (30 mins validity)
                        set_transient($transient_key, 1, PILLPALNOW_TRANSIENT_TTL);
                    } else {
                        // Exists in DB, so set transient to skip next time
                        set_transient($transient_key, 1, PILLPALNOW_TRANSIENT_TTL);
                    }
                }
            }
        }
        $paged++;
        if ($paged > 200)
            break;
    }
}

/**
 * Logic B: Check Postponed Reminders
 * If postponed_until <= current_time => Set back to pending & Notify
 */
function pillpalnow_check_postponed_reminders()
{
    $current_time = current_time('timestamp');

    $postponed = get_posts([
        'post_type' => 'reminder_log',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'postponed']
        ]
    ]);

    foreach ($postponed as $rem) {
        $until = get_post_meta($rem->ID, 'postponed_until', true);
        if ($until && $until <= $current_time) {
            // It's time!
            $med_id = get_post_meta($rem->ID, 'medication_id', true);
            $user_id = get_post_meta($rem->ID, 'user_id', true);

            // Update to pending so it shows on dashboard
            update_post_meta($rem->ID, 'status', 'pending');
            // Update scheduled time to now so it sorts correctly as "Due Now"
            update_post_meta($rem->ID, 'scheduled_datetime', $until);

            // Notify
            $med_title = get_the_title($med_id);
            $msg = sprintf(__('Reminder: Time to take %s', 'pillpalnow'), $med_title);
            pillpalnow_send_notification($user_id, __('Postponed Reminder', 'pillpalnow'), $msg, 'postponed');
        }
    }
}

/**
 * Logic C: Process Missed Reminders
 * If current_time > scheduled_datetime + grace_period AND status=pending
 * IMPORTANT: Check if dose was already logged (taken/skipped) before marking as missed
 */
function pillpalnow_process_missed_reminders()
{
    $grace_period = 3600; // 1 Hour (in seconds)
    $current_time = current_time('timestamp');

    $pending_reminders = get_posts([
        'post_type' => 'reminder_log',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'pending']
        ]
    ]);

    foreach ($pending_reminders as $rem) {
        $scheduled_ts = get_post_meta($rem->ID, 'scheduled_datetime', true);
        if (!$scheduled_ts)
            continue;

        if ($current_time > ($scheduled_ts + $grace_period)) {
            // Get medication and user info
            $med_id = get_post_meta($rem->ID, 'medication_id', true);
            $user_id = get_post_meta($rem->ID, 'user_id', true);
            $scheduled_date = date('Y-m-d', $scheduled_ts);
            $scheduled_time = date('H:i', $scheduled_ts);

            // CHECK IF DOSE WAS ALREADY LOGGED (taken, skipped, or missed) FOR THIS SCHEDULED TIME
            // This prevents marking as missed when user took dose early or at a slightly different time
            $existing_logs = get_posts([
                'post_type' => 'dose_log',
                'posts_per_page' => 1,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => 'medication_id', 'value' => $med_id],
                    ['key' => 'user_id', 'value' => $user_id],
                    ['key' => 'log_date', 'value' => $scheduled_date],
                    [
                        'key' => 'status',
                        'value' => array('taken', 'skipped', 'missed'),
                        'compare' => 'IN'
                    ]
                ]
            ]);

            // If a dose was already logged for this medication on this day, just dismiss the reminder
            // Don't create another missed entry
            if (!empty($existing_logs)) {
                // Just update the reminder status to dismissed (already handled)
                update_post_meta($rem->ID, 'status', 'dismissed');
                error_log("[PILLPALNOW] Reminder dismissed - dose already logged for med $med_id on $scheduled_date");
                continue;
            }

            // Also check if there's a dose logged within a reasonable time window (e.g., 2 hours before/after scheduled time)
            // This handles cases where user logs dose with slightly different time
            $window_start = $scheduled_ts - 7200; // 2 hours before
            $window_end = $scheduled_ts + 7200;   // 2 hours after

            $nearby_logs = get_posts([
                'post_type' => 'dose_log',
                'posts_per_page' => 1,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => 'medication_id', 'value' => $med_id],
                    ['key' => 'user_id', 'value' => $user_id],
                    [
                        'key' => 'status',
                        'value' => array('taken', 'skipped'),
                        'compare' => 'IN'
                    ]
                ],
                'date_query' => [
                    [
                        'after' => date('Y-m-d H:i:s', $window_start),
                        'before' => date('Y-m-d H:i:s', $window_end),
                        'inclusive' => true
                    ]
                ]
            ]);

            if (!empty($nearby_logs)) {
                // Dose was logged within the time window, dismiss reminder
                update_post_meta($rem->ID, 'status', 'dismissed');
                error_log("[PILLPALNOW] Reminder dismissed - nearby dose found for med $med_id");
                continue;
            }

            // No existing log found - MARK AS MISSED
            $log_title = "Missed: " . get_the_title($med_id);
            $history_id = wp_insert_post([
                'post_type' => 'dose_log',
                'post_title' => $log_title,
                'post_status' => 'publish',
                'post_author' => $user_id
            ]);

            update_post_meta($history_id, 'medication_id', $med_id);
            update_post_meta($history_id, 'user_id', $user_id);
            update_post_meta($history_id, 'status', 'missed');
            update_post_meta($history_id, 'log_date', $scheduled_date);
            update_post_meta($history_id, 'log_time', $scheduled_time);
            update_post_meta($history_id, 'is_missed_auto', 1);

            // Create missed notification (only one per dose)
            if (class_exists('PillPalNow_Notifications')) {
                $missed_date = date('Y-m-d', $scheduled_ts);

                // Check if missed notification already exists for this dose
                if (!PillPalNow_Notifications::has_missed_notification($med_id, $user_id, $missed_date)) {
                    $med_title = get_the_title($med_id);
                    $time_display = date('g:i A', $scheduled_ts);

                    PillPalNow_Notifications::create(
                        $user_id,
                        PillPalNow_Notifications::TYPE_MISSED,
                        "Missed Dose: {$med_title}",
                        "You missed your {$time_display} dose",
                        $med_id,
                        home_url('/dashboard')
                    );
                }
            }

            // Mark reminder as missed
            update_post_meta($rem->ID, 'status', 'missed');
        }
    }
}

/**
 * Helper: Send Notification
 *
 * @param int $user_id User ID
 * @param string $heading Notification heading
 * @param string $message Notification message
 * @param string $notification_type Type of notification (reminder, refill, missed, postponed)
 */
function pillpalnow_send_notification($user_id, $heading, $message, $notification_type = 'reminder')
{
    if (!$user_id) {
        return;
    }

    $user_info = get_userdata($user_id);
    if (!$user_info) {
        return;
    }

    // Check if notification type is enabled
    if (!PillPalNow_Admin_Settings::is_notification_enabled($notification_type)) {
        PillPalNow_Notification_Logger::log($user_id, $notification_type, 'skipped', 'skipped', $message, 'Notification type disabled in settings');
        return;
    }

    // Deduplication check to prevent sending identical notifications repeatedly
    $content_hash = md5($user_id . '|' . $heading . '|' . $message . '|' . $notification_type);
    $transient_key = 'pillpalnow_notif_' . $content_hash;
    if (get_transient($transient_key)) {
        PillPalNow_Notification_Logger::log($user_id, $notification_type, 'skipped', 'deduplicated', $message, 'Identical notification sent within the last ' . PILLPALNOW_DEDUP_WINDOW . ' seconds');
        return;
    }

    $providers = PillPalNow_Admin_Settings::get_email_providers();
    $priority = PillPalNow_Admin_Settings::get_notification_priority();

    // CHECK PRO STATUS
    $is_pro = class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user($user_id);

    // If NOT Pro, force priority to normal
    if (!$is_pro) {
        $priority = 'normal';
    }

    // --- 1. OneSignal Notification ---
    // RESTRICTION: OneSignal is Pro Only
    if ($is_pro && in_array('onesignal', $providers)) {
        PillPalNow_OneSignal_Service::get_instance()->send_notification(
            $user_id,
            $heading,
            $message,
            $notification_type,
            $priority
        );
    }

    // --- 2. FluentCRM Webhook ---
    // RESTRICTION: Only for Pro Users
    if ($is_pro && in_array('fluentcrm', $providers) && defined('FLUENTCRM_WEBHOOK_URL') && FLUENTCRM_WEBHOOK_URL !== 'YOUR_WEBHOOK_URL_HERE') {
        $webhook_data = array(
            'email' => $user_info->user_email,
            'trigger' => $notification_type,
            'heading' => $heading,
            'message' => $message,
            'priority' => $priority,
        );

        $response = wp_remote_post(FLUENTCRM_WEBHOOK_URL, array(
            'body' => $webhook_data,
            'timeout' => 30,
        ));

        $error_details = '';
        if (is_wp_error($response)) {
            $status = 'failed';
            $error_details = 'WP Error: ' . $response->get_error_message();
            $response_body = $error_details;
        } else {
            $status = ($response['response']['code'] >= 200 && $response['response']['code'] < 300) ? 'sent' : 'failed';
            $response_body = wp_remote_retrieve_body($response);
            if ($status === 'failed') {
                $error_details = "HTTP {$response['response']['code']} - " . $response_body;
            }
        }

        PillPalNow_Notification_Logger::log($user_id, $notification_type, 'fluentcrm', $status, $message, $error_details ?: $response_body);
    }

    // Set deduplication transient to prevent repeated sends
    set_transient($transient_key, 1, PILLPALNOW_DEDUP_WINDOW);
}

/**
 * Fallback email notification when OneSignal fails
 *
 * @param int $user_id User ID
 * @param string $heading Notification heading
 * @param string $message Notification message
 * @param string $notification_type Type of notification
 * @param string $error_details Details of the failure
 */
function pillpalnow_send_email_fallback($user_id, $heading, $message, $notification_type, $error_details)
{
    $user_info = get_userdata($user_id);
    if (!$user_info) {
        return;
    }

    // RESTRICTION: Email Fallback is Pro Only
    if (class_exists('Subscription_Manager') && !Subscription_Manager::is_pro_user($user_id)) {
        PillPalNow_Notification_Logger::log($user_id, $notification_type, 'email_fallback', 'skipped', $message, 'Email fallback skipped - Basic Plan');
        return;
    }

    $email_subject = sprintf('[%s] %s', get_bloginfo('name'), $heading);

    // 1. Get Template
    $default_tmpl = "Hello {name},\n\n{message}\n\n(Sent via email because push notification failed).\n\nDetails: {error_details}";
    $template = get_option('pillpalnow_tmpl_reminder', $default_tmpl);

    if (empty($template)) {
        $template = $default_tmpl;
    }

    // 2. Variable Replacement
    $vars = [
        '{name}' => $user_info->display_name,
        '{message}' => $message,
        '{error_details}' => $error_details
    ];

    $body_content = str_replace(array_keys($vars), array_values($vars), $template);

    // 3. HTML Wrapper
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: sans-serif;
                background-color: #f1f5f9;
                color: #334155;
            }

            .container {
                max-width: 600px;
                margin: 20px auto;
                background-color: #ffffff;
                padding: 25px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
            }

            h2 {
                color: #f59e0b;
                margin-top: 0;
            }

            .details {
                background-color: #f8fafc;
                padding: 10px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 12px;
                margin-top: 20px;
                color: #64748b;
            }

            .footer {
                margin-top: 20px;
                font-size: 12px;
                color: #94a3b8;
                text-align: center;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <h2><?php echo esc_html($heading); ?></h2>
            <?php echo wpautop($body_content); ?>
            <div class="footer">
                Notification from <?php echo esc_html(get_bloginfo('name')); ?>
            </div>
        </div>
    </body>

    </html>
    <?php
    $email_body = ob_get_clean();

    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Context
    if (function_exists('pillpalnow_set_email_context')) {
        pillpalnow_set_email_context($notification_type, $user_id);
    }

    $email_sent = wp_mail($user_info->user_email, $email_subject, $email_body, $headers);

    $status = $email_sent ? 'sent' : 'failed';
    $log_details = $email_sent ? 'Email fallback sent successfully' : 'Email fallback failed to send';

    PillPalNow_Notification_Logger::log($user_id, $notification_type, 'email_fallback', $status, $message, $log_details);
}

/**
 * Daily cleanup function for notification logs
 */
function pillpalnow_daily_cleanup()
{
    // Clean up old notification logs based on settings
    $settings = PillPalNow_Admin_Settings::get_settings();
    $retention_days = intval($settings['log_retention_days']);

    if (class_exists('PillPalNow_Notification_Logger')) {
        PillPalNow_Notification_Logger::clear_old_logs($retention_days);
    }

    // Clean up old notifications (existing functionality)
    if (class_exists('PillPalNow_Notifications')) {
        PillPalNow_Notifications::cleanup_old_notifications(30);
    }
}
add_action('pillpalnow_daily_cleanup', 'pillpalnow_daily_cleanup');
