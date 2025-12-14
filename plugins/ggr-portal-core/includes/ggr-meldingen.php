<?php
/**
 * GGR Portal – Meldingen CPT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'ggr_register_meldingen_cpt' );

/**
 * Beschikbare statuswaarden voor meldingen.
 */
function ggr_meldingen_get_statuses() {
    return array(
        'nieuw'       => 'Nieuw',
        'gezien'      => 'Gezien',
        'opgepakt'    => 'Opgepakt',
        'overleggen'  => 'Overleggen',
        'gedaan'      => 'Gedaan',
    );
}

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
        'show_ui'            => false, // beheerd via eigen adminpagina
        'show_in_menu'       => false,
        'capability_type'    => 'post',
        'supports'           => array( 'title', 'editor', 'author' ),
        'menu_icon'          => 'dashicons-bell',
        'has_archive'        => false,
        'rewrite'            => false,
        'show_in_rest'       => false,
        'capabilities'       => array(
            'create_posts' => 'do_not_allow', // Geen handmatige meldingen aanmaken
        ),
        'map_meta_cap'       => true,
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

    $statuses = ggr_meldingen_get_statuses();
    $status   = isset( $meta['ggr_melding_status'] ) && isset( $statuses[ $meta['ggr_melding_status'] ] )
        ? $meta['ggr_melding_status']
        : 'nieuw';

    update_post_meta( $post_id, 'ggr_melding_status', $status );

    if ( ! empty( $meta ) && is_array( $meta ) ) {
        foreach ( $meta as $key => $value ) {
            if ( 'ggr_melding_status' === $key ) {
                continue; // reeds verwerkt
            }
            update_post_meta( $post_id, sanitize_key( $key ), $value );
        }
    }

    return $post_id;
}

/**
 * Admin-menu voor meldingen.
 */
add_action( 'admin_menu', 'ggr_meldingen_register_admin_page' );

function ggr_meldingen_register_admin_page() {
    add_menu_page(
        'Meldingen',
        'Meldingen',
        'list_users',
        'ggr-meldingen',
        'ggr_meldingen_render_admin_page',
        'dashicons-bell',
        58
    );
}

/**
 * Status van een melding bijwerken.
 */
function ggr_meldingen_handle_status_update() {
    if ( ! isset( $_POST['ggr_melding_nonce'] ) || ! wp_verify_nonce( $_POST['ggr_melding_nonce'], 'ggr_meldingen_update' ) ) {
        return;
    }

    if ( ! current_user_can( 'list_users' ) ) {
        return;
    }

    $melding_id = isset( $_POST['ggr_melding_id'] ) ? (int) $_POST['ggr_melding_id'] : 0;
    $status     = isset( $_POST['ggr_melding_status'] ) ? sanitize_key( wp_unslash( $_POST['ggr_melding_status'] ) ) : '';

    if ( ! $melding_id ) {
        return;
    }

    $statuses = ggr_meldingen_get_statuses();
    if ( ! isset( $statuses[ $status ] ) ) {
        return;
    }

    update_post_meta( $melding_id, 'ggr_melding_status', $status );
}

/**
 * Voeg een melding toe voor een onboarding-status.
 */
function ggr_meldingen_add_onboarding_status_change( $user_id, $status, $title, $content ) {
    $status = sanitize_key( $status );
    $user_id = (int) $user_id;

    // Voorkom dubbele meldingen voor dezelfde user/status-combinatie.
    $existing = get_posts(
        array(
            'post_type'      => 'ggr_melding',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => 'ggr_melding_user',
                    'value' => $user_id,
                ),
                array(
                    'key'   => 'onboarding_status',
                    'value' => $status,
                ),
            ),
        )
    );

    if ( ! empty( $existing ) ) {
        return;
    }

    ggr_meldingen_add(
        $title,
        $content,
        $user_id,
        array(
            'onboarding_status' => $status,
        )
    );
}

/**
 * Meldingen-overzicht renderen.
 */
function ggr_meldingen_render_admin_page() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_die( 'Geen toegang.' );
    }

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
        ggr_meldingen_handle_status_update();
    }

    $statuses   = ggr_meldingen_get_statuses();
    $meldingen  = get_posts(
        array(
            'post_type'      => 'ggr_melding',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        )
    );

    ?>
    <div class="wrap ggr-meldingen-page">
        <h1>Meldingen</h1>
        <p>Overzicht van automatische meldingen. Je kunt alleen de status aanpassen.</p>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Melding</th>
                    <th>Participant</th>
                    <th>Beschrijving</th>
                    <th>Aangemaakt</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $meldingen ) ) : ?>
                    <tr>
                        <td colspan="5">Geen meldingen gevonden.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $meldingen as $melding ) :
                        $user_id   = (int) get_post_meta( $melding->ID, 'ggr_melding_user', true );
                        $status    = get_post_meta( $melding->ID, 'ggr_melding_status', true );
                        $status    = isset( $statuses[ $status ] ) ? $status : 'nieuw';
                        $user_link = $user_id ? get_edit_user_link( $user_id ) : '';
                        $user_name = $user_id ? ggr_portal_get_nice_user_name( $user_id ) : 'Onbekende gebruiker';
                        ?>
                        <tr>
                            <td>
                                <form method="post">
                                    <?php wp_nonce_field( 'ggr_meldingen_update', 'ggr_melding_nonce' ); ?>
                                    <input type="hidden" name="ggr_melding_id" value="<?php echo esc_attr( $melding->ID ); ?>" />
                                    <select name="ggr_melding_status">
                                        <?php foreach ( $statuses as $key => $label ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $status ); ?>><?php echo esc_html( strtoupper( $label ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button">Opslaan</button>
                                </form>
                            </td>
                            <td><strong><?php echo esc_html( $melding->post_title ); ?></strong></td>
                            <td>
                                <?php if ( $user_link ) : ?>
                                    <a href="<?php echo esc_url( $user_link ); ?>"><?php echo esc_html( $user_name ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $user_name ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo wp_kses_post( wpautop( $melding->post_content ) ); ?></td>
                            <td><?php echo esc_html( get_the_date( 'd-m-Y H:i', $melding ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
