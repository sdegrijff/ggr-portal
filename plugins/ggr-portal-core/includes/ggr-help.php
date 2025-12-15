<?php
/**
 * Help & FAQ functionaliteit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registreer FAQ custom post type.
 */
add_action( 'init', 'ggr_portal_register_faq_cpt' );
function ggr_portal_register_faq_cpt() {
    $labels = array(
        'name'               => 'FAQs',
        'singular_name'      => 'FAQ',
        'menu_name'          => 'FAQs',
        'add_new'            => 'Nieuwe FAQ',
        'add_new_item'       => 'Nieuwe FAQ toevoegen',
        'edit_item'          => 'FAQ bewerken',
        'new_item'           => 'Nieuwe FAQ',
        'view_item'          => 'FAQ bekijken',
        'search_items'       => 'FAQ zoeken',
        'not_found'          => 'Geen FAQ gevonden',
        'not_found_in_trash' => 'Geen FAQ in prullenbak',
    );

    $args = array(
        'labels'        => $labels,
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'supports'      => array( 'title', 'editor', 'page-attributes' ),
        'menu_position' => 27,
    );

    register_post_type( 'ggr_faq', $args );
}

/**
 * Shortcode: [ggr_portal_help]
 */
add_shortcode( 'ggr_portal_help', 'ggr_portal_help_shortcode' );
function ggr_portal_help_shortcode() {
    $maybe_error = ggrp_fe_require_login();
    if ( null !== $maybe_error ) {
        return $maybe_error;
    }

    $user = wp_get_current_user();

    $errors         = array();
    $success_notice = '';

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_help_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_help_nonce'] ) ), 'ggr_help' ) ) {
            $errors[] = 'Ongeldige sessie, probeer het opnieuw.';
        } else {
            $message = isset( $_POST['ggr_help_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_help_message'] ) ) : '';

            if ( '' === trim( $message ) ) {
                $errors[] = 'Vul een bericht in zodat we je kunnen helpen.';
            }

            if ( empty( $errors ) ) {
                if ( function_exists( 'ggr_meldingen_add' ) ) {
                    $content = $message . "\n\n" . 'Contact: ' . $user->user_email;

                    ggr_meldingen_add(
                        'Nieuw helpverzoek van ' . ggr_portal_get_nice_user_name( $user ),
                        $content,
                        $user->ID,
                        array(
                            'melding_type' => 'help',
                        )
                    );
                }

                $success_notice = 'Je bericht is verzonden. We komen hier zo snel mogelijk op terug.';
            }
        }
    }

    $faq_query = new WP_Query(
        array(
            'post_type'      => 'ggr_faq',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => array(
                'menu_order' => 'ASC',
                'date'       => 'DESC',
            ),
        )
    );

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--help">
        <div class="ggrp-fe-help-header">
            <div>
                <p class="ggrp-fe-kicker">Hulp nodig?</p>
                <h1>Help &amp; vragen</h1>
                <p class="ggrp-fe-subtitle">Vind snel een antwoord of stuur ons direct een bericht.</p>
            </div>
            <div class="ggrp-fe-help-actions">
                <a class="ggrp-fe-pill" href="mailto:info@ggrincome.com">Mail ons</a>
                <a class="ggrp-fe-pill" href="https://wa.me/31850805035" target="_blank" rel="noreferrer">WhatsApp</a>
                <a class="ggrp-fe-pill" href="tel:+31850805035">Bel: +31 85 080 50 35</a>
            </div>
        </div>

        <?php if ( ! empty( $errors ) ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--error">
                <?php foreach ( $errors as $err ) : ?>
                    <p><?php echo esc_html( $err ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( $success_notice ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--success">
                <p><?php echo esc_html( $success_notice ); ?></p>
            </div>
        <?php endif; ?>

        <div class="ggrp-fe-help-grid">
            <div class="ggrp-fe-help-card">
                <h2>Stel je vraag</h2>
                <p class="ggrp-fe-card-text">Kom je er niet uit? Stuur ons een bericht en we reageren via de meldingen in je portal.</p>

                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                    <?php wp_nonce_field( 'ggr_help', 'ggr_help_nonce' ); ?>
                    <div class="ggrp-fe-form-row">
                        <label for="ggr_help_message">Bericht</label>
                        <textarea id="ggr_help_message" name="ggr_help_message" rows="5" placeholder="Beschrijf je vraag of verzoek" required></textarea>
                    </div>

                    <button type="submit" class="ggrp-fe-button">Stuur bericht</button>
                </form>

                <ul class="ggrp-fe-contact-list">
                    <li><strong>E-mail:</strong> <a href="mailto:info@ggrincome.com">info@ggrincome.com</a></li>
                    <li><strong>WhatsApp:</strong> <a href="https://wa.me/31850805035" target="_blank" rel="noreferrer">+31 85 080 50 35</a></li>
                    <li><strong>Bellen:</strong> <a href="tel:+31850805035">+31 85 080 50 35</a></li>
                </ul>
            </div>

            <div class="ggrp-fe-help-card">
                <h2>FAQ</h2>
                <p class="ggrp-fe-card-text">Veelgestelde vragen vanuit het team.</p>

                <?php if ( $faq_query->have_posts() ) : ?>
                    <div class="ggrp-fe-faq-list">
                        <?php
                        while ( $faq_query->have_posts() ) :
                            $faq_query->the_post();
                            ?>
                            <div class="ggrp-fe-faq-item">
                                <h3><?php the_title(); ?></h3>
                                <div class="ggrp-fe-faq-body"><?php the_content(); ?></div>
                            </div>
                            <?php
                        endwhile;
                        wp_reset_postdata();
                        ?>
                    </div>
                <?php else : ?>
                    <p class="ggrp-fe-card-text">Nog geen FAQ-items beschikbaar.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
