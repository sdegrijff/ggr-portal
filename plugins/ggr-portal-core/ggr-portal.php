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

$ggr_modules = glob( GGR_PORTAL_CORE_PATH . 'includes/*.php' );

if ( $ggr_modules === false ) {
    error_log( 'GGR Portal: Module glob kon niet worden uitgevoerd.' );
    $ggr_modules = array();
}

sort( $ggr_modules );

foreach ( $ggr_modules as $module_path ) {
    if ( file_exists( $module_path ) ) {
        require_once $module_path;
    } else {
        error_log( 'GGR Portal: Module niet gevonden — ' . basename( $module_path ) );
    }
}
