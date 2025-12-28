<?php
/**
 * Mail-instellingen voor standaard afzender.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ggr_portal_mail_from( $from_email ) {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( ! $host ) {
        return $from_email;
    }

    $host   = strtolower( preg_replace( '/^www\./', '', $host ) );
    $parts  = array_values( array_filter( explode( '.', $host ) ) );
    $domain = $host;
    if ( count( $parts ) > 2 ) {
        $domain = implode( '.', array_slice( $parts, -2 ) );
    }
    if ( ! $domain ) {
        return $from_email;
    }

    return 'noreply@' . $domain;
}
add_filter( 'wp_mail_from', 'ggr_portal_mail_from' );

function ggr_portal_mail_from_name( $from_name ) {
    $site_name = get_bloginfo( 'name' );
    if ( ! empty( $site_name ) ) {
        return $site_name;
    }

    return $from_name;
}
add_filter( 'wp_mail_from_name', 'ggr_portal_mail_from_name' );

function ggr_portal_configure_phpmailer( $phpmailer ) {
    $from_email = apply_filters( 'wp_mail_from', $phpmailer->From );
    if ( $from_email ) {
        $phpmailer->From   = $from_email;
        $phpmailer->Sender = $from_email;
    }

    $from_name = apply_filters( 'wp_mail_from_name', $phpmailer->FromName );
    if ( $from_name ) {
        $phpmailer->FromName = $from_name;
    }
}
add_action( 'phpmailer_init', 'ggr_portal_configure_phpmailer' );
