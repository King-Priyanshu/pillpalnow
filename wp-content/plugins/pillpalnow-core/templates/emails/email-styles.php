<?php
/**
 * Email Inline Styles
 * 
 * Shared CSS styles returned as a string for inline injection.
 * Email clients strip <style> blocks, so these must be applied inline.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get shared email CSS styles
 * @return string CSS string
 */
function pillpalnow_email_get_styles()
{
    return '
    /* Reset */
    body, table, td, p, a, li, blockquote {
        -webkit-text-size-adjust: 100%;
        -ms-text-size-adjust: 100%;
        margin: 0;
        padding: 0;
    }
    table, td {
        mso-table-lspace: 0pt;
        mso-table-rspace: 0pt;
        border-collapse: collapse;
    }
    img {
        -ms-interpolation-mode: bicubic;
        border: 0;
        height: auto;
        line-height: 100%;
        outline: none;
        text-decoration: none;
    }

    /* Base */
    body {
        background-color: #f4f7fa;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 16px;
        line-height: 1.6;
        color: #333333;
        margin: 0;
        padding: 0;
        width: 100% !important;
    }

    /* Container */
    .email-wrapper {
        width: 100%;
        background-color: #f4f7fa;
        padding: 40px 0;
    }
    .email-container {
        max-width: 600px;
        margin: 0 auto;
        background-color: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    }

    /* Header */
    .email-header {
        background: linear-gradient(135deg, #1e3a5f 0%, #2d5a88 100%);
        padding: 32px 40px;
        text-align: center;
    }
    .email-header img.logo {
        max-height: 48px;
        width: auto;
    }
    .email-header h1 {
        color: #ffffff;
        font-size: 24px;
        font-weight: 700;
        margin: 16px 0 0 0;
        line-height: 1.3;
    }

    /* Body */
    .email-body {
        padding: 40px;
    }
    .email-body p {
        font-size: 16px;
        line-height: 1.6;
        color: #4a5568;
        margin: 0 0 16px 0;
    }
    .email-body h2 {
        font-size: 20px;
        font-weight: 600;
        color: #1e3a5f;
        margin: 0 0 16px 0;
    }
    .email-body ul {
        margin: 0 0 20px 0;
        padding: 0 0 0 20px;
    }
    .email-body ul li {
        font-size: 15px;
        color: #4a5568;
        margin: 0 0 8px 0;
        line-height: 1.5;
    }

    /* Info Box */
    .info-box {
        background-color: #f0f7ff;
        border-left: 4px solid #2d5a88;
        border-radius: 0 8px 8px 0;
        padding: 20px 24px;
        margin: 24px 0;
    }
    .info-box p {
        margin: 4px 0;
        font-size: 14px;
        color: #2d5a88;
    }
    .info-box strong {
        color: #1e3a5f;
    }

    /* Warning Box */
    .warning-box {
        background-color: #fff8f0;
        border-left: 4px solid #e67e22;
        border-radius: 0 8px 8px 0;
        padding: 20px 24px;
        margin: 24px 0;
    }
    .warning-box p {
        margin: 4px 0;
        font-size: 14px;
        color: #8b5e3c;
    }

    /* Alert Box */
    .alert-box {
        background-color: #fff5f5;
        border-left: 4px solid #e53e3e;
        border-radius: 0 8px 8px 0;
        padding: 20px 24px;
        margin: 24px 0;
    }
    .alert-box p {
        margin: 4px 0;
        font-size: 14px;
        color: #c53030;
    }

    /* Success Box */
    .success-box {
        background-color: #f0fff4;
        border-left: 4px solid #38a169;
        border-radius: 0 8px 8px 0;
        padding: 20px 24px;
        margin: 24px 0;
    }
    .success-box p {
        margin: 4px 0;
        font-size: 14px;
        color: #276749;
    }

    /* CTA Button */
    .btn-primary {
        display: inline-block;
        background: linear-gradient(135deg, #2d5a88 0%, #1e3a5f 100%);
        color: #ffffff !important;
        text-decoration: none;
        padding: 14px 32px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        text-align: center;
        margin: 8px 0;
        mso-padding-alt: 0;
    }
    .btn-secondary {
        display: inline-block;
        background-color: #e2e8f0;
        color: #2d3748 !important;
        text-decoration: none;
        padding: 12px 28px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        text-align: center;
        margin: 8px 0;
    }
    .btn-danger {
        display: inline-block;
        background-color: #e53e3e;
        color: #ffffff !important;
        text-decoration: none;
        padding: 12px 28px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        text-align: center;
        margin: 8px 0;
    }

    /* Divider */
    .divider {
        border: none;
        border-top: 1px solid #e2e8f0;
        margin: 28px 0;
    }

    /* Footer */
    .email-footer {
        background-color: #f8fafc;
        padding: 28px 40px;
        text-align: center;
        border-top: 1px solid #e2e8f0;
    }
    .email-footer p {
        font-size: 12px;
        color: #a0aec0;
        line-height: 1.5;
        margin: 0 0 8px 0;
    }
    .email-footer a {
        color: #718096;
        text-decoration: underline;
    }
    .footer-links {
        margin: 12px 0;
    }
    .footer-links a {
        color: #718096;
        text-decoration: none;
        font-size: 12px;
        margin: 0 8px;
    }

    /* Table for billing details */
    .billing-table {
        width: 100%;
        border-collapse: collapse;
        margin: 16px 0;
    }
    .billing-table th {
        text-align: left;
        padding: 10px 12px;
        background-color: #f7fafc;
        font-size: 13px;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
    }
    .billing-table td {
        padding: 12px;
        font-size: 14px;
        color: #4a5568;
        border-bottom: 1px solid #edf2f7;
    }

    /* Responsive */
    @media only screen and (max-width: 620px) {
        .email-container {
            width: 100% !important;
            border-radius: 0 !important;
        }
        .email-header,
        .email-body,
        .email-footer {
            padding: 24px 20px !important;
        }
        .email-header h1 {
            font-size: 20px !important;
        }
        .btn-primary,
        .btn-secondary,
        .btn-danger {
            display: block !important;
            width: 100% !important;
            text-align: center !important;
            box-sizing: border-box !important;
        }
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .email-wrapper {
            background-color: #1a202c !important;
        }
        .email-container {
            background-color: #2d3748 !important;
        }
        .email-body p,
        .email-body ul li {
            color: #e2e8f0 !important;
        }
        .email-body h2 {
            color: #63b3ed !important;
        }
        .info-box {
            background-color: #2a4365 !important;
        }
        .info-box p,
        .info-box strong {
            color: #bee3f8 !important;
        }
        .email-footer {
            background-color: #1a202c !important;
            border-top-color: #4a5568 !important;
        }
        .email-footer p,
        .email-footer a {
            color: #a0aec0 !important;
        }
    }
    ';
}
