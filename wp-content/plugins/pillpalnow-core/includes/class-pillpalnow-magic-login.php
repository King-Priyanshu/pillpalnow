<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Magic_Login
 * 
 * Handles magic link generation, email sending, and authentication.
 */
class PillPalNow_Magic_Login
{
    /**
     * Generate and store a magic token for a user
     * 
     * @param int $user_id User ID
     * @return string|bool The magic token or false on failure
     */
    public static function generate_token($user_id)
    {
        // 1. Check rate limit for this user (5 requests per hour)
        $rate_key = 'pillpalnow_magic_limit_' . $user_id;
        $attempts = get_transient($rate_key) ?: 0;

        if ($attempts >= 5) {
            return false; // Rate limited
        }

        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        // 2. Generate secure token
        $token = bin2hex(random_bytes(32)); // 64 chars
        $token_hash = hash('sha256', $token);

        // 3. Store hash and expiry (15 minutes)
        $expires = time() + (15 * MINUTE_IN_SECONDS);

        update_user_meta($user_id, 'pillpalnow_magic_token_hash', $token_hash);
        update_user_meta($user_id, 'pillpalnow_magic_token_expires', $expires);

        return $token;
    }

    /**
     * Send magic link email to user
     * 
     * @param int $user_id User ID
     * @param string $email User Email
     * @param string $name User Name
     * @param string $parent_name Parent Name (who added them)
     * @return bool Success status
     */
    public static function send_link($user_id, $email, $name, $parent_name)
    {
        $token = self::generate_token($user_id);

        if (!$token) {
            error_log("[PILLPALNOW] Magic Link Rate Limited for User $user_id");
            return false;
        }

        $magic_link = add_query_arg([
            'token' => $token,
            'uid' => $user_id // Optional, helps with lookup speed/collision avoidance if needed, but token is key
        ], home_url('/magic-login'));

        $site_name = get_bloginfo('name');
        $subject = sprintf(__('%s - Your Login Link', 'pillpalnow'), $site_name);

        $message = self::get_email_template($name, $magic_link, $site_name, $parent_name);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );

        // Set email context for logging (if PillPalNow API is available)
        if (function_exists('pillpalnow_set_email_context')) {
            pillpalnow_set_email_context('magic_link', $user_id);
        }

        $sent = wp_mail($email, $subject, $message, $headers);

        if ($sent) {
            error_log("[PILLPALNOW] Magic link sent to $email by $parent_name");

            // Notify parent that magic link was sent to their child
            self::notify_parent_magic_link_sent($user_id, $name, $email);
        } else {
            error_log("[PILLPALNOW] Failed to send magic link to $email");
        }

