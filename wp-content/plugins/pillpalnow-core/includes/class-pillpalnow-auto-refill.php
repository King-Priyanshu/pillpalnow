<?php
/**
 * Auto Refill Request System
 * 
 * Automatically creates refill requests and sends OneSignal push notifications
 * when medication stock reaches threshold (≤7).
 * 
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Auto_Refill
 * 
 * Handles automatic refill request creation and OneSignal push notifications.
 */
class PillPalNow_Auto_Refill
{
    /**
     * Singleton instance
     * @var PillPalNow_Auto_Refill
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * 
     * @return PillPalNow_Auto_Refill
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Refill threshold quantity - hardcoded to 7
     */
    const REFILL_THRESHOLD = 7;

    /**
     * Reset threshold (when to clear the trigger flag)
     */
    const RESET_THRESHOLD = 8;

    /**
     * Initialize the class
     */
    public function __construct()
    {
        // Hook after dose is logged and stock recalculated

        // Priority 20: Standard refill request check
        add_action('pillpalnow/dose_logged', array($this, 'check_and_create_refill_request'), 20, 3);

        // Hook when stock is manually updated via admin or refill confirmation
        add_action('pillpalnow/stock_updated', array($this, 'check_and_create_refill_request_from_update'), 10, 2);

        // Hook when refill base quantity is updated (manual refill)
        add_action('updated_post_meta', array($this, 'on_refill_base_updated'), 10, 4);


        // Check on admin login/init
        add_action('admin_init', array($this, 'check_on_admin_init'));
    }

    /**
     * Check for refill needs when user accesses admin area
     * Runs once daily per user to avoid performance impact
     */
    public function check_on_admin_init()
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $date = date('Y-m-d');
        $transient_key = "pillpalnow_auto_refill_check_{$user_id}_{$date}";

        // Check if already ran today
        if (get_transient($transient_key)) {
            return;
        }

        // Process checks
        $this->process_user_refill_checks($user_id);

