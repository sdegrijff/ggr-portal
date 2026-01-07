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

/**
 * Haal de backlog van een melding op.
 */
function ggr_meldingen_get_history( $melding_id ) {
    $history = get_post_meta( $melding_id, 'ggr_melding_history', true );
    return is_array( $history ) ? $history : array();
}

/**
 * Voeg een item toe aan de melding-backlog.
 */
function ggr_meldingen_add_history_entry( $melding_id, $type, $message, $meta = array() ) {
    $melding_id = (int) $melding_id;
    $history    = ggr_meldingen_get_history( $melding_id );

    $history[] = array(
        'type'       => sanitize_key( $type ),
        'message'    => sanitize_textarea_field( $message ),
        'user_id'    => get_current_user_id(),
        'created_at' => current_time( 'mysql' ),
        'meta'       => is_array( $meta ) ? $meta : array(),
    );

    update_post_meta( $melding_id, 'ggr_melding_history', $history );
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

    if ( function_exists( 'ggr_portal_send_admin_templated_email' ) ) {
        $author_name = '';
        if ( $user_id ) {
            $author_name = function_exists( 'ggr_portal_get_nice_user_name' )
                ? ggr_portal_get_nice_user_name( $user_id )
                : '';
        }
        if ( '' === $author_name ) {
            $current_user = wp_get_current_user();
            $author_name  = $current_user ? $current_user->display_name : '';
        }

        $extra_placeholders = array(
            'melding_title'  => $title,
            'melding_url'    => admin_url( 'admin.php?page=ggr-meldingen' ),
            'melding_type'   => isset( $meta['melding_type'] ) ? $meta['melding_type'] : '',
            'melding_status' => $status,
            'melding_author' => $author_name,
        );

        ggr_portal_send_admin_templated_email( 'admin_new_melding', $extra_placeholders );
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
        'read',
        'ggr-meldingen',
        'ggr_meldingen_render_admin_page',
        'dashicons-bell',
        58
    );
}

function ggr_meldingen_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'list_users' );
}

/**
 * Status van een melding bijwerken.
 */
