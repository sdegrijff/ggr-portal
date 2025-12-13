<?php
/**
 * GGR Portal – Meldingen CPT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'ggr_register_meldingen_cpt' );

function ggr_register_meldingen_cpt() {
    $labels = array(
        'name'               => 'Meldingen',
        'singular_name'      => 'Melding',
        'menu_name'          => 'Meldingen',
        'name_admin_bar'     => 'Melding',
        'add_new'            => 'Nieuwe melding',
        'add_new_item'       => 'Nieuwe melding toevoegen',
        'edit_item'          => 'Melding bewerken',
        'new_item'           => 'Nieuwe melding',
        'view_item'          => 'Bekijk melding',
        'all_items'          => 'Alle meldingen',
        'search_items'       => 'Zoek meldingen',
        'not_found'          => 'Geen meldingen gevonden',
        'not_found_in_trash' => 'Geen meldingen in prullenbak',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'supports'           => array( 'title', 'editor', 'author' ),
        'menu_icon'          => 'dashicons-bell',
        'has_archive'        => false,
        'rewrite'            => false,
        'show_in_rest'       => false,
    );

    register_post_type( 'ggr_melding', $args );
}

/**
 * Helper om een melding aan te maken.
 */
function ggr_meldingen_add( $title, $content = '', $user_id = 0, $meta = array() ) {
    $post_data = array(
        'post_title'   => wp_strip_all_tags( $title ),
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'ggr_melding',
        'post_author'  => $user_id ? (int) $user_id : get_current_user_id(),
    );

    $post_id = wp_insert_post( $post_data );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    if ( $user_id ) {
        update_post_meta( $post_id, 'ggr_melding_user', (int) $user_id );
    }

    if ( ! empty( $meta ) && is_array( $meta ) ) {
        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, sanitize_key( $key ), $value );
        }
    }

    return $post_id;
}
