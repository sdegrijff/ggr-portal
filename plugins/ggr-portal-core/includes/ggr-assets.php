<?php
/**
 * Frontend CSS/JS voor het GGR portaal
 * - Login / wachtwoord / 2FA / nieuw wachtwoord => ggr-portal-login.css
 * - Dashboard / Transacties / Berichten / Mijn account => ggr-portal-frontend.css
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ggr_portal_core_enqueue_assets() {
    // In de admin willen we niets doen
    if ( is_admin() ) {
        return;
    }

    // Veiligheidscheck: hoofdplugin moet GGR_PORTAL_CORE_URL definiëren
    if ( ! defined( 'GGR_PORTAL_CORE_URL' ) ) {
        return;
    }

    $assets_url = trailingslashit( GGR_PORTAL_CORE_URL ) . 'assets/';

    // Flags voor pagina-types
    $is_auth_page = is_page( array(
        'login',
        'inloggen',             // als je deze ook gebruikt
        'wachtwoord-vergeten',
        '2fa',
        'nieuw-wachtwoord',
        'investeerder-worden',
    ) );

    $is_onboarding_page = is_page( 'onboarding' );

    $is_portal_page = is_page( array(
        'dashboard',
        'transacties',
        'berichten',
        'mijn-account',
        // voeg hier evt. andere portal-slugs toe
    ) );

    /**
     * 1) AUTH PAGINA'S
     * -> login/wachtwoord/2fa krijgen login CSS
     */
    if ( $is_auth_page ) {
        wp_enqueue_style(
            'ggr-portal-login',
            $assets_url . 'ggr-portal-login.css',
            array(),
            '1.0.0'
        );
        return;
    }

    /**
     * 2) ONBOARDING
     */
    if ( $is_onboarding_page ) {
         wp_enqueue_style(
             'ggr-portal-onboarding',
            $assets_url . 'ggr-portal-onboarding.css',
             array(),
            '1.0.0'
         );
        return;
    }

    /**
     * 3) PORTAAL PAGINA'S
     * -> alleen voor ingelogde NIET-leads
     */
    if ( ! $is_portal_page ) {
        // Geen portalpagina → niks uit deze plugin
        return;
    }

    // Vanaf hier: we zitten op een portalpagina

    if ( ! is_user_logged_in() ) {
        // Niet ingelogd → geen portal CSS
        return;
    }

    $user  = wp_get_current_user();
    $roles = (array) $user->roles;

    // Leads krijgen GEEN portal-frontend CSS
    if ( in_array( 'lead', $roles, true ) ) {
        return;
    }

    // Echte portal gebruikers (participant/admin/...) krijgen portal-frontend
    wp_enqueue_style(
        'ggr-portal-frontend',
        $assets_url . 'ggr-portal-frontend.css',
        array(),
        '1.0.0'
    );
}

add_action( 'wp_enqueue_scripts', 'ggr_portal_core_enqueue_assets', 20 );

