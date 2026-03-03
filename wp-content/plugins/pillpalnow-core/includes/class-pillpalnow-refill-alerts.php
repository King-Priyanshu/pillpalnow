<?php
/**
 * Refill Alerts Handler
 * 
 * Single source of truth for refill alert logic.
 * FluentCRM may only be used as transport, never as decision engine.
 * 
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Refill_Alerts
 * 
 * Responsibilities:
 * - Remaining quantity calculation
 * - Threshold detection
 * - Alert dispatch (email + push)
 */
class PillPalNow_Refill_Alerts
{

    /**
     * Initialize the class
     * 
     * NOTE: As of 2024-01, refill request creation and user notifications are handled
     * by PillPalNow_Auto_Refill class (class-pillpalnow-auto-refill.php).
     * This class now only provides helper methods for threshold detection and
     * is kept for backward compatibility with external integrations.
     */
    public function __construct()
    {
        // DISABLED: PillPalNow_Auto_Refill now handles dose_logged events and creates
        // refill requests + notifications in a unified flow. Keeping this hook
        // would cause duplicate notifications.
        // add_action('pillpalnow/dose_logged', array($this, 'on_dose_logged'), 10, 3);

        // Listen for low refill events (for extensibility - can be triggered externally)
        add_action('pillpalnow/refill_low', array($this, 'dispatch_alerts'), 10, 3);

        // Auto-configure medications that lack refill settings (runs once daily)
        add_action('init', array($this, 'maybe_auto_configure_medications'), 20);
    }

    /**
     * Handle dose logged event
     * 
     * @param int   $medication_id  Medication post ID
     * @param int   $dose_log_id    Dose log post ID
     * @param float $dosage_taken   Amount of dosage taken
     */
    public function on_dose_logged($medication_id, $dose_log_id, $dosage_taken)
    {
        // Check if alerts are enabled for this medication
        if (!$this->is_alerts_enabled($medication_id)) {
            return;
        }

        // Get remaining quantity
        $remaining = $this->get_remaining_quantity($medication_id);

        // Get threshold
        $threshold = $this->get_threshold($medication_id);

        if ($threshold <= 0) {
            return;
        }

        // Check if we've hit the threshold
        if ($remaining <= $threshold && $remaining > 0) {
            /**
             * Fires when medication quantity is at or below refill threshold
             * 
             * @param int   $medication_id  Medication post ID
             * @param float $remaining      Remaining quantity
             * @param int   $threshold      Configured threshold
             */
            do_action('pillpalnow/refill_low', $medication_id, $remaining, $threshold);
        }
    }

    /**
     * Check if refill alerts are enabled for a medication
     * 
     * @param int $medication_id Medication post ID
     * @return bool
     */
    public function is_alerts_enabled($medication_id)
    {
        $enabled = get_post_meta($medication_id, 'refill_alerts_enabled', true);

        // Default to true if not explicitly set (backward compatibility)
        if ($enabled === '') {
            return true;
        }

        return (bool) $enabled;
    }

    /**
     * Get remaining quantity for a medication
     * 
     * @param int $medication_id Medication post ID
     * @return float
     */
    public function get_remaining_quantity($medication_id)
    {
        if (function_exists('pillpalnow_get_remaining_stock')) {
            return (float) pillpalnow_get_remaining_stock($medication_id);
        }

        return (float) get_post_meta($medication_id, 'stock_quantity', true);
    }

    /**
     * Get refill threshold for a medication
     * 
     * @param int $medication_id Medication post ID
     * @return int
     */
    public function get_threshold($medication_id)
    {
        return (int) get_post_meta($medication_id, 'refill_threshold', true);
    }

    /**
     * Maybe auto-configure medications (runs once daily max)
     * 
     * Uses transient guard to prevent running on every page load.
     * Only configures medications that have no refill settings.
     */
    public function maybe_auto_configure_medications()
    {
        // Transient guard - run once per day
        if (get_transient('pillpalnow_refill_auto_config_done')) {
            return;
        }

        /**
         * Filter to disable auto-configuration
         * 
         * @param bool $enabled Whether to enable auto-configuration (default: true)
         */
        if (!apply_filters('pillpalnow_enable_auto_configure_refills', true)) {
            return;
        }

        $this->auto_configure_medications();
        set_transient('pillpalnow_refill_auto_config_done', 1, DAY_IN_SECONDS);
    }

