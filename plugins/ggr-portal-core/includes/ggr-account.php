<?php
/**
 * Account / Mijn Account weergave
 * Shortcode: [ggr_portal_account]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Verwerken van updates (POST) nog v贸贸r de pagina rendert
 */
add_action( 'init', 'ggrp_fe_handle_account_update' );

function ggrp_fe_format_nl_datetime( $timestamp_or_mysql ) {
    if ( empty( $timestamp_or_mysql ) ) {
        return '';
    }

    if ( function_exists( 'ggr_portal_format_datetime_nl' ) ) {
        return ggr_portal_format_datetime_nl( $timestamp_or_mysql );
    }
    
    $timestamp = is_numeric( $timestamp_or_mysql )
        ? (int) $timestamp_or_mysql
        : strtotime( $timestamp_or_mysql );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( 'd-m-Y H:i', $timestamp );
}

function ggrp_fe_handle_account_update() {
    if (
        ! isset( $_POST['ggr_account_nonce'], $_POST['ggr_account_section'] )
        || ! wp_verify_nonce( $_POST['ggr_account_nonce'], 'ggr_account_update' )
    ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        return;
    }

    $user_id = get_current_user_id();
    $section = sanitize_text_field( wp_unslash( $_POST['ggr_account_section'] ) );

    $user           = get_userdata( $user_id );
    $roles          = $user instanceof WP_User ? (array) $user->roles : array();
    $can_edit_data  = in_array( 'lead', $roles, true ) || current_user_can( 'manage_options' );

    if ( ! $can_edit_data && 'password' !== $section ) {
        return;
    }
    
    $before_snapshot = function_exists( 'ggr_portal_get_participant_audit_snapshot' )
        ? ggr_portal_get_participant_audit_snapshot( $user_id )
        : array();

    $profile_changed = false;
    
    switch ( $section ) {

        /**
         * Participant: voornaam, achternaam, e-mail (login), telefoon
         */
        case 'participant_contact':
            $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
            $last_name  = isset( $_POST['last_name'] )  ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) )  : '';
            $greeting_name = isset( $_POST['ggr_greeting_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_greeting_name'] ) ) : '';
            $email      = isset( $_POST['email'] )      ? sanitize_email( wp_unslash( $_POST['email'] ) )           : '';
            $phone      = isset( $_POST['phone'] )      ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )      : '';

            update_user_meta( $user_id, 'first_name', $first_name );
            update_user_meta( $user_id, 'last_name',  $last_name );
            update_user_meta( $user_id, 'phone',      $phone );
            update_user_meta( $user_id, 'ggr_greeting_name', $greeting_name );
            
            if ( $email && is_email( $email ) ) {
                wp_update_user( [
                    'ID'         => $user_id,
                    'user_email' => $email,
                ] );
            }

            $profile_changed = true;
            break;
            
          /**
         * Participant wachtwoord
         */    
          case 'password':
            $current_pass = isset( $_POST['current_password'] )
                ? wp_unslash( $_POST['current_password'] )
                : '';
            $new_pass     = isset( $_POST['new_password'] )
                ? wp_unslash( $_POST['new_password'] )
                : '';
            $new_pass2    = isset( $_POST['new_password_repeat'] )
                ? wp_unslash( $_POST['new_password_repeat'] )
                : '';

            $error_code = '';

            // 1) Alle velden verplicht
            if ( $current_pass === '' || $new_pass === '' || $new_pass2 === '' ) {
                $error_code = 'empty';
            }
            // 2) Nieuwe wachtwoorden moeten gelijk zijn
            elseif ( $new_pass !== $new_pass2 ) {
                $error_code = 'mismatch';
            }
            // 3) Minimale sterkte (bijvoorbeeld 8 chars)
            elseif ( strlen( $new_pass ) < 8 ) {
                $error_code = 'too_short';
            } else {
                $user = get_userdata( $user_id );
                if ( ! $user || ! wp_check_password( $current_pass, $user->user_pass, $user_id ) ) {
                    $error_code = 'current_invalid';
                }
            }

            $redirect = wp_get_referer();
            if ( ! $redirect ) {
                $redirect = home_url( '/mijn-account/' );
            }

            if ( $error_code ) {
                // Fout doorgeven via querystring C jij kunt hier een toast / melding op baseren
                $redirect = add_query_arg( 'password_error', $error_code, $redirect );
            } else {
                // Alles ok: wachtwoord updaten
                wp_update_user( [
                    'ID'        => $user_id,
                    'user_pass' => $new_pass,
                ] );

                // Let op: dit logt in de praktijk sessies uit.
                // Dat is juist goed voor security, maar check of je dat wilt.
                $redirect = add_query_arg( 'password_updated', '1', $redirect );

                if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
                    ggr_portal_log_participant_action( $user_id, 'password_reset', 'Wachtwoord aangepast.', array() );
                }
            }

            wp_safe_redirect( $redirect );
            exit;

        /**
         * Mede-participant (optioneel): eigen meta velden
         */
        case 'co_contact':
            $co_first = isset( $_POST['co_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['co_first_name'] ) ) : '';
            $co_last  = isset( $_POST['co_last_name'] )  ? sanitize_text_field( wp_unslash( $_POST['co_last_name'] ) )  : '';
            $co_email = isset( $_POST['co_email'] )      ? sanitize_email( wp_unslash( $_POST['co_email'] ) )           : '';
            $co_phone = isset( $_POST['co_phone'] )      ? sanitize_text_field( wp_unslash( $_POST['co_phone'] ) )      : '';

            update_user_meta( $user_id, 'co_first_name', $co_first );
            update_user_meta( $user_id, 'co_last_name',  $co_last );
            update_user_meta( $user_id, 'co_email',      $co_email );
            update_user_meta( $user_id, 'co_phone',      $co_phone );
            $profile_changed = true;
            break;

        case 'bank':
            $iban      = isset( $_POST['bank_account_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_account_iban'] ) ) : '';
            $iban_name = isset( $_POST['bank_account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_account_name'] ) ) : '';

            update_user_meta( $user_id, 'bank_account_iban', $iban );
            update_user_meta( $user_id, 'bank_account_name', $iban_name );
            $profile_changed = true;
            break;

        case 'company':
            $company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
            $company_kvk  = isset( $_POST['company_kvk'] )  ? sanitize_text_field( wp_unslash( $_POST['company_kvk'] ) )  : '';

            update_user_meta( $user_id, 'company_name', $company_name );
            update_user_meta( $user_id, 'company_kvk',  $company_kvk );
            update_user_meta( $user_id, 'billing_company', $company_name );
            $profile_changed = true;
            break;

        case 'address':
            $street  = isset( $_POST['address_street'] )   ? sanitize_text_field( wp_unslash( $_POST['address_street'] ) )   : '';
            $zip     = isset( $_POST['address_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['address_postcode'] ) ) : '';
            $city    = isset( $_POST['address_city'] )     ? sanitize_text_field( wp_unslash( $_POST['address_city'] ) )     : '';
            $country = isset( $_POST['address_country'] )  ? sanitize_text_field( wp_unslash( $_POST['address_country'] ) )  : '';

            update_user_meta( $user_id, 'address_street',   $street );
            update_user_meta( $user_id, 'address_postcode', $zip );
            update_user_meta( $user_id, 'address_city',     $city );
            update_user_meta( $user_id, 'address_country',  $country );
            $profile_changed = true;
            break;

        default:
            return;
    }

    update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );

    if ( $profile_changed && function_exists( 'ggr_portal_log_participant_profile_changes' ) ) {
        ggr_portal_log_participant_profile_changes( $user_id, $before_snapshot );
    }

    $redirect = wp_get_referer();
    if ( ! $redirect ) {
        $redirect = home_url( '/mijn-account/' );
    }
    wp_safe_redirect( $redirect );
    exit;
}

