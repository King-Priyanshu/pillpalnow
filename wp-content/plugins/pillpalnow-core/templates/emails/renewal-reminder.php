<?php
/**
 * Renewal Reminder Email Template
 * 
 * Variables: $user_name, $plan_name, $amount, $renewal_date, 
 *            $days_remaining, $manage_url, $cancel_url, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$plan_name = isset($plan_name) ? $plan_name : 'Pro Plan';
$amount = isset($amount) ? $amount : '';
$renewal_date = isset($renewal_date) ? $renewal_date : (isset($next_billing_date) ? $next_billing_date : '');
$days_remaining = isset($days_remaining) ? $days_remaining : 3;

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    This is a friendly reminder that your
    <?php echo esc_html($site_name); ?> subscription will automatically renew soon.
</p>

<!-- Renewal Details -->
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
    <?php if ($renewal_date): ?>
        <p style="margin:4px 0; font-size:14px; color:#2d5a88;"><strong style="color:#1e3a5f;">Renewal Date:</strong>
            <?php echo esc_html($renewal_date); ?>
        </p>
    <?php endif; ?>
    <p style="margin:4px 0; font-size:14px; color:#2d5a88;"><strong style="color:#1e3a5f;">Renewing In:</strong>
        <?php echo esc_html($days_remaining); ?> days
    </p>
</div>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    No action is needed if you'd like to continue — your subscription will renew automatically using your payment method
    on file.
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    If you'd like to make changes, you can manage your subscription below:
</p>

<!-- CTA Buttons -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url($manage_url); ?>" class="btn-primary"
                style="display:inline-block; background:linear-gradient(135deg,#2d5a88 0%,#1e3a5f 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">Manage
                Subscription</a>
        </td>
    </tr>
    <?php if ($cancel_url): ?>
        <tr>
            <td align="center" style="padding:8px 0;">
                <a href="<?php echo esc_url($cancel_url); ?>" class="btn-secondary"
                    style="display:inline-block; background-color:#e2e8f0; color:#2d3748; text-decoration:none; padding:12px 28px; border-radius:8px; font-size:14px; font-weight:500;">Cancel
                    Subscription</a>
            </td>
        </tr>
    <?php endif; ?>
</table>

<hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:28px 0;">

<p style="font-size:14px; line-height:1.6; color:#718096; margin:0;">
    If you have questions about your upcoming renewal, please <a href="mailto:<?php echo esc_attr($support_email); ?>"
        style="color:#2d5a88; text-decoration:underline;">contact support</a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>