        // Set transient for 24 hours (or until end of day)
        set_transient($transient_key, true, DAY_IN_SECONDS);
    }

    /**
     * Check all medications for a specific user
     * 
     * @param int $user_id User ID
     */
    private function process_user_refill_checks($user_id)
    {
        // Find medications where user is assigned OR author
        $args = array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'assigned_user_id',
                    'value' => $user_id,
                    'compare' => '='
                ),
                array(
                    'key' => 'assigned_user_id',
                    'compare' => 'NOT EXISTS' // Check authorship if no assigned user
                )
            )
        );

        $medications = get_posts($args);

        foreach ($medications as $medication) {
            // If no assigned user, check authorship
            $assigned = get_post_meta($medication->ID, 'assigned_user_id', true);
            if (!$assigned && $medication->post_author != $user_id) {
                continue;
            }

            // If assigned user exists but doesn't match current user, skip
            if ($assigned && $assigned != $user_id) {
                continue;
            }

            // Run standard check
            $this->process_refill_check($medication->ID);
        }
    }


    /**
     * Check and create refill request after dose logged
     * 
     * @param int   $medication_id  Medication ID
     * @param int   $dose_log_id    Dose Log ID
     * @param float $dosage_taken   Dosage taken
     */
    public function check_and_create_refill_request($medication_id, $dose_log_id, $dosage_taken)
    {
        $this->process_refill_check($medication_id);
    }

    /**
     * Check and create refill request from stock update
     * 
     * @param int   $medication_id Medication ID
     * @param float $new_stock     New stock quantity
     */
    public function check_and_create_refill_request_from_update($medication_id, $new_stock)
    {
        $this->process_refill_check($medication_id);
    }

    /**
     * Handle refill base quantity update (manual refill)
     * Reset the trigger flag when stock is replenished
     * 
     * @param int    $meta_id    Meta ID
     * @param int    $post_id    Post ID
     * @param string $meta_key   Meta key
     * @param mixed  $meta_value Meta value
     */
    public function on_refill_base_updated($meta_id, $post_id, $meta_key, $meta_value)
    {
        // Only process medication posts with _refill_base_qty updates
        if ($meta_key !== '_refill_base_qty' || get_post_type($post_id) !== 'medication') {
            return;
        }

        // Get current stock after update
        $current_stock = function_exists('pillpalnow_get_remaining_stock')
            ? pillpalnow_get_remaining_stock($post_id)
            : (float) get_post_meta($post_id, 'stock_quantity', true);

        // Reset trigger flag if stock replenished above threshold
        if ($current_stock >= self::RESET_THRESHOLD) {
            $was_triggered = get_post_meta($post_id, '_refill_triggered', true);

            if ($was_triggered) {
                delete_post_meta($post_id, '_refill_triggered');

                $this->log_event(
                    'refill_reset',
                    $post_id,
                    sprintf(
                        'Refill trigger flag reset for medication %s (stock replenished to %.1f)',
                        get_the_title($post_id),
                        $current_stock
                    )
                );
            }
        }

        // Check if we need to create a new refill request
        $this->process_refill_check($post_id);
    }

    /**
     * Process refill check and create request if needed
     * 
     * @param int $medication_id Medication ID
     */
    private function process_refill_check($medication_id)
    {
        $this->log_event(
            'refill_check_started',
            $medication_id,
            sprintf('Starting refill check for medication %s', get_the_title($medication_id))
        );

        // Get current stock
        $current_stock = function_exists('pillpalnow_get_remaining_stock')
            ? pillpalnow_get_remaining_stock($medication_id)
            : (float) get_post_meta($medication_id, 'stock_quantity', true);

        $this->log_event(
            'refill_stock_check',
            $medication_id,
            sprintf('Current stock: %.1f, Threshold: %d', $current_stock, self::REFILL_THRESHOLD)
        );

        // Get user ID
        $user_id = $this->get_medication_user($medication_id);

        $this->log_event(
            'refill_user_check',
            $medication_id,
            sprintf('Assigned user ID: %s', $user_id ? $user_id : 'NONE')
        );

        if (!$user_id) {
            $this->log_event(
                'refill_error',
                $medication_id,
                'Cannot create refill request - no user associated with medication'
            );
            return;
        }

        // Check if stock is at or below threshold
        // PRO: Uses predictive days logic (handled externally or simplified here to keep consistency)
        // BASIC: Simple stock count (<= 5)
        $is_pro = class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user($user_id);

        $threshold = $is_pro ? self::REFILL_THRESHOLD : 5;

        if ($current_stock > $threshold) {
            $this->log_event(
                'refill_skip_above_threshold',
                $medication_id,
                sprintf('Stock (%.1f) above threshold (%d) - skipping', $current_stock, $threshold)
            );
            return;
        }

        // Check if refill already triggered (duplicate prevention)
        $already_triggered = get_post_meta($medication_id, '_refill_triggered', true);

        if ($already_triggered) {
            $this->log_event(
                'refill_duplicate_prevented',
                $medication_id,
                sprintf(
                    'Duplicate refill request prevented for medication %s (stock: %.1f, already triggered)',
                    get_the_title($medication_id),
                    $current_stock
                )
            );
            return;
        }

        // Check if refill alerts are enabled for this medication
        // Default to enabled if not explicitly set to disabled
        $alerts_enabled = get_post_meta($medication_id, 'refill_alerts_enabled', true);

        $this->log_event(
            'refill_alerts_check',
            $medication_id,
            sprintf('Refill alerts enabled value: %s (type: %s)', var_export($alerts_enabled, true), gettype($alerts_enabled))
        );

        // Only skip if explicitly disabled (value is '0' or 0)
        if ($alerts_enabled === '0' || $alerts_enabled === 0) {
            $this->log_event(
                'refill_skip_disabled',
                $medication_id,
                'Refill alerts are disabled for this medication - skipping'
            );
            return;
        }

        // Create refill request with trigger source
        $trigger_source = $this->get_current_trigger_source();
        $refill_request_id = $this->create_refill_request($medication_id, $user_id, $current_stock, $trigger_source);

        if (!$refill_request_id) {
            return;
        }

        // Send user email via Smart API
        $email_status = $this->send_user_email($medication_id, $user_id, $current_stock, $refill_request_id);

        // Send OneSignal push notification
        $push_status = $this->send_onesignal_notification($medication_id, $user_id, $current_stock);

        // Update refill request with notification statuses
        update_post_meta($refill_request_id, 'notification_email_status', $email_status);
        update_post_meta($refill_request_id, 'notification_push_status', $push_status);

        // Send admin notifications
        $this->notify_admin($medication_id, $user_id, $current_stock, $refill_request_id);

        // Set trigger flag to prevent duplicates
        update_post_meta($medication_id, '_refill_triggered', '1');

        $this->log_event(
            'refill_triggered',
            $medication_id,
            sprintf(
                'Refill request #%d auto-created for medication %s (stock: %.1f, source: %s, email: %s, push: %s)',
                $refill_request_id,
                get_the_title($medication_id),
                $current_stock,
                $trigger_source,
                $email_status,
                $push_status
            )
        );
    }

    /**
     * Get current trigger source based on context
     * 
     * @return string Trigger source (cron, page_load, dose_logged, stock_update, system)
     */
    private function get_current_trigger_source()
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return 'cron';
        }
        if (current_action() === 'pillpalnow/dose_logged') {
            return 'dose_logged';
        }
        if (current_action() === 'pillpalnow/stock_updated') {
            return 'stock_update';
        }
        if (current_action() === 'updated_post_meta') {
            return 'manual_refill';
        }
        if (current_action() === 'admin_init') {
            return 'admin_login_check';
        }
        return 'system';
    }

    /**
     * Create refill request CPT entry
     * 
     * @param int    $medication_id  Medication ID
     * @param int    $user_id        User ID
     * @param float  $remaining_qty  Remaining quantity
     * @param string $trigger_source Trigger source (cron, dose_logged, etc.)
     * @return int|false Post ID on success, false on failure
     */
    private function create_refill_request($medication_id, $user_id, $remaining_qty, $trigger_source = 'system')
    {
        $medication_title = get_the_title($medication_id);
        $today = date('Y-m-d');

        // Check for existing refill request created today (additional duplicate prevention)
        $existing = get_posts(array(
            'post_type' => 'refill_request',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => 'medication_id', 'value' => $medication_id),
                array('key' => 'requested_date', 'value' => $today),
            ),
        ));

        if (!empty($existing)) {
            $this->log_event(
                'refill_duplicate_today',
                $medication_id,
                sprintf('Refill request already exists for today (ID: %d)', $existing[0]->ID)
            );
            return false;
        }

        $post_id = wp_insert_post(array(
            'post_title' => sprintf('Auto Refill: %s', $medication_title),
            'post_type' => 'refill_request',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ));

        if (!$post_id || is_wp_error($post_id)) {
            $this->log_event(
                'refill_error',
                $medication_id,
                'Failed to create refill request CPT entry'
            );
            return false;
        }

        // Add meta fields
        update_post_meta($post_id, 'user_id', $user_id);
        update_post_meta($post_id, 'medication_id', $medication_id);
        update_post_meta($post_id, 'remaining_qty', $remaining_qty);
        update_post_meta($post_id, 'status', 'pending');
        update_post_meta($post_id, 'created_at', current_time('mysql'));
        update_post_meta($post_id, 'requested_date', $today);
        update_post_meta($post_id, 'trigger_source', $trigger_source);
        update_post_meta($post_id, 'auto_created', '1');

        $this->log_event(
            'refill_created',
            $medication_id,
            sprintf(
                'Refill request #%d created successfully for user %d (source: %s)',
                $post_id,
                $user_id,
                $trigger_source
            )
        );

        return $post_id;
    }

    /**
     * Send email to user via wp_mail() (triggers PillPalNow Smart API)
     * 
     * @param int   $medication_id     Medication ID
     * @param int   $user_id           User ID
     * @param float $remaining_qty     Remaining quantity
     * @param int   $refill_request_id Refill request ID
     * @return string Status (sent, failed, skipped)
     */
    private function send_user_email($medication_id, $user_id, $remaining_qty, $refill_request_id)
    {
        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) {
            return 'skipped';
        }

        // RESTRICTION: Email Alerts are Pro Only
        if (class_exists('Subscription_Manager') && !Subscription_Manager::is_pro_user($user_id)) {
            $this->log_event('refill_email_skipped', $medication_id, 'Refill email skipped - Basic Plan');
            return 'skipped';
        }

        $medication_title = get_the_title($medication_id);
        $refill_url = home_url('/refills/?med=' . $medication_id);

        $subject = sprintf(__('⚠️ Refill Alert: %s is running low', 'pillpalnow'), $medication_title);

        // HTML email template
        $message = sprintf(
            '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #2563eb;">⚠️ Your Medication is Running Low</h2>
                <p>Hello %s,</p>
                <p>Your medication <strong>%s</strong> is running low.</p>
                <div style="background: #fef3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0;">
                    <strong>Remaining Quantity:</strong> %d pills/doses
                </div>
                <p>We recommend requesting a refill soon to avoid missing any doses.</p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="%s" style="background: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Request Refill Now</a>
                </div>
                <p style="color: #666; font-size: 14px;">Stay healthy,<br>The PillPalNow Team</p>
            </div>
            </body></html>',
            esc_html($user->display_name),
            esc_html($medication_title),
            (int) $remaining_qty,
            esc_url($refill_url)
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: PillPalNow <%s>', get_option('admin_email'))
        );

        $sent = wp_mail($user->user_email, $subject, $message, $headers);

        // Get provider info from Smart API
        $email_provider = 'unknown';
        if (function_exists('pillpalnow_smart_api_init')) {
            $smart_api = pillpalnow_smart_api_init();
            $email_provider = $smart_api->get_last_provider() ?: 'fallback';
        }

        // Update refill request with provider info
        update_post_meta($refill_request_id, 'email_provider', $email_provider);

        $this->log_event(
            $sent ? 'email_sent' : 'email_failed',
            $medication_id,
            sprintf('User email %s via %s to %s', $sent ? 'sent' : 'failed', $email_provider, $user->user_email)
        );

        return $sent ? 'sent' : 'failed';
    }

    /**
     * Notify admin about new refill request
     * 
     * @param int   $medication_id     Medication ID
     * @param int   $user_id           User ID
     * @param float $remaining_qty     Remaining quantity
     * @param int   $refill_request_id Refill request ID
     */
    private function notify_admin($medication_id, $user_id, $remaining_qty, $refill_request_id)
    {
        $user = get_userdata($user_id);
        $medication_title = get_the_title($medication_id);
        $admin_email = get_option('admin_email');

        // Send admin push notification
        $this->send_admin_push($user, $medication_title, $remaining_qty);

        // Send admin email
        $subject = sprintf(__('🔔 New Refill Request: %s', 'pillpalnow'), $medication_title);
        $message = sprintf(
            '<html><body style="font-family: Arial, sans-serif;">
            <h2>New Refill Request Generated</h2>
            <table style="border-collapse: collapse; width: 100%%;">
                <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>User:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s (%s)</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Medication:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Remaining Qty:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%d</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Request ID:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">#%d</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Date:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">%s</td></tr>
            </table>
            <p><a href="%s">View in Admin</a></p>
            </body></html>',
            $user ? esc_html($user->display_name) : 'Unknown',
            $user ? esc_html($user->user_email) : 'Unknown',
            esc_html($medication_title),
            (int) $remaining_qty,
            $refill_request_id,
            current_time('Y-m-d H:i:s'),
            admin_url('post.php?post=' . $refill_request_id . '&action=edit')
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);

        $this->log_event(
            'admin_notified',
            $medication_id,
            sprintf('Admin notified about refill request #%d', $refill_request_id)
        );
    }

    /**
     * Send push notification to admin
     * 
     * @param WP_User|null $user              User who triggered refill
     * @param string       $medication_title  Medication name
     * @param float        $remaining_qty     Remaining quantity
     */
    private function send_admin_push($user, $medication_title, $remaining_qty)
    {
        // Get admin OneSignal settings (use admin email for targeting)
        $admin_email = get_option('admin_email');

        if (!defined('ONESIGNAL_APP_ID') || !defined('ONESIGNAL_REST_KEY')) {
            return;
        }

        $fields = array(
            'app_id' => ONESIGNAL_APP_ID,
            'include_email_tokens' => array($admin_email),
            'headings' => array('en' => '🔔 New Refill Request'),
            'contents' => array(
                'en' => sprintf(
                    '%s requested refill for %s (Qty: %d)',
                    $user ? $user->display_name : 'User',
                    $medication_title,
                    (int) $remaining_qty
                )
            ),
        );

        wp_remote_post('https://onesignal.com/api/v1/notifications', array(
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . ONESIGNAL_REST_KEY
            ),
            'body' => wp_json_encode($fields),
            'timeout' => 15
        ));
    }

    /**
     * Send OneSignal push notification
     * 
     * @param int   $medication_id Medication ID
     * @param int   $user_id       User ID
     * @param float $remaining_qty Remaining quantity
     * @return string Status (sent, failed, skipped)
     */
    private function send_onesignal_notification($medication_id, $user_id, $remaining_qty)
    {
        // Check if OneSignal service is available
        if (!class_exists('PillPalNow_OneSignal_Service')) {
            $this->log_event(
                'onesignal_skipped',
                $medication_id,
                'OneSignal service not available - push notification skipped'
            );
            return 'skipped';
        }

        $onesignal_service = PillPalNow_OneSignal_Service::get_instance();
        
        if (!$onesignal_service->is_configured()) {
            $this->log_event(
                'onesignal_skipped',
                $medication_id,
                'OneSignal not configured - push notification skipped'
            );
            return 'skipped';
        }

        // Get medication title
        $medication_title = get_the_title($medication_id);

        // Send notification using OneSignal service
        $success = $onesignal_service->send_notification(
            $user_id,
            'Refill Needed',
            sprintf('Medication running low (%d remaining)', (int) $remaining_qty),
            'refill_alert',
            'high'
        );

        if ($success) {
            return 'sent';
        } else {
            $this->log_event(
                'onesignal_error',
                $medication_id,
                'Failed to send OneSignal notification'
            );
            return 'failed';
        }
    }




    /**
     * Get the user ID associated with a medication
     * 
     * @param int $medication_id Medication post ID
     * @return int|false User ID or false if not found
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
     * Log event to notification logger
     * 
     * @param string $event_type    Event type
     * @param int    $medication_id Medication ID
     * @param string $message       Log message
     */
    private function log_event($event_type, $medication_id, $message)
    {
        // Log to notification logger if available
        if (class_exists('PillPalNow_Notification_Logger')) {
            PillPalNow_Notification_Logger::log(
                $event_type,
                $message,
                'auto_refill', // context string instead of array
                $medication_id,
                0 // user_id (0 for system)
            );
        }

        // ALWAYS log to error_log for debugging (can be disabled later)
        error_log(sprintf('[PillPalNow Auto Refill] %s: %s (Med ID: %d)', $event_type, $message, $medication_id));
    }
}

// Initialize the class
PillPalNow_Auto_Refill::get_instance();