function ggr_meldingen_handle_status_update() {
    if ( ! isset( $_POST['ggr_melding_nonce'] ) || ! wp_verify_nonce( $_POST['ggr_melding_nonce'], 'ggr_meldingen_update' ) ) {
        return;
    }

    if ( ! ggr_meldingen_user_can_access() ) {
        return;
    }

    if ( isset( $_POST['ggr_meldingen_bulk_action'] ) && 'delete' === $_POST['ggr_meldingen_bulk_action'] ) {
        $ids = isset( $_POST['ggr_melding_ids'] ) ? array_map( 'intval', (array) $_POST['ggr_melding_ids'] ) : array();
        $ids = array_values( array_filter( $ids ) );
        if ( empty( $ids ) ) {
            return;
        }

        foreach ( $ids as $melding_id ) {
            if ( current_user_can( 'delete_post', $melding_id ) ) {
                wp_trash_post( $melding_id );
            }
        }

        return;
    }

    $melding_id = isset( $_POST['ggr_melding_id'] ) ? (int) $_POST['ggr_melding_id'] : 0;
    $status     = isset( $_POST['ggr_melding_status'] ) ? sanitize_key( wp_unslash( $_POST['ggr_melding_status'] ) ) : '';
    $comment    = isset( $_POST['ggr_melding_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_melding_comment'] ) ) : '';
    
    if ( ! $melding_id ) {
        return;
    }

    $statuses = ggr_meldingen_get_statuses();
    if ( ! isset( $statuses[ $status ] ) ) {
        return;
    }

    $previous_status = get_post_meta( $melding_id, 'ggr_melding_status', true );
    update_post_meta( $melding_id, 'ggr_melding_status', $status );

    if ( $previous_status !== $status ) {
        ggr_meldingen_add_history_entry(
            $melding_id,
            'status',
            sprintf(
                'Status gewijzigd van %s naar %s',
                isset( $statuses[ $previous_status ] ) ? $statuses[ $previous_status ] : $previous_status,
                $statuses[ $status ]
            ),
            array(
                'from' => $previous_status,
                'to'   => $status,
            )
        );
    }

    if ( '' !== $comment ) {
        ggr_meldingen_add_history_entry(
            $melding_id,
            'comment',
            $comment,
            array( 'status' => $status )
        );
    }
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
    if ( ! ggr_meldingen_user_can_access() ) {
        wp_die( 'Geen toegang.' );
    }

    if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
        ggr_meldingen_handle_status_update();
    }
    
    $hide_done = isset( $_GET['hide_done'] ) && '1' === $_GET['hide_done'];

    $statuses   = ggr_meldingen_get_statuses();
    $query_args = array(
        'post_type'      => 'ggr_melding',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    if ( $hide_done ) {
        $query_args['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key'     => 'ggr_melding_status',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => 'ggr_melding_status',
                'value'   => 'gedaan',
                'compare' => '!=',
            ),
        );
    }

    $meldingen = get_posts( $query_args );

    ?>
    <div class="wrap ggr-meldingen-page">
        <h1>Meldingen</h1>
        <p>Overzicht van automatische meldingen. Je kunt de status aanpassen of meldingen verwijderen.</p>

        <form method="get" class="ggr-meldingen-filter" style="margin: 10px 0;">
            <input type="hidden" name="page" value="ggr-meldingen" />
            <label>
                <input type="checkbox" name="hide_done" value="1" <?php checked( $hide_done ); ?> />
                Verberg meldingen met status "Gedaan"
            </label>
            <button class="button">Toepassen</button>
        </form>
        
        <form method="post" id="ggr-meldingen-bulk-form" style="margin: 10px 0;">
            <?php wp_nonce_field( 'ggr_meldingen_update', 'ggr_melding_nonce' ); ?>
            <input type="hidden" name="ggr_meldingen_bulk_action" value="delete" />
            <button class="button button-secondary" type="submit" onclick="return confirm('Weet je zeker dat je de geselecteerde meldingen wilt verwijderen?');">
                Geselecteerde meldingen verwijderen
            </button>
        </form>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" id="ggr-meldingen-select-all" form="ggr-meldingen-bulk-form" />
                    </th>                    
                    <th>Status</th>
                    <th>Melding</th>
                    <th>Participant</th>
                    <th>Beschrijving</th>
                    <th>Opmerkingen & backlog</th>                    
                    <th>Aangemaakt</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $meldingen ) ) : ?>
                    <tr>
                        <td colspan="7">Geen meldingen gevonden.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $meldingen as $melding ) :
                        $user_id   = (int) get_post_meta( $melding->ID, 'ggr_melding_user', true );
                        $status    = get_post_meta( $melding->ID, 'ggr_melding_status', true );
                        $status    = isset( $statuses[ $status ] ) ? $status : 'nieuw';
                        $user_link = $user_id ? get_edit_user_link( $user_id ) : '';
                        $user_name = $user_id ? ggr_portal_get_nice_user_name( $user_id ) : 'Onbekende gebruiker';
                        $history   = ggr_meldingen_get_history( $melding->ID );                        
                        ?>
                        <tr>
                            <td class="check-column">
                                <input type="checkbox" name="ggr_melding_ids[]" value="<?php echo esc_attr( $melding->ID ); ?>" form="ggr-meldingen-bulk-form" />
                            </td>                            
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
                            <td>
                                <form method="post" style="margin-bottom:8px;">
                                    <?php wp_nonce_field( 'ggr_meldingen_update', 'ggr_melding_nonce' ); ?>
                                    <input type="hidden" name="ggr_melding_id" value="<?php echo esc_attr( $melding->ID ); ?>" />
                                    <input type="hidden" name="ggr_melding_status" value="<?php echo esc_attr( $status ); ?>" />
                                    <textarea name="ggr_melding_comment" rows="3" style="width:100%;" placeholder="Voeg een opmerking toe"></textarea>
                                    <button class="button" style="margin-top:4px;">Opslaan</button>
                                </form>
                                <?php if ( ! empty( $history ) ) : ?>
                                    <ul class="ggr-melding-history" style="margin:0; padding-left:18px;">
                                        <?php
                                        $history_display = array_reverse( $history );
                                        foreach ( $history_display as $entry ) :
                                            $entry_time = ! empty( $entry['created_at'] ) ? strtotime( $entry['created_at'] ) : false;
                                            $entry_date = $entry_time ? date_i18n( 'd-m-Y H:i', $entry_time ) : '';
                                            $entry_user = ! empty( $entry['user_id'] ) ? get_user_by( 'id', (int) $entry['user_id'] ) : null;
                                            $entry_user_name = $entry_user ? $entry_user->display_name : 'Systeem';
                                            $entry_type = ( isset( $entry['type'] ) && 'status' === $entry['type'] ) ? 'Status' : 'Opmerking';
                                            ?>
                                            <li>
                                                <strong><?php echo esc_html( $entry_type ); ?></strong>
                                                <?php if ( $entry_date ) : ?>
                                                    <span style="color:#6b7280;">(<?php echo esc_html( $entry_date ); ?>)</span>
                                                <?php endif; ?>
                                                <br>
                                                <span><?php echo esc_html( $entry['message'] ?? '' ); ?></span>
                                                <br>
                                                <small style="color:#6b7280;">Door: <?php echo esc_html( $entry_user_name ); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>                            
                            <td><?php echo esc_html( get_the_date( 'd-m-Y H:i', $melding ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <script>
            (function() {
                var selectAll = document.getElementById('ggr-meldingen-select-all');
                if (!selectAll) {
                    return;
                }
                selectAll.addEventListener('change', function(event) {
                    var checkboxes = document.querySelectorAll('input[name="ggr_melding_ids[]"]');
                    checkboxes.forEach(function(box) {
                        box.checked = event.target.checked;
                    });
                });
            })();
        </script>        
    </div>
    <?php
}
