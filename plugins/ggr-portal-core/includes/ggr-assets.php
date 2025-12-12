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
        // In de admin willen we niets doen en de hoofdplugin moet beschikbaar zijn
    if ( is_admin() || ! defined( 'GGR_PORTAL_CORE_URL' ) ) {
        return;
    }

    $assets_url = trailingslashit( GGR_PORTAL_CORE_URL ) . 'assets/';
    $page_context = 'other';

    if ( is_page( array(
        'login',
        'inloggen',             // als je deze ook gebruikt
        'wachtwoord-vergeten',
        '2fa',
        'nieuw-wachtwoord',
        'investeerder-worden',
            ) ) ) {
        $page_context = 'auth';
    } elseif ( is_page( 'onboarding' ) ) {
        $page_context = 'onboarding';
    } elseif ( is_page( array(
        'dashboard',
        'transacties',
        'berichten',
        'mijn-account',
        // voeg hier evt. andere portal-slugs toe
            ) ) ) {
        $page_context = 'portal';
    }
   switch ( $page_context ) {
        case 'auth':
            wp_enqueue_style(
                'ggr-portal-login',
                $assets_url . 'ggr-portal-login.css',
                array(),
                '1.0.0'
            );
            break;

        case 'onboarding':
            wp_enqueue_style(
                'ggr-portal-onboarding',
                $assets_url . 'ggr-portal-onboarding.css',
                array(),
                '1.0.0'
            );
            break;

        case 'portal':
            if ( ! is_user_logged_in() ) {
                break;
            }

            wp_enqueue_style(
                'ggr-portal-frontend',
                $assets_url . 'ggr-portal-frontend.css',
                array(),
                '1.0.0'
            );
            break;
    }
    }

add_action( 'wp_enqueue_scripts', 'ggr_portal_core_enqueue_assets', 20 );
