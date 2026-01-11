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
function ggr_portal_get_latest_inleg_mutatie( $user_id ) {
    $mutaties = get_posts(
        array(
            'post_type'      => 'ggr_mutatie',
            'post_status'    => 'any',
            'numberposts'    => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'   => 'ggr_mutatie_type',
                    'value' => 'inleg',
                ),
                array(
                    'key'   => 'ggr_mutatie_user_id',
                    'value' => (int) $user_id,
                ),
            ),
        )
    );

    if ( empty( $mutaties ) ) {
        return null;
    }

    return $mutaties[0];
}
 
function ggr_portal_investeren_shortcode() {
    $maybe_error = ggrp_fe_require_login();
    if ( null !== $maybe_error ) {
        return $maybe_error;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! in_array( 'participant', (array) $user->roles, true ) ) {
        return '<section class="ggrp-fe"><h1>Wijziging</h1><p>Deze functie is alleen beschikbaar voor ingelogde participanten.</p></section>';
        }

    $errors            = array();
    $success_messages  = array();
    $submitted_action  = '';
    $active_flow       = '';
    $selected_action   = isset( $_GET['change'] ) ? sanitize_key( wp_unslash( $_GET['change'] ) ) : '';
    $available_actions = array( 'deposit', 'withdrawal', 'strategy', 'bank_change' );

    if ( ! in_array( $selected_action, $available_actions, true ) ) {
        $selected_action = '';
    }


    $deposit_stage    = 'amount';
    $withdrawal_stage = 'amount';
    $strategy_stage   = 'choose';
    $bank_stage       = 'details';

    $deposit_amount     = '';
    $deposit_reference  = '';
    $deposit_source     = '';
    $deposit_explanation = '';    
    $withdrawal_amount  = '';
    $strategy_choice    = '';
    $new_iban           = '';
    $new_iban_name      = '';
    $back_link_url      = remove_query_arg( 'change' );
    $back_link_url      = remove_query_arg( 'change' );    

    $current_strategy = get_user_meta( $user->ID, 'ggr_distribution_strategy', true );
    $nice_user_name   = function_exists( 'ggr_portal_get_nice_user_name' )
        ? ggr_portal_get_nice_user_name( $user )
        : $user->display_name;
    $submitted_at_label = wp_date( 'Y-m-d H:i' );
    $deposit_threshold  = (float) apply_filters( 'ggr_portal_deposit_approval_threshold', 10000 );    

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['ggr_wijziging_nonce'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ggr_wijziging_nonce'] ) ), 'ggr_wijziging' ) ) {
            $errors[] = 'Ongeldige aanvraag, probeer het opnieuw.';
        } else {
            $action = isset( $_POST['ggr_change_action'] ) ? sanitize_key( wp_unslash( $_POST['ggr_change_action'] ) ) : '';

            $selected_action = in_array( $action, $available_actions, true ) ? $action : '';

            switch ( $action ) {
                case 'deposit':
                    $deposit_amount_raw = isset( $_POST['ggr_deposit_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_amount'] ) ) : '';
                    $deposit_amount     = (float) str_replace( ',', '.', $deposit_amount_raw );
                    $deposit_reference  = isset( $_POST['ggr_deposit_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_deposit_reference'] ) ) : '';
                    $deposit_source     = isset( $_POST['ggr_deposit_source'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_deposit_source'] ) ) : '';
                    $deposit_explanation = isset( $_POST['ggr_deposit_explanation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_deposit_explanation'] ) ) : '';
                    $deposit_stage      = isset( $_POST['ggr_flow_step'] ) ? sanitize_key( wp_unslash( $_POST['ggr_flow_step'] ) ) : 'amount';
                    $deposit_requires_review = $deposit_amount >= $deposit_threshold;

                    if ( $deposit_amount <= 0 ) {
                        $errors[] = 'Vul een geldig stortingsbedrag in.';
                    }

                    if ( empty( $errors ) ) {
                        $active_flow = 'deposit';

                        if ( $deposit_requires_review ) {
                            if ( 'questions' === $deposit_stage ) {
                                if ( ! $deposit_source || ! $deposit_explanation ) {
                                    $errors[] = 'Beantwoord de vragen over de herkomst van de middelen.';
                                }

                                if ( empty( $errors ) ) {
                                    $submitted_action = 'deposit';
                                    $deposit_stage    = 'review';

                                    if ( function_exists( 'ggr_mutaties_create_mutatie' ) ) {
                                        $mutatie_id = ggr_mutaties_create_mutatie( 'inleg', $user->ID, $deposit_amount );
                                        if ( is_wp_error( $mutatie_id ) ) {
                                            $errors[] = 'Kon de mutatie voor de storting niet aanmaken.';
                                        } else {
                                            $planned_date = function_exists( 'ggr_mutaties_get_next_run_date' )
                                                ? ggr_mutaties_get_next_run_date()
                                                : '';                                            
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'in_behandeling' );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_betaalstatus', 'open' );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_no_participations', 1 );                                            
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_schedule_enabled', 1 );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', $planned_date );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_publication_date', $planned_date );
                                            if ( $planned_date && function_exists( 'ggr_mutaties_get_nav_date_for_planned_date' ) ) {
                                                update_post_meta( $mutatie_id, 'ggr_mutatie_nav_date', ggr_mutaties_get_nav_date_for_planned_date( $planned_date ) );
                                            }
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_deposit_reference', $deposit_reference );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_source', $deposit_source );
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_source_explanation', $deposit_explanation );
                                            $success_messages[] = 'Je aanvraag is ontvangen en staat klaar voor beoordeling.';
                                        }
                                    } else {
                                        $errors[] = 'Mutatie-functies ontbreken om de storting te verwerken.';
                                    }

                                    if ( function_exists( 'ggr_meldingen_add' ) ) {
                                        $content_lines = array(
                                            'Bedrag: ' . ggrp_fe_format_money( $deposit_amount ),
                                            'Herkomst: ' . $deposit_source,
                                            'Toelichting: ' . $deposit_explanation,
                                            'Referentie: ' . ( $deposit_reference ? $deposit_reference : '—' ),
                                            'Ingediend door: ' . $nice_user_name,
                                            'Datum: ' . $submitted_at_label,
                                        );

                                        ggr_meldingen_add(
                                            'Extra inleg ter beoordeling van ' . $nice_user_name,
                                            implode( "\n", $content_lines ),
                                            $user->ID,
                                            array(
                                                'melding_type'      => 'wijziging',
                                                'wijziging_variant' => 'deposit_review',
                                            )
                                        );
                                    }
                                }
                            } else {
                                $deposit_stage = 'questions';
                            }
                        } else {
                            if ( 'confirm' === $deposit_stage ) {
                                $submitted_action = 'deposit';
                                $deposit_stage    = 'payment';

                                if ( function_exists( 'ggr_mutaties_create_mutatie' ) ) {
                                    $mutatie_id = ggr_mutaties_create_mutatie( 'inleg', $user->ID, $deposit_amount );
                                    if ( is_wp_error( $mutatie_id ) ) {
                                        $errors[] = 'Kon de mutatie voor de storting niet aanmaken.';
                                    } else {
                                        $planned_date = function_exists( 'ggr_mutaties_get_next_run_date' )
                                            ? ggr_mutaties_get_next_run_date()
                                            : '';                                       
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'in_behandeling' );
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_betaalstatus', 'open' );
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_no_participations', 1 );                                        
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_schedule_enabled', 1 );
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', $planned_date );
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_publication_date', $planned_date );
                                        if ( $planned_date && function_exists( 'ggr_mutaties_get_nav_date_for_planned_date' ) ) {
                                            update_post_meta( $mutatie_id, 'ggr_mutatie_nav_date', ggr_mutaties_get_nav_date_for_planned_date( $planned_date ) );
                                        }
                                        update_post_meta( $mutatie_id, 'ggr_mutatie_deposit_reference', $deposit_reference );
                                    }
                                } else {
                                    $errors[] = 'Mutatie-functies ontbreken om de storting te verwerken.';
                                }                            

                                if ( empty( $errors ) && function_exists( 'ggr_mollie_create_payment_for_mutatie' ) ) {
                                    $description  = sprintf( 'Extra inleg van %s', $nice_user_name );
                                    $redirect_url = esc_url_raw( add_query_arg( 'change', 'deposit', $back_link_url ) );
                                    $payment      = ggr_mollie_create_payment_for_mutatie( $mutatie_id, $deposit_amount, $description, $redirect_url );
                                    if ( is_wp_error( $payment ) ) {
                                        $errors[] = $payment->get_error_message();
                                    } else {
                                        $success_messages[] = 'De betaling is klaar om te starten via Mollie.';
                                        if ( ! empty( $payment['checkout_url'] ) ) {
                                            wp_redirect( $payment['checkout_url'] );
                                            exit;
                                        }                                        
                                    }
                                } elseif ( empty( $errors ) ) {
                                    $errors[] = 'Mollie-functies ontbreken om de betaling te starten.';
                                }
                                
                                if ( function_exists( 'ggr_meldingen_add' ) ) {
                                    $content_lines = array(
                                        'Bedrag: ' . ggrp_fe_format_money( $deposit_amount ),
                                        'Referentie: ' . ( $deposit_reference ? $deposit_reference : '—' ),
                                        'Ingediend door: ' . $nice_user_name,
                                        'Datum: ' . $submitted_at_label,
                                    );

                                    ggr_meldingen_add(
                                        'Extra inleg aangevraagd door ' . $nice_user_name,
                                        implode( "\n", $content_lines ),
                                        $user->ID,
                                        array(
                                            'melding_type'      => 'wijziging',
                                            'wijziging_variant' => 'deposit',
                                        )
                                    );
                                }
                            } else {
                                $deposit_stage = 'confirm';
                            }
                        }
                    }

                    break;

                case 'withdrawal':
                    $withdrawal_amount_raw = isset( $_POST['ggr_withdrawal_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_withdrawal_amount'] ) ) : '';
                    $withdrawal_amount     = (float) str_replace( ',', '.', $withdrawal_amount_raw );
                    $withdrawal_stage      = isset( $_POST['ggr_flow_step'] ) ? sanitize_key( wp_unslash( $_POST['ggr_flow_step'] ) ) : 'amount';

                    if ( $withdrawal_amount <= 0 ) {
                        $errors[] = 'Vul een geldig opnamebedrag in.';
                    }

                    if ( empty( $errors ) ) {
                        $active_flow = 'withdrawal';

                        if ( 'confirm' === $withdrawal_stage ) {
                            $submitted_action = 'withdrawal';
                            $withdrawal_stage = 'done';

                            if ( function_exists( 'ggr_mutaties_create_mutatie' ) ) {
                                $mutatie_id = ggr_mutaties_create_mutatie( 'opname', $user->ID, $withdrawal_amount );
                                if ( is_wp_error( $mutatie_id ) ) {
                                    $errors[] = 'Kon de mutatie voor de opname niet aanmaken.';
                                } else {
                                    $success_messages[] = 'Je opname is direct doorgevoerd als mutatie.';
                                }
                            } else {
                                $errors[] = 'Mutatie-functies ontbreken om de opname te verwerken.';
                            }
                        } else {
                            $withdrawal_stage = 'confirm';
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
                            $success_messages[] = 'Je strategievoorkeur is direct bijgewerkt in je profiel.';
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

                default:
                    $errors[] = 'Onbekende actie. Probeer het opnieuw.';
                    break;
            }
        }
    }

    if ( '' !== $submitted_action ) {
        $selected_action = $submitted_action;
    }

    if ( empty( $current_strategy ) ) {
        $current_strategy = 'herbeleggen';
    }
    if ( '' === $strategy_choice ) {
        $strategy_choice = $current_strategy;
    }

    $latest_deposit_mutatie = ggr_portal_get_latest_inleg_mutatie( $user->ID );
    $latest_payment_status  = '';
    $latest_payment_url     = '';
    if ( $latest_deposit_mutatie ) {
        $latest_payment_status = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_payment_status', true );
        if ( function_exists( 'ggr_mollie_refresh_payment_status' ) && in_array( $latest_payment_status, array( 'open', 'pending' ), true ) ) {
            ggr_mollie_refresh_payment_status( $latest_deposit_mutatie->ID );
            $latest_payment_status = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_payment_status', true );
        }
        $latest_payment_url  = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_payment_url', true );
    }

    if ( $latest_deposit_mutatie && $latest_payment_status && ! in_array( $latest_payment_status, array( 'open', 'pending' ), true ) ) {
        $latest_status = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_status', true );
        if ( 'uitgevoerd' !== $latest_status && function_exists( 'ggr_mutaties_apply_to_history' ) ) {
            $history_errors = array();
            $planned_date   = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_planned_date', true );
            $updated        = ggr_mutaties_apply_to_history( $latest_deposit_mutatie->ID, $planned_date, $history_errors );
            if ( $updated ) {
                update_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_status', 'uitgevoerd' );
            }
            if ( $history_errors ) {
                $errors = array_merge( $errors, $history_errors );
            }
        }
        $latest_deposit_mutatie = null;
        $deposit_stage          = 'amount';
    }

    if ( $latest_deposit_mutatie && function_exists( 'ggr_mutaties_find_history_entry_by_date' ) ) {
        $planned_date  = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_planned_date', true );
        $effective_date = function_exists( 'ggr_mutaties_get_effective_date' )
            ? ggr_mutaties_get_effective_date( $latest_deposit_mutatie->ID, $planned_date )
            : $planned_date;
        if ( $effective_date ) {
            $history_entry = ggr_mutaties_find_history_entry_by_date( $user->ID, $effective_date );
            if ( $history_entry ) {
                $latest_deposit_mutatie = null;
                $latest_payment_status  = '';
                $latest_payment_url     = '';
                $deposit_stage          = 'amount';
            }
        }
    }

    if ( 'deposit' === $selected_action && '' === $submitted_action && $latest_deposit_mutatie ) {
        $latest_status = get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_status', true );
        $latest_amount = ggr_mutaties_parse_decimal( get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_amount', true ) );

        if ( $latest_payment_url && ! in_array( $latest_status, array( 'betaald', 'afgewezen' ), true ) ) {
            $deposit_stage = 'payment';
        } elseif ( $latest_amount >= $deposit_threshold && in_array( $latest_status, array( 'in_behandeling', 'goedgekeurd' ), true ) ) {
            $deposit_stage = 'review';
        }
    }

    if (
        'deposit' === $selected_action
        && '' === $submitted_action
        && $latest_deposit_mutatie
        && $latest_payment_url
        && in_array( $latest_payment_status, array( 'open', 'pending' ), true )
        && empty( $errors )
    ) {
        wp_redirect( $latest_payment_url );
        exit;
    }

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--investeren">
       <div class="ggrp-fe-wijziging-header">
            <div>
                <h1>Wijziging</h1>
                <p class="ggrp-fe-subtitle">Kies wat je wil aanpassen en open daarna het formulier.</p>
            </div>
        </div>

        <?php if ( ! empty( $errors ) ) : ?>
            <div class="ggrp-fe-alert ggrp-fe-alert--error">
                <?php foreach ( $errors as $err ) : ?>
                    <p><?php echo esc_html( $err ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! $selected_action ) : ?>
            <div class="ggrp-fe-wijziging-grid">
                <a href="<?php echo esc_url( add_query_arg( 'change', 'deposit' ) ); ?>"
                   class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split ggrp-fe-wijziging-card--clickable">
                
                    <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                        <i class="ri-money-euro-circle-line"></i>
                    </div>
                
                    <div class="ggrp-fe-wijziging-content">
                        <p class="ggrp-fe-kicker">Storten</p>
                        <h2>Geld storten</h2>
                        <p class="ggrp-fe-card-text">
                            Wil je meer geld storten in het GGR Income fund? 
                        </p>
                    </div>

                </a>
                <a href="<?php echo esc_url( add_query_arg( 'change', 'withdrawal' ) ); ?>"
                   class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split ggrp-fe-wijziging-card--clickable">
                
                    <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                        <i class="ri-hand-coin-line"></i>
                    </div>
                
                    <div class="ggrp-fe-wijziging-content">
                        <p class="ggrp-fe-kicker">Opnemen</p>
                        <h2>Geld opnemen</h2>
                        <p class="ggrp-fe-card-text">
                            Geef door welk bedrag je wilt opnemen.
                        </p>
                    </div>
                
                </a>
            </div>
                
            <div class="ggrp-fe-wijziging-grid">
                <a href="<?php echo esc_url( add_query_arg( 'change', 'strategy' ) ); ?>"
                   class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split ggrp-fe-wijziging-card--clickable">
                
                    <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                        <i class="ri-token-swap-line"></i>
                    </div>
                
                    <div class="ggrp-fe-wijziging-content">
                        <p class="ggrp-fe-kicker">Strategie</p>
                        <h2>Uitkeren of herinvesteren</h2>
                        <p class="ggrp-fe-card-text">
                            Pas je voorkeur aan naar herbeleggen of uitkeren.
                        </p>
                    </div>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'change', 'bank_change' ) ); ?>"
                   class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split ggrp-fe-wijziging-card--clickable">
                
                    <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                        <i class="ri-bank-line"></i>
                    </div>
                
                    <div class="ggrp-fe-wijziging-content">
                        <p class="ggrp-fe-kicker">Rekening</p>
                        <h2>Rekeningnummer wijzigen</h2>
                        <p class="ggrp-fe-card-text">
                            Vraag een wijziging van je uitbetalingsrekening aan.
                        </p>
                    </div>
                
                </a>
            </div>
            
        <?php else : ?>
            <a class="ggrp-fe-back-link" href="<?php echo esc_url( $back_link_url ); ?>">← Terug naar overzicht</a>
            <div class="ggrp-fe-wijziging-grid">
                <?php if ( 'deposit' === $selected_action ) : ?>
                    <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'deposit' === $active_flow ) ? 'is-active' : ''; ?>">
                        
                        <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                        <i class="ri-money-euro-circle-line"></i>
                        </div>
                        <div class="ggrp-fe-wijziging-content">
                        <?php
                        $deposit_step_label = '';
                        $deposit_amount_check = $deposit_amount;
                        if ( ! $deposit_amount_check && $latest_deposit_mutatie ) {
                            $deposit_amount_check = ggr_mutaties_parse_decimal( get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_amount', true ) );
                        }
                        $deposit_needs_review = $deposit_amount_check >= $deposit_threshold;

                        if ( 'questions' === $deposit_stage ) {
                            $deposit_step_label = 'Stap 2 van 3';
                        } elseif ( 'review' === $deposit_stage ) {
                            $deposit_step_label = 'Stap 3 van 3';
                        } elseif ( 'payment' === $deposit_stage ) {
                            $deposit_step_label = $deposit_needs_review ? 'Stap 3 van 3' : 'Stap 2 van 2';
                        } elseif ( 'confirm' === $deposit_stage ) {
                            $deposit_step_label = $deposit_needs_review ? 'Stap 2 van 3' : 'Stap 2 van 2';
                        } elseif ( 'amount' === $deposit_stage ) {
                            $deposit_step_label = $deposit_needs_review ? 'Stap 1 van 3' : 'Stap 1 van 2';
                        }
                        ?>

                        <div class="ggrp-fe-wijziging-topbar">
                            <?php if ( $deposit_step_label ) : ?>
                                <div class="ggrp-fe-step-badge"><?php echo esc_html( $deposit_step_label ); ?></div>
                            <?php endif; ?>
                        </div>

                        <p class="ggrp-fe-kicker">Storten</p>
                        <h2>Geld storten</h2>

                        <?php
                        $deposit_status_key = $latest_deposit_mutatie ? get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_status', true ) : '';
                        $deposit_statuses   = function_exists( 'ggr_mutaties_get_statuses' ) ? ggr_mutaties_get_statuses() : array();
                        $deposit_status_label = $deposit_status_key && isset( $deposit_statuses[ $deposit_status_key ] )
                            ? $deposit_statuses[ $deposit_status_key ]
                            : '';
                        ?>
                        <?php if ( $deposit_status_label ) : ?>
                            <p class="ggrp-fe-card-text"><strong>Status:</strong> <?php echo esc_html( $deposit_status_label ); ?></p>
                        <?php endif; ?>
                        
                            <?php if ( 'questions' === $deposit_stage ) : ?>
                                <p class="ggrp-fe-card-text">Omdat je extra inleg €10.000 of hoger is, hebben we aanvullende informatie nodig.</p>
                                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                                    <input type="hidden" name="ggr_change_action" value="deposit" />
                                    <input type="hidden" name="ggr_flow_step" value="questions" />
                                    <input type="hidden" name="ggr_deposit_amount" value="<?php echo esc_attr( $deposit_amount ); ?>" />
                                    <input type="hidden" name="ggr_deposit_reference" value="<?php echo esc_attr( $deposit_reference ); ?>" />

                                    <div class="ggrp-fe-form-row">
                                        <label for="ggr_deposit_source">Wat is de herkomst van de middelen?</label>
                                        <textarea id="ggr_deposit_source" name="ggr_deposit_source" rows="3" required><?php echo esc_textarea( $deposit_source ); ?></textarea>
                                    </div>
                                    <div class="ggrp-fe-form-row">
                                        <label for="ggr_deposit_explanation">Toelichting / achtergrond bij deze extra inleg</label>
                                        <textarea id="ggr_deposit_explanation" name="ggr_deposit_explanation" rows="3" required><?php echo esc_textarea( $deposit_explanation ); ?></textarea>
                                    </div>

                                    <button type="submit" class="ggrp-fe-button">Aanvraag versturen</button>
                                </form>
                            <?php elseif ( 'review' === $deposit_stage ) : ?>
                                <div class="ggrp-fe-alert ggrp-fe-alert--success">
                                    <p>Je aanvraag is verstuurd. We beoordelen de herkomst van de middelen en nemen contact met je op.</p>
                                </div>
                            <?php elseif ( 'payment' === $deposit_stage ) : ?>
                                <?php
                                $payment_url = $latest_deposit_mutatie ? get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_payment_url', true ) : '';
                                $payment_status = $latest_deposit_mutatie ? get_post_meta( $latest_deposit_mutatie->ID, 'ggr_mutatie_payment_status', true ) : '';
                                ?>
                                <p class="ggrp-fe-card-text">Gebruik de Mollie-betaallink om je extra inleg af te ronden.</p>
                                <?php if ( $payment_url ) : ?>
                                    <a class="ggrp-fe-button ggrp-fe-button--primary" href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener">Ga naar de betaling</a>
                                <?php else : ?>
                                    <div class="ggrp-fe-alert ggrp-fe-alert--error">
                                        <p>We konden geen betaallink vinden. Neem contact op met ondersteuning.</p>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $payment_status ) : ?>
                                    <p class="ggrp-fe-card-text"><strong>Betaalstatus:</strong> <?php echo esc_html( ucfirst( $payment_status ) ); ?></p>
                                <?php endif; ?>
                            <?php elseif ( 'confirm' === $deposit_stage ) : ?>
                                <p class="ggrp-fe-card-text">Controleer je gegevens en bevestig daarna je transactie door op de onderstaande knop te klikken. Vervolgens word je doorgestuurd naar onze beveiligde betaalomgeving.</p>
                                <ul class="ggrp-fe-summary-list">
                                    <li><strong>Bedrag:</strong> <?php echo wp_kses_post( ggrp_fe_format_money( $deposit_amount ) ); ?></li>
                                    <li><strong>Referentie:</strong> <?php echo $deposit_reference ? esc_html( $deposit_reference ) : '—'; ?></li>
                                </ul>
                                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked ggrp-fe-form--inline-actions">
                                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                                    <input type="hidden" name="ggr_change_action" value="deposit" />
                                    <input type="hidden" name="ggr_flow_step" value="confirm" />
                                    <input type="hidden" name="ggr_deposit_amount" value="<?php echo esc_attr( $deposit_amount ); ?>" />
                                    <input type="hidden" name="ggr_deposit_reference" value="<?php echo esc_attr( $deposit_reference ); ?>" />
                                    <button type="submit" class="ggrp-fe-button ggrp-fe-button--primary">Bevestig transactie</button>
                                </form>
                            <?php else : ?>
                                <p class="ggrp-fe-card-text">Geef door hoeveel je wilt storten. Voor bedragen boven de €10.000 vragen we extra informatie.</p>
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

                                    <button type="submit" class="ggrp-fe-button">Verder</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ( 'withdrawal' === $selected_action ) : ?>
                    <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'withdrawal' === $active_flow ) ? 'is-active' : ''; ?>">
                        <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                            <i class="ri-hand-coin-line"></i>
                        </div>
                        <div class="ggrp-fe-wijziging-content">
                        <?php
                        $withdrawal_step_label = '';
                        if ( 'confirm' === $withdrawal_stage ) {
                            $withdrawal_step_label = 'Stap 2 van 2';
                        } elseif ( 'done' !== $withdrawal_stage ) {
                            $withdrawal_step_label = 'Stap 1 van 2';
                        }
                        ?>

                        <div class="ggrp-fe-wijziging-topbar">
                            <?php if ( $withdrawal_step_label ) : ?>
                                <div class="ggrp-fe-step-badge"><?php echo esc_html( $withdrawal_step_label ); ?></div>
                            <?php endif; ?>
                        </div>

                        <p class="ggrp-fe-kicker">Opnemen</p>
                        <h2>Geld opnemen</h2>

                            <?php if ( 'confirm' === $withdrawal_stage ) : ?>
                                <p class="ggrp-fe-card-text">Bevestig dat je dit bedrag wilt opnemen.</p>
                                <ul class="ggrp-fe-summary-list">
                                    <li><strong>Bedrag:</strong> <?php echo wp_kses_post( ggrp_fe_format_money( $withdrawal_amount ) ); ?></li>
                                </ul>
                                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked ggrp-fe-form--inline-actions">
                                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                                    <input type="hidden" name="ggr_change_action" value="withdrawal" />
                                    <input type="hidden" name="ggr_flow_step" value="confirm" />
                                    <input type="hidden" name="ggr_withdrawal_amount" value="<?php echo esc_attr( $withdrawal_amount ); ?>" />
                                    <button type="submit" class="ggrp-fe-button ggrp-fe-button--primary">Opname bevestigen</button>
                                </form>
                            <?php elseif ( 'done' === $withdrawal_stage ) : ?>
                                <div class="ggrp-fe-alert ggrp-fe-alert--success">
                                    <p>Bedankt! Je opname is als mutatie aangemaakt.</p>
                                </div>
                            <?php else : ?>
                                <p class="ggrp-fe-card-text">Geef door welk bedrag je wilt opnemen.</p>
                                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                                    <input type="hidden" name="ggr_change_action" value="withdrawal" />
                                    <input type="hidden" name="ggr_flow_step" value="amount" />

                                    <div class="ggrp-fe-form-row">
                                        <label for="ggr_withdrawal_amount">Opnamebedrag (EUR)</label>
                                        <input type="number" id="ggr_withdrawal_amount" name="ggr_withdrawal_amount" min="0" step="0.01" value="<?php echo esc_attr( $withdrawal_amount ); ?>" required />
                                    </div>

                                    <button type="submit" class="ggrp-fe-button">Verder naar bevestiging</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ( 'strategy' === $selected_action ) : ?>
                    <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'strategy' === $active_flow ) ? 'is-active' : ''; ?>">
                        <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                            <i class="ri-token-swap-line"></i>
                        </div>
                        <div class="ggrp-fe-wijziging-content">
                        <?php
                        $strategy_step_label = '';
                        if ( 'confirm' === $strategy_stage ) {
                            $strategy_step_label = 'Stap 2 van 2';
                        } elseif ( 'done' !== $strategy_stage ) {
                            $strategy_step_label = 'Stap 1 van 2';
                        }
                        ?>

                        <div class="ggrp-fe-wijziging-topbar">
                            <?php if ( $strategy_step_label ) : ?>
                                <div class="ggrp-fe-step-badge"><?php echo esc_html( $strategy_step_label ); ?></div>
                            <?php endif; ?>
                        </div>

                        <p class="ggrp-fe-kicker">Strategie</p>
                        <h2>Uitkeren of herinvesteren</h2>
                        <p class="ggrp-fe-card-text">Huidige keuze: <strong><?php echo esc_html( 'herbeleggen' === $current_strategy ? 'Herbeleggen' : 'Uitkeren' ); ?></strong></p>

                            <?php if ( 'confirm' === $strategy_stage ) : ?>
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
                                    <p>Je strategie is direct bijgewerkt.</p>
                                </div>
                            <?php else : ?>
                                <p class="ggrp-fe-card-text">Kies of je rendement wilt laten uitkeren of automatisch wilt herinvesteren.</p>
                                <form method="post" class="ggrp-fe-form ggrp-fe-form--stacked">
                                    <?php wp_nonce_field( 'ggr_wijziging', 'ggr_wijziging_nonce' ); ?>
                                    <input type="hidden" name="ggr_change_action" value="strategy" />
                                    <input type="hidden" name="ggr_flow_step" value="choose" />

                                    <div class="ggrp-fe-form-row ggrp-fe-form-row--inline">
                                        <label class="ggrp-fe-radio">
                                            <input type="radio" name="ggr_strategy_choice" value="herbeleggen" <?php checked( $strategy_choice, 'herbeleggen' ); ?> />
                                            <span class="ggrp-fe-radio__box" aria-hidden="true">✓</span>
                                            <span class="ggrp-fe-radio__label">
                                                <span class="ggrp-fe-radio__title">Herbeleggen</span>
                                                <span class="ggrp-fe-radio__hint">Extra groei op lange termijn.</span>
                                            </span>
                                        </label>
                                    </div>
                                    <div class="ggrp-fe-form-row ggrp-fe-form-row--inline">
                                        <label class="ggrp-fe-radio">
                                            <input type="radio" name="ggr_strategy_choice" value="uitkeren" <?php checked( $strategy_choice, 'uitkeren' ); ?> />
                                            <span class="ggrp-fe-radio__box" aria-hidden="true">✓</span>
                                            <span class="ggrp-fe-radio__label">
                                                <span class="ggrp-fe-radio__title">Uitkeren</span>
                                                <span class="ggrp-fe-radio__hint">Ontvang uitbetaling op je rekening.</span>
                                            </span>
                                        </label>
                                    </div>

                                    <button type="submit" class="ggrp-fe-button">Verder naar bevestiging</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ( 'bank_change' === $selected_action ) : ?>
                    <div class="ggrp-fe-wijziging-card ggrp-fe-wijziging-card--split <?php echo ( 'bank_change' === $active_flow ) ? 'is-active' : ''; ?>">
                        <div class="ggrp-fe-wijziging-icon" aria-hidden="true">
                            <i class="ri-bank-line"></i>
                        </div>
                        <div class="ggrp-fe-wijziging-content">
                        <?php
                        $bank_step_label = '';
                        if ( 'verification' === $bank_stage ) {
                            $bank_step_label = 'Stap 2 van 3';
                        } elseif ( 'done' !== $bank_stage ) {
                            $bank_step_label = 'Stap 1 van 3';
                        }
                        ?>

                        <div class="ggrp-fe-wijziging-topbar">
                            <?php if ( $bank_step_label ) : ?>
                                <div class="ggrp-fe-step-badge"><?php echo esc_html( $bank_step_label ); ?></div>
                            <?php endif; ?>
                        </div>

                        <p class="ggrp-fe-kicker">Rekening</p>
                        <h2>Rekeningnummer wijzigen</h2>

                            <?php if ( 'verification' === $bank_stage ) : ?>
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
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_portal_investeren', 'ggr_portal_investeren_shortcode' );
add_shortcode( 'ggr_portal_wijziging', 'ggr_portal_investeren_shortcode' );
