<?php
/**
 * GGR Portal – Mutaties CPT + admin omgeving.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'ggr_register_mutaties_cpt' );

/**
 * Beschikbare statussen voor mutaties.
 */
function ggr_mutaties_get_statuses() {
    return array(
        'nieuw'       => 'Nieuw',
        'goedgekeurd' => 'Goedgekeurd',
        'ingepland'   => 'Ingepland',
        'uitgevoerd'  => 'Uitgevoerd',
        'geannuleerd' => 'Geannuleerd',
    );
}

/**
 * Beschikbare mutatietypes.
 */
function ggr_mutaties_get_types() {
    return array(
        'dividend' => 'Dividend',
        'uitkering' => 'Uitkering',
        'opname' => 'Opname',
        'inleg' => 'Inleg',
    );
}

/**
 * Bepaal volgende mutatiedatum: eerste dag van volgende maand.
 */
function ggr_mutaties_get_next_run_date() {
    return wp_date( 'Y-m-01', strtotime( 'first day of next month' ) );
}

function ggr_register_mutaties_cpt() {
    $labels = array(
        'name'               => 'Mutaties',
        'singular_name'      => 'Mutatie',
        'menu_name'          => 'Mutaties',
        'name_admin_bar'     => 'Mutatie',
        'add_new'            => 'Nieuwe mutatie',
        'add_new_item'       => 'Nieuwe mutatie toevoegen',
        'edit_item'          => 'Mutatie bewerken',
        'new_item'           => 'Nieuwe mutatie',
        'view_item'          => 'Bekijk mutatie',
        'all_items'          => 'Alle mutaties',
        'search_items'       => 'Zoek mutaties',
        'not_found'          => 'Geen mutaties gevonden',
        'not_found_in_trash' => 'Geen mutaties in prullenbak',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => false,
        'capability_type'    => 'post',
        'supports'           => array( 'title', 'editor' ),
        'menu_icon'          => 'dashicons-randomize',
        'has_archive'        => false,
        'rewrite'            => false,
        'show_in_rest'       => false,
        'map_meta_cap'       => true,
    );

    register_post_type( 'ggr_mutatie', $args );
}

/**
 * Admin-menu voor mutaties.
 */
add_action( 'admin_menu', 'ggr_mutaties_register_admin_page' );

function ggr_mutaties_register_admin_page() {
    add_menu_page(
        'Mutaties',
        'Mutaties',
        'read',
        'ggr-mutaties',
        'ggr_mutaties_render_admin_page',
        'dashicons-randomize',
        58
    );
}

function ggr_mutaties_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'list_users' );
}

/**
 * Metaboxes voor mutatie-details.
 */
add_action( 'add_meta_boxes', 'ggr_mutaties_register_metaboxes' );

function ggr_mutaties_register_metaboxes() {
    add_meta_box(
        'ggr_mutatie_details',
        'Mutatie-details',
        'ggr_mutaties_render_metabox',
        'ggr_mutatie',
        'normal',
        'default'
    );
}

