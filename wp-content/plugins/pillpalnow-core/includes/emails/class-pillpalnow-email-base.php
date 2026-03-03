<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Email Base Class
 * 
 * Provides base functionality for all PillPalNow transactional emails.
 * Extends WC_Email when WooCommerce is active, otherwise provides
 * standalone email sending capabilities.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */

// Determine if WooCommerce email class is available
if (class_exists('WC_Email')) {
    /**
     * WooCommerce-aware email base class
     */
    abstract class PillPalNow_Email_Base extends WC_Email
    {
        /** @var string Email context for PillPalNow API */
        protected $context_type = 'general';

        /** @var array Template variables */
        protected $template_data = [];

        /** @var string Plugin templates path */
        protected $plugin_template_path;

        /**
         * Constructor
         */
        public function __construct()
        {
            $this->plugin_template_path = PILLPALNOW_PLUGIN_PATH . 'templates/';
            $this->template_base = $this->plugin_template_path;

            // Support theme overrides in: theme/pillpalnow-core/emails/
            $this->template_html = 'emails/' . $this->get_template_filename();
            $this->template_plain = 'emails/plain/' . $this->get_template_filename();

            // Call parent constructor
            parent::__construct();

            // This is not a customer-facing WC order email by default
            $this->customer_email = true;
        }

        /**
         * Get the template filename (without path)
         * Must be implemented by each email class
         * 
         * @return string
         */
        abstract protected function get_template_filename();

        /**
         * Trigger the email
         * 
         * @param int   $user_id User ID
         * @param array $data    Template data
         */
        public function trigger($user_id, $data = [])
        {
            $this->setup_locale();

            $user = get_userdata($user_id);
            if (!$user) {
                $this->restore_locale();
                return;
            }

            $this->recipient = $user->user_email;
            $this->template_data = $this->prepare_template_data($user, $data);

            if ($this->is_enabled() && $this->get_recipient()) {
                $this->send(
                    $this->get_recipient(),
                    $this->get_subject(),
                    $this->get_content(),
                    $this->get_headers(),
                    $this->get_attachments()
                );
            }

            $this->restore_locale();
        }

        /**
         * Prepare template data with common variables
         */
        protected function prepare_template_data($user, $data)
        {
            // Generate secure links
            $manage_url = '';
            $cancel_url = '';
            if (class_exists('PillPalNow_Secure_Token')) {
                $manage_url = PillPalNow_Secure_Token::get_manage_url($user->ID);
                $cancel_url = PillPalNow_Secure_Token::get_cancel_url($user->ID);
            }

            return array_merge([
                'user_name' => $user->display_name,
                'user_email' => $user->user_email,
                'user_id' => $user->ID,
                'site_name' => get_bloginfo('name'),
                'site_url' => home_url('/'),
                'manage_url' => $manage_url,
                'cancel_url' => $cancel_url,
                'privacy_url' => get_privacy_policy_url(),
                'terms_url' => home_url('/terms/'),
                'unsubscribe_url' => home_url('/notification-preferences/'),
                'support_email' => get_option('admin_email'),
                'current_year' => date('Y'),
                'logo_url' => $this->get_logo_url(),
            ], $data);
        }

        /**
         * Get email content (HTML version)
         */
        public function get_content_html()
        {
            return $this->render_template($this->template_html, $this->template_data);
        }

        /**
         * Get email content (Plain text version)
         */
        public function get_content_plain()
        {
            return $this->render_template($this->template_plain, $this->template_data, true);
        }

        /**
         * Render a template file with data
         */
        protected function render_template($template, $data, $plain = false)
        {
            $template_path = $this->locate_template($template);

            if (!$template_path || !file_exists($template_path)) {
                error_log('[PillPalNow Email] Template not found: ' . $template);
                return '';
            }

            // Extract data for template use
            $email = $this;
            extract($data, EXTR_SKIP);

            ob_start();
            include $template_path;
            $content = ob_get_clean();

            if (!$plain) {
                $content = $this->style_inline($content);
            }

            return $content;
        }

        /**
         * Locate a template with theme override support
         * 
         * Search order:
         * 1. theme/pillpalnow-core/{template}
         * 2. plugin/templates/{template}
         */
        protected function locate_template($template)
        {
            // Check theme override
            $theme_path = get_stylesheet_directory() . '/pillpalnow-core/' . $template;
            if (file_exists($theme_path)) {
                return $theme_path;
            }

            // Check parent theme
            if (get_stylesheet_directory() !== get_template_directory()) {
                $parent_path = get_template_directory() . '/pillpalnow-core/' . $template;
                if (file_exists($parent_path)) {
                    return $parent_path;
                }
            }

            // Plugin default
            $plugin_path = $this->plugin_template_path . $template;
            if (file_exists($plugin_path)) {
                return $plugin_path;
            }

            return '';
        }

        /**
         * Apply inline styles to HTML content
         */
        protected function style_inline($content)
        {
            // WooCommerce has a built-in method for this
            if (is_callable('parent::style_inline')) {
                return parent::style_inline($content);
            }
            return $content;
        }

        /**
         * Get logo URL from settings or default
         */
        protected function get_logo_url()
        {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'medium');
                if ($logo_data) {
                    return $logo_data[0];
                }
            }
            // Fallback to a default logo path
            return defined('PILLPALNOW_PLUGIN_URL')
                ? PILLPALNOW_PLUGIN_URL . 'assets/images/pillpalnow-logo.png'
                : plugins_url('assets/images/pillpalnow-logo.png', dirname(dirname(__FILE__)));
        }

        /**
         * Initialize form fields for WooCommerce email settings
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'pillpalnow'),
                    'type' => 'checkbox',
                    'label' => __('Enable this email notification', 'pillpalnow'),
                    'default' => 'yes',
                ),
                'subject' => array(
                    'title' => __('Subject', 'pillpalnow'),
                    'type' => 'text',
                    'description' => sprintf(__('Available placeholders: %s', 'pillpalnow'), '{site_title}, {user_name}'),
                    'placeholder' => $this->get_default_subject(),
                    'default' => '',
                ),
                'heading' => array(
                    'title' => __('Email Heading', 'pillpalnow'),
                    'type' => 'text',
                    'description' => sprintf(__('Available placeholders: %s', 'pillpalnow'), '{site_title}, {user_name}'),
                    'placeholder' => $this->get_default_heading(),
                    'default' => '',
                ),
            );
        }
    }
} else {
    /**
     * Standalone email base class (when WooCommerce is not active)
     */
    abstract class PillPalNow_Email_Base
    {
        /** @var string Email ID */
        public $id = '';

        /** @var string Email title */
        public $title = '';

        /** @var string Email description */
        public $description = '';

        /** @var string Recipient */
        protected $recipient = '';

        /** @var string Subject */
        protected $subject = '';

        /** @var string Heading */
        protected $heading = '';

        /** @var bool Enabled */
        protected $enabled = true;

        /** @var string Email context for PillPalNow API */
        protected $context_type = 'general';

        /** @var array Template variables */
        protected $template_data = [];

        /** @var string Plugin templates path */
        protected $plugin_template_path;

        /**
         * Constructor
         */
        public function __construct()
        {
            $this->plugin_template_path = defined('PILLPALNOW_PLUGIN_PATH')
                ? PILLPALNOW_PLUGIN_PATH . 'templates/'
                : dirname(dirname(__DIR__)) . '/templates/';
        }

        /**
         * Get the template filename
         * @return string
         */
        abstract protected function get_template_filename();

        /**
         * Get default subject
         * @return string
         */
        abstract public function get_default_subject();

        /**
         * Get default heading
         * @return string
         */
        abstract public function get_default_heading();

        /**
         * Trigger the email
         */
        public function trigger($user_id, $data = [])
        {
            $user = get_userdata($user_id);
            if (!$user) {
                return false;
            }

            $this->recipient = $user->user_email;
            $this->template_data = $this->prepare_template_data($user, $data);

            if (!$this->is_enabled() || !$this->recipient) {
                return false;
            }

            $subject = $this->get_subject();
            $html_content = $this->get_content_html();
            $plain_content = $this->get_content_plain();

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
            ];

            // Use PillPalNow Email Service if available for logging/PillPalNow integration
            if (class_exists('PillPalNow_Email_Service')) {
                return PillPalNow_Email_Service::send(
                    $this->recipient,
                    $subject,
                    $html_content,
                    $this->context_type,
                    $user_id,
                    $headers
                );
            }

            return wp_mail($this->recipient, $subject, $html_content, $headers);
        }

        /**
         * Prepare template data
         */
        protected function prepare_template_data($user, $data)
        {
            $manage_url = '';
            $cancel_url = '';
            if (class_exists('PillPalNow_Secure_Token')) {
                $manage_url = PillPalNow_Secure_Token::get_manage_url($user->ID);
                $cancel_url = PillPalNow_Secure_Token::get_cancel_url($user->ID);
            }

            return array_merge([
                'user_name' => $user->display_name,
                'user_email' => $user->user_email,
                'user_id' => $user->ID,
                'site_name' => get_bloginfo('name'),
                'site_url' => home_url('/'),
                'manage_url' => $manage_url,
                'cancel_url' => $cancel_url,
                'privacy_url' => get_privacy_policy_url(),
                'terms_url' => home_url('/terms/'),
                'unsubscribe_url' => home_url('/notification-preferences/'),
                'support_email' => get_option('admin_email'),
                'current_year' => date('Y'),
                'logo_url' => $this->get_logo_url(),
            ], $data);
        }

        /**
         * Get HTML content
         */
        public function get_content_html()
        {
            return $this->render_template(
                'emails/' . $this->get_template_filename(),
                $this->template_data
            );
        }

        /**
         * Get plain text content
         */
        public function get_content_plain()
        {
            return $this->render_template(
                'emails/plain/' . $this->get_template_filename(),
                $this->template_data,
                true
            );
        }

        /**
         * Render template with data
         */
        protected function render_template($template, $data, $plain = false)
        {
            $template_path = $this->locate_template($template);

            if (!$template_path || !file_exists($template_path)) {
                error_log('[PillPalNow Email] Template not found: ' . $template);
                return '';
            }

            $email = $this;
            extract($data, EXTR_SKIP);

            ob_start();
            include $template_path;
            return ob_get_clean();
        }

        /**
         * Locate template with theme override support
         */
        protected function locate_template($template)
        {
            $theme_path = get_stylesheet_directory() . '/pillpalnow-core/' . $template;
            if (file_exists($theme_path)) {
                return $theme_path;
            }

            if (get_stylesheet_directory() !== get_template_directory()) {
                $parent_path = get_template_directory() . '/pillpalnow-core/' . $template;
                if (file_exists($parent_path)) {
                    return $parent_path;
                }
            }

            $plugin_path = $this->plugin_template_path . $template;
            if (file_exists($plugin_path)) {
                return $plugin_path;
            }

            return '';
        }

        /**
         * Get subject with placeholder replacement
         */
        public function get_subject()
        {
            $subject = $this->subject ?: $this->get_default_subject();
            return str_replace(
                ['{site_title}', '{user_name}'],
                [get_bloginfo('name'), $this->template_data['user_name'] ?? ''],
                $subject
            );
        }

        /**
         * Get heading
         */
        public function get_heading()
        {
            return $this->heading ?: $this->get_default_heading();
        }

        /**
         * Get recipient
         */
        public function get_recipient()
        {
            return $this->recipient;
        }

        /**
         * Check if enabled
         */
        public function is_enabled()
        {
            return $this->enabled;
        }

        /**
         * Get logo URL
         */
        protected function get_logo_url()
        {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'medium');
                if ($logo_data) {
                    return $logo_data[0];
                }
            }
            return defined('PILLPALNOW_PLUGIN_URL')
                ? PILLPALNOW_PLUGIN_URL . 'assets/images/pillpalnow-logo.png'
                : plugins_url('assets/images/pillpalnow-logo.png', dirname(dirname(__FILE__)));
        }

        // Stub methods for compatibility
        protected function setup_locale()
        {
        }
        protected function restore_locale()
        {
        }
    }
}
