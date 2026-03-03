<?php
/**
 * Refund Confirmation Email Template
 * 
 * Variables: $user_name, $refund_amount, $currency, $refund_reason,
 *            $original_amount, $invoice_id, $manage_url, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$refund_amount = isset($refund_amount) ? $refund_amount : (isset($amount) ? $amount : '0.00');
$currency = isset($currency) ? $currency : 'USD';
$refund_reason = isset($refund_reason) ? $refund_reason : '';
$invoice_id = isset($invoice_id) ? $invoice_id : '';

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    We've processed a refund to your payment method. Here are the details:
</p>

<!-- Refund Details -->
<div class="success-box"
    style="background-color:#f0fff4; border-left:4px solid #38a169; border-radius:0 8px 8px 0; padding:20px 24px; margin:24px 0;">
    <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Refund Amount:</strong> $
        <?php echo esc_html($refund_amount); ?>
        <?php echo esc_html($currency); ?>
    </p>
    <?php if ($invoice_id): ?>
        <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Invoice:</strong>
            <?php echo esc_html($invoice_id); ?>
        </p>
    <?php endif; ?>
    <?php if ($refund_reason): ?>
        <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Reason:</strong>
            <?php echo esc_html($refund_reason); ?>
        </p>
    <?php endif; ?>
    <p style="margin:4px 0; font-size:14px; color:#276749;"><strong>Status:</strong> ✅ Refund Processed</p>
</div>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Please allow 5-10 business days for the refund to appear on your statement, depending on your bank or card issuer.
</p>

<!-- CTA -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
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
    If you have questions about this refund, please <a href="mailto:<?php echo esc_attr($support_email); ?>"
        style="color:#2d5a88; text-decoration:underline;">contact our support team</a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>