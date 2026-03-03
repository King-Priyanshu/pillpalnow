<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Notification Email
 * 
 * Sent to the site admin for important subscription events
 * (new subscription, cancellation, persistent failures).
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Admin_Notification extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_admin_notification';
        $this->title = __('Admin Subscription Notification', 'pillpalnow');
        $this->description = __('Sent to admin when important subscription events occur.', 'pillpalnow');
        $this->context_type = 'admin';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'admin-notification.php';
    }

    public function get_default_subject()
    {
        return __('[{site_title}] Subscription Event Notification', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Subscription Event', 'pillpalnow');
    }

    /**
     * Override trigger to send to admin instead of the user
     */
    public function trigger($user_id, $data = [])
    {
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return false;
        }

        // Build admin-specific data
        $affected_user = null;
        $affected_user_id = isset($data['affected_user_id']) ? $data['affected_user_id'] : $user_id;
        if ($affected_user_id) {
            $affected_user = get_userdata($affected_user_id);
        }

        $notification_type = isset($data['notification_type']) ? $data['notification_type'] : 'unknown';

        // Build a pseudo-user for the admin
        $admin_user = get_user_by('email', $admin_email);
        if (!$admin_user) {
            $admin_user = (object) [
                'ID' => 0,
                'display_name' => 'Admin',
                'user_email' => $admin_email,
            ];
        }

        $this->recipient = $admin_email;
        $this->template_data = array_merge(
            $this->prepare_template_data_admin($admin_user, $data),
            [
                'notification_type' => $notification_type,
                'affected_user_name' => $affected_user ? $affected_user->display_name : 'Unknown User',
                'affected_user_email' => $affected_user ? $affected_user->user_email : '',
                'affected_user_id' => $affected_user_id,
                'event_description' => $this->get_event_description($notification_type),
            ]
        );

        if (!$this->is_enabled() || !$this->recipient) {
            return false;
        }

        $subject = $this->get_subject();
        $html_content = $this->get_content_html();

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        if (class_exists('PillPalNow_Email_Service')) {
            return PillPalNow_Email_Service::send(
                $this->recipient,
                $subject,
                $html_content,
                'admin',
                0,
                $headers
            );
        }

        return wp_mail($this->recipient, $subject, $html_content, $headers);
    }

    /**
     * Prepare template data for admin context
     */
    private function prepare_template_data_admin($admin_user, $data)
    {
        return array_merge([
            'user_name' => $admin_user->display_name,
            'user_email' => $admin_user->user_email,
            'user_id' => $admin_user->ID ?? 0,
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'manage_url' => admin_url('admin.php?page=pillpalnow-settings'),
            'cancel_url' => '',
            'privacy_url' => get_privacy_policy_url(),
            'terms_url' => home_url('/terms/'),
            'support_email' => get_option('admin_email'),
            'current_year' => date('Y'),
            'logo_url' => $this->get_logo_url(),
        ], $data);
    }

    /**
     * Map notification type to human-readable description
     */
    private function get_event_description($type)
    {
        $descriptions = [
            'pillpalnow_subscription_confirm' => 'A new subscription was activated.',
            'pillpalnow_payment_success' => 'A subscription payment was successfully processed.',
            'pillpalnow_payment_failed' => 'A subscription payment has failed.',
            'pillpalnow_cancellation_confirm' => 'A subscription was cancelled.',
            'pillpalnow_refund_confirm' => 'A refund was processed.',
            'pillpalnow_renewal_reminder' => 'A renewal reminder was sent.',
        ];

        return isset($descriptions[$type]) ? $descriptions[$type] : 'A subscription event occurred.';
    }
}
