<?php
/**
 * GGR Portal – Mutaties CPT + admin omgeving.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'ggr_register_mutaties_cpt' );
add_action( 'init', 'ggr_mutaties_remove_post_support', 11 );

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
        'dividend_herinvestering' => 'Dividend herinvestering',
        'dividend_uitkering'      => 'Dividend uitkering',
        'inleg'                   => 'Inleg',
        'opname'                  => 'Opname',
    );
}

/**
 * Bepaal volgende mutatiedatum: eerste dag van volgende maand.
 */
function ggr_mutaties_get_next_run_date( $base_date = null ) {
    $timestamp = $base_date ? strtotime( $base_date ) : current_time( 'timestamp' );

    return wp_date( 'Y-m-01', strtotime( 'first day of next month', $timestamp ) );
}

/**
 * Bepaal mutatiedatum voor huidige maand: eerste dag van deze maand.
 */
function ggr_mutaties_get_current_run_date( $base_date = null ) {
    $timestamp = $base_date ? strtotime( $base_date ) : current_time( 'timestamp' );

    return wp_date( 'Y-m-01', strtotime( 'first day of this month', $timestamp ) );
}

function ggr_mutaties_normalize_planned_date( $raw_date, $allow_any_date = false ) {
    $raw_date = trim( (string) $raw_date );
    if ( $raw_date === '' ) {
        return $allow_any_date
            ? wp_date( 'Y-m-d', current_time( 'timestamp' ) )
            : ggr_mutaties_get_next_run_date();
    }

    if ( function_exists( 'ggr_portal_parse_date_to_mysql' ) ) {
        $planned_date = ggr_portal_parse_date_to_mysql( $raw_date );
    } else {
        $planned_date = date( 'Y-m-d', strtotime( $raw_date ) );
    }

    if ( ! $planned_date ) {
        return $allow_any_date
            ? wp_date( 'Y-m-d', current_time( 'timestamp' ) )
            : ggr_mutaties_get_next_run_date();
    }

    $dt = DateTime::createFromFormat( 'Y-m-d', $planned_date, wp_timezone() );
    if ( ! $dt ) {
        return $allow_any_date
            ? wp_date( 'Y-m-d', current_time( 'timestamp' ) )
            : ggr_mutaties_get_next_run_date();
    }

    if ( ! $allow_any_date && '01' !== $dt->format( 'd' ) ) {
        $dt->modify( 'first day of next month' );
    }

    return $dt->format( 'Y-m-d' );
}

function ggr_mutaties_parse_decimal( $raw ) {
    $raw = trim( (string) $raw );
    if ( $raw === '' ) {
        return 0.0;
    }

    $raw = str_replace( ',', '.', $raw );

    return (float) $raw;
}

function ggr_mutaties_format_nl_date( $date ) {
    if ( ! $date ) {
        return '—';
    }

    $timestamp = strtotime( $date );
    if ( ! $timestamp ) {
        return $date;
    }

    return wp_date( 'd-m-Y', $timestamp );
}

function ggr_mutaties_get_nav_date_for_planned_date( $planned_date ) {
    $dt = DateTime::createFromFormat( 'Y-m-d', $planned_date );
    if ( ! $dt ) {
        return $planned_date;
    }

    $dt->modify( 'last day of previous month' );

    return $dt->format( 'Y-m-d' );
}

function ggr_mutaties_get_dividend_per_participation( $planned_date ) {
    if ( ! function_exists( 'ggr_dividend_accruals_get_per_participation' ) ) {
        return null;
    }

    $lookup_date = $planned_date;
    if ( function_exists( 'ggr_dividend_accruals_get_previous_month_end' ) ) {
        $lookup_date = ggr_dividend_accruals_get_previous_month_end( $planned_date );
    } else {
        $dt = DateTime::createFromFormat( 'Y-m-d', $planned_date );
        if ( $dt ) {
            $dt->modify( 'last day of previous month' );
            $lookup_date = $dt->format( 'Y-m-d' );
        }
    }

    return ggr_dividend_accruals_get_per_participation( $lookup_date );
}

