<?php
/**
 * The template for displaying all pages
 *
 * @package PillPalNow
 */

get_header();
?>

<main class="container py-6">
    <?php
    while (have_posts()):
        the_post();
        ?>

        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header class="app-header">
                <h1 class="text-xl font-bold">
                    <?php the_title(); ?>
                </h1>
            </header>

            <div class="entry-content card p-6 text-white">
                <?php the_content(); ?>
            </div>
        </article>

        <?php
    endwhile; // End of the loop.
    ?>
</main>

<?php
get_footer();
