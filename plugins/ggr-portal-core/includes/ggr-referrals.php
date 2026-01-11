<?php
/**
 * Verwijs een vriend shortcode en formulier.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'ggr_portal_verwijs_vriend', 'ggr_portal_verwijs_vriend_shortcode' );
function ggr_portal_verwijs_vriend_shortcode() {
    $maybe_error = ggrp_fe_require_login();
    if ( null !== $maybe_error ) {
        return $maybe_error;
    }

    $user = wp_get_current_user();

    $errors         = array();
    $success_notice = '';

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_verwijs_vriend_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_verwijs_vriend_nonce'] ) ), 'ggr_verwijs_vriend' ) ) {
            $errors[] = 'Ongeldige sessie, probeer het opnieuw.';
        } else {
            $friend_email = isset( $_POST['ggr_verwijs_vriend_email'] )
                ? sanitize_email( wp_unslash( $_POST['ggr_verwijs_vriend_email'] ) )
                : '';

            if ( '' === $friend_email || ! is_email( $friend_email ) ) {
                $errors[] = 'Vul een geldig e-mailadres in.';
            }

            if ( empty( $errors ) ) {
                $referrer_name = function_exists( 'ggr_portal_get_nice_user_name' )
                    ? ggr_portal_get_nice_user_name( $user )
                    : $user->display_name;

                $sent = false;

                if ( function_exists( 'ggr_portal_send_templated_email_to_address' ) ) {
                    $placeholders = array(
                        'referrer_name'  => $referrer_name,
                        'referrer_email' => $user->user_email,
                        'referral_link'  => home_url( '/investeerder-worden/' ),
                    );

                    $sent = ggr_portal_send_templated_email_to_address(
                        'referral_invite',
                        $friend_email,
                        $placeholders
                    );
                }

                if ( $sent ) {
                    $success_notice = 'Je uitnodiging is verstuurd.';
                } else {
                    $errors[] = 'Er ging iets mis bij het versturen. Probeer het later opnieuw.';
                }
            }
        }
    }

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--referral">
        <h1>Verwijs een vriend</h1>
        <p>
            Verwijs een vriend en ontvang eenmalig 100 euro wanneer deze participant voor minimaal 12 maanden bij onze
            platform blijft en de participant die je verwijst, die krijgt een jaar lang 25% korting op onze kosten.
        </p>

        <?php if ( ! empty( $errors ) ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--error">
                <?php foreach ( $errors as $error ) : ?>
                    <p><?php echo esc_html( $error ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( $success_notice ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--success">
                <p><?php echo esc_html( $success_notice ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
            <?php wp_nonce_field( 'ggr_verwijs_vriend', 'ggr_verwijs_vriend_nonce' ); ?>
            <div class="ggrp-fe-form-row">
                <label for="ggr_verwijs_vriend_email">E-mailadres van je vriend</label>
                <input
                    type="email"
                    id="ggr_verwijs_vriend_email"
                    name="ggr_verwijs_vriend_email"
                    placeholder="vriend@voorbeeld.nl"
                    required
                />
            </div>
            <button type="submit" class="ggrp-fe-button">Verstuur uitnodiging</button>
        </form>
    </section>
    <?php
    return ob_get_clean();
}