    /**
     * Auto-configure medications that lack refill tracking settings
     * 
     * Sets default values ONLY if meta keys do NOT exist.
     * Never overwrites user-defined values.
     * 
     * Default values:
     * - stock_quantity: 90
     * - refill_threshold: 7
     * - refill_alerts_enabled: 1
     */
    private function auto_configure_medications()
    {
        // Find medications without refill configuration
        $medications = get_posts(array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'post_status' => array('publish', 'draft', 'pending'),
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'refill_alerts_enabled',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));

        if (empty($medications)) {
            return;
        }

        foreach ($medications as $med_id) {
            // Set stock_quantity if not set
            if (metadata_exists('post', $med_id, 'stock_quantity') === false) {
                update_post_meta($med_id, 'stock_quantity', 90);
            }

            // Set refill_threshold if not set
            if (metadata_exists('post', $med_id, 'refill_threshold') === false) {
                update_post_meta($med_id, 'refill_threshold', 7);
            }

            // Set refill_alerts_enabled (this is our marker)
            update_post_meta($med_id, 'refill_alerts_enabled', '1');
        }

        /**
         * Fires after medications have been auto-configured
         * 
         * @param array $medications Array of medication IDs that were configured
         */
        do_action('pillpalnow/medications_auto_configured', $medications);
    }

    /**
     * Dispatch refill alerts (email + push)
     * 
     * @param int   $medication_id  Medication post ID
     * @param float $remaining      Remaining quantity
     * @param int   $threshold      Configured threshold
     */
    public function dispatch_alerts($medication_id, $remaining, $threshold)
    {
        // Prevent duplicate daily alerts
        if ($this->was_alert_sent_today($medication_id)) {
            return;
        }

        $medication_title = get_the_title($medication_id);
        $user_id = $this->get_medication_user($medication_id);

        if (!$user_id) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Send email alert
        $this->send_email_alert($user, $medication_title, $remaining);

        /**
         * Optionally send admin email alert (disabled by default)
         * 
         * @filter pillpalnow_send_admin_refill_alert
         * @param bool $send_admin_alert Whether to send admin email (default: false)
         * @param int  $medication_id    Medication post ID
         */
        if (apply_filters('pillpalnow_send_admin_refill_alert', false, $medication_id)) {
            $this->send_admin_email_alert($medication_id, $user, $medication_title, $remaining);
        }

        // Send push notification
        $this->send_push_notification($user_id, $medication_title, $remaining);

        // PERSIST NOTIFICATION TO DB
        if (class_exists('PillPalNow_Notifications')) {
            PillPalNow_Notifications::create(
                $user_id,
                PillPalNow_Notifications::TYPE_REFILL_LOW,
                __('Refill Needed', 'pillpalnow'),
                sprintf(
                    __('%s is running low (%d remaining)', 'pillpalnow'),
                    $medication_title,
                    $remaining
                ),
                $medication_id,
                home_url('/refills') // Redirect to Refills page
            );
        }

        // Mark as sent today
        $this->mark_alert_sent($medication_id);
    }

    /**
     * Check if alert was already sent today
     * 
     * @param int $medication_id Medication post ID
     * @return bool
     */
    private function was_alert_sent_today($medication_id)
    {
        $last_sent = get_post_meta($medication_id, '_refill_alert_sent_date', true);
        $today = date('Y-m-d');

        return ($last_sent === $today);
    }

    /**
     * Mark alert as sent for today
     * 
     * @param int $medication_id Medication post ID
     */
    private function mark_alert_sent($medication_id)
    {
        update_post_meta($medication_id, '_refill_alert_sent_date', date('Y-m-d'));
    }

    /**
     * Get the user ID associated with a medication
     * 
     * @param int $medication_id Medication post ID
     * @return int|false
     */
    private function get_medication_user($medication_id)
    {
        // Check for assigned user first
        $assigned_user_id = get_post_meta($medication_id, 'assigned_user_id', true);
        if ($assigned_user_id) {
            return (int) $assigned_user_id;
        }

        // Fallback to post author
        return (int) get_post_field('post_author', $medication_id);
    }

    /**
     * Send email alert via wp_mail()
     * FluentSMTP/FluentCRM Free handles delivery only
     * 
     * @param WP_User $user             User object
     * @param string  $medication_title Medication name
     * @param float   $remaining        Remaining quantity
     */
    private function send_email_alert($user, $medication_title, $remaining)
    {
        $to = $user->user_email;
        $subject = sprintf(
            __('Refill Alert: %s is Running Low', 'pillpalnow'),
            $medication_title
        );

        // 1. Get Template
        $default_tmpl = "Hello {name},\n\nYour medication '{medication_name}' is running low.\n\nRemaining: {remaining} pills\n\nPlease refill soon to avoid missing doses.";
        $template = get_option('pillpalnow_tmpl_refill', $default_tmpl);

        if (empty($template)) {
            $template = $default_tmpl;
        }

        // 2. Replace Variables
        $vars = [
            '{name}' => $user->display_name,
            '{medication_name}' => $medication_title,
            '{remaining}' => $remaining
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
                    background-color: #f8fafc;
                    color: #334155;
                }

                .container {
                    max-width: 600px;
                    margin: 20px auto;
                    background-color: #ffffff;
                    padding: 30px;
                    border-radius: 8px;
                    border: 1px solid #e2e8f0;
                }

                h1 {
                    color: #dc2626;
                    margin-top: 0;
                }

                .footer {
                    margin-top: 30px;
                    font-size: 12px;
                    color: #94a3b8;
                    border-top: 1px solid #e2e8f0;
                    padding-top: 15px;
                }
            </style>
        </head>

        <body>
            <div class="container">
                <h1>Refill Alert</h1>
                <?php echo wpautop($body_content); ?>
                <div class="footer">
                    Sent by <?php echo esc_html(get_bloginfo('name')); ?>
                </div>
            </div>
        </body>

        </html>
        <?php
        $message = ob_get_clean();

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Log context
        if (function_exists('pillpalnow_set_email_context')) {
            pillpalnow_set_email_context('refill', $user->ID);
        }

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Send refill alert email to admin
     * 
     * Reuses the same HTML email template as user alerts.
     * Only sends if admin email differs from user email.
     * 
     * @param int     $medication_id    Medication post ID
     * @param WP_User $user             User object (medication owner)
     * @param string  $medication_title Medication name
     * @param float   $remaining        Remaining quantity
     */
    private function send_admin_email_alert($medication_id, $user, $medication_title, $remaining)
    {
        $admin_email = get_option('admin_email');

        // Skip if no admin email or admin is the same as user
        if (empty($admin_email) || $admin_email === $user->user_email) {
            return;
        }

        $subject = sprintf(
            __('[Admin] Refill Alert: %s is Running Low', 'pillpalnow'),
            $medication_title
        );

        // Build admin-specific message content
        $body_content = sprintf(
            __("A patient's medication requires refill attention.\n\nPatient: %s (%s)\nMedication: %s\nRemaining: %d pills\n\nPlease review and ensure timely refill.", 'pillpalnow'),
            $user->display_name,
            $user->user_email,
            $medication_title,
            $remaining
        );

        // HTML Wrapper (same style as user emails)
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
                    background-color: #f8fafc;
                    color: #334155;
                }

                .container {
                    max-width: 600px;
                    margin: 20px auto;
                    background-color: #ffffff;
                    padding: 30px;
                    border-radius: 8px;
                    border: 1px solid #e2e8f0;
                }

                h1 {
                    color: #dc2626;
                    margin-top: 0;
                }

                .admin-notice {
                    background-color: #fef3c7;
                    border-left: 4px solid #f59e0b;
                    padding: 10px 15px;
                    margin-bottom: 20px;
                    font-size: 13px;
                    color: #92400e;
                }

                .footer {
                    margin-top: 30px;
                    font-size: 12px;
                    color: #94a3b8;
                    border-top: 1px solid #e2e8f0;
                    padding-top: 15px;
                }
            </style>
        </head>

        <body>
            <div class="container">
                <div class="admin-notice">
                    <?php esc_html_e('This is an admin notification. The patient has also been notified.', 'pillpalnow'); ?>
                </div>
                <h1><?php esc_html_e('Refill Alert', 'pillpalnow'); ?></h1>
                <?php echo wpautop(esc_html($body_content)); ?>
                <div class="footer">
                    <?php echo esc_html(sprintf(__('Sent by %s', 'pillpalnow'), get_bloginfo('name'))); ?>
                </div>
            </div>
        </body>

        </html>
        <?php
        $message = ob_get_clean();

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Log context for admin
        if (function_exists('pillpalnow_set_email_context')) {
            pillpalnow_set_email_context('refill_admin', 0);
        }

        wp_mail($admin_email, $subject, $message, $headers);
    }

    /**
     * Send push notification via OneSignal REST API
     * 
     * @param int    $user_id          User ID
     * @param string $medication_title Medication name
     * @param float  $remaining        Remaining quantity
     */
    private function send_push_notification($user_id, $medication_title, $remaining)
    {
        // Use existing helper function if available
        if (function_exists('pillpalnow_send_notification')) {
            $heading = __('Refill Needed', 'pillpalnow');
            $message = sprintf(
                __('%s is running low (%d remaining)', 'pillpalnow'),
                $medication_title,
                $remaining
            );

            pillpalnow_send_notification($user_id, $heading, $message, 'refill');
            return;
        }

        // Fallback: Direct OneSignal API call
        if (!defined('ONESIGNAL_APP_ID') || !defined('ONESIGNAL_REST_KEY')) {
            return;
        }

        if (ONESIGNAL_APP_ID === 'YOUR_APP_ID_HERE' || ONESIGNAL_REST_KEY === 'YOUR_REST_KEY_HERE') {
            return;
        }

        // Get all player IDs (supports single + array for multi-device)
        $player_ids = $this->get_all_player_ids($user_id);
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        $fields = array(
            'app_id' => ONESIGNAL_APP_ID,
            'headings' => array('en' => __('Refill Needed', 'pillpalnow')),
            'contents' => array(
                'en' => sprintf(
                    __('%s is running low (%d remaining)', 'pillpalnow'),
                    $medication_title,
                    $remaining
                )
            ),
        );

        // Use player_ids if available, otherwise email
        if (!empty($player_ids)) {
            $fields['include_player_ids'] = $player_ids;
        } else {
            $fields['include_email_tokens'] = array($user->user_email);
        }

        $response = wp_remote_post('https://onesignal.com/api/v1/notifications', array(
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . ONESIGNAL_REST_KEY
            ),
            'body' => wp_json_encode($fields),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            // Silent fail - notifications should not break the application
        }
    }

    /**
     * Get all OneSignal player IDs for a user
     * 
     * Supports both single ID (existing) and array of IDs (multi-device).
     * Merges, deduplicates, and filters empty values.
     * 
     * @param int $user_id User ID
     * @return array Deduplicated array of player IDs
     */
    private function get_all_player_ids($user_id)
    {
        $ids = array();

        // Single ID (existing meta key)
        $single_id = get_user_meta($user_id, 'onesignal_player_id', true);
        if (!empty($single_id)) {
            $ids[] = $single_id;
        }

        // Array of IDs (new meta key for multi-device support)
        $multi_ids = get_user_meta($user_id, 'onesignal_player_ids', true);
        if (is_array($multi_ids)) {
            $ids = array_merge($ids, $multi_ids);
        }

        // Deduplicate, filter empty, and reindex
        return array_values(array_unique(array_filter(array_map('trim', $ids))));
    }
}

// Initialize the class
new PillPalNow_Refill_Alerts();
