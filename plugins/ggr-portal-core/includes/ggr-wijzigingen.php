<?php
/**
 * Shortcode voor wijzigingen door participanten.
 * URL-voorbeeld: /wijziging met shortcode [ggr_portal_wijziging].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode: [ggr_portal_wijziging]
 * Front-end flow voor deelnemers om wijzigingen door te geven.
 */
function ggr_portal_investeren_shortcode() {
    $maybe_error = ggrp_fe_require_login();
    if ( null !== $maybe_error ) {
        return $maybe_error;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! in_array( 'participant', (array) $user->roles, true ) ) {
        return '<section class="ggrp-fe"><h1>Wijziging</h1><p>Deze functie is alleen beschikbaar voor ingelogde participanten.</p></section>';
        }

    $errors           = array();
    $success_message  = '';
    $submitted_action = '';

    $payment_details = apply_filters(
        'ggr_portal_investeren_payment_details',
        array(
            'iban'         => 'Nader te bepalen',
            'tenaam'       => 'GGR Investeringen B.V.',
            'omschrijving' => 'Gebruik je naam en referentie als omschrijving.',
            'bank'         => 'Vul de bankgegevens hier aan.',
        )
    );

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_wijziging_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_wijziging_nonce'] ) ), 'ggr_wijziging' ) ) {
            $errors[] = 'Ongeldige aanvraag, probeer het opnieuw.';
        } else {
            $action = isset( $_POST['ggr_change_action'] ) ? sanitize_key( wp_unslash( $_POST['ggr_change_action'] ) ) : '';

            switch ( $action ) {
                case 'withdrawal':
                    $amount_raw  = isset( $_POST['ggr_withdraw_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_withdraw_amount'] ) ) : '';
                    $amount      = (float) str_replace( ',', '.', $amount_raw );
                    $description = isset( $_POST['ggr_withdraw_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_withdraw_notes'] ) ) : '';

                    if ( $amount <= 0 ) {
                        $errors[] = 'Vul een geldig opnamebedrag in.';
                    }

                    if ( empty( $errors ) ) {
                        $submitted_action = 'withdrawal';
                        $success_message  = 'Je opnameverzoek is ontvangen. We nemen contact met je op voor de afhandeling.';

                        if ( function_exists( 'ggr_meldingen_add' ) ) {
                            $content_lines = array(
                                'Bedrag: ' . ggrp_fe_format_money( $amount ),
                            );

                            if ( $description ) {
                                $content_lines[] = 'Toelichting: ' . $description;
                            }

                            ggr_meldingen_add(
                                'Opnameverzoek van ' . ggr_portal_get_nice_user_name( $user ),
                                implode( "\n", $content_lines ),
                                $user->ID,
                                array(
                                    'melding_type'      => 'wijziging',
                                    'wijziging_variant' => 'withdrawal',
                                )
                            );
                        }
                    }

                    break;

                case 'deposit':
                    $amount_raw  = isset( $_POST['ggr_deposit_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_amount'] ) ) : '';
                    $amount      = (float) str_replace( ',', '.', $amount_raw );
                    $reference   = isset( $_POST['ggr_deposit_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_reference'] ) ) : '';

                    if ( $amount <= 0 ) {
                        $errors[] = 'Vul een geldig stortingsbedrag in.';
                    }

                    if ( empty( $errors ) ) {
                        $submitted_action = 'deposit';
                        $success_message  = 'We hebben je gewenste storting genoteerd. Gebruik de betaalgegevens hieronder om de overboeking te doen.';

                        if ( function_exists( 'ggr_meldingen_add' ) ) {
                            $content_lines = array(
                                'Bedrag: ' . ggrp_fe_format_money( $amount ),
                            );

                            if ( $reference ) {
                                $content_lines[] = 'Referentie: ' . $reference;
                            }

                            ggr_meldingen_add(
                                'Stortingsverzoek van ' . ggr_portal_get_nice_user_name( $user ),
                                implode( "\n", $content_lines ),
                                $user->ID,
                                array(
                                    'melding_type'      => 'wijziging',
                                    'wijziging_variant' => 'deposit',
                                )
                            );
                        }
                    }

                    break;

                case 'strategy':
                    $strategy = isset( $_POST['ggr_strategy_choice'] ) ? sanitize_key( wp_unslash( $_POST['ggr_strategy_choice'] ) ) : '';

                    if ( ! in_array( $strategy, array( 'herbeleggen', 'uitkeren' ), true ) ) {
                        $errors[] = 'Selecteer een geldige strategie-optie.';
                    }

                    if ( empty( $errors ) ) {
                        $submitted_action = 'strategy';
                        $success_message  = 'Je strategievoorkeur is opgeslagen. We verwerken dit zo snel mogelijk.';
                        update_user_meta( $user->ID, 'ggr_distribution_strategy', $strategy );

                        if ( function_exists( 'ggr_meldingen_add' ) ) {
                            ggr_meldingen_add(
                                'Strategiewijziging door ' . ggr_portal_get_nice_user_name( $user ),
                                'Nieuwe keuze: ' . $strategy,
                                $user->ID,
                                array(
                                    'melding_type'      => 'wijziging',
                                    'wijziging_variant' => 'strategy',
                                )
                            );
                        }
                    }

                    break;

                default:
                    $errors[] = 'Onbekende actie. Probeer het opnieuw.';
                    break;
            }
        }
    }

    $current_strategy = get_user_meta( $user->ID, 'ggr_distribution_strategy', true );

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--investeren">
       <div class="ggrp-fe-wijziging-header">
            <div>
                <h1>Wijziging</h1>
                <p class="ggrp-fe-subtitle">Kies wat je wil aanpassen en dien direct je verzoek in.</p>
            </div>
        </div>

        <?php if ( ! empty( $errors ) ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--error">
                <?php foreach ( $errors as $err ) : ?>
                    <p><?php echo esc_html( $err ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( $success_message ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--success">
                <p><?php echo esc_html( $success_message ); ?></p>
            </div>
        <?php endif; ?>

        <div class="ggrp-fe-wijziging-grid">
            <div class="ggrp-fe-wijziging-card">
                <div class="ggrp-fe-wijziging-card-head">
                    <div>
                        <p class="ggrp-fe-kicker">Opnemen</p>
                        <h2>Geld opnemen</h2>
                        <p class="ggrp-fe-card-text">Stuur een opnameverzoek naar het team. We stemmen de uitbetaling met je af.</p>
                    </div>
                    <span class="ggrp-fe-icon" aria-hidden="true">💸</span>
                </div>
                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                    <input type="hidden" name="ggr_change_action" value="withdrawal" />

                    <div class="ggrp-fe-form-row">
                        <label for="ggr_withdraw_amount">Opnamebedrag (EUR)</label>
                        <input type="number" id="ggr_withdraw_amount" name="ggr_withdraw_amount" min="0" step="0.01" required />
                    </div>

                    <div class="ggrp-fe-form-row">
                        <label for="ggr_withdraw_notes">Toelichting (optioneel)</label>
                        <textarea id="ggr_withdraw_notes" name="ggr_withdraw_notes" rows="3" placeholder="Bijvoorbeeld gewenste datum of aanvullende instructies"></textarea>
                    </div>

                    <button type="submit" class="ggrp-fe-button">Opname aanvragen</button>
                </form>
            </div>

            <div class="ggrp-fe-wijziging-card">
                <div class="ggrp-fe-wijziging-card-head">
                    <div>
                        <p class="ggrp-fe-kicker">Inleggen</p>
                        <h2>Geld inleggen</h2>
                        <p class="ggrp-fe-card-text">Geef je gewenste storting door en gebruik de betaalgegevens om over te maken.</p>
                    </div>
                    <span class="ggrp-fe-icon" aria-hidden="true">📥</span>
                </div>
                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                    <input type="hidden" name="ggr_change_action" value="deposit" />

                    <div class="ggrp-fe-form-row">
                        <label for="ggr_deposit_amount">Stortingsbedrag (EUR)</label>
                        <input type="number" id="ggr_deposit_amount" name="ggr_deposit_amount" min="0" step="0.01" required />
                    </div>

                    <div class="ggrp-fe-form-row">
                        <label for="ggr_deposit_reference">Referentie (optioneel)</label>
                        <input type="text" id="ggr_deposit_reference" name="ggr_deposit_reference" placeholder="Bijv. bedrijfsnaam of opmerking" />
                    </div>

                    <button type="submit" class="ggrp-fe-button">Storting doorgeven</button>
                </form>

                <div class="ggrp-fe-invest-details">
                    <h3>Betaalgegevens</h3>
                    <ul>
                        <li><strong>IBAN:</strong> <?php echo esc_html( $payment_details['iban'] ?? '' ); ?></li>
                        <li><strong>Tenaamstelling:</strong> <?php echo esc_html( $payment_details['tenaam'] ?? '' ); ?></li>
                        <li><strong>Bank:</strong> <?php echo esc_html( $payment_details['bank'] ?? '' ); ?></li>
                        <li><strong>Omschrijving:</strong> <?php echo esc_html( $payment_details['omschrijving'] ?? '' ); ?></li>
                    </ul>
                    <p class="ggrp-fe-invest-note">Na ontvangst verwerken we de storting en zie je de update terug in je dashboard.</p>
                </div>
            </div>
            
            <div class="ggrp-fe-wijziging-card">
                <div class="ggrp-fe-wijziging-card-head">
                    <div>
                        <p class="ggrp-fe-kicker">Strategie</p>
                        <h2>Strategie wijzigen</h2>
                        <p class="ggrp-fe-card-text">Kies of je rendement wilt laten uitkeren of automatisch wilt herbeleggen.</p>
                    </div>

                    <div class="ggrp-fe-form-row ggrp-fe-form-row--inline">
                        <label class="ggrp-fe-radio">
                            <input type="radio" name="ggr_strategy_choice" value="herbeleggen" <?php checked( $current_strategy, 'herbeleggen' ); ?> />
                            <span>Herbeleggen</span>
                        </label>
                        <label class="ggrp-fe-radio">
                            <input type="radio" name="ggr_strategy_choice" value="uitkeren" <?php checked( $current_strategy, 'uitkeren' ); ?> />
                            <span>Uitkeren</span>
                        </label>
                    </div>

                    <button type="submit" class="ggrp-fe-button">Strategie opslaan</button>
                </form>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_portal_investeren', 'ggr_portal_investeren_shortcode' );
add_shortcode( 'ggr_portal_wijziging', 'ggr_portal_investeren_shortcode' );
