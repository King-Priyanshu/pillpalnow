<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @package PillPalNow
 */

get_header();
?>

<main class="container py-12 text-center">
    <div class="card p-8 inline-block">
        <h1 class="text-4xl font-bold text-primary mb-4">404</h1>
        <h2 class="text-xl font-semibold mb-4 text-white">
            <?php esc_html_e('Page Not Found', 'pillpalnow'); ?>
        </h2>
        <p class="text-secondary mb-6">
            <?php esc_html_e('It looks like nothing was found at this location.', 'pillpalnow'); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/')); ?>" class="btn btn-primary">
            <?php esc_html_e('Go to Dashboard', 'pillpalnow'); ?>
        </a>
    </div>
</main>

<?php
get_footer();
