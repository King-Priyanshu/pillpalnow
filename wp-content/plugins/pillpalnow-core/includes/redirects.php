<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect Single Medication Views to Management Dashboard
 * 
 * If a user tries to access a single medication URL (e.g. /medication/slug/),
 * they will be redirected to the Manage Medications page.
 */
function pillpalnow_redirect_single_medication()
{
    if (is_singular('medication') && !is_admin()) {
        wp_safe_redirect(site_url('/manage-medications/'));
        exit;
    }
}
add_action('template_redirect', 'pillpalnow_redirect_single_medication');

/**
 * Filter Post Type Link
 * 
 * Changes the permalink for medication posts to point to the management page
 * to prevent users from even seeing the single post URL in some contexts.
 */
function pillpalnow_filter_medication_link($post_link, $post)
{
    if ($post->post_type === 'medication') {
        return site_url('/manage-medications/');
    }
    return $post_link;
}
add_filter('post_type_link', 'pillpalnow_filter_medication_link', 10, 2);

/**
 * Redirect After Save (Frontend)
 * 
 * NOTE: Admin saves usually redirect to the edit screen, which is fine.
 * This ensures that if a frontend form submits and redirects to the post, it goes to management.
 */
// The template_redirect above handles the case where save redirects to the single post URL.
