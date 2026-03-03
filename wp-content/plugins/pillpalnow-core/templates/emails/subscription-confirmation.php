<?php
/**
 * Subscription Confirmation Email Template
 * 
 * Variables: $user_name, $plan_name, $amount, $next_billing_date, 
 *            $manage_url, $cancel_url, $logo_url, $site_name, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$plan_name = isset($plan_name) ? $plan_name : 'Pro Plan';
$amount = isset($amount) ? $amount : '';
$next_billing_date = isset($next_billing_date) ? $next_billing_date : '';

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Thank you for subscribing to <strong>
        <?php echo esc_html($site_name); ?>
    </strong>! Your subscription is now active and you have full access to all Pro features.
</p>

<!-- Subscription Details -->
<div class="info-box"
    style="background-color:#f0f7ff; border-left:4px solid #2d5a88; border-radius:0 8px 8px 0; padding:20px 24px; margin:24px 0;">
    <p style="margin:4px 0; font-size:14px; color:#2d5a88;"><strong style="color:#1e3a5f;">Plan:</strong>
        <?php echo esc_html($plan_name); ?>
    </p>
    <?php if ($amount): ?>
        <p style="margin:4px 0; font-size:14px; color:#2d5a88;"><strong style="color:#1e3a5f;">Amount:</strong>
            <?php echo esc_html($amount); ?>
        </p>
    <?php endif; ?>
    <?php if ($next_billing_date): ?>
        <p style="margin:4px 0; font-size:14px; color:#2d5a88;"><strong style="color:#1e3a5f;">Next Billing Date:</strong>
            <?php echo esc_html($next_billing_date); ?>
        </p>
    <?php endif; ?>
</div>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 8px 0;">
    Here's what you now have access to:
</p>

<ul style="margin:0 0 20px 0; padding:0 0 0 20px;">
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">✅ Unlimited Medication Tracking</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">✅ Caregiver & Family Mode</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">✅ Advanced Refill Alerts</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">✅ Cloud Sync Across Devices</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">✅ Priority Support</li>
</ul>

<!-- CTA -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url($manage_url); ?>" class="btn-primary"
                style="display:inline-block; background:linear-gradient(135deg,#2d5a88 0%,#1e3a5f 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">Manage
                Your Subscription</a>
        </td>
    </tr>
</table>

<hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:28px 0;">

<p style="font-size:14px; line-height:1.6; color:#718096; margin:0;">
    If you have any questions about your subscription, please don't hesitate to <a
        href="mailto:<?php echo esc_attr($support_email); ?>" style="color:#2d5a88; text-decoration:underline;">contact
        our support team</a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>