        return $sent;
    }

    /**
     * Validate token and login user
     * 
     * @param string $token Token from URL
     * @param int $user_id User ID from URL (optional but good for double-check)
     * @return bool|WP_Error True on success, Error on failure
     */
    public static function validate_login($token, $user_id_check = 0)
    {
        // Search for user with this token hash?
        // Actually, scanning all users is inefficient. 
        // We should really pass the User ID in the URL too, or store the token in a custom table.
        // For standard WP meta, passing UID is efficient.

        if (empty($user_id_check)) {
            // If we didn't pass UID, we'd have to scan all users which is bad.
            // But let's assume we update the URL structure to include ?uid=123
            return new WP_Error('missing_uid', 'User ID missing from link.');
        }

        $stored_hash = get_user_meta($user_id_check, 'pillpalnow_magic_token_hash', true);
        $expires = get_user_meta($user_id_check, 'pillpalnow_magic_token_expires', true);

        if (!$stored_hash || !$expires) {
            return new WP_Error('invalid_token', 'Invalid login link.');
        }

        // Check hash
        if (!hash_equals($stored_hash, hash('sha256', $token))) {
            return new WP_Error('invalid_token', 'Invalid login link.');
        }

        // Check expiry
        if (time() > $expires) {
            return new WP_Error('expired_token', 'This login link has expired.');
        }

        // Success - Login User
        wp_clear_auth_cookie();
        wp_set_current_user($user_id_check);
        wp_set_auth_cookie($user_id_check);

        // Invalidate token
        delete_user_meta($user_id_check, 'pillpalnow_magic_token_hash');
        delete_user_meta($user_id_check, 'pillpalnow_magic_token_expires');

        // Notify parent that child has logged in
        self::notify_parent_child_logged_in($user_id_check);

        // Trigger Ultimate Member post-login hook (or our custom auth flow)
        do_action('um_after_login', $user_id_check);

        return true;
    }

    /**
     * Notify parent when child logs in via magic link
     * 
     * @param int $child_user_id The child user ID who just logged in
     */
    private static function notify_parent_child_logged_in($child_user_id)
    {
        // Find the family_member post linked to this user
        $family_members = get_posts(array(
            'post_type' => 'family_member',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => 'linked_user_id',
                    'value' => $child_user_id,
                ),
            ),
        ));

        if (empty($family_members)) {
            return; // Not a family member
        }

        $family_member = $family_members[0];
        $parent_user_id = (int) $family_member->post_author;
        $child_name = $family_member->post_title;

        if (!$parent_user_id || $parent_user_id === $child_user_id) {
            return; // No parent or same user
        }

        // Create notification for parent
        if (class_exists('PillPalNow_Notifications')) {
            $login_time = current_time('g:i A');

            PillPalNow_Notifications::create(
                $parent_user_id,
                PillPalNow_Notifications::TYPE_FAMILY_LOGIN,
                sprintf(__('%s has logged in', 'pillpalnow'), $child_name),
                sprintf(__('Your family member logged in at %s', 'pillpalnow'), $login_time),
                null,
                home_url('/add-family-member')
            );

            error_log("[PILLPALNOW] Parent (ID: $parent_user_id) notified of child login: $child_name");
        }
    }

    /**
     * Notify parent when magic link is sent to child
     * 
     * @param int $child_user_id The child user ID
     * @param string $child_name Child's display name
     * @param string $child_email Child's email
     */
    private static function notify_parent_magic_link_sent($child_user_id, $child_name, $child_email)
    {
        // Find the family_member post linked to this user
        $family_members = get_posts(array(
            'post_type' => 'family_member',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => 'linked_user_id',
                    'value' => $child_user_id,
                ),
            ),
        ));

        if (empty($family_members)) {
            return; // Not a family member
        }

        $family_member = $family_members[0];
        $parent_user_id = (int) $family_member->post_author;

        if (!$parent_user_id || $parent_user_id === $child_user_id) {
            return; // No parent or same user
        }

        // Create notification for parent
        if (class_exists('PillPalNow_Notifications')) {
            PillPalNow_Notifications::create(
                $parent_user_id,
                PillPalNow_Notifications::TYPE_MAGIC_LINK_SENT,
                sprintf(__('Login link sent to %s', 'pillpalnow'), $child_name),
                sprintf(__('A magic login link was sent to %s', 'pillpalnow'), $child_email),
                null,
                home_url('/add-family-member')
            );

            error_log("[PILLPALNOW] Parent (ID: $parent_user_id) notified of magic link sent to: $child_name");
        }
    }

    /**
     * Handle View Magic Link Request (Admin/Parent)
     */
    public static function handle_view_magic_link_request()
    {
        if (!isset($_POST['pillpalnow_view_magic_nonce']) || !wp_verify_nonce($_POST['pillpalnow_view_magic_nonce'], 'pillpalnow_view_magic_action')) {
            wp_die('Security check failed');
        }

        $email = sanitize_email($_POST['email']);
        $redirect_url = isset($_POST['redirect_to_url']) ? esc_url_raw($_POST['redirect_to_url']) : home_url();

        if (!is_email($email)) {
            wp_die('Invalid Email');
        }

        $user = get_user_by('email', $email);

        if ($user) {
            if (!current_user_can('edit_user', $user->ID) && !current_user_can('administrator') && get_current_user_id() !== $user->ID) {
                // Strict parent check: Verify current user is the parent of this family member
                $current_user_id = get_current_user_id();
                $target_user_id = $user->ID;
                
                // Check if target user is a family member (has parent_user_id or pillpalnow_parent_user meta)
                $target_parent_id = (int) get_user_meta($target_user_id, 'pillpalnow_parent_user', true);
                $target_legacy_parent_id = (int) get_user_meta($target_user_id, 'parent_user_id', true);
                
                // If target has a parent, current user must be that parent
                if ($target_parent_id > 0 && $target_parent_id !== $current_user_id) {
                    wp_die('You do not have permission to send magic links to this user.', 'Permission Denied', array('response' => 403));
                }
                if ($target_legacy_parent_id > 0 && $target_legacy_parent_id !== $current_user_id) {
                    wp_die('You do not have permission to send magic links to this user.', 'Permission Denied', array('response' => 403));
                }
                
                // Also check if target user is a family member post created by current user
                $family_members = get_posts(array(
                    'post_type' => 'family_member',
                    'author' => $current_user_id,
                    'meta_query' => array(
                        array('key' => 'linked_user_id', 'value' => $target_user_id)
                    ),
                    'posts_per_page' => 1,
                    'fields' => 'ids'
                ));
                
                // If we found a family member linked to this user, verify ownership
                if (empty($family_members) && ($target_parent_id > 0 || $target_legacy_parent_id > 0)) {
                    // User has parent meta but current user is not the parent - deny
                    wp_die('You do not have permission to send magic links to this user.', 'Permission Denied', array('response' => 403));
                }
            }

            $token = self::generate_token($user->ID);

            if (!$token) {
                wp_die('Rate limit exceeded. Please wait.');
            }

            $magic_link = add_query_arg([
                'token' => $token,
                'uid' => $user->ID
            ], home_url('/magic-login'));

            $redirect_url = add_query_arg('view_magic_link', urlencode($magic_link), $redirect_url);
            wp_redirect($redirect_url);
            exit;
        } else {
            wp_die('User not found.');
        }
    }

    /**
     * Handle Resend Link Request
     * 
     * @return void
     */
    public static function handle_resend_request()
    {
        if (!isset($_POST['pillpalnow_resend_magic_nonce']) || !wp_verify_nonce($_POST['pillpalnow_resend_magic_nonce'], 'pillpalnow_resend_magic_action')) {
            wp_die('Security check failed');
        }

        $email = sanitize_email($_POST['email']);

        // Determine redirect page (login or magic-login)
        $redirect_page = isset($_POST['redirect_to']) && $_POST['redirect_to'] === 'login' ? '/login' : '/magic-login';
        $error_param = $redirect_page === '/login' ? 'magic_error' : 'error';
        $success_param = $redirect_page === '/login' ? 'magic_sent' : 'sent';

        // Check for Custom Redirect URL
        $redirect_url = isset($_POST['redirect_to_url']) ? esc_url_raw($_POST['redirect_to_url']) : home_url($redirect_page);

        if (!is_email($email)) {
            $redirect_url = add_query_arg($error_param, 'invalid_email', $redirect_url);
            wp_redirect($redirect_url);
            exit;
        }

        $user = get_user_by('email', $email);

        if ($user) {
            // Check rate limit
            $rate_key = 'pillpalnow_magic_limit_' . $user->ID;
            $attempts = get_transient($rate_key) ?: 0;
            if ($attempts >= 5) {
                $redirect_url = add_query_arg($error_param, 'rate_limit', $redirect_url);
                wp_redirect($redirect_url);
                exit;
            }

            // Find Parent Name (Inviter)
            $parent_name = 'Family Admin';
            $family_members = get_posts([
                'post_type' => 'family_member',
                'meta_key' => 'linked_user_id',
                'meta_value' => $user->ID,
                'posts_per_page' => 1
            ]);

            if ($family_members) {
                $parent_id = $family_members[0]->post_author;
                $parent_user = get_userdata($parent_id);
                if ($parent_user) {
                    $parent_name = $parent_user->display_name;
                }
            }

            // Generate and Send
            self::send_link($user->ID, $email, $user->display_name, $parent_name);

            $redirect_url = add_query_arg($success_param, 'true', $redirect_url);
            // Also add generic 'invitation_sent' param for standard banners
            $redirect_url = add_query_arg('invitation_sent', '1', $redirect_url);
            $redirect_url = add_query_arg('member_name', urlencode($user->display_name), $redirect_url);
            $redirect_url = add_query_arg('member_email', urlencode($email), $redirect_url);

            wp_redirect($redirect_url);
            exit;
        } else {
            // Fake success
            $redirect_url = add_query_arg($success_param, 'true', $redirect_url);
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Listen for magic login requests
     * Hook: init
     */
    public static function listen_for_magic_login()
    {
        if (isset($_GET['token']) && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/magic-login') !== false) {

            $token = sanitize_text_field($_GET['token']);
            $uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0;

            if (!$token || !$uid) {
                wp_die('Invalid login link (Missing Parameters).', 'Login Error');
            }

            $result = self::validate_login($token, $uid);

            if (is_wp_error($result)) {
                wp_die($result->get_error_message(), 'Login Failed');
            }

            // Success! Redirect to dashboard (or wherever)
            wp_redirect(home_url('/dashboard'));
            exit;
        }
    }

    /**
     * Get HTML email template
     */
    private static function get_email_template($name, $magic_link, $site_name, $parent_name)
    {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>

        <body
            style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0f172a;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0f172a; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="100%" max-width="600" cellpadding="0" cellspacing="0"
                            style="max-width: 600px; background-color: #1e293b; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);">
                            <!-- Header -->
                            <tr>
                                <td
                                    style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 35px 30px; text-align: center;">
                                    <h1
                                        style="color: #ffffff; margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;">
                                        Login to <?php echo esc_html($site_name); ?>
                                    </h1>
                                </td>
                            </tr>

                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px 30px;">
                                    <h2 style="color: #f1f5f9; margin: 0 0 20px 0; font-size: 24px;">Hi
                                        <?php echo esc_html($name); ?>,
                                    </h2>

                                    <p style="color: #cbd5e1; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
                                        <strong style="color: #10b981;"><?php echo esc_html($parent_name); ?></strong> invited
                                        you to join their family on <?php echo esc_html($site_name); ?>.
                                    </p>

                                    <p style="color: #94a3b8; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">
                                        (Or you requested a new login link).
                                    </p>

                                    <p style="color: #e2e8f0; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">
                                        Click the button below to securely login. No password required.
                                    </p>

                                    <!-- CTA Button -->
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding: 10px 0 30px 0;">
                                                <a href="<?php echo esc_url($magic_link); ?>"
                                                    style="display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; padding: 16px 48px; border-radius: 12px; font-size: 18px; font-weight: 600; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4); text-align: center;">
                                                    🚀 Login Now
                                                </a>
                                            </td>
                                        </tr>
                                    </table>

                                    <p
                                        style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 0; text-align: center;">
                                        This link expires in 15 minutes. If it expires, ask your family admin to remove and add
                                        you again to resend a new link.
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #0f172a; padding: 25px 30px; border-top: 1px solid #334155;">
                                    <p style="color: #475569; font-size: 12px; margin: 0; text-align: center;">
                                        &copy; <?php echo date('Y'); ?>         <?php echo esc_html($site_name); ?>. All rights
                                        reserved.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>

        </html>
        <?php
        return ob_get_clean();
    }
}

// Hook Registration
// Hook Registration
add_action('init', array('PillPalNow_Magic_Login', 'listen_for_magic_login'));
add_action('admin_post_pillpalnow_resend_magic', array('PillPalNow_Magic_Login', 'handle_resend_request'));
add_action('admin_post_nopriv_pillpalnow_resend_magic', array('PillPalNow_Magic_Login', 'handle_resend_request'));
add_action('admin_post_pillpalnow_view_magic_link', array('PillPalNow_Magic_Login', 'handle_view_magic_link_request'));
