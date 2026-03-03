<?php
/**
 * Email Header Partial
 * 
 * Shared branded header for all PillPalNow emails.
 * 
 * Variables available: $logo_url, $email (email instance)
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

// Load styles
require_once dirname(__FILE__) . '/email-styles.php';
$styles = pillpalnow_email_get_styles();

$heading = isset($email) && method_exists($email, 'get_heading') ? $email->get_heading() : 'PillPalNow';
$logo = isset($logo_url) ? $logo_url : '';
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml"
    xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>
        <?php echo esc_html($heading); ?>
    </title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:AllowPNG/>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        <?php echo $styles; ?>
    </style>
</head>

<body
    style="margin:0; padding:0; background-color:#f4f7fa; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <!--[if mso]>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f7fa;">
    <tr><td align="center">
    <![endif]-->

    <div class="email-wrapper" style="width:100%; background-color:#f4f7fa; padding:40px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="email-container"
            style="max-width:600px; margin:0 auto; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 6px rgba(0,0,0,0.07);">
            <!-- Header -->
            <tr>
                <td class="email-header"
                    style="background:linear-gradient(135deg,#1e3a5f 0%,#2d5a88 100%); padding:32px 40px; text-align:center;">
                    <?php if ($logo): ?>
                        <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
                            class="logo" style="max-height:48px; width:auto;" />
                    <?php else: ?>
                        <div style="font-size:28px; font-weight:800; color:#ffffff; letter-spacing:-0.5px;">PillPalNow</div>
                    <?php endif; ?>
                    <h1 style="color:#ffffff; font-size:24px; font-weight:700; margin:16px 0 0 0; line-height:1.3;">
                        <?php echo esc_html($heading); ?>
                    </h1>
                </td>
            </tr>
            <!-- Body Start -->
            <tr>
                <td class="email-body" style="padding:40px;">