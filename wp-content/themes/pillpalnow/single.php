<?php
/**
 * The template for displaying all single posts
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
                <div class="flex flex-col">
                    <span class="text-xs text-secondary uppercase tracking-wider mb-1">
                        <?php echo get_post_type(); ?>
                    </span>
                    <h1 class="text-2xl font-bold">
                        <?php the_title(); ?>
                    </h1>
                </div>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-text">Back</a>
            </header>

            <div class="card p-6 entry-content text-white">
                <?php if (has_post_thumbnail()): ?>
                    <div class="mb-4">
                        <?php the_post_thumbnail('large', array('class' => 'rounded-lg w-full h-auto object-cover')); ?>
                    </div>
                <?php endif; ?>

                <?php the_content(); ?>
            </div>
        </article>

        <?php
    endwhile; // End of the loop.
    ?>

</main>

<?php
get_footer();
