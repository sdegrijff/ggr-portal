<?php
/**
 * Plugin Name: GGR Portal Core
 * Description: Centrale loader voor alle GGR Portal modules.
 * Version: 3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Basispaden
 */
define( 'GGR_PORTAL_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GGR_PORTAL_CORE_URL',  plugin_dir_url( __FILE__ ) );

/**
 * MODULE LOADER
 * Elke module is 100% zelfstandig.
 */

$ggr_modules = [
    'includes/ggr-portal-auth.php',               // Login & authenticatie
    'includes/ggr-account.php',            // Profiel pagina portaal      
    'includes/ggr-assets.php',             // CSS & JS
    'includes/ggr-messages.php',           // Berichten centrum
    'includes/ggr-frontend.php',           // Design portal omgeving
    'includes/ggr-email-templates.php',    // E-mail templates
    'includes/ggr-stock-price.php',        // Dagelijkse koers
    'includes/ggr-crm.php',                // CRM voor leads + participants
    'includes/ggr-participants.php',       // Participant profiel & admin scherm
    'includes/ggr-onboarding.php',         // Onboarding module
];

foreach ( $ggr_modules as $module ) {
    $file = GGR_PORTAL_CORE_PATH . $module;
    if ( file_exists( $file ) ) {
        require_once $file;
    } else {
        error_log( 'GGR Portal: Module niet gevonden — ' . $module );
    }
}