/**
 * Haal accountdata op voor een user.
 */
function ggrp_fe_get_account_data( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return null;
    }

    $meta = get_user_meta( $user_id );

    $first_name = isset( $meta['first_name'][0] ) ? $meta['first_name'][0] : '';
    $last_name  = isset( $meta['last_name'][0] )  ? $meta['last_name'][0]  : '';
    $full_name  = trim( $first_name . ' ' . $last_name );
    $greeting_name = isset( $meta['ggr_greeting_name'][0] ) ? $meta['ggr_greeting_name'][0] : '';
    if ( $full_name === '' ) {
        $full_name = $user->display_name;
    }

    $phone = '';
    if ( ! empty( $meta['phone'][0] ) ) {
        $phone = $meta['phone'][0];
    } elseif ( ! empty( $meta['billing_phone'][0] ) ) {
        $phone = $meta['billing_phone'][0];
    }

    // Mede-participant
    $co_first = ! empty( $meta['co_first_name'][0] ) ? $meta['co_first_name'][0] : '';
    $co_last  = ! empty( $meta['co_last_name'][0] )  ? $meta['co_last_name'][0]  : '';
    $co_email = ! empty( $meta['co_email'][0] )      ? $meta['co_email'][0]      : '';
    $co_phone = ! empty( $meta['co_phone'][0] )      ? $meta['co_phone'][0]      : '';

    $bank_iban = ! empty( $meta['bank_account_iban'][0] ) ? $meta['bank_account_iban'][0] : '';
    $bank_name = ! empty( $meta['bank_account_name'][0] ) ? $meta['bank_account_name'][0] : '';

    $company_name = '';
    if ( ! empty( $meta['company_name'][0] ) ) {
        $company_name = $meta['company_name'][0];
    } elseif ( ! empty( $meta['billing_company'][0] ) ) {
        $company_name = $meta['billing_company'][0];
    }
    $company_kvk  = ! empty( $meta['company_kvk'][0] ) ? $meta['company_kvk'][0] : '';

    $street  = ! empty( $meta['address_street'][0] )   ? $meta['address_street'][0]   : '';
    $zip     = ! empty( $meta['address_postcode'][0] ) ? $meta['address_postcode'][0] : '';
    $city    = ! empty( $meta['address_city'][0] )     ? $meta['address_city'][0]     : '';
    $country = ! empty( $meta['address_country'][0] )  ? $meta['address_country'][0]  : '';

    $onboarding_updated = ! empty( $meta['ggr_onboarding_updated_at'][0] )
        ? ggrp_fe_format_nl_datetime( $meta['ggr_onboarding_updated_at'][0] )
        : '';
    $last_login = ! empty( $meta['ggr_last_login_at'][0] )
        ? ggrp_fe_format_nl_datetime( $meta['ggr_last_login_at'][0] )
        : '';

    return [
        'user'        => $user,

        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'greeting_name' => $greeting_name,
        'full_name'   => $full_name,
        'email'       => $user->user_email,
        'phone'       => $phone,

        'co_first_name' => $co_first,
        'co_last_name'  => $co_last,
        'co_email'      => $co_email,
        'co_phone'      => $co_phone,

        'bank_iban'   => $bank_iban,
        'bank_name'   => $bank_name,

        'company_name' => $company_name,
        'company_kvk'  => $company_kvk,

        'street'   => $street,
        'zip'      => $zip,
        'city'     => $city,
        'country'  => $country,

        'onboarding_updated' => $onboarding_updated,
        'last_login'         => $last_login,
    ];
}

