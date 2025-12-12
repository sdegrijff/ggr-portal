<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Basis theme-support
 */
function ggr_portal_theme_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support(
        'html5',
        [ 'search-form', 'gallery', 'caption', 'style', 'script' ]
    );
}
add_action( 'after_setup_theme', 'ggr_portal_theme_setup' );


/**
 * Helper: login-achtige pagina (front-end, dus niet wp-login.php)
 */
function ggr_portal_is_login_like_page() {
    if ( ! is_singular() ) {
        return false;
    }

    global $post;

    if ( ! $post instanceof WP_Post ) {
        return false;
    }

    $login_slugs = [
        'inloggen',
        'login',
        '2fa',
        'wachtwoord-vergeten',
        'nieuw-wachtwoord',
        'investeerder-worden',
    ];

    $slug = $post->post_name;

    if ( in_array( $slug, $login_slugs, true ) ) {
        return true;
    }

    if ( ! empty( $post->post_content ) ) {
        if (
            has_shortcode( $post->post_content, 'ggr_login_form' ) ||
            has_shortcode( $post->post_content, 'ggr_portal_login' )
        ) {
            return true;
        }
    }

    return false;
}

function ggr_portal_is_onboarding_page() {
    if ( ! is_singular() ) {
        return false;
    }

    global $post;

    if ( ! $post instanceof WP_Post ) {
        return false;
    }

    return ( $post->post_name === 'onboarding' );
}

/**
 * Helper: is dit een portal-pagina?
 * Zet hier de slugs van je portal-omgeving neer.
 */
function ggr_portal_is_portal_page() {
    // Vul deze lijst met de echte slugs van je portalpagina's
    $portal_slugs = [
        'dashboard',
        'mijn-portefeuille',
        'transacties',
        'berichten',
        'mijn-account',
        // etc...
    ];

    if ( empty( $portal_slugs ) ) {
        return false;
    }

    if ( ! is_singular() ) {
        return false;
    }

    global $post;

    if ( ! $post instanceof WP_Post ) {
        return false;
    }

    return in_array( $post->post_name, $portal_slugs, true );
}


/**
 * Toegangscontrole (frontend):
 *
 * - Niet ingelogd:
 *      → GEEN toegang tot onboarding of portal, redirect naar /inloggen/
 *
 * - Lead:
 *      → Alleen login-achtige pagina's + /onboarding/
 *      → Alles anders → redirect naar /onboarding/
 *
 * - Participant/Admin:
 *      → Wel portal
 *      → Geen onboarding (redirect naar bv. /dashboard/)
 */
add_action( 'template_redirect', 'ggr_portal_access_control' );

function ggr_portal_access_control() {

    // Geen gedoe in de wp-admin
    if ( is_admin() ) {
        return;
    }

    // Login-achtige pagina's zijn altijd bereikbaar
    if ( ggr_portal_is_login_like_page() ) {
        return;
    }

    $login_url      = home_url( '/inloggen/' );
    $onboarding_url = home_url( '/onboarding/' );
    $dashboard_url  = home_url( '/dashboard/' ); // pas aan als je een andere startpagina hebt

    /**
     * NIET INGELOGD:
     * - Geen toegang tot onboarding
     * - Geen toegang tot portal
     * - Wel normale publieke pagina's
     */
    if ( ! is_user_logged_in() ) {

        if ( ggr_portal_is_onboarding_page() || ggr_portal_is_portal_page() ) {
            wp_safe_redirect( $login_url );
            exit;
        }

        return;
    }

    /**
     * INGELOGD:
     * - Split op rol
     */
    $user  = wp_get_current_user();
    $roles = (array) $user->roles;

    // LEAD: alleen login-achtige + onboarding
    if ( in_array( 'lead', $roles, true ) ) {

        // Onboarding of login-achtige pagina? → toegestaan
        if ( ggr_portal_is_onboarding_page() || ggr_portal_is_login_like_page() ) {
            return;
        }
        
        // Onboarding-profiel: sta leads toe om hun accountgegevens te bekijken
        if ( ggr_portal_is_portal_page() ) {
            global $post;

            if ( $post instanceof WP_Post && $post->post_name === 'mijn-account' ) {
                return;
            }
        }

        // Alles anders → terug naar onboarding
        wp_safe_redirect( $onboarding_url );
        exit;
    }

    // NIET-lead (participant / admin / andere rollen):

    // Portal-pagina's → toegestaan
    if ( ggr_portal_is_portal_page() ) {
        return;
    }

    // Onboarding is NIET meer toegankelijk voor non-leads
    if ( ggr_portal_is_onboarding_page() ) {
        wp_safe_redirect( $dashboard_url );
        exit;
    }

    // Overige publieke pagina's → toegestaan
}



/**
 * Front-end styles (portal vs login vs onboarding gescheiden)
 */
function ggr_portal_theme_assets() {

    // Altijd basis style.css laden
    wp_enqueue_style(
        'ggr-portal-style',
        get_stylesheet_uri(),
        [],
        '1.0'
    );

    /**
     * LOGIN-ACHTIGE PAGINA'S
     * /inloggen, /2fa, /wachtwoord-vergeten, etc.
     * → alleen login.css (geen shell/portal)
     */
    if ( ggr_portal_is_login_like_page() ) {

        wp_enqueue_style(
            'ggr-portal-login',
            get_theme_file_uri( 'assets/css/login.css' ),
            [ 'ggr-portal-style' ],
            '1.0'
        );

        return;
    }

    /**
     * ONBOARDING-PAGINA
     * → eigen onboarding.css (geen shell/portal)
     */
    if ( ggr_portal_is_onboarding_page() ) {

        wp_enqueue_style(
            'ggr-portal-onboarding',
            get_theme_file_uri( 'assets/css/onboarding.css' ),
            [ 'ggr-portal-style' ],
            '1.0'
        );

        return;
    }

    /**
     * PORTAAL-OMGEVING
     * → alleen voor niet-leads
     */
    if ( is_user_logged_in() ) {
        $user  = wp_get_current_user();
        $roles = (array) $user->roles;

        // Leads krijgen GEEN shell.css / portal.css
        if ( in_array( 'lead', $roles, true ) ) {
            return;
        }
    }

    // PORTAAL: shell + portal styles
    wp_enqueue_style(
        'ggr-portal-shell',
        get_theme_file_uri( 'assets/css/shell.css' ),
        [ 'ggr-portal-style' ],
        '1.0'
    );

    wp_enqueue_style(
        'ggr-portal-frontend',
        get_theme_file_uri( 'assets/css/portal.css' ),
        [ 'ggr-portal-shell' ],
        '1.0'
    );
}
add_action( 'wp_enqueue_scripts', 'ggr_portal_theme_assets' );



/**
 * Login styling op de WordPress core loginpagina (wp-login.php)
 * (hier wordt het theme zelf niet geladen, dus geen shell/portal styles)
 */
function ggr_portal_wp_login_assets() {
    wp_enqueue_style(
        'ggr-portal-login',
        get_theme_file_uri( 'assets/css/login.css' ),
        [],
        '1.0'
    );
}
add_action( 'login_enqueue_scripts', 'ggr_portal_wp_login_assets' );
