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
    $success_messages = array();
    $submitted_action = '';
    $active_flow      = '';


    $deposit_stage    = 'amount';
    $strategy_stage   = 'choose';
    $bank_stage       = 'details';

    $deposit_amount     = '';
    $deposit_reference  = '';
    $strategy_choice    = '';
    $new_iban           = '';
    $new_iban_name      = '';
    $feedback_note      = '';

    $payment_details = apply_filters(
        'ggr_portal_investeren_payment_details',
        array(
            'iban'         => 'Nader te bepalen',
            'tenaam'       => 'GGR Investeringen B.V.',
            'omschrijving' => 'Gebruik je naam en referentie als omschrijving.',
            'bank'         => 'Vul de bankgegevens hier aan.',
        )
    );

    $current_strategy = get_user_meta( $user->ID, 'ggr_distribution_strategy', true );
    $nice_user_name   = function_exists( 'ggr_portal_get_nice_user_name' )
        ? ggr_portal_get_nice_user_name( $user )
        : $user->display_name;
    $submitted_at_label = wp_date( 'Y-m-d H:i' );

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
                        $success_messages[] = 'Je opnameverzoek is ontvangen. We nemen contact met je op voor de afhandeling.';

                        if ( function_exists( 'ggr_meldingen_add' ) ) {
                            $content_lines = array(
                                'Bedrag: ' . ggrp_fe_format_money( $amount ),
                                'Ingediend door: ' . $nice_user_name,
                                'Datum: ' . $submitted_at_label,                                
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
                    $deposit_amount_raw = isset( $_POST['ggr_deposit_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_amount'] ) ) : '';
                    $deposit_amount     = (float) str_replace( ',', '.', $deposit_amount_raw );
                    $deposit_reference  = isset( $_POST['ggr_deposit_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_reference'] ) ) : '';
                    $deposit_stage      = isset( $_POST['ggr_flow_step'] ) ? sanitize_key( wp_unslash( $_POST['ggr_flow_step'] ) ) : 'amount';

                    if ( $deposit_amount <= 0 ) {
                        $errors[] = 'Vul een geldig stortingsbedrag in.';
                    }

                    if ( empty( $errors ) ) {
                        $active_flow = 'deposit';

                        if ( 'confirm' === $deposit_stage ) {
                            $submitted_action = 'deposit';
                            $deposit_stage    = 'done';
                            $success_messages[] = 'Bedankt! We hebben bevestigd dat je de storting hebt gedaan.';

                            if ( function_exists( 'ggr_meldingen_add' ) ) {
                                $content_lines = array(
                                    'Bedrag: ' . ggrp_fe_format_money( $deposit_amount ),
                                    'Referentie: ' . ( $deposit_reference ? $deposit_reference : '—' ),
                                    'Ingediend door: ' . $nice_user_name,
                                    'Datum: ' . $submitted_at_label,
                                );

                                ggr_meldingen_add(
                                    'Stortingsbevestiging van ' . $nice_user_name,
                                    implode( "\n", $content_lines ),
                                    $user->ID,
                                    array(
                                        'melding_type'      => 'wijziging',
                                        'wijziging_variant' => 'deposit',
                                    )
                                );
                            }
                        } else {
                            $deposit_stage = 'details';
                        }
                    }

                    break;

                case 'strategy':
                    $strategy_choice = isset( $_POST['ggr_strategy_choice'] ) ? sanitize_key( wp_unslash( $_POST['ggr_strategy_choice'] ) ) : '';
                    $strategy_stage  = isset( $_POST['ggr_flow_step'] ) ? sanitize_key( wp_unslash( $_POST['ggr_flow_step'] ) ) : 'choose';

                    if ( ! in_array( $strategy_choice, array( 'herbeleggen', 'uitkeren' ), true ) ) {
                        $errors[] = 'Selecteer een geldige strategie-optie.';
                    }

                    if ( empty( $errors ) ) {
                        $active_flow = 'strategy';

                        if ( 'confirm' === $strategy_stage ) {
                            $submitted_action   = 'strategy';
                            $strategy_stage     = 'done';
                            $current_strategy   = $strategy_choice;
                            $success_messages[] = 'Je strategievoorkeur is opgeslagen. We verwerken dit zo snel mogelijk.';
                            update_user_meta( $user->ID, 'ggr_distribution_strategy', $strategy_choice );

                            if ( function_exists( 'ggr_meldingen_add' ) ) {
                                ggr_meldingen_add(
                                    'Strategiewijziging door ' . $nice_user_name,
                                    "Nieuwe keuze: {$strategy_choice}\nIngediend op: {$submitted_at_label}",
                                    $user->ID,
                                    array(
                                        'melding_type'      => 'wijziging',
                                        'wijziging_variant' => 'strategy',
                                    )
                                );
                            }
                        } else {
                            $strategy_stage = 'confirm';
                        }
                    }

                    break;

                case 'bank_change':
                    $new_iban      = isset( $_POST['ggr_bank_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_bank_iban'] ) ) : '';
                    $new_iban_name = isset( $_POST['ggr_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_bank_name'] ) ) : '';
                    $bank_stage    = isset( $_POST['ggr_flow_step'] ) ? sanitize_key( wp_unslash( $_POST['ggr_flow_step'] ) ) : 'details';

                    if ( ! $new_iban || ! $new_iban_name ) {
                        $errors[] = 'Vul zowel de IBAN als tenaamstelling in.';
                    }

                    if ( empty( $errors ) ) {
                        $active_flow = 'bank_change';

                        if ( 'confirm' === $bank_stage ) {
                            $submitted_action   = 'bank_change';
                            $bank_stage         = 'done';
                            $success_messages[] = 'We hebben je verzoek tot rekeningnummer-wijziging ontvangen. We nemen contact met je op na de verificatiebetaling.';

                            if ( function_exists( 'ggr_meldingen_add' ) ) {
                                $content_lines = array(
                                    'Nieuw IBAN: ' . $new_iban,
                                    'Tenaamstelling: ' . $new_iban_name,
                                    'Ingediend door: ' . $nice_user_name,
                                    'Datum: ' . $submitted_at_label,
                                );

                                ggr_meldingen_add(
                                    'Rekeningwijziging aangevraagd door ' . $nice_user_name,
                                    implode( "\n", $content_lines ),
                                    $user->ID,
                                    array(
                                        'melding_type'      => 'wijziging',
                                        'wijziging_variant' => 'bank_change',
                                    )
                                );
                            }
                        } else {
                            $bank_stage = 'verification';
                        }
                    }

                    break;

                case 'feedback':
                    $feedback_note = isset( $_POST['ggr_feedback_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_feedback_note'] ) ) : '';

                    if ( '' === $feedback_note ) {
                        $errors[] = 'Vul je feedback in.';
                    } else {
                        $submitted_action   = 'feedback';
                        $success_messages[] = 'Bedankt voor je feedback! We nemen dit mee in de verbeteringen.';

                        if ( function_exists( 'ggr_meldingen_add' ) ) {
                            ggr_meldingen_add(
                                'Feedback wijzigingspagina door ' . $nice_user_name,
                                $feedback_note . "\nIngediend op: " . $submitted_at_label,
                                $user->ID,
                                array(
                                    'melding_type'      => 'wijziging',
                                    'wijziging_variant' => 'feedback',
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

    if ( empty( $current_strategy ) ) {
        $current_strategy = 'herbeleggen';
    }
    if ( '' === $strategy_choice ) {
        $strategy_choice = $current_strategy;
    }

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

        <?php if ( ! empty( $success_messages ) ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--success">
                <?php foreach ( $success_messages as $msg ) : ?>
                    <p><?php echo esc_html( $msg ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="ggrp-fe-wijziging-grid">
            <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split">
                <div class="ggrp-fe-wijziging-icon" aria-hidden="true">💸</div>
                <div class="ggrp-fe-wijziging-content">
                    <p class="ggrp-fe-kicker">Opnemen</p>
                    <h2>Geld opnemen</h2>
                    <p class="ggrp-fe-card-text">Stuur een opnameverzoek naar het team. We stemmen de uitbetaling met je af.</p>
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
            </div>

            <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'deposit' === $active_flow ) ? 'is-active' : ''; ?>">
                <div class="ggrp-fe-wijziging-icon" aria-hidden="true">📥</div>
                <div class="ggrp-fe-wijziging-content">
                    <p class="ggrp-fe-kicker">Storten</p>
                    <h2>Geld storten</h2>

                    <?php if ( 'details' === $deposit_stage ) : ?>
                        <div class="ggrp-fe-step-badge">Stap 2 van 3</div>
                        <p class="ggrp-fe-card-text">Controleer je bedrag en gebruik onderstaande betaalgegevens.</p>
                        <ul class="ggrp-fe-summary-list">
                            <li><strong>Bedrag:</strong> <?php echo wp_kses_post( ggrp_fe_format_money( $deposit_amount ) ); ?></li>
                            <li><strong>Referentie:</strong> <?php echo $deposit_reference ? esc_html( $deposit_reference ) : '—'; ?></li>
                        </ul>

                        <div class="ggrp-fe-invest-details">
                            <h3>Betaalgegevens</h3>
                            <ul>
                                <li><strong>IBAN:</strong> <?php echo esc_html( $payment_details['iban'] ?? '' ); ?></li>
                                <li><strong>Tenaamstelling:</strong> <?php echo esc_html( $payment_details['tenaam'] ?? '' ); ?></li>
                                <li><strong>Bank:</strong> <?php echo esc_html( $payment_details['bank'] ?? '' ); ?></li>
                                <li><strong>Omschrijving:</strong> <?php echo esc_html( $payment_details['omschrijving'] ?? '' ); ?></li>
                            </ul>
                            <p class="ggrp-fe-invest-note">Maak het bedrag over en bevestig hieronder dat je de betaling hebt gedaan.</p>
                        </div>

                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked ggrp-fe-form--inline-actions">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="deposit" />
                            <input type="hidden" name="ggr_flow_step" value="confirm" />
                            <input type="hidden" name="ggr_deposit_amount" value="<?php echo esc_attr( $deposit_amount ); ?>" />
                            <input type="hidden" name="ggr_deposit_reference" value="<?php echo esc_attr( $deposit_reference ); ?>" />
                            <button type="submit" class="ggrp-fe-button ggrp-fe-button--primary">Ik heb het bedrag overgemaakt</button>
                        </form>
                    <?php elseif ( 'done' === $deposit_stage ) : ?>
                        <div class="ggrp-fe-alert ggrp-fe-alert--success">
                            <p>Bedankt voor je bevestiging. We verwerken je storting.</p>
                        </div>
                    <?php else : ?>
                        <div class="ggrp-fe-step-badge">Stap 1 van 3</div>
                        <p class="ggrp-fe-card-text">Geef door hoeveel je wilt storten. We leiden je daarna langs de betaalgegevens.</p>
                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="deposit" />
                            <input type="hidden" name="ggr_flow_step" value="amount" />

                            <div class="ggrp-fe-form-row">
                                <label for="ggr_deposit_amount">Stortingsbedrag (EUR)</label>
                                <input type="number" id="ggr_deposit_amount" name="ggr_deposit_amount" min="0" step="0.01" value="<?php echo esc_attr( $deposit_amount ); ?>" required />
                            </div>

                            <div class="ggrp-fe-form-row">
                                <label for="ggr_deposit_reference">Referentie (optioneel)</label>
                                <input type="text" id="ggr_deposit_reference" name="ggr_deposit_reference" value="<?php echo esc_attr( $deposit_reference ); ?>" placeholder="Bijv. bedrijfsnaam of opmerking" />
                            </div>

                            <button type="submit" class="ggrp-fe-button">Verder naar betaalgegevens</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'strategy' === $active_flow ) ? 'is-active' : ''; ?>">
                <div class="ggrp-fe-wijziging-icon" aria-hidden="true">🔁</div>
                <div class="ggrp-fe-wijziging-content">
                    <p class="ggrp-fe-kicker">Strategie</p>
                    <h2>Uitkeren of herinvesteren</h2>
                    <p class="ggrp-fe-card-text">Huidige keuze: <strong><?php echo esc_html( 'herbeleggen' === $current_strategy ? 'Herbeleggen' : 'Uitkeren' ); ?></strong></p>

                    <?php if ( 'confirm' === $strategy_stage ) : ?>
                        <div class="ggrp-fe-step-badge">Stap 2 van 2</div>
                        <p class="ggrp-fe-card-text">Je wijzigt je strategie naar <strong><?php echo esc_html( 'herbeleggen' === $strategy_choice ? 'Herbeleggen' : 'Uitkeren' ); ?></strong>. Bevestig hieronder.</p>
                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked ggrp-fe-form--inline-actions">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="strategy" />
                            <input type="hidden" name="ggr_strategy_choice" value="<?php echo esc_attr( $strategy_choice ); ?>" />
                            <input type="hidden" name="ggr_flow_step" value="confirm" />
                            <button type="submit" class="ggrp-fe-button ggrp-fe-button--primary">Strategie bevestigen</button>
                        </form>
                    <?php elseif ( 'done' === $strategy_stage ) : ?>
                        <div class="ggrp-fe-alert ggrp-fe-alert--success">
                            <p>Je strategiekeuze is ontvangen. We passen dit zo snel mogelijk aan.</p>
                        </div>
                    <?php else : ?>
                        <div class="ggrp-fe-step-badge">Stap 1 van 2</div>
                        <p class="ggrp-fe-card-text">Kies of je rendement wilt laten uitkeren of automatisch wilt herinvesteren.</p>
                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="strategy" />
                            <input type="hidden" name="ggr_flow_step" value="choose" />

                            <div class="ggrp-fe-form-row ggrp-fe-form-row--inline">
                                <label class="ggrp-fe-radio">
                                    <input type="radio" name="ggr_strategy_choice" value="herbeleggen" <?php checked( $strategy_choice, 'herbeleggen' ); ?> />
                                    <span>Herbeleggen – extra groei op lange termijn.</span>
                                </label>
                            </div>
                            <div class="ggrp-fe-form-row ggrp-fe-form-row--inline">
                                <label class="ggrp-fe-radio">
                                    <input type="radio" name="ggr_strategy_choice" value="uitkeren" <?php checked( $strategy_choice, 'uitkeren' ); ?> />
                                    <span>Uitkeren – ontvang uitbetaling op je rekening.</span>
                                </label>
                            </div>

                            <button type="submit" class="ggrp-fe-button">Verder naar bevestiging</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'bank_change' === $active_flow ) ? 'is-active' : ''; ?>">
                <div class="ggrp-fe-wijziging-icon" aria-hidden="true">🏦</div>
                <div class="ggrp-fe-wijziging-content">
                    <p class="ggrp-fe-kicker">Rekening</p>
                    <h2>Rekeningnummer wijzigen</h2>

                    <?php if ( 'verification' === $bank_stage ) : ?>
                        <div class="ggrp-fe-step-badge">Stap 2 van 3</div>
                        <p class="ggrp-fe-card-text">Controleer je nieuwe rekeninggegevens en maak €0,01 over ter verificatie.</p>
                        <ul class="ggrp-fe-summary-list">
                            <li><strong>IBAN:</strong> <?php echo esc_html( $new_iban ); ?></li>
                            <li><strong>Tenaamstelling:</strong> <?php echo esc_html( $new_iban_name ); ?></li>
                        </ul>
                        <p class="ggrp-fe-invest-note">Gebruik de huidige betaalgegevens om €0,01 over te maken vanaf het nieuwe rekeningnummer.</p>
                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked ggrp-fe-form--inline-actions">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="bank_change" />
                            <input type="hidden" name="ggr_flow_step" value="confirm" />
                            <input type="hidden" name="ggr_bank_iban" value="<?php echo esc_attr( $new_iban ); ?>" />
                            <input type="hidden" name="ggr_bank_name" value="<?php echo esc_attr( $new_iban_name ); ?>" />
                            <button type="submit" class="ggrp-fe-button ggrp-fe-button--primary">Wijziging bevestigen</button>
                        </form>
                    <?php elseif ( 'done' === $bank_stage ) : ?>
                        <div class="ggrp-fe-alert ggrp-fe-alert--success">
                            <p>We hebben je verzoek ontvangen. Na de verificatiebetaling passen we het rekeningnummer aan.</p>
                        </div>
                    <?php else : ?>
                        <div class="ggrp-fe-step-badge">Stap 1 van 3</div>
                        <p class="ggrp-fe-card-text">Vul je nieuwe rekeningnummer en tenaamstelling in. We verifiëren dit met een overboeking van €0,01.</p>
                        <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                            <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                            <input type="hidden" name="ggr_change_action" value="bank_change" />
                            <input type="hidden" name="ggr_flow_step" value="details" />

                            <div class="ggrp-fe-form-row">
                                <label for="ggr_bank_iban">Nieuw IBAN</label>
                                <input type="text" id="ggr_bank_iban" name="ggr_bank_iban" value="<?php echo esc_attr( $new_iban ); ?>" required />
                            </div>

                            <div class="ggrp-fe-form-row">
                                <label for="ggr_bank_name">Tenaamstelling</label>
                                <input type="text" id="ggr_bank_name" name="ggr_bank_name" value="<?php echo esc_attr( $new_iban_name ); ?>" required />
                            </div>

                            <button type="submit" class="ggrp-fe-button">Verder naar verificatie</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split">
                <div class="ggrp-fe-wijziging-icon" aria-hidden="true">💬</div>
                <div class="ggrp-fe-wijziging-content">
                    <p class="ggrp-fe-kicker">Feedback</p>
                    <h2>Wat wil je hier nog meer terugzien?</h2>
                    <p class="ggrp-fe-card-text">Laat ons weten hoe we deze pagina kunnen verbeteren. Je naam en datum worden automatisch meegestuurd.</p>
                    <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                        <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                        <input type="hidden" name="ggr_change_action" value="feedback" />
                        <div class="ggrp-fe-form-row">
                            <label for="ggr_feedback_note">Feedback</label>
                            <textarea id="ggr_feedback_note" name="ggr_feedback_note" rows="3" required><?php echo esc_textarea( $feedback_note ); ?></textarea>
                        </div>
                        <button type="submit" class="ggrp-fe-button">Feedback versturen</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_portal_investeren', 'ggr_portal_investeren_shortcode' );
add_shortcode( 'ggr_portal_wijziging', 'ggr_portal_investeren_shortcode' );
