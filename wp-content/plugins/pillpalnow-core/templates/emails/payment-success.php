<?php
/**
 * Payment Success Email Template
 * 
 * Variables: $user_name, $amount, $currency, $invoice_id, $invoice_url, 
 *            $invoice_pdf, $next_billing_date, $manage_url, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$amount = isset($amount) ? $amount : '0.00';
$currency = isset($currency) ? $currency : 'USD';
$invoice_id = isset($invoice_id) ? $invoice_id : '';
$invoice_url = isset($invoice_url) ? $invoice_url : '';
$invoice_pdf = isset($invoice_pdf) ? $invoice_pdf : '';
$next_billing_date = isset($next_billing_date) ? $next_billing_date : '';

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Your payment has been successfully processed. Here are the details:
</p>

<!-- Payment Details -->
<div class="success-box"
    style="background-color:#f0fff4; border-left:4px solid #38a169; border-radius:0 8px 8px 0; padding:20px 24px; margin:24px 0;">
    <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Amount Paid:</strong> $
        <?php echo esc_html($amount); ?>
        <?php echo esc_html($currency); ?>
    </p>
    <?php if ($invoice_id): ?>
        <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Invoice ID:</strong>
            <?php echo esc_html($invoice_id); ?>
        </p>
    <?php endif; ?>
    <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Status:</strong> ✅ Paid</p>
    <?php if ($next_billing_date): ?>
        <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Next Billing:</strong>
            <?php echo esc_html($next_billing_date); ?>
        </p>
    <?php endif; ?>
</div>

<!-- CTA Buttons -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <?php if ($invoice_pdf): ?>
        <tr>
            <td align="center" style="padding:8px 0;">
                <a href="<?php echo esc_url($invoice_pdf); ?>" class="btn-primary"
                    style="display:inline-block; background:linear-gradient(135deg,#2d5a88 0%,#1e3a5f 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">Download
                    Invoice (PDF)</a>
            </td>
        </tr>
    <?php endif; ?>
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url($manage_url); ?>" class="btn-secondary"
                style="display:inline-block; background-color:#e2e8f0; color:#2d3748; text-decoration:none; padding:12px 28px; border-radius:8px; font-size:14px; font-weight:500;">View
                Account</a>
        </td>
    </tr>
</table>

<hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:28px 0;">

<p style="font-size:14px; line-height:1.6; color:#718096; margin:0;">
    This receipt was sent to
    <?php echo esc_html($user_email); ?>. If this was unexpected, please <a
        href="mailto:<?php echo esc_attr($support_email); ?>" style="color:#2d5a88; text-decoration:underline;">contact
        support</a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>