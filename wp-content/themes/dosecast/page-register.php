<?php
/**
 * Template Name: Register Page
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

        <div class="um-register-container">
            <?php
            // Attempt to find a UM Register form
            $args = array(
                'post_type' => 'um_form',
                'meta_key' => '_um_mode',
                'meta_value' => 'register',
                'posts_per_page' => 1
            );
            $register_forms = get_posts($args);

            if (!empty($register_forms)) {
                $form_id = $register_forms[0]->ID;
                echo do_shortcode('[ultimatemember form_id="' . $form_id . '"]');
            } else {
                echo '<p class="text-danger text-center">Registration form not found. Please create a form with "Register" mode in Ultimate Member.</p>';
            }
            ?>
        </div>

    </div>
</div>

<style>
    .min-h-screen {
        min-height: calc(100vh - 64px);
    }

    .um-form input[type=text],
    .um-form input[type=password],
    .um-form input[type=email] {
        background-color: var(--card-color) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-primary) !important;
        border-radius: var(--radius-md) !important;
    }

    .um .um-button {
        background-color: var(--primary-color) !important;
        border-radius: var(--radius-lg) !important;
    }

    .um-field-label {
        color: var(--text-secondary) !important;
    }
</style>

<?php get_footer(); ?>