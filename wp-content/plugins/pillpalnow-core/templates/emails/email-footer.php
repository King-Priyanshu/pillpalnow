<?php
/**
 * Email Footer Partial
 * 
 * Shared branded footer for all PillPalNow emails.
 * Includes legal links, unsubscribe, and compliance text.
 * 
 * Variables available: $site_name, $privacy_url, $terms_url, 
 *                      $unsubscribe_url, $manage_url, $support_email, $current_year
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

$site_name = isset($site_name) ? $site_name : get_bloginfo('name');
$privacy_url = isset($privacy_url) ? $privacy_url : get_privacy_policy_url();
$terms_url = isset($terms_url) ? $terms_url : home_url('/terms/');
$unsubscribe_url = isset($unsubscribe_url) ? $unsubscribe_url : home_url('/notification-preferences/');
$manage_url = isset($manage_url) ? $manage_url : home_url('/manage-subscription/');
$support_email = isset($support_email) ? $support_email : get_option('admin_email');
$current_year = isset($current_year) ? $current_year : date('Y');
?>
</td>
</tr>
<!-- Footer -->
<tr>
    <td class="email-footer"
        style="background-color:#f8fafc; padding:28px 40px; text-align:center; border-top:1px solid #e2e8f0;">
        <!-- Management Links -->
        <?php if (!empty($manage_url)): ?>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
                <tr>
                    <td align="center">
                        <a href="<?php echo esc_url($manage_url); ?>"
                            style="display:inline-block; background-color:#e2e8f0; color:#2d3748; text-decoration:none; padding:10px 24px; border-radius:6px; font-size:13px; font-weight:500;">Manage
                            Subscription</a>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <!-- Legal Links -->
        <div class="footer-links" style="margin:12px 0;">
            <?php if ($privacy_url): ?>
                <a href="<?php echo esc_url($privacy_url); ?>"
                    style="color:#718096; text-decoration:none; font-size:12px; margin:0 8px;">Privacy Policy</a>
            <?php endif; ?>
            <span style="color:#cbd5e0;">|</span>
            <?php if ($terms_url): ?>
                <a href="<?php echo esc_url($terms_url); ?>"
                    style="color:#718096; text-decoration:none; font-size:12px; margin:0 8px;">Terms of Service</a>
            <?php endif; ?>
            <span style="color:#cbd5e0;">|</span>
            <a href="<?php echo esc_url($unsubscribe_url); ?>"
                style="color:#718096; text-decoration:none; font-size:12px; margin:0 8px;">Email Preferences</a>
        </div>

        <!-- Contact -->
        <p style="font-size:12px; color:#a0aec0; line-height:1.5; margin:12px 0 4px 0;">
            Questions? Contact us at <a href="mailto:<?php echo esc_attr($support_email); ?>"
                style="color:#718096; text-decoration:underline;">
                <?php echo esc_html($support_email); ?>
            </a>
        </p>

        <!-- Copyright -->
        <p style="font-size:12px; color:#a0aec0; line-height:1.5; margin:4px 0;">
            &copy;
            <?php echo esc_html($current_year); ?>
            <?php echo esc_html($site_name); ?>. All rights reserved.
        </p>

        <!-- Compliance -->
        <p style="font-size:11px; color:#cbd5e0; line-height:1.4; margin:12px 0 0 0;">
            You are receiving this email because you have an active account with
            <?php echo esc_html($site_name); ?>.<br>
            To stop receiving these emails, <a href="<?php echo esc_url($unsubscribe_url); ?>"
                style="color:#a0aec0; text-decoration:underline;">manage your preferences</a>.
        </p>
    </td>
</tr>
</table>
</div>

<!--[if mso]>
    </td></tr></table>
    <![endif]-->
</body>

</html>