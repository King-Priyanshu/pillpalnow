<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect non-admins to homepage after login if they try to access admin
 */
function pillpalnow_redirect_non_admin()
{


    // Allow access to admin-post.php for form handling
    if (!defined('DOING_AJAX') && isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) == 'admin-post.php') {
        return;
    }

    // Allow Parents (non-admins, non-family-members) to access specific PillPalNow pages
    if (is_admin() && !current_user_can('administrator') && !defined('DOING_AJAX')) {
        $allowed_pages = ['pillpalnow-settings'];
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';

        $user = wp_get_current_user();
        $is_family_member = in_array('family_member', (array) $user->roles);

        // If Parent (not admin, not family member) AND accessing allowed page -> Allow
        if (!$is_family_member && in_array($current_page, $allowed_pages)) {
            return;
        }

        error_log("[PILLPALNOW_REDIRECT] Blocking backend access. URI: " . $_SERVER['REQUEST_URI'] . " | PHP_SELF: " . $_SERVER['PHP_SELF']);
        wp_redirect(home_url());
        exit;
    }
}
add_action('admin_init', 'pillpalnow_redirect_non_admin');

/**
 * Create necessary pages if they don't exist
 */
function pillpalnow_create_pages()
{
    $pages = array(
        'dashboard' => 'Dashboard',
        'history' => 'History',
        'refills' => 'Refills',
        'profile' => 'Profile',
        'add-medication' => 'Add Medication',
        'dose-logger' => 'Dose Logger',
        'add-family-member' => 'Add Family Member',
        'manage-medications' => 'Manage Medications',
        'edit-medication' => 'Edit Medication',
        'upgrade' => 'Upgrade',
    );

    foreach ($pages as $slug => $title) {
        $page = get_page_by_path($slug);
        if (!$page) {
            wp_insert_post(array(
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page'
            ));
        }
    }
}
add_action('init', 'pillpalnow_create_pages');

/**
 * 5. Pro Feature Lock
 */
function pillpalnow_pro_feature_lock($feature)
{
    if (class_exists('Subscription_Manager') && !Subscription_Manager::is_pro_user(get_current_user_id())) {
        wp_die('Pro feature. Upgrade: /checkout', 'Pro Feature', ['response' => 403]);
    }
}

/**
 * Register Family Member Role
 */
function pillpalnow_setup_roles()
{
    add_role('family_member', 'Family Member', array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ));
}
add_action('init', 'pillpalnow_setup_roles');

/**
 * Enqueue Frontend Scripts
 */
function pillpalnow_enqueue_scripts()
{
    // Only enqueue on PillPalNow pages or globally if needed. 
    // For now, let's target History page to be efficient, or just global since it's a frontend script.
    // Given the request context, global or specific page check is fine. Let's do global for simplicity unless specific page ID is known.
    // Actually, checking standard pages like history is good practice.

    if (is_page('history')) {
        wp_enqueue_script('pillpalnow-frontend', plugins_url('../assets/js/pillpalnow-frontend.js', __FILE__), array('jquery'), '1.0.0', true);

        wp_localize_script('pillpalnow-frontend', 'pillpalnow_share_vars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'share_nonce' => wp_create_nonce('pillpalnow_share_nonce')
        ));
    }
}
add_action('wp_enqueue_scripts', 'pillpalnow_enqueue_scripts');
