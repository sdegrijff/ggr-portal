<?php
/**
 * Template Name: GGR Portal Page
 *
 * Gebruik dit template voor:
 * - Mijn Dashboard
 * - Transacties
 * - Berichten
 * - Mijn Account
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<div class="ggr-portal-wrapper">
    <?php
    if ( have_posts() ) :
        while ( have_posts() ) :
            the_post();
            the_content(); // hier komen je shortcodes zoals [ggr_portal_dashboard]
        endwhile;
    endif;
    ?>
</div>

<?php
get_footer();
