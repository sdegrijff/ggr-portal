<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<div class="ggr-portal-wrapper">
    <?php if ( have_posts() ) : ?>
        <?php while ( have_posts() ) : the_post(); ?>
            <?php the_content(); ?>
        <?php endwhile; ?>
    <?php else : ?>
        <p>Er is nog geen inhoud voor deze pagina.</p>
    <?php endif; ?>
</div>

<?php
get_footer();