function ggr_mutaties_get_user_participations_at_date( $user_id, $planned_date ) {
    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return 0.0;
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return 0.0;
    }

    $planned_ts = strtotime( $planned_date );
    if ( ! $planned_ts ) {
        return 0.0;
    }

    $total = 0.0;
    foreach ( $history as $row ) {
        $row_ts = strtotime( $row->datum );
        if ( ! $row_ts || $row_ts > $planned_ts ) {
            break;
        }

        $total += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;
    }

    return $total;
}

function ggr_mutaties_create_mutatie( $type, $user_id = 0, $amount = '', $participaties = '' ) {
    $types = ggr_mutaties_get_types();
    if ( ! isset( $types[ $type ] ) ) {
        return new WP_Error( 'ggr_mutatie_type', 'Ongeldig mutatietype.' );
    }

    $user_id = (int) $user_id;
    $scope   = $user_id > 0 ? 'user' : 'all';
    $planned = ggr_mutaties_get_next_run_date();

    if ( in_array( $type, array( 'dividend_herinvestering', 'dividend_uitkering' ), true ) && ( '' === $amount || (float) $amount <= 0 ) ) {
        $dividend_rate = ggr_mutaties_get_dividend_per_participation( $planned );

        if ( null !== $dividend_rate ) {
            if ( $user_id > 0 ) {
                $participations = ggr_mutaties_get_user_participations_at_date( $user_id, $planned );
            } else {
                $participations = function_exists( 'ggr_portal_get_total_participations_all_users' )
                    ? ggr_portal_get_total_participations_all_users( $planned )
                    : 0.0;
            }

            $amount = $participations > 0 ? round( $dividend_rate * $participations, 2 ) : $amount;
        }
    }

    $post_id = wp_insert_post(
        array(
            'post_type'   => 'ggr_mutatie',
            'post_status' => 'publish',
        ),
        true
    );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    update_post_meta( $post_id, 'ggr_mutatie_status', 'nieuw' );
    update_post_meta( $post_id, 'ggr_mutatie_type', $type );
    update_post_meta( $post_id, 'ggr_mutatie_amount', $amount );
    update_post_meta( $post_id, 'ggr_mutatie_participaties', $participaties );
    update_post_meta( $post_id, 'ggr_mutatie_scope', $scope );
    update_post_meta( $post_id, 'ggr_mutatie_user_id', $user_id );
    update_post_meta( $post_id, 'ggr_mutatie_planned_date', $planned );

    return $post_id;
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
        'supports'           => array(),
        'menu_icon'          => 'dashicons-randomize',
        'has_archive'        => false,
        'rewrite'            => false,
        'show_in_rest'       => false,
        'map_meta_cap'       => true,
    );

    register_post_type( 'ggr_mutatie', $args );
}

