<?php
/**
 * Payment Failed Email Template
 * 
 * Variables: $user_name, $amount, $currency, $manage_url, etc.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$amount = isset($amount) ? $amount : '';
$currency = isset($currency) ? $currency : 'USD';

include dirname(__FILE__) . '/email-header.php';
?>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Hi
    <?php echo esc_html($user_name); ?>,
</p>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    We were unable to process your subscription payment. Don't worry — your access won't be removed immediately, but
    please update your payment method to avoid interruption.
</p>

<!-- Alert -->
<div class="alert-box"
    style="background-color:#fff5f5; border-left:4px solid #e53e3e; border-radius:0 8px 8px 0; padding:20px 24px; margin:24px 0;">
    <p style="margin:4px 0; font-size:14px; color:#c53030;"><strong>Status:</strong> ⚠️ Payment Failed</p>
    <?php if ($amount): ?>
        <p style="margin:4px 0; font-size:14px; color:#c53030;"><strong>Amount:</strong> $
            <?php echo esc_html($amount); ?>
            <?php echo esc_html($currency); ?>
        </p>
    <?php endif; ?>
    <p style="margin:4px 0; font-size:14px; color:#c53030;"><strong>Action Required:</strong> Update your payment method
    </p>
</div>

<p style="font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 16px 0;">
    Common reasons for payment failure:
</p>

<ul style="margin:0 0 20px 0; padding:0 0 0 20px;">
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">Insufficient funds on card</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">Expired credit or debit card</li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">Card issuer declined the transaction
    </li>
    <li style="font-size:15px; color:#4a5568; margin:0 0 8px 0; line-height:1.5;">Incorrect payment information</li>
</ul>

<!-- CTA -->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:8px 0;">
            <a href="<?php echo esc_url($manage_url); ?>" class="btn-primary"
                style="display:inline-block; background:linear-gradient(135deg,#e53e3e 0%,#c53030 100%); color:#ffffff; text-decoration:none; padding:14px 32px; border-radius:8px; font-size:16px; font-weight:600;">Update
                Payment Method</a>
        </td>
    </tr>
</table>

<hr class="divider" style="border:none; border-top:1px solid #e2e8f0; margin:28px 0;">

<p style="font-size:14px; line-height:1.6; color:#718096; margin:0;">
    We'll retry the payment automatically. If you need help, please <a
        href="mailto:<?php echo esc_attr($support_email); ?>" style="color:#2d5a88; text-decoration:underline;">contact
        our support team</a>.
</p>

<?php include dirname(__FILE__) . '/email-footer.php'; ?>