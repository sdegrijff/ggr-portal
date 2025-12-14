<?php
/**
 * Shortcode voor investeringsaanvraag door participanten.
 * URL-voorbeeld: /investeren met shortcode [ggr_portal_investeren].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode: [ggr_portal_investeren]
 * Stap 1: formulier voor het gewenste investeringsbedrag.
 * Stap 2: bevestiging + betaalgegevens.
 */
function ggr_portal_investeren_shortcode() {
    // 0) Logincheck
    $maybe_error = ggrp_fe_require_login();
    if ( null !== $maybe_error ) {
        return $maybe_error;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! in_array( 'participant', (array) $user->roles, true ) ) {
        return '<section class="ggrp-fe"><h1>Investeren</h1><p>Deze functie is alleen beschikbaar voor ingelogde participanten.</p></section>';
    }

    $errors        = [];
    $amount_input  = '';
    $reference_raw = '';
    $submitted     = false;

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_investeren_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_investeren_nonce'] ) ), 'ggr_investeren' ) ) {
            $errors[] = 'Ongeldige aanvraag, probeer het opnieuw.';
        }

        $amount_input  = isset( $_POST['ggr_invest_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_invest_amount'] ) ) : '';
        $reference_raw = isset( $_POST['ggr_invest_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_invest_reference'] ) ) : '';

        $amount = (float) str_replace( ',', '.', $amount_input );
        if ( $amount <= 0 ) {
            $errors[] = 'Vul een geldig investeringsbedrag in.';
        }

        if ( empty( $errors ) ) {
            $submitted = true;
        }
    }

    // Betaalgegevens zijn aanpasbaar via filter zodat beheer eenvoudig kan wijzigen.
    $payment_details = apply_filters(
        'ggr_portal_investeren_payment_details',
        [
            'iban'      => 'Nader te bepalen',
            'tenaam'    => 'GGR Investeringen B.V.',
            'omschrijving' => 'Gebruik je naam en referentie als omschrijving.',
            'bank'      => 'Vul de bankgegevens hier aan.',
        ]
    );

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--investeren">
        <h1>Investeren</h1>

        <?php if ( $submitted ) : ?>
            <div class="ggrp-fe-invest-step">
                <h2>Stap 2: Maak je investering over</h2>
                <p>Je hebt aangegeven te willen investeren:</p>
                <ul class="ggrp-fe-invest-summary">
                    <li><strong>Bedrag:</strong> <?php echo esc_html( ggrp_fe_format_money( (float) str_replace( ',', '.', $amount_input ) ) ); ?></li>
                    <?php if ( $reference_raw ) : ?>
                        <li><strong>Referentie:</strong> <?php echo esc_html( $reference_raw ); ?></li>
                    <?php endif; ?>
                </ul>

                <div class="ggrp-fe-invest-details">
                    <h3>Betaalgegevens</h3>
                    <ul>
                        <li><strong>IBAN:</strong> <?php echo esc_html( $payment_details['iban'] ?? '' ); ?></li>
                        <li><strong>Tenaamstelling:</strong> <?php echo esc_html( $payment_details['tenaam'] ?? '' ); ?></li>
                        <li><strong>Bank:</strong> <?php echo esc_html( $payment_details['bank'] ?? '' ); ?></li>
                        <li><strong>Omschrijving:</strong> Gebruik minimaal: <?php echo esc_html( $reference_raw ?: 'je naam en emailadres' ); ?></li>
                    </ul>
                    <p class="ggrp-fe-invest-note">Na ontvangst verwerken we de investering en zie je de update terug in je dashboard.</p>
                </div>
            </div>
        <?php else : ?>
            <?php if ( ! empty( $errors ) ) : ?>
                <div class="ggrp-fe-alert ggrp-fe-alert--error">
                    <?php foreach ( $errors as $err ) : ?>
                        <p><?php echo esc_html( $err ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ggrp-fe-invest-step">
                <h2>Stap 1: Geef je gewenste inleg door</h2>
                <form method="post" class="ggrp-fe-form">
                    <?php wp_nonce_field( 'ggr_investeren', 'ggr_investeren_nonce' ); ?>
                    <div class="ggrp-fe-form-row">
                        <label for="ggr_invest_amount">Investeringsbedrag (EUR)</label>
                        <input type="number" name="ggr_invest_amount" id="ggr_invest_amount" min="0" step="0.01" value="<?php echo esc_attr( $amount_input ); ?>" required>
                    </div>

                    <div class="ggrp-fe-form-row">
                        <label for="ggr_invest_reference">Referentie (optioneel)</label>
                        <input type="text" name="ggr_invest_reference" id="ggr_invest_reference" value="<?php echo esc_attr( $reference_raw ); ?>" placeholder="Bijv. bedrijfsnaam of opmerking">
                    </div>

                    <button type="submit" class="ggrp-fe-button">Ga door naar betaalgegevens</button>
                </form>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_portal_investeren', 'ggr_portal_investeren_shortcode' );
