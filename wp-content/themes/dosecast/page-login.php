<?php
/**
 * Template Name: Login Page
 * 
 * @package PillPalNow
 */

// Redirect if already logged in
if (is_user_logged_in()) {
    wp_redirect(home_url('/dashboard'));
    exit;
}

get_header('simple');
?>

<div class="flex items-center justify-center min-h-screen py-12 px-4">
    <div class="card w-full max-w-md p-8 animate-enter">

        <div class="um-login-container">
            <?php
            // Attempt to find a UM Login form
            $args = array(
                'post_type' => 'um_form',
                'meta_key' => '_um_mode',
                'meta_value' => 'login',
                'posts_per_page' => 1
            );
            $login_forms = get_posts($args);

            if (!empty($login_forms)) {
                $form_id = $login_forms[0]->ID;
                echo do_shortcode('[ultimatemember form_id="' . $form_id . '"]');
            } else {
                // Fallback or error message
                echo '<p class="text-danger text-center">Login form not found. Please create a form with "Login" mode in Ultimate Member.</p>';
            }
            ?>
        </div>


    </div>

</div>

<style>
    /* Ensure the container is vertically centered properly */
    .min-h-screen {
        min-height: calc(100vh - 64px);
    }

    /* Optional: Style adjustments for UM form to match dark theme if needed */
    .um-form input[type=text],
    .um-form input[type=password] {
        background-color: var(--card-color) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-primary) !important;
        border-radius: var(--radius-md) !important;
    }

    .um .um-button {
        background-color: var(--primary-color) !important;
        border-radius: var(--radius-lg) !important;
    }

    /* Hide standard UM register button if it looks off, or style it */
    .um-field-area-response {
        color: var(--danger-color);
    }
</style>

<?php get_footer(); ?>