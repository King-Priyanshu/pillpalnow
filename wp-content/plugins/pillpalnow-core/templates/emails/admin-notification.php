<?php
/**
 * Admin Notification Email Template
 * 
 * Variables: $user_name, $notification_type, $event_description,
 *            $affected_user_name, $affected_user_email, $affected_user_id,
 *            $manage_url, $site_name, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$notification_type = isset($notification_type) ? $notification_type : 'unknown';
$event_description = isset($event_description) ? $event_description : 'A subscription event occurred.';
$affected_user_name = isset($affected_user_name) ? $affected_user_name : 'Unknown User';
$affected_user_email = isset($affected_user_email) ? $affected_user_email : '';
$affected_user_id = isset($affected_user_id) ? $affected_user_id : 0;

// Type labels for admin display
$type_labels = [
    'pillpalnow_subscription_confirm' => ['🎉 New Subscription', '#38a169'],
    'pillpalnow_payment_success' => ['💳 Payment Received', '#38a169'],
    'pillpalnow_payment_failed' => ['⚠️ Payment Failed', '#e53e3e'],
    'pillpalnow_cancellation_confirm' => ['🚪 Subscription Cancelled', '#e67e22'],
    'pillpalnow_refund_confirm' => ['💰 Refund Processed', '#3182ce'],
    'pillpalnow_renewal_reminder' => ['🔔 Renewal Reminder Sent', '#805ad5'],
];

$type_info = isset($type_labels[$notification_type])
    ? $type_labels[$notification_type]
    : ['📋 Subscription Event', '#4a5568'];

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi Admin,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    The following subscription event just occurred:
</p>

<!-- Event Details -->
<div style="background-color:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px 24px; margin:24px 0;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td style="padding:8px 0;">
                <span
                    style="display:inline-block; background-color:<?php echo esc_attr($type_info[1]); ?>; color:#ffffff; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600;">
                    <?php echo esc_html($type_info[0]); ?>
                </span>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="billing-table"
        style="margin:12px 0 0 0;">
        <tr>
            <td style="padding:8px 0; font-size:14px; color:#718096; width:40%;">Event</td>
            <td style="padding:8px 0; font-size:14px; color:#2d3748; font-weight:500;">
                <?php echo esc_html($event_description); ?>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 0; font-size:14px; color:#718096; border-top:1px solid #edf2f7;">User</td>
            <td style="padding:8px 0; font-size:14px; color:#2d3748; font-weight:500; border-top:1px solid #edf2f7;">
                <?php echo esc_html($affected_user_name); ?>
            </td>
        </tr>
        <?php if ($affected_user_email): ?>
            <tr>
                <td style="padding:8px 0; font-size:14px; color:#718096; border-top:1px solid #edf2f7;">Email</td>
                <td style="padding:8px 0; font-size:14px; color:#2d3748; border-top:1px solid #edf2f7;">
                    <a href="mailto:<?php echo esc_attr($affected_user_email); ?>"
                        style="color:#2d5a88; text-decoration:none;">
                        <?php echo esc_html($affected_user_email); ?>
                    </a>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td style="padding:8px 0; font-size:14px; color:#718096; border-top:1px solid #edf2f7;">User ID</td>
            <td style="padding:8px 0; font-size:14px; color:#2d3748; border-top:1px solid #edf2f7;">#
                <?php echo esc_html($affected_user_id); ?>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 0; font-size:14px; color:#718096; border-top:1px solid #edf2f7;">Time</td>
            <td style="padding:8px 0; font-size:14px; color:#2d3748; border-top:1px solid #edf2f7;">
                <?php echo esc_html(current_time('F j, Y g:i A')); ?>
            </td>
        </tr>
    </table>
</div>

<!-- Admin CTA -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <?php if ($affected_user_id): ?>
        <tr>
            <td align="center" style="padding:8px 0;">
                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $affected_user_id)); ?>"
                    class="btn-primary"
                    style="display:inline-block; background:linear-gradient(135deg,#2d5a88 0%,#1e3a5f 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">View
                    User Profile</a>
            </td>
        </tr>
    <?php endif; ?>
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=pillpalnow-logs')); ?>" class="btn-secondary"
                style="display:inline-block; background-color:#e2e8f0; color:#2d3748; text-decoration:none; padding:12px 28px; border-radius:8px; font-size:14px; font-weight:500;">View
                Notification Logs</a>
        </td>
    </tr>
</table>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>