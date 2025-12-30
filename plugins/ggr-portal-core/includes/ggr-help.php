<?php
/**
 * Help Shortcode en functionaliteiten
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

    $errors          = array();
    $success_notice  = '';
    $feedback_notice = '';

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_help_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_help_nonce'] ) ), 'ggr_help' ) ) {
            $errors[] = 'Ongeldige sessie, probeer het opnieuw.';
        } else {
            $action  = isset( $_POST['ggr_help_action'] ) ? sanitize_key( wp_unslash( $_POST['ggr_help_action'] ) ) : '';

            if ( 'question' === $action ) {
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

                    if ( function_exists( 'ggr_portal_send_templated_email' ) ) {
                        $help_url = home_url( '/help-vragen/' );
                        ggr_portal_send_templated_email(
                            'help_request_confirmation',
                            $user->ID,
                            array(
                                'help_message' => $message,
                                'portal_link'  => $help_url,
                                'login_link'   => wp_login_url( $help_url ),
                            )
                        );
                    }

                    $success_notice = 'Je bericht is verzonden. We komen hier zo snel mogelijk op terug.';
                }
            } elseif ( 'feedback' === $action ) {
                $feedback = isset( $_POST['ggr_help_feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_help_feedback'] ) ) : '';

                if ( '' === trim( $feedback ) ) {
                    $errors[] = 'Vul je feedback in.';
                }

                if ( empty( $errors ) && function_exists( 'ggr_meldingen_add' ) ) {
                    ggr_meldingen_add(
                        'Feedback door ' . ggr_portal_get_nice_user_name( $user ),
                        $feedback,
                        $user->ID,
                        array(
                            'melding_type'      => 'wijziging',
                            'wijziging_variant' => 'feedback',
                        )
                    );
                }

                if ( empty( $errors ) ) {
                    $feedback_notice = 'Bedankt voor je feedback! We nemen dit mee in de verbeteringen.';
                }
            }
        }
    }

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

        <?php if ( $feedback_notice ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--success">
                <p><?php echo esc_html( $feedback_notice ); ?></p>
            </div>
        <?php endif; ?>

        <div class="ggrp-fe-help-grid">
            <div class="ggrp-fe-help-card">
                <h2>Stel je vraag</h2>
                <p class="ggrp-fe-card-text">Kom je er niet uit? Stuur ons een bericht en we reageren via de meldingen in je portal.</p>

                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                    <?php wp_nonce_field( 'ggr_help', 'ggr_help_nonce' ); ?>
                    <input type="hidden" name="ggr_help_action" value="question" />                    
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
                <h2>Feedback voor verbeteringen</h2>
                <p class="ggrp-fe-card-text">Laat weten wat we kunnen verbeteren in het portal.</p>

                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                    <?php wp_nonce_field( 'ggr_help', 'ggr_help_nonce' ); ?>
                    <input type="hidden" name="ggr_help_action" value="feedback" />
                    <div class="ggrp-fe-form-row">
                        <label for="ggr_help_feedback">Feedback</label>
                        <textarea id="ggr_help_feedback" name="ggr_help_feedback" rows="5" placeholder="Deel je suggesties" required></textarea>
                    </div>
                    <button type="submit" class="ggrp-fe-button">Verstuur feedback</button>
                </form>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
