<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Auth_Flow
 * 
 * Handles the flow: Magic Link Login -> Set Password -> Dashboard
 */
class PillPalNow_Auth_Flow
{
    public static function init()
    {
        // Hook into login success (fired from Magic Login or standard UM login)
        add_action('um_after_login', array(__CLASS__, 'check_child_password_status'));

        // Shortcode for the "Set Password" page
        add_shortcode('um_set_child_password', array(__CLASS__, 'render_set_password_form'));

        // Ensure the "Set Child Password" page exists
        add_action('admin_init', array(__CLASS__, 'ensure_set_password_page_exists'));
    }

    /**
     * Check if user needs to set a password after login
     * 
     * @param int $user_id
     */
    public static function check_child_password_status($user_id)
    {
        // Only enforce for family_member role or generic users added via our flow
        // We check a specific meta: 'child_password_set'

        // If password is NOT set (meta is empty/false), redirect to setup page
        if (!get_user_meta($user_id, 'child_password_set', true)) {
            // Prevent redirect loop if we are already on the set-password page?
            // Rely on wp_redirect to handle it, but better to check if we are already there?
            // Since this hook happens *during* login request processing, we redirect immediately.

            $redirect_url = home_url('/set-child-password/');
            // Append user_id for context if needed, though get_current_user_id() usually works after auth cookie set. 
            // However, verify_login sets auth cookie but global user might not be set yet in that specific execution context.
            // Let's pass user_id just in case, or rely on cookie which should be set by now.

            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Shortcode: [um_set_child_password]
     */
    public static function render_set_password_form()
    {
        if (!is_user_logged_in()) {
            return '<p>Please login first.</p>';
        }

        $user_id = get_current_user_id();

        // If password already set, show success or redirect
        if (get_user_meta($user_id, 'child_password_set', true)) {
            return '<div class="um-message success">You have already set your password. <a href="' . home_url('/dashboard') . '">Go to Dashboard</a></div>';
        }

        // Handle Form Submission
        if (isset($_POST['pillpalnow_set_password_nonce']) && wp_verify_nonce($_POST['pillpalnow_set_password_nonce'], 'pillpalnow_set_password_action')) {

            if (empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
                $error = 'Please enter and confirm your new password.';
            } elseif ($_POST['new_password'] !== $_POST['confirm_password']) {
                $error = 'Passwords do not match.';
            } else {
                // Set Password
                wp_set_password($_POST['new_password'], $user_id);

                // Mark as set
                update_user_meta($user_id, 'child_password_set', 1);

                // Re-login user because wp_set_password logs them out
                $creds = array(
                    'user_login' => wp_get_current_user()->user_login,
                    'user_password' => $_POST['new_password'],
                    'remember' => true
                );
                $user = wp_signon($creds, false);

                if (is_wp_error($user)) {
                    // Fallback if auto-relogin fails (unlikely)
                    return '<div class="um-message success">Password set! Please <a href="' . wp_login_url() . '">login again</a> with your new password.</div>';
                }

                // Redirect to dashboard
                wp_redirect(home_url('/dashboard'));
                exit;
            }
        }

        ob_start();
        ?>
        <div class="um-form-container"
            style="max-width: 400px; margin: 0 auto; padding: 20px; background: #1f2937; border-radius: 8px; color: white;">
            <h3>Set Your Password</h3>
            <p style="color: #9ca3af; margin-bottom: 20px;">Welcome! Please set a secure password for your account.</p>

            <?php if (isset($error)): ?>
                <div
                    style="background: rgba(239,68,68,0.2); color: #ef4444; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <?php echo esc_html($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('pillpalnow_set_password_action', 'pillpalnow_set_password_nonce'); ?>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">New Password</label>
                    <input type="password" name="new_password" required
                        style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #4b5563; background: #374151; color: white;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px;">Confirm Password</label>
                    <input type="password" name="confirm_password" required
                        style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #4b5563; background: #374151; color: white;">
                </div>

                <button type="submit"
                    style="width: 100%; padding: 10px; background: #2563eb; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
                    Set Password & Login
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Create the page programmatically if it doesn't exist
     */
    public static function ensure_set_password_page_exists()
    {
        if (get_transient('pillpalnow_check_set_password_page')) {
            return;
        }

        $page = get_page_by_path('set-child-password');
        if (!$page) {
            wp_insert_post(array(
                'post_title' => 'Set Password',
                'post_name' => 'set-child-password',
                'post_content' => '[um_set_child_password]',
                'post_status' => 'publish',
                'post_type' => 'page'
            ));
        }

        set_transient('pillpalnow_check_set_password_page', 1, DAY_IN_SECONDS);
    }
}

// Initialize
PillPalNow_Auth_Flow::init();