function ggr_mutaties_remove_post_support() {
    remove_post_type_support( 'ggr_mutatie', 'title' );
    remove_post_type_support( 'ggr_mutatie', 'editor' );
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

add_action( 'add_meta_boxes_ggr_mutatie', 'ggr_mutaties_move_publish_box', 99 );

function ggr_mutaties_move_publish_box() {
    remove_meta_box( 'submitdiv', 'ggr_mutatie', 'side' );
    add_meta_box( 'submitdiv', 'Publiceren', 'post_submit_meta_box', 'ggr_mutatie', 'normal', 'high' );
}

function ggr_mutaties_render_metabox( $post ) {
    $status  = get_post_meta( $post->ID, 'ggr_mutatie_status', true );
    $type    = get_post_meta( $post->ID, 'ggr_mutatie_type', true );
    $amount  = get_post_meta( $post->ID, 'ggr_mutatie_amount', true );
    $units   = get_post_meta( $post->ID, 'ggr_mutatie_participaties', true );    
    $planned = get_post_meta( $post->ID, 'ggr_mutatie_planned_date', true );
    $scope   = get_post_meta( $post->ID, 'ggr_mutatie_scope', true );
    $user_id = get_post_meta( $post->ID, 'ggr_mutatie_user_id', true );
    $nav_price = function_exists( 'ggr_get_latest_stock_price' ) ? ggr_get_latest_stock_price() : null;
    
    if ( ! $status ) {
        $status = 'nieuw';
    }

    if ( ! $type ) {
        $type = 'dividend_herinvestering';
    }

    if ( ! $scope ) {
        $scope = 'all';
    }

    if ( ! $planned ) {
        $planned = ggr_mutaties_get_next_run_date();
    }

    ?>
    <?php wp_nonce_field( 'ggr_mutatie_meta_save', 'ggr_mutatie_meta_nonce' ); ?>    
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
                <p class="description">
                    Voer het bedrag in euro in (optioneel als participaties worden opgegeven). Dividend herinvestering wordt omgerekend naar participaties.
                    <?php if ( null !== $nav_price ) : ?>
                        <br>Huidige waarde per participatie: <strong>€ <?php echo esc_html( number_format( (float) $nav_price, 6, ',', '.' ) ); ?></strong>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_participaties">Participaties</label></th>
            <td>
                <input type="text" name="ggr_mutatie_participaties" id="ggr_mutatie_participaties" value="<?php echo esc_attr( $units ); ?>" />
                <p class="description">
                    Voor inleg/opname of dividend herinvestering: aantal participaties (wordt omgerekend tegen de GGR stock price op de transactiedatum).
                    <?php if ( null !== $nav_price ) : ?>
                        <br>Huidige waarde per participatie: <strong>€ <?php echo esc_html( number_format( (float) $nav_price, 6, ',', '.' ) ); ?></strong>
                    <?php endif; ?>
                </p>
                </td>
        </tr>
        <tr>
            <th scope="row"><label for="ggr_mutatie_direct_apply">Direct doorvoeren</label></th>
            <td>
                <label>
                    <input type="checkbox" name="ggr_mutatie_direct_apply" id="ggr_mutatie_direct_apply" value="1" />
                    Mutatie direct toepassen (zonder planning)
                </label>
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
        <tr id="ggr_mutatie_user_row">
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
                <p class="description">De transactiedatum is altijd de 1e van een maand. Bij opslaan plannen we dit automatisch in.</p>
            </td>
        </tr>
    </table>
    <script>
    (function() {
        var scopeField = document.getElementById('ggr_mutatie_scope');
        var userRow = document.getElementById('ggr_mutatie_user_row');
        var amountField = document.getElementById('ggr_mutatie_amount');
        var unitsField = document.getElementById('ggr_mutatie_participaties');
        var navPrice = <?php echo null !== $nav_price ? esc_js( (float) $nav_price ) : 'null'; ?>;        
        if (!scopeField || !userRow) return;

        function toggleUserRow() {
            if (scopeField.value === 'user') {
                userRow.style.display = '';
            } else {
                userRow.style.display = 'none';
            }
        }

        scopeField.addEventListener('change', toggleUserRow);
        toggleUserRow();

        if (!amountField || !unitsField || !navPrice) return;

        var isUpdating = false;

        function parseDecimal(value) {
            if (!value) return 0;
            return parseFloat(value.replace(',', '.')) || 0;
        }

        function formatDecimal(value, decimals) {
            return value.toFixed(decimals).replace('.', ',');
        }

        function syncFromAmount() {
            if (isUpdating) return;
            isUpdating = true;
            var amountValue = parseDecimal(amountField.value);
            if (!amountValue) {
                unitsField.value = '';
                isUpdating = false;
                return;
            }
            var unitsValue = amountValue / navPrice;
            unitsField.value = formatDecimal(unitsValue, 4);
            isUpdating = false;
        }

        function syncFromUnits() {
            if (isUpdating) return;
            isUpdating = true;
            var unitsValue = parseDecimal(unitsField.value);
            if (!unitsValue) {
                amountField.value = '';
                isUpdating = false;
                return;
            }
            var amountValue = unitsValue * navPrice;
            amountField.value = formatDecimal(amountValue, 2);
            isUpdating = false;
        }

        amountField.addEventListener('input', syncFromAmount);
        unitsField.addEventListener('input', syncFromUnits);        
    })();
    </script>
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

    $type    = isset( $_POST['ggr_mutatie_type'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_type'] ) ) : 'dividend_herinvestering';
    $amount  = isset( $_POST['ggr_mutatie_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_mutatie_amount'] ) ) : '';
    $units   = isset( $_POST['ggr_mutatie_participaties'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_mutatie_participaties'] ) ) : '';    
    $scope   = isset( $_POST['ggr_mutatie_scope'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_scope'] ) ) : 'all';
    $user_id = isset( $_POST['ggr_mutatie_user_id'] ) ? (int) $_POST['ggr_mutatie_user_id'] : 0;
    $status  = isset( $_POST['ggr_mutatie_status'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutatie_status'] ) ) : 'nieuw';
    $planned = isset( $_POST['ggr_mutatie_planned_date'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_mutatie_planned_date'] ) ) : '';

    $types    = ggr_mutaties_get_types();
    $statuses = ggr_mutaties_get_statuses();

    if ( ! isset( $types[ $type ] ) ) {
        $type = 'dividend_herinvestering';
    }

    if ( ! isset( $statuses[ $status ] ) ) {
        $status = 'nieuw';
    }

    if ( ! in_array( $scope, array( 'all', 'user' ), true ) ) {
        $scope = 'all';
    }

    update_post_meta( $post_id, 'ggr_mutatie_type', $type );
    update_post_meta( $post_id, 'ggr_mutatie_amount', $amount );
    update_post_meta( $post_id, 'ggr_mutatie_participaties', $units );    
    update_post_meta( $post_id, 'ggr_mutatie_scope', $scope );
    update_post_meta( $post_id, 'ggr_mutatie_user_id', 'all' === $scope ? 0 : $user_id );
    update_post_meta( $post_id, 'ggr_mutatie_status', $status );
    $direct_apply = isset( $_POST['ggr_mutatie_direct_apply'] ) && '1' === $_POST['ggr_mutatie_direct_apply'];
    $planned      = ggr_mutaties_normalize_planned_date( $planned, $direct_apply );
    
    update_post_meta( $post_id, 'ggr_mutatie_planned_date', $planned );

    if ( $direct_apply ) {
        $errors  = array();
        $updated = ggr_mutaties_apply_to_history( $post_id, $planned, $errors );

        if ( $updated ) {
            update_post_meta( $post_id, 'ggr_mutatie_status', 'uitgevoerd' );
        }

        if ( $errors ) {
            set_transient( 'ggr_mutatie_direct_apply_errors_' . $post_id, $errors, MINUTE_IN_SECONDS );
        }
    }
}

add_action( 'admin_notices', 'ggr_mutaties_direct_apply_notice' );

function ggr_mutaties_direct_apply_notice() {
    $screen = get_current_screen();
    if ( ! $screen || 'ggr_mutatie' !== $screen->post_type ) {
        return;
    }

    if ( empty( $_GET['post'] ) ) {
        return;
    }

    $post_id = (int) $_GET['post'];
    $errors  = get_transient( 'ggr_mutatie_direct_apply_errors_' . $post_id );

    if ( ! $errors ) {
        return;
    }

    delete_transient( 'ggr_mutatie_direct_apply_errors_' . $post_id );

    echo '<div class="notice notice-error"><p><strong>Mutatie niet direct doorgevoerd.</strong></p><ul>';
    foreach ( $errors as $error ) {
        echo '<li>' . esc_html( $error ) . '</li>';
    }
    echo '</ul></div>';    
}

function ggr_mutaties_get_target_user_ids( $scope, $user_id ) {
    if ( 'user' === $scope && $user_id ) {
        return array( (int) $user_id );
    }

    $users = get_users( array(
        'role__in' => array( 'participant' ),
        'fields'   => array( 'ID' ),
    ) );

    return array_map( 'intval', wp_list_pluck( $users, 'ID' ) );
}

function ggr_mutaties_find_history_entry_by_date( $user_id, $planned_date ) {
    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return null;
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return null;
    }

    $match = null;
    foreach ( $history as $row ) {
        if ( isset( $row->datum ) && $row->datum === $planned_date ) {
            $match = $row;
        }
    }

    return $match;
}

function ggr_mutaties_apply_to_history( $mutatie_id, $planned_date, array &$errors ) {
    if ( ! function_exists( 'ggr_portal_add_history_entry' ) || ! function_exists( 'ggr_portal_update_history_entry' ) ) {
        $errors[] = 'Historie-functies ontbreken om mutaties toe te passen.';
        return false;
    }

    $type         = get_post_meta( $mutatie_id, 'ggr_mutatie_type', true );
    $amount_raw   = get_post_meta( $mutatie_id, 'ggr_mutatie_amount', true );
    $units_raw    = get_post_meta( $mutatie_id, 'ggr_mutatie_participaties', true );
    $scope        = get_post_meta( $mutatie_id, 'ggr_mutatie_scope', true );
    $user_id      = (int) get_post_meta( $mutatie_id, 'ggr_mutatie_user_id', true );

    $amount       = ggr_mutaties_parse_decimal( $amount_raw );
    $participates = ggr_mutaties_parse_decimal( $units_raw );

    $needs_nav = in_array( $type, array( 'inleg', 'opname', 'dividend_herinvestering' ), true );
    $nav_price = null;
    $dividend_rate = null;
    
    if ( $needs_nav ) {
        if ( ! function_exists( 'ggr_get_stock_price_for_date' ) ) {
            $errors[] = 'Stock price functies ontbreken om participaties om te rekenen.';
            return false;
        }

        $nav_price = ggr_get_stock_price_for_date( $planned_date );

        if ( $nav_price === null ) {
            $errors[] = sprintf( 'Geen koers gevonden voor %s.', $planned_date );
            return false;
        }

    }

    if ( in_array( $type, array( 'dividend_herinvestering', 'dividend_uitkering' ), true ) ) {
        $dividend_rate = ggr_mutaties_get_dividend_per_participation( $planned_date );
    }

    $target_user_ids = ggr_mutaties_get_target_user_ids( $scope, $user_id );
    if ( empty( $target_user_ids ) ) {
        $errors[] = 'Geen participanten gevonden om de mutatie op toe te passen.';
        return false;
    }

    $updated = 0;
    foreach ( $target_user_ids as $target_user_id ) {
        $amount_for_user       = $amount;
        $participates_for_user = $participates;

        if ( $dividend_rate !== null && $amount_for_user <= 0 ) {
            $user_parts      = ggr_mutaties_get_user_participations_at_date( $target_user_id, $planned_date );
            $amount_for_user = $user_parts > 0 ? round( $dividend_rate * $user_parts, 2 ) : 0.0;
        }

        if ( $needs_nav ) {
            if ( $participates_for_user > 0 && $amount_for_user <= 0 ) {
                $amount_for_user = round( $participates_for_user * $nav_price, 2 );
            } elseif ( $amount_for_user > 0 && $participates_for_user <= 0 ) {
                $participates_for_user = round( $amount_for_user / $nav_price, 4 );
            }
        }

        $inleg      = 0.0;
        $opname     = 0.0;
        $nieuwe     = 0.0;
        $verkochte  = 0.0;
        $dividend   = 0.0;

        if ( 'inleg' === $type ) {
            $inleg  = $amount_for_user;
            $nieuwe = $participates_for_user;
        } elseif ( 'opname' === $type ) {
            $opname    = $amount_for_user;
            $verkochte = $participates_for_user;
        } elseif ( 'dividend_herinvestering' === $type ) {
            $dividend = $amount_for_user;
            $nieuwe   = $participates_for_user;
        } elseif ( 'dividend_uitkering' === $type ) {
            $participates_for_user = 0.0;
            $dividend              = $amount_for_user;
            $opname                = $amount_for_user;
        } else {
            $participates_for_user = 0.0;
            $dividend              = $amount_for_user;
        }

        $entry = ggr_mutaties_find_history_entry_by_date( $target_user_id, $planned_date );

        if ( $entry ) {
            $new_inleg     = (float) $entry->inlegbedrag + $inleg;
            $new_opname    = (float) $entry->opnamebedrag + $opname;
            $new_nieuwe    = (float) $entry->nieuwe_participaties + $nieuwe;
            $new_verkochte = (float) $entry->verkochte_participaties + $verkochte;
            $new_dividend  = (float) $entry->distributievergoeding + $dividend;

            $ok = ggr_portal_update_history_entry(
                $entry->id,
                $planned_date,
                $new_inleg,
                $new_opname,
                $new_nieuwe,
                $new_verkochte,
                $new_dividend
            );
        } else {
            $ok = ggr_portal_add_history_entry(
                $target_user_id,
                $planned_date,
                $inleg,
                $opname,
                $nieuwe,
                $verkochte,
                $dividend
            );
        }

        if ( $ok ) {
            $updated++;
        }
    }

    return $updated;
}

/**
 * Admin pagina om mutaties te beheren.
 */
function ggr_mutaties_render_admin_page() {
    if ( ! ggr_mutaties_user_can_access() ) {
        wp_die( 'Geen toegang.' );
    }

    $message = '';
    $errors  = array();

    $action_ids = array();
    $action     = '';

    if ( isset( $_GET['ggr_mutatie_action'], $_GET['mutatie_id'] ) ) {
        $action = sanitize_key( wp_unslash( $_GET['ggr_mutatie_action'] ) );
        $action_ids = array( (int) $_GET['mutatie_id'] );

        $nonce_action = 'ggr_mutatie_row_action_' . $action_ids[0];
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
            $action = '';
            $action_ids = array();
            $errors[] = 'Ongeldige actie (nonce).';
        }
    } elseif ( isset( $_POST['ggr_mutaties_dividend_run_nonce'] ) && wp_verify_nonce( $_POST['ggr_mutaties_dividend_run_nonce'], 'ggr_mutaties_dividend_run' ) ) {
        $action = 'dividend_run';
    } elseif ( isset( $_POST['ggr_mutaties_action_nonce'] ) && wp_verify_nonce( $_POST['ggr_mutaties_action_nonce'], 'ggr_mutaties_action' ) ) {
        $action = isset( $_POST['ggr_mutaties_action'] ) ? sanitize_key( wp_unslash( $_POST['ggr_mutaties_action'] ) ) : '';
        $action_ids = isset( $_POST['ggr_mutatie_ids'] ) ? array_map( 'intval', (array) $_POST['ggr_mutatie_ids'] ) : array();
    }

    if ( 'dividend_run' === $action ) {
        $run_month    = isset( $_POST['ggr_dividend_run_month'] ) ? sanitize_key( wp_unslash( $_POST['ggr_dividend_run_month'] ) ) : 'next';
        $planned_date = 'previous' === $run_month
            ? ggr_mutaties_get_current_run_date()
            : ggr_mutaties_get_next_run_date();
        $dividend_rate = ggr_mutaties_get_dividend_per_participation( $planned_date );

        if ( null === $dividend_rate ) {
            $errors[] = 'Geen dividend accrual gevonden voor deze run.';
        }

        $participants = get_users(
            array(
                'role__in' => array( 'participant' ),
                'fields'   => array( 'ID' ),
            )
        );

        if ( empty( $participants ) ) {
            $errors[] = 'Geen participanten gevonden voor een dividend run.';
        } elseif ( empty( $errors ) ) {
            $created = 0;
            foreach ( $participants as $participant ) {
                $user_id  = (int) $participant->ID;
                $strategy = get_user_meta( $user_id, 'ggr_distribution_strategy', true );
                $strategy = $strategy ? sanitize_key( $strategy ) : 'herbeleggen';

                if ( ! in_array( $strategy, array( 'herbeleggen', 'uitkeren' ), true ) ) {
                    $strategy = 'herbeleggen';
                }

                $type = 'herbeleggen' === $strategy ? 'dividend_herinvestering' : 'dividend_uitkering';

                $user_parts = ggr_mutaties_get_user_participations_at_date( $user_id, $planned_date );
                if ( $user_parts <= 0 ) {
                    continue;
                }

                $amount = round( $dividend_rate * $user_parts, 2 );
                $result = ggr_mutaties_create_mutatie( $type, $user_id, $amount );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                    continue;
                }

                $created++;
            }

            if ( $created > 0 ) {
                if ( 'previous' === $run_month ) {
                    $message = sprintf( 'Dividend run vorige maand aangemaakt voor %d participanten.', $created );
                } else {
                    $message = sprintf( 'Dividend run aangemaakt voor %d participanten.', $created );
                }
            }
        }
    } elseif ( $action && $action_ids ) {
        if ( 'approve' === $action ) {
            $today     = current_time( 'Y-m-d' );
            $applied   = 0;
            $scheduled = 0;
            foreach ( $action_ids as $mutatie_id ) {
                $planned_meta = get_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', true );
                $planned_date = ggr_mutaties_normalize_planned_date( $planned_meta );

                update_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', $planned_date );

                if ( strtotime( $planned_date ) <= strtotime( $today ) ) {
                    $updated = ggr_mutaties_apply_to_history( $mutatie_id, $planned_date, $errors );
                    if ( $updated !== false ) {
                        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'uitgevoerd' );
                        $applied++;
                    } else {
                        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'goedgekeurd' );
                    }
                } else {
                    update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'ingepland' );
                    $scheduled++;
                }
            }                

            $message = sprintf(
                '%d mutaties ingepland, %d mutaties verwerkt.',
                $scheduled,
                $applied
            );
        } elseif ( 'reject' === $action ) {
            foreach ( $action_ids as $mutatie_id ) {
                update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'geannuleerd' );
            }
            $message = sprintf( '%d mutaties afgewezen.', count( $action_ids ) );
        } elseif ( 'delete' === $action ) {
            $deleted = 0;
            foreach ( $action_ids as $mutatie_id ) {
                if ( current_user_can( 'delete_post', $mutatie_id ) && wp_trash_post( $mutatie_id ) ) {
                    $deleted++;
                }
            }
            $message = sprintf( '%d mutaties verwijderd.', $deleted );
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

        <p>Beheer financiële mutaties (dividend herinvestering/uitkering, opnames en inleg) en plan deze in voor de eerstvolgende maandnota.</p>

        <p>
            <a class="button button-primary" href="<?php echo esc_url( $new_url ); ?>">Nieuwe mutatie</a>
        </p>
        <form method="post" style="margin: 0 0 16px;">
            <?php wp_nonce_field( 'ggr_mutaties_dividend_run', 'ggr_mutaties_dividend_run_nonce' ); ?>
            <button type="submit" class="button" name="ggr_dividend_run_month" value="next">Dividend run</button>
            <button type="submit" class="button" name="ggr_dividend_run_month" value="previous">Dividend run vorige maand</button>
        </form>

        <?php if ( $message ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( $errors ) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html( implode( ' ', array_unique( $errors ) ) ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( empty( $mutaties ) ) : ?>
            <p>Er zijn nog geen mutaties aangemaakt.</p>
        <?php else : ?>
            <form method="post" id="ggr_mutaties_form">
                <?php wp_nonce_field( 'ggr_mutaties_action', 'ggr_mutaties_action_nonce' ); ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb"><input type="checkbox" id="ggr_mutaties_select_all" /></th>
                            <th scope="col">Mutatie</th>
                            <th scope="col">Type</th>
                            <th scope="col">Doelgroep</th>
                            <th scope="col">Bedrag</th>
                            <th scope="col">Participaties</th>     
                            <th scope="col">Koers</th>
                            <th scope="col">Status</th>
                            <th scope="col">Gepland</th>
                            <th scope="col">Aangemaakt</th>
                            <th scope="col">Acties</th>                            
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $mutaties as $mutatie ) : ?>
                            <?php
                            $mutatie_id = $mutatie->ID;
                            $status     = get_post_meta( $mutatie_id, 'ggr_mutatie_status', true );
                            $type       = get_post_meta( $mutatie_id, 'ggr_mutatie_type', true );
                            $amount     = get_post_meta( $mutatie_id, 'ggr_mutatie_amount', true );
                            $units      = get_post_meta( $mutatie_id, 'ggr_mutatie_participaties', true );                            
                            $scope      = get_post_meta( $mutatie_id, 'ggr_mutatie_scope', true );
                            $planned    = get_post_meta( $mutatie_id, 'ggr_mutatie_planned_date', true );
                            $user_id    = (int) get_post_meta( $mutatie_id, 'ggr_mutatie_user_id', true );
                            $user_name  = $user_id ? ( get_user_by( 'ID', $user_id )->display_name ?? '' ) : '';
                            $price_used = null;
                            $amount_value = $amount !== '' ? ggr_mutaties_parse_decimal( $amount ) : null;
                            $units_value  = $units !== '' ? ggr_mutaties_parse_decimal( $units ) : null;
                            
                            if ( $planned && function_exists( 'ggr_get_stock_price_for_date' ) ) {
                                $price_used = ggr_get_stock_price_for_date( $planned );
                            }

                            $display_amount = $amount_value;
                            $display_units  = $units_value;

                            $needs_nav = in_array( $type, array( 'inleg', 'opname', 'dividend_herinvestering' ), true );

                            if ( in_array( $type, array( 'dividend_herinvestering', 'dividend_uitkering' ), true ) && $planned ) {
                                $dividend_rate = ggr_mutaties_get_dividend_per_participation( $planned );

                                if ( null !== $dividend_rate && $user_id > 0 && ( $display_amount === null || $display_amount <= 0 ) ) {
                                    $user_parts    = ggr_mutaties_get_user_participations_at_date( $user_id, $planned );
                                    $display_amount = $user_parts > 0 ? round( $dividend_rate * $user_parts, 2 ) : $display_amount;
                                }
                            }

                            if ( $needs_nav && $price_used ) {
                                if ( $display_units !== null && ( $display_amount === null || $display_amount <= 0 ) ) {
                                    $display_amount = round( $display_units * $price_used, 2 );
                                } elseif ( $display_amount !== null && ( $display_units === null || $display_units <= 0 ) ) {
                                    $display_units = round( $display_amount / $price_used, 4 );
                                }
                            }

                            if ( 'dividend_uitkering' === $type ) {
                                $display_units = null;
                            }                          

                            if ( ! $status ) {
                                $status = 'nieuw';
                            }
                            if ( ! $type ) {
                                $type = 'dividend_herinvestering';
                            }

                            $is_executed = ( 'uitgevoerd' === $status );
                            $can_schedule = in_array( $status, array( 'nieuw', 'goedgekeurd' ), true );
                            $can_reject   = in_array( $status, array( 'nieuw', 'goedgekeurd', 'ingepland' ), true );

                            $row_action_url = admin_url( 'admin.php?page=ggr-mutaties' );
                            $approve_url = add_query_arg(
                                array(
                                    'ggr_mutatie_action' => 'approve',
                                    'mutatie_id'         => $mutatie_id,
                                ),
                                $row_action_url
                            );
                            $reject_url = add_query_arg(
                                array(
                                    'ggr_mutatie_action' => 'reject',
                                    'mutatie_id'         => $mutatie_id,
                                ),
                                $row_action_url
                            );                            
                            ?>
                            <tr>
                                <th scope="row">
                                    <input type="checkbox" name="ggr_mutatie_ids[]" value="<?php echo (int) $mutatie_id; ?>" <?php disabled( $is_executed ); ?> />
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url( get_edit_post_link( $mutatie_id ) ); ?>">
                                            <?php echo esc_html( 'Mutatie #' . $mutatie_id ); ?>
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
                                <td><?php echo $display_amount !== null ? esc_html( '€ ' . number_format( (float) $display_amount, 2, ',', '.' ) ) : '—'; ?></td>
                                <td><?php echo $display_units !== null ? esc_html( number_format( (float) $display_units, 4, ',', '.' ) ) : '—'; ?></td>
                                <td><?php echo $price_used ? esc_html( '€ ' . number_format( (float) $price_used, 4, ',', '.' ) ) : '—'; ?></td>
                                <td><?php echo esc_html( $statuses[ $status ] ?? $status ); ?></td>
                                <td><?php echo esc_html( ggr_mutaties_format_nl_date( $planned ) ); ?></td>
                                <td><?php echo esc_html( ggr_mutaties_format_nl_date( $mutatie->post_date ) ); ?></td>
                                <td>
                                    <?php if ( $can_schedule ) : ?>
                                        <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( $approve_url, 'ggr_mutatie_row_action_' . $mutatie_id ) ); ?>">Goedkeuren</a>
                                    <?php endif; ?>
                                    <?php if ( $can_reject ) : ?>
                                        <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( $reject_url, 'ggr_mutatie_row_action_' . $mutatie_id ) ); ?>">Afwijzen</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 16px;">
                    <button type="submit" class="button button-primary" name="ggr_mutaties_action" value="approve">Goedkeuren & inplannen </button>
                    <button type="submit" class="button" name="ggr_mutaties_action" value="reject">Afwijzen</button>
                    <button type="submit" class="button" name="ggr_mutaties_action" value="delete" onclick="return confirm('Weet je zeker dat je de geselecteerde mutaties wilt verwijderen?');">Verwijderen</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <script>
    (function() {
        var selectAll = document.getElementById('ggr_mutaties_select_all');
        if (!selectAll) return;
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="ggr_mutatie_ids[]"]:not(:disabled)');
            checkboxes.forEach(function(box) {
                box.checked = selectAll.checked;
            });
        });
    })();
    </script>
    <?php
}
