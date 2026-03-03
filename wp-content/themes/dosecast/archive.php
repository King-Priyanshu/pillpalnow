<?php
/**
 * The template for displaying archive pages
 *
 * @package PillPalNow
 */

get_header();
?>

<main class="container py-6">

    <?php if (have_posts()): ?>

        <header class="page-header mb-6">
            <?php
            the_archive_title('<h1 class="text-xl font-bold">', '</h1>');
            the_archive_description('<div class="text-secondary mt-2">', '</div>');
            ?>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php
            while (have_posts()):
                the_post();
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('card'); ?>>
                    <h2 class="text-lg font-bold mb-2"><a href="<?php the_permalink(); ?>" class="text-primary no-underline">
                            <?php the_title(); ?>
                        </a></h2>
                    <div class="entry-content text-sm text-secondary">
                        <?php the_excerpt(); ?>
                    </div>
                </article>
                <?php
            endwhile;

            the_posts_navigation();

    else:
        ?>
            <p>
                <?php esc_html_e('Nothing found.', 'pillpalnow'); ?>
            </p>
            <?php
    endif;
    ?>
    </div>
</main>

<?php
get_footer();