/**
 * Shortcode: [ggr_portal_account]
 */
function ggrp_fe_account_shortcode( $atts ) {
    if ( ! function_exists( 'ggrp_fe_require_login' ) ) {
        return '';
    }

    $maybe_error = ggrp_fe_require_login();
    if ( $maybe_error !== null ) {
        return $maybe_error;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return '<p>Geen gebruiker gevonden.</p>';
    }

    $data = ggrp_fe_get_account_data( $user_id );
    if ( ! $data ) {
        return '<p>Accountgegevens niet beschikbaar.</p>';
    }

    $laatste_datum = do_shortcode( '[ggr_latest_datum]' );

    $roles            = (array) $data['user']->roles;
    $can_edit_profile = in_array( 'lead', $roles, true ) || current_user_can( 'manage_options' );
    
    $participant_name   = trim( $data['first_name'] . ' ' . $data['last_name'] );
    $co_participant_name = trim( $data['co_first_name'] . ' ' . $data['co_last_name'] );

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--account">
        <header class="ggrp-fe-header">
            <div>
                <h1>Mijn Account</h1>
            </div>
        </header>

        <div class="ggrp-fe-account-grid">

            <!-- 1. CONTACTGEGEVENS -->
            <article class="ggrp-fe-account-card">
                <div class="ggrp-fe-account-card-header">
                    <h2>Contactgegevens</h2>
                </div>

                <div class="ggrp-fe-account-card-body">

                    <!-- Participant (view) -->
                    <div class="ggrp-fe-account-row" data-section="participant_contact">
                        <div class="ggrp-fe-account-label">Participant</div>
                        <div class="ggrp-fe-account-value">
                            <div><?php echo esc_html( $participant_name ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['greeting_name'] ? 'Groetnaam: ' . $data['greeting_name'] : '-' ); ?></div>
                            <div><?php echo esc_html( $data['email'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['phone'] ?: '-' ); ?></div>
                        </div>
                        <?php if ( $can_edit_profile ) : ?>
                            <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                        <?php endif; ?>
                    </div>

                    <!-- Participant (form) -->
                    <?php if ( $can_edit_profile ) : ?>
                        <div class="ggrp-fe-account-row-form" data-section="participant_contact">
                            <form method="post" class="ggrp-fe-account-form">
                                <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                                <input type="hidden" name="ggr_account_section" value="participant_contact" />

                                <div class="ggrp-fe-account-label"> </div>
                                <div class="ggrp-fe-account-form-fields">
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Voornaam</label>
                                        <input type="text" name="first_name" class="ggrp-fe-account-input" value="<?php echo esc_attr( $data['first_name'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Groetnaam</label>
                                        <input type="text" name="ggr_greeting_name" class="ggrp-fe-account-input" value="<?php echo esc_attr( $data['greeting_name'] ); ?>" />
                                    </div>                                    
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Achternaam</label>
                                        <input type="text" name="last_name" class="ggrp-fe-account-input" value="<?php echo esc_attr( $data['last_name'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>E-mailadres</label>
                                        <input type="email" name="email" class="ggrp-fe-account-input" value="<?php echo esc_attr( $data['email'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Telefoonnummer</label>
                                        <input type="text" name="phone" class="ggrp-fe-account-input" value="<?php echo esc_attr( $data['phone'] ); ?>" />
                                    </div>
                                </div>

                                <div class="ggrp-fe-account-actions">
                                    <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">Opslaan</button>
                                    <button type="button" class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                        Annuleren
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- Wachtwoord (view) -->
                    <div class="ggrp-fe-account-row" data-section="password">
                        <div class="ggrp-fe-account-label">Wachtwoord</div>
                        <div class="ggrp-fe-account-value">
                            <div>Om veiligheidsredenen tonen we je wachtwoord niet.</div>
                        </div>
                        <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                    </div>

                    <!-- Wachtwoord (form) -->
                    <div class="ggrp-fe-account-row-form" data-section="password">
                        <form method="post" class="ggrp-fe-account-form">
                            <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                            <input type="hidden" name="ggr_account_section" value="password" />

                            <div class="ggrp-fe-account-label"> </div>
                            <div class="ggrp-fe-account-form-fields">

                                <?php
                                // Eventuele simpele feedback op basis van querystring.
                                $pw_error   = isset( $_GET['password_error'] )   ? sanitize_text_field( wp_unslash( $_GET['password_error'] ) )   : '';
                                $pw_success = isset( $_GET['password_updated'] ) ? (bool) $_GET['password_updated'] : false;
                                ?>

                                <?php if ( $pw_success ) : ?>
                                    <div class="ggrp-fe-account-alert ggrp-fe-account-alert--success">
                                        Je wachtwoord is succesvol gewijzigd. Log opnieuw in als daarom wordt gevraagd.
                                    </div>
                                <?php elseif ( $pw_error ) : ?>
                                    <div class="ggrp-fe-account-alert ggrp-fe-account-alert--error">
                                        <?php
                                        switch ( $pw_error ) {
                                            case 'empty':
                                                echo 'Vul alle velden in.';
                                                break;
                                            case 'mismatch':
                                                echo 'De nieuwe wachtwoorden komen niet overeen.';
                                                break;
                                            case 'too_short':
                                                echo 'Je nieuwe wachtwoord is te kort (minimaal 8 tekens).';
                                                break;
                                            case 'current_invalid':
                                                echo 'Je huidige wachtwoord klopt niet.';
                                                break;
                                            default:
                                                echo 'Er is iets misgegaan bij het wijzigen van je wachtwoord.';
                                                break;
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="ggrp-fe-account-form-row">
                                    <label>Huidig wachtwoord</label>
                                    <input type="password" name="current_password" class="ggrp-fe-account-input" />
                                </div>
                                <div class="ggrp-fe-account-form-row">
                                    <label>Nieuw wachtwoord</label>
                                    <input type="password" name="new_password" class="ggrp-fe-account-input" />
                                </div>
                                <div class="ggrp-fe-account-form-row">
                                    <label>Herhaal nieuw wachtwoord</label>
                                    <input type="password" name="new_password_repeat" class="ggrp-fe-account-input" />
                                </div>
                            </div>

                            <div class="ggrp-fe-account-actions">
                                <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">
                                    Wachtwoord opslaan
                                </button>
                                <button type="button"
                                        class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                    Annuleren
                                </button>
                            </div>
                        </form>
                    </div>

                    <hr class="ggrp-fe-account-separator" />

                
                    <!-- Mede-participant (view) -->
                    <div class="ggrp-fe-account-row" data-section="co_contact">
                        <div class="ggrp-fe-account-label">Mede-participant (optioneel)</div>
                        <div class="ggrp-fe-account-value">
                            <div><?php echo esc_html( $co_participant_name ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['co_email'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['co_phone'] ?: '-' ); ?></div>
                        </div>
                        <?php if ( $can_edit_profile ) : ?>
                            <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                        <?php endif; ?>
                    </div>

                    <!-- Mede-participant (form) -->
                    <?php if ( $can_edit_profile ) : ?>
                        <div class="ggrp-fe-account-row-form" data-section="co_contact">
                            <form method="post" class="ggrp-fe-account-form">
                                <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                                <input type="hidden" name="ggr_account_section" value="co_contact" />

                                <div class="ggrp-fe-account-label"> </div>
                                <div class="ggrp-fe-account-form-fields">
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Voornaam</label>
                                        <input type="text" name="co_first_name" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['co_first_name'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Achternaam</label>
                                        <input type="text" name="co_last_name" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['co_last_name'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>E-mailadres</label>
                                        <input type="email" name="co_email" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['co_email'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Telefoonnummer</label>
                                        <input type="text" name="co_phone" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['co_phone'] ); ?>" />
                                    </div>
                                </div>

                                <div class="ggrp-fe-account-actions">
                                    <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">Opslaan</button>
                                    <button type="button" class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                        Annuleren
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </article>

            <!-- 2. ADRESGEGEVENS -->
            <article class="ggrp-fe-account-card">
                <div class="ggrp-fe-account-card-header">
                    <h2>Adresgegevens</h2>
                </div>

                <div class="ggrp-fe-account-card-body">
                    <div class="ggrp-fe-account-row" data-section="address">
                        <div class="ggrp-fe-account-label">Adresgegevens</div>
                        <div class="ggrp-fe-account-value">
                            <div><?php echo esc_html( $data['street'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['zip'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['city'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['country'] ?: '-' ); ?></div>
                        </div>
                        <?php if ( $can_edit_profile ) : ?>
                            <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php if ( $can_edit_profile ) : ?>
                        <div class="ggrp-fe-account-row-form" data-section="address">
                            <form method="post" class="ggrp-fe-account-form">
                                <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                                <input type="hidden" name="ggr_account_section" value="address" />

                                <div class="ggrp-fe-account-label"> </div>
                                <div class="ggrp-fe-account-form-fields">
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Straat + huisnummer</label>
                                        <input type="text" name="address_street" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['street'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Postcode</label>
                                        <input type="text" name="address_postcode" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['zip'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Plaats</label>
                                        <input type="text" name="address_city" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['city'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Land</label>
                                        <input type="text" name="address_country" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['country'] ); ?>" />
                                    </div>
                                </div>


                                <div class="ggrp-fe-account-actions">
                                    <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">Opslaan</button>
                                    <button type="button" class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                        Annuleren
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <!-- 3. BANKGEGEVENS -->
            <article class="ggrp-fe-account-card">
                <div class="ggrp-fe-account-card-header">
                    <h2>Bankgegevens</h2>
                </div>

                <div class="ggrp-fe-account-card-body">
                    <div class="ggrp-fe-account-row" data-section="bank">
                        <div class="ggrp-fe-account-label">Bankgegevens</div>
                        <div class="ggrp-fe-account-value">
                            <div><?php echo esc_html( $data['bank_iban'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['bank_name'] ?: '-' ); ?></div>
                        </div>
                        <?php if ( $can_edit_profile ) : ?>
                            <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php if ( $can_edit_profile ) : ?>
                        <div class="ggrp-fe-account-row-form" data-section="bank">
                            <form method="post" class="ggrp-fe-account-form">
                                <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                                <input type="hidden" name="ggr_account_section" value="bank" />

                                <div class="ggrp-fe-account-label"> </div>
                                <div class="ggrp-fe-account-form-fields">
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Rekeningnummer (IBAN)</label>
                                        <input type="text" name="bank_account_iban" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['bank_iban'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Tenaamstelling rekening</label>
                                        <input type="text" name="bank_account_name" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['bank_name'] ); ?>" />
                                    </div>
                                </div>

                                <div class="ggrp-fe-account-actions">
                                    <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">Opslaan</button>
                                    <button type="button" class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                        Annuleren
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <!-- 4. BEDRIJFSGEGEVENS -->
            <article class="ggrp-fe-account-card">
                <div class="ggrp-fe-account-card-header">
                    <h2>Bedrijfsgegevens (optioneel)</h2>
                </div>

                <div class="ggrp-fe-account-card-body">
                    <div class="ggrp-fe-account-row" data-section="company">
                        <div class="ggrp-fe-account-label">Bedrijfsgegevens</div>
                        <div class="ggrp-fe-account-value">
                            <div><?php echo esc_html( $data['company_name'] ?: '-' ); ?></div>
                            <div><?php echo esc_html( $data['company_kvk'] ?: '-' ); ?></div>
                        </div>
                        <?php if ( $can_edit_profile ) : ?>
                            <a href="#" class="ggrp-fe-account-row-edit">Edit</a>
                        <?php endif; ?>
                    </div>

                    <?php if ( $can_edit_profile ) : ?>
                        <div class="ggrp-fe-account-row-form" data-section="company">
                            <form method="post" class="ggrp-fe-account-form">
                                <?php wp_nonce_field( 'ggr_account_update', 'ggr_account_nonce' ); ?>
                                <input type="hidden" name="ggr_account_section" value="company" />

                                <div class="ggrp-fe-account-label"> </div>
                                <div class="ggrp-fe-account-form-fields">
                                    <div class="ggrp-fe-account-form-row">
                                        <label>Bedrijfsnaam</label>
                                        <input type="text" name="company_name" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['company_name'] ); ?>" />
                                    </div>
                                    <div class="ggrp-fe-account-form-row">
                                        <label>KvK-nummer</label>
                                        <input type="text" name="company_kvk" class="ggrp-fe-account-input"
                                               value="<?php echo esc_attr( $data['company_kvk'] ); ?>" />
                                    </div>
                                </div>

                                <div class="ggrp-fe-account-actions">
                                    <button type="submit" class="ggrp-fe-account-btn ggrp-fe-account-btn--primary">Opslaan</button>
                                    <button type="button" class="ggrp-fe-account-btn ggrp-fe-account-btn--ghost ggrp-fe-account-cancel">
                                        Annuleren
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

        </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Edit knoppen per rij
        document.querySelectorAll('.ggrp-fe-account-row-edit').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var row = btn.closest('.ggrp-fe-account-row');
                if (!row) return;
                var section = row.getAttribute('data-section');
                var formRow = document.querySelector('.ggrp-fe-account-row-form[data-section="' + section + '"]');
                if (!formRow) return;

                // alles sluiten
                document.querySelectorAll('.ggrp-fe-account-row.is-editing').forEach(function (r) {
                    r.classList.remove('is-editing');
                });
                document.querySelectorAll('.ggrp-fe-account-row-form.is-visible').forEach(function (fr) {
                    fr.classList.remove('is-visible');
                });

                // huidige openen
                row.classList.add('is-editing');
                formRow.classList.add('is-visible');
            });
        });

        // Annuleren
        document.querySelectorAll('.ggrp-fe-account-cancel').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var formRow = btn.closest('.ggrp-fe-account-row-form');
                if (!formRow) return;
                var section = formRow.getAttribute('data-section');
                var row = document.querySelector('.ggrp-fe-account-row[data-section="' + section + '"]');
                if (row) row.classList.remove('is-editing');
                formRow.classList.remove('is-visible');
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode( 'ggr_portal_account', 'ggrp_fe_account_shortcode' );
