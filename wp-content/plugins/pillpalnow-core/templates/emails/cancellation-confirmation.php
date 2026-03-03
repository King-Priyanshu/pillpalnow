<?php
/**
 * Cancellation Confirmation Email Template
 * 
 * Variables: $user_name, $plan_name, $end_date, $manage_url, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$plan_name = isset($plan_name) ? $plan_name : 'Pro Plan';
$end_date = isset($end_date) ? $end_date : 'the end of your current billing period';

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    We've received your cancellation request. Your
    <?php echo esc_html($plan_name); ?> subscription has been cancelled.
</p>

<!-- Grace period notice -->
<div class="warning-box"
    style="background-color:#fff8f0; border-left:4px solid #e67e22; border-radius:0 8px 8px 0; padding:20px 24px; margin:24px 0;">
    <p style="margin:4px 0; font-size:14px; color:#8b5e3c;"><strong>Important:</strong> You will retain full access to
        Pro features until <strong>
            <?php echo esc_html($end_date); ?>
        </strong>.</p>
    <p style="margin:4px 0; font-size:14px; color:#8b5e3c;">After this date, your account will revert to the free plan.
    </p>
</div>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 8px 0;">
    After your access ends, you'll lose:
</p>

<ul style="margin:0 0 20px 0; padding:0 0 0 20px;">
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">❌ Unlimited Medication Tracking</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">❌ Caregiver & Family Mode</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">❌ Advanced Refill Alerts</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">❌ Cloud Sync Across Devices</li>
</ul>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Changed your mind? You can reactivate your subscription anytime before your access expires.
</p>

<!-- CTA -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url($manage_url); ?>" class="btn-primary"
                style="display:inline-block; background:linear-gradient(135deg,#2d5a88 0%,#1e3a5f 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">Reactivate
                Subscription</a>
        </td>
    </tr>
</table>

<hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:28px 0;">

<p style="font-size:14px; line-height:1.6; color:#718096; margin:0;">
    We're sorry to see you go. If there's anything we could have done better, we'd love to hear your feedback at <a
        href="mailto:<?php echo esc_attr($support_email); ?>" style="color:#2d5a88; text-decoration:underline;">
        <?php echo esc_html($support_email); ?>
    </a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>