function ggr_mutaties_render_metabox( $post ) {
    $status  = get_post_meta( $post->ID, 'ggr_mutatie_status', true );
    $type    = get_post_meta( $post->ID, 'ggr_mutatie_type', true );
    $amount  = get_post_meta( $post->ID, 'ggr_mutatie_amount', true );
    $planned = get_post_meta( $post->ID, 'ggr_mutatie_planned_date', true );
    $scope   = get_post_meta( $post->ID, 'ggr_mutatie_scope', true );
    $user_id = get_post_meta( $post->ID, 'ggr_mutatie_user_id', true );

    if ( ! $status ) {
        $status = 'nieuw';
    }

    if ( ! $type ) {
        $type = 'dividend';
    }

    if ( ! $scope ) {
        $scope = 'all';
    }

    wp_nonce_field( 'ggr_mutatie_meta_save', 'ggr_mutatie_meta_nonce' );
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="ggr_mutatie_type">Type</label></th>
            <td>
                <select name="ggr_mutatie_type" id="ggr_mutatie_type">
                    <?php foreach ( ggr_mutaties_get_types() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_amount">Bedrag</label></th>
            <td>
                <input type="text" name="ggr_mutatie_amount" id="ggr_mutatie_amount" value="<?php echo esc_attr( $amount ); ?>" />
                <p class="description">Voer het bedrag in euro in (optioneel bij dividend).</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_scope">Doelgroep</label></th>
            <td>
                <select name="ggr_mutatie_scope" id="ggr_mutatie_scope">
                    <option value="all" <?php selected( $scope, 'all' ); ?>>Alle participanten</option>
                    <option value="user" <?php selected( $scope, 'user' ); ?>>Specifieke participant</option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_user_id">Participant (optioneel)</label></th>
            <td>
                <?php
                wp_dropdown_users( array(
                    'name'              => 'ggr_mutatie_user_id',
                    'selected'          => (int) $user_id,
                    'show_option_none'  => '— Selecteer participant —',
                    'role__in'          => array( 'participant' ),
                    'show'              => 'display_name',
                ) );
                ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_status">Status</label></th>
            <td>
                <select name="ggr_mutatie_status" id="ggr_mutatie_status">
                    <?php foreach ( ggr_mutaties_get_statuses() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_planned_date">Geplande datum</label></th>
            <td>
                <input type="date" name="ggr_mutatie_planned_date" id="ggr_mutatie_planned_date" value="<?php echo esc_attr( $planned ); ?>" />
                <p class="description">Bij goedkeuring vullen we automatisch de eerstvolgende maandnota (1e).</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_ggr_mutatie', 'ggr_mutaties_save_meta' );

function ggr_mutaties_save_meta( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! isset( $_POST['ggr_mutatie_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ggr_mutatie_meta_nonce'], 'ggr_mutatie_meta_save' ) ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $type    = isset( $_POST['ggr_mutatie_type'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_type'] ) ) : 'dividend';
    $amount  = isset( $_POST['ggr_mutatie_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_mutatie_amount'] ) ) : '';
    $scope   = isset( $_POST['ggr_mutatie_scope'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_scope'] ) ) : 'all';
    $user_id = isset( $_POST['ggr_mutatie_user_id'] ) ? (int) $_POST['ggr_mutatie_user_id'] : 0;
    $status  = isset( $_POST['ggr_mutatie_status'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_status'] ) ) : 'nieuw';
    $planned = isset( $_POST['ggr_mutatie_planned_date'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_mutatie_planned_date'] ) ) : '';

    $types    = ggr_mutaties_get_types();
    $statuses = ggr_mutaties_get_statuses();

    if ( ! isset( $types[ $type ] ) ) {
        $type = 'dividend';
    }

    if ( ! isset( $statuses[ $status ] ) ) {
        $status = 'nieuw';
    }

    if ( ! in_array( $scope, array( 'all', 'user' ), true ) ) {
        $scope = 'all';
    }

    update_post_meta( $post_id, 'ggr_mutatie_type', $type );
    update_post_meta( $post_id, 'ggr_mutatie_amount', $amount );
    update_post_meta( $post_id, 'ggr_mutatie_scope', $scope );
    update_post_meta( $post_id, 'ggr_mutatie_user_id', $user_id );
    update_post_meta( $post_id, 'ggr_mutatie_status', $status );
    update_post_meta( $post_id, 'ggr_mutatie_planned_date', $planned );
}

/**
 * Admin pagina om mutaties te beheren.
 */
function ggr_mutaties_render_admin_page() {
    if ( ! ggr_mutaties_user_can_access() ) {
        wp_die( 'Geen toegang.' );
    }

    $message = '';

    if ( isset( $_POST['ggr_mutaties_approve_nonce'] ) && wp_verify_nonce( $_POST['ggr_mutaties_approve_nonce'], 'ggr_mutaties_approve' ) ) {
        $ids = isset( $_POST['ggr_mutatie_ids'] ) ? array_map( 'intval', (array) $_POST['ggr_mutatie_ids'] ) : array();

        if ( $ids ) {
            $planned_date = ggr_mutaties_get_next_run_date();
            foreach ( $ids as $mutatie_id ) {
                update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'ingepland' );
                update_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', $planned_date );
            }

            $message = sprintf( '%d mutaties ingepland voor %s.', count( $ids ), $planned_date );
        }
    }

    $mutaties = get_posts( array(
        'post_type'      => 'ggr_mutatie',
        'posts_per_page' => 200,
        'post_status'    => array( 'publish', 'draft' ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $statuses = ggr_mutaties_get_statuses();
    $types    = ggr_mutaties_get_types();
    $new_url  = admin_url( 'post-new.php?post_type=ggr_mutatie' );

    ?>
    <div class="wrap">
        <h1>Mutaties</h1>

        <p>Beheer maandelijkse mutaties (dividend, uitkeringen, opnames en inleg) en plan deze in voor de eerstvolgende maandnota.</p>

        <p><a class="button button-primary" href="<?php echo esc_url( $new_url ); ?>">Nieuwe mutatie</a></p>

        <?php if ( $message ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( empty( $mutaties ) ) : ?>
            <p>Er zijn nog geen mutaties aangemaakt.</p>
        <?php else : ?>
            <form method="post">
                <?php wp_nonce_field( 'ggr_mutaties_approve', 'ggr_mutaties_approve_nonce' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb"><input type="checkbox" id="ggr_mutaties_select_all" /></th>
                            <th scope="col">Titel</th>
                            <th scope="col">Type</th>
                            <th scope="col">Doelgroep</th>
                            <th scope="col">Bedrag</th>
                            <th scope="col">Status</th>
                            <th scope="col">Gepland</th>
                            <th scope="col">Aangemaakt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $mutaties as $mutatie ) : ?>
                            <?php
                            $mutatie_id = $mutatie->ID;
                            $status     = get_post_meta( $mutatie_id, 'ggr_mutatie_status', true );
                            $type       = get_post_meta( $mutatie_id, 'ggr_mutatie_type', true );
                            $amount     = get_post_meta( $mutatie_id, 'ggr_mutatie_amount', true );
                            $scope      = get_post_meta( $mutatie_id, 'ggr_mutatie_scope', true );
                            $planned    = get_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', true );
                            $user_id    = (int) get_post_meta( $mutatie_id, 'ggr_mutatie_user_id', true );
                            $user_name  = $user_id ? ( get_user_by( 'ID', $user_id )->display_name ?? '' ) : '';

                            if ( ! $status ) {
                                $status = 'nieuw';
                            }
                            if ( ! $type ) {
                                $type = 'dividend';
                            }

                            $can_schedule = in_array( $status, array( 'nieuw', 'goedgekeurd' ), true );
                            ?>
                            <tr>
                                <th scope="row">
                                    <?php if ( $can_schedule ) : ?>
                                        <input type="checkbox" name="ggr_mutatie_ids[]" value="<?php echo (int) $mutatie_id; ?>" />
                                    <?php endif; ?>
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url( get_edit_post_link( $mutatie_id ) ); ?>">
                                            <?php echo esc_html( $mutatie->post_title ); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td>
                                <td>
                                    <?php
                                    if ( 'user' === $scope && $user_name ) {
                                        echo esc_html( $user_name );
                                    } else {
                                        echo esc_html( 'Alle participanten' );
                                    }
                                    ?>
                                </td>
                                <td><?php echo $amount !== '' ? esc_html( '€ ' . $amount ) : '—'; ?></td>
                                <td><?php echo esc_html( $statuses[ $status ] ?? $status ); ?></td>
                                <td><?php echo $planned ? esc_html( $planned ) : '—'; ?></td>
                                <td><?php echo esc_html( wp_date( 'd-m-Y', strtotime( $mutatie->post_date ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 16px;">
                    <button type="submit" class="button button-primary">Goedkeuren &amp; inplannen voor eerstvolgende maandnota</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <script>
    (function() {
        var selectAll = document.getElementById('ggr_mutaties_select_all');
        if (!selectAll) return;
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="ggr_mutatie_ids[]"]');
            checkboxes.forEach(function(box) {
                box.checked = selectAll.checked;
            });
        });
    })();
    </script>
    <?php
}
