<?php
/**
 * Template Name: Caregiver Signup / Redirect Page
 *
 * This page is shown to users outside the United States who are blocked via GEO restriction.
 * It reads a ?country=XX parameter to display localized contact info.
 */

// If a US user somehow lands here (e.g. direct link), redirect them to home?
// The requirement says: "US IP -> Page never shown".
// We assume the geo-blocking logic redirects non-US IPs TO this page.
// However, adding a safeguard check for safety if we had GeoIP capability here would be good.
// Since we don't have a reliable WP-native GeoIP function in strict context without a plugin,
// we will focus on the content rendering rules as requested.

$country_code = isset($_GET['country']) ? strtoupper(sanitize_text_field($_GET['country'])) : '';

$content_map = [
    'FR' => [
        'country_name' => 'France',
        'message' => 'Pour le service en France, contactez votre soignant local :',
        'phone' => '+33 1 23 45 67 89',
        'phone_display' => '+33 1 23 45 67 89',
    ],
    'ES' => [
        'country_name' => 'Spain',
        'message' => 'Para el servicio en España, contacte a su cuidador local :',
        'phone' => '+34 912 345 678',
        'phone_display' => '+34 912 345 678',
    ],
    'DE' => [
        'country_name' => 'Germany',
        'message' => 'Für Service in Deutschland wenden Sie sich bitte an Ihren lokalen Betreuer:',
        'phone' => '+49 30 123456',
        'phone_display' => '+49 30 123456',
    ],
];

// Fallback
$fallback = [
    'country_name' => 'International',
    'message' => 'Please contact your local caregiver for assistance:',
    'phone' => '+1 800 000 0000',
    'phone_display' => '+1 800 000 0000',
];

$selected_content = isset($content_map[$country_code]) ? $content_map[$country_code] : $fallback;

// Remove header/footer if requested to be "Clean", but usually WP pages need wp_head() for styles.
// The prompt says "English UI (localized lines allowed per country)" and "No forms, no login, no signup actions".
// It also mentions "AppMySite-friendly layout".
// We'll use a simplified header structure or just output raw HTML with wp_head to ensure styles load.

// Safety Check: Redirect US users to Home if they stumble upon this page
if (function_exists('iqblockcountry_check_ipaddress') && function_exists('iqblockcountry_get_ipaddress')) {
    $current_ip = iqblockcountry_get_ipaddress();
    $current_country = iqblockcountry_check_ipaddress($current_ip);
    if ($current_country === 'US') {
        wp_redirect(home_url());
        exit;
    }
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Service United States Only</title>
    <?php wp_head(); ?>
    <style>
        body.page-template-page-caregiver-signup {
            background-color: #ffffff;
            /* Or theme default */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            text-align: center;
            color: #333;
        }

        .geo-block-container {
            max-width: 600px;
            padding: 40px 20px;
            margin: 0 auto;
        }

        .geo-header-alert {
            font-size: 24px;
            font-weight: 800;
            color: #D32F2F;
            /* Red alert color */
            margin-bottom: 24px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .geo-primary-message {
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .geo-sub-message {
            font-size: 16px;
            line-height: 1.5;
            color: #666;
            margin-bottom: 40px;
        }

        .geo-local-contact {
            background-color: #f5f5f5;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 40px;
            border: 1px solid #e0e0e0;
        }

        .geo-local-message {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #2c3e50;
        }

        .geo-phone-link {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: #007AFF;
            /* iOS Blue */
            font-size: 22px;
            font-weight: 700;
            padding: 12px 24px;
            border-radius: 50px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .geo-phone-link:active {
            transform: scale(0.98);
        }

        .geo-phone-icon {
            margin-right: 10px;
        }

        .geo-footer-disclaimer {
            font-size: 13px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 24px;
            line-height: 1.5;
        }

        /* Dark mode support if applicable */
        @media (prefers-color-scheme: dark) {
            body.page-template-page-caregiver-signup {
                background-color: #121212;
                color: #e0e0e0;
            }

            .geo-local-contact {
                background-color: #1e1e1e;
                border-color: #333;
            }

            .geo-local-message {
                color: #fff;
            }

            .geo-phone-link {
                background: #2c2c2c;
                color: #4da3ff;
            }

            .geo-sub-message {
                color: #aaa;
            }
        }
    </style>
</head>

<body <?php body_class(); ?>>

    <div class="geo-block-container">

        <div class="geo-header-alert">
            🚨 SERVICE UNITED STATES ONLY 🚨
        </div>

        <div class="geo-primary-message">
            This application is designed exclusively for users located in the United States.
        </div>

        <div class="geo-sub-message">
            The platform supports FDA-approved U.S. medications only and uses RxNorm,
            which is limited to the United States healthcare system.
            <br><br>
            Access to app features is not available in your country.
        </div>

        <div class="geo-local-contact">
            <div class="geo-local-message">
                <?php echo esc_html($selected_content['message']); ?>
            </div>
            <a href="tel:<?php echo esc_attr(str_replace(' ', '', $selected_content['phone'])); ?>"
                class="geo-phone-link">
                <span class="geo-phone-icon">📞</span>
                <?php echo esc_html($selected_content['phone_display']); ?>
            </a>
        </div>

        <div class="geo-footer-disclaimer">
            U.S. users receive full access to medication reminders, dose schedules, and refill alerts.
            <br>
            International access is restricted for regulatory and safety compliance.
        </div>

    </div>

    <?php wp_footer(); ?>
</body>

</html>