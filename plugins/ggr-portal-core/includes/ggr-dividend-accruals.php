<?php
/**
 * GGR Dividend Accruals – Maandelijkse dividendpot
 *
 * - Database tabel voor dividend accruals
 * - Admin pagina voor handmatige invoer
 * - REST API endpoint voor externe input
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GGR_DIVIDEND_ACCRUAL_DB_VERSION' ) ) {
    define( 'GGR_DIVIDEND_ACCRUAL_DB_VERSION', '1.0' );
}

add_action( 'plugins_loaded', 'ggr_maybe_create_dividend_accrual_table' );

/**
 * Maak de dividend accruals tabel aan of upgrade deze.
 */
function ggr_create_dividend_accrual_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_dividend_accruals';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            accrual_date DATE NOT NULL,
            accrual_total DECIMAL(20,4) NOT NULL,
            total_participations DECIMAL(20,4) DEFAULT NULL,
            per_participation DECIMAL(15,6) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_accrual_date (accrual_date)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'ggr_dividend_accrual_db_version', GGR_DIVIDEND_ACCRUAL_DB_VERSION );
}

function ggr_maybe_create_dividend_accrual_table() {
    $installed = get_option( 'ggr_dividend_accrual_db_version', '0.0' );

    if ( version_compare( $installed, GGR_DIVIDEND_ACCRUAL_DB_VERSION, '>=' ) ) {
        return;
    }

    ggr_create_dividend_accrual_table();
}

function ggr_dividend_accrual_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'manage_options' );
}

function ggr_dividend_accruals_parse_date( $raw_date ) {
    if ( function_exists( 'ggr_portal_parse_date_to_mysql' ) ) {
        return ggr_portal_parse_date_to_mysql( $raw_date );
    }

    $timestamp = strtotime( (string) $raw_date );
    return $timestamp ? date( 'Y-m-d', $timestamp ) : '';
}

function ggr_dividend_accruals_get_by_date( $date ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';
    $date_mysql = ggr_dividend_accruals_parse_date( $date );

    if ( ! $date_mysql ) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE accrual_date = %s LIMIT 1",
            $date_mysql
        ),
        ARRAY_A
    );
}

function ggr_dividend_accruals_get_per_participation( $date ) {
    $row = ggr_dividend_accruals_get_by_date( $date );

    if ( ! $row ) {
        return null;
    }

    $per_participation = isset( $row['per_participation'] ) ? (float) $row['per_participation'] : 0.0;

    if ( $per_participation > 0 ) {
        return $per_participation;
    }

    $total_parts = isset( $row['total_participations'] ) ? (float) $row['total_participations'] : 0.0;
    $total_value = isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] : 0.0;

    if ( $total_parts <= 0 || $total_value <= 0 ) {
        return null;
    }

    return round( $total_value / $total_parts, 6 );
}

function ggr_dividend_accruals_upsert( $date, $total, $total_participations = null ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';
    $date_mysql = ggr_dividend_accruals_parse_date( $date );

    if ( ! $date_mysql ) {
        return new WP_Error( 'invalid_date', 'Ongeldige datum.' );
    }

    $total_value = (float) $total;
    if ( $total_value <= 0 ) {
        return new WP_Error( 'invalid_total', 'Dividend totaal moet groter zijn dan 0.' );
    }

    if ( null === $total_participations ) {
        $total_participations = function_exists( 'ggr_portal_get_total_participations_all_users' )
            ? ggr_portal_get_total_participations_all_users( $date_mysql )
            : 0.0;
    }

    $total_participations = (float) $total_participations;
    if ( $total_participations <= 0 ) {
        return new WP_Error( 'missing_participations', 'Geen participaties gevonden voor deze datum.' );
    }

    $per_participation = round( $total_value / $total_participations, 6 );
    $now               = current_time( 'mysql' );

    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE accrual_date = %s LIMIT 1",
            $date_mysql
        )
    );

    if ( $existing_id ) {
        $updated = $wpdb->update(
            $table_name,
            array(
                'accrual_total'       => $total_value,
                'total_participations' => $total_participations,
                'per_participation'   => $per_participation,
                'updated_at'          => $now,
            ),
            array( 'id' => (int) $existing_id ),
            array( '%f', '%f', '%f', '%s' ),
            array( '%d' )
        );

        return $updated !== false ? (int) $existing_id : new WP_Error( 'update_failed', 'Bijwerken is mislukt.' );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'accrual_date'         => $date_mysql,
            'accrual_total'        => $total_value,
            'total_participations' => $total_participations,
            'per_participation'    => $per_participation,
            'created_at'           => $now,
            'updated_at'           => $now,
        ),
        array( '%s', '%f', '%f', '%f', '%s', '%s' )
    );

    return $inserted ? (int) $wpdb->insert_id : new WP_Error( 'insert_failed', 'Opslaan is mislukt.' );
}

/* ============================================================================
 * ADMIN MENU
 * ========================================================================== */

add_action( 'admin_menu', 'ggr_register_dividend_accrual_menu' );

function ggr_register_dividend_accrual_menu() {
    add_menu_page(
        'Dividend accruals',
        'Dividend accruals',
        'read',
        'ggr-dividend-accruals',
        'ggr_render_dividend_accrual_page',
        'dashicons-chart-bar',
        27
    );
}

/* ============================================================================
 * ADMIN ACTIES
 * ========================================================================== */

add_action( 'admin_init', 'ggr_handle_dividend_accrual_actions' );

function ggr_handle_dividend_accrual_actions() {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ggr-dividend-accruals' ) {
        return;
    }

    if ( ! ggr_dividend_accrual_user_can_access() ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';

    if (
        isset( $_GET['action'], $_GET['id'] ) &&
        $_GET['action'] === 'delete' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_delete_dividend_accrual_' . (int) $_GET['id'] )
    ) {
        $id = (int) $_GET['id'];

        $deleted = $wpdb->delete(
            $table_name,
            array( 'id' => $id ),
            array( '%d' )
        );

        $msg = $deleted ? 'deleted' : 'delete_failed';

        $target_url = add_query_arg(
            array(
                'page' => 'ggr-dividend-accruals',
                'msg'  => $msg,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $target_url );
        exit;
    }
}

/* ============================================================================
 * ADMIN UI
 * ========================================================================== */

function ggr_render_dividend_accrual_page() {
    if ( ! ggr_dividend_accrual_user_can_access() ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';

    $notice = '';
    $error  = '';

    if ( isset( $_GET['msg'] ) ) {
        if ( $_GET['msg'] === 'deleted' ) {
            $notice = 'Dividend accrual verwijderd.';
        } elseif ( $_GET['msg'] === 'delete_failed' ) {
            $error = 'Verwijderen is mislukt of record bestond niet meer.';
        }
    }

    $today      = current_time( 'Y-m-d' );
    $form_date  = $today;
    $form_total = '';
    $is_edit    = false;
    $edit_id    = 0;

    if ( isset( $_GET['edit_id'] ) ) {
        $edit_id = (int) $_GET['edit_id'];

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
                $edit_id
            ),
            ARRAY_A
        );

        if ( $row ) {
            $form_date  = $row['accrual_date'];
            $form_total = number_format( (float) $row['accrual_total'], 2, ',', '' );
            $is_edit    = true;
        }
    }

    if ( isset( $_POST['ggr_dividend_accrual_submit'] ) ) {
        check_admin_referer( 'ggr_save_dividend_accrual' );

        $date_raw  = isset( $_POST['accrual_date'] ) ? sanitize_text_field( wp_unslash( $_POST['accrual_date'] ) ) : '';
        $total_raw = isset( $_POST['accrual_total'] ) ? sanitize_text_field( wp_unslash( $_POST['accrual_total'] ) ) : '';
        $edit_id   = isset( $_POST['accrual_id'] ) ? (int) $_POST['accrual_id'] : 0;

        $form_date  = $date_raw;
        $form_total = $total_raw;

        $date_mysql = ggr_dividend_accruals_parse_date( $date_raw );
        $total_value = $total_raw !== ''
            ? (float) str_replace( array( '.', ' ', ',' ), array( '', '', '.' ), $total_raw )
            : 0.0;

        if ( ! $date_mysql || $total_raw === '' ) {
            $error = 'Datum en dividend totaal zijn verplicht.';
        } elseif ( $total_value <= 0 ) {
            $error = 'Dividend totaal moet groter zijn dan 0.';
        } else {
            $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                ? ggr_portal_get_total_participations_all_users( $date_mysql )
                : 0.0;

            if ( $total_parts <= 0 ) {
                $error = 'Geen participaties gevonden om de dividendwaarde te berekenen.';
            } else {
                $per_participation = round( $total_value / $total_parts, 6 );
                $now               = current_time( 'mysql' );

                if ( $edit_id ) {
                    $existing_date = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$table_name} WHERE accrual_date = %s AND id <> %d LIMIT 1",
                            $date_mysql,
                            $edit_id
                        )
                    );

                    if ( $existing_date ) {
                        $error = 'Er bestaat al een accrual voor deze datum.';
                    } else {
                        $updated = $wpdb->update(
                            $table_name,
                            array(
                                'accrual_date'         => $date_mysql,
                                'accrual_total'        => $total_value,
                                'total_participations' => $total_parts,
                                'per_participation'    => $per_participation,
                                'updated_at'           => $now,
                            ),
                            array( 'id' => $edit_id ),
                            array( '%s', '%f', '%f', '%f', '%s' ),
                            array( '%d' )
                        );

                        if ( $updated !== false ) {
                            $notice   = 'Dividend accrual bijgewerkt.';
                            $is_edit  = false;
                            $edit_id  = 0;
                            $form_date  = $date_mysql;
                            $form_total = '';
                        } else {
                            $error = 'Bijwerken is mislukt.';
                        }
                    }
                } else {
                    $saved = ggr_dividend_accruals_upsert( $date_mysql, $total_value, $total_parts );

                    if ( is_wp_error( $saved ) ) {
                        $error = $saved->get_error_message();
                    } else {
                        $notice   = 'Dividend accrual opgeslagen.';
                        $form_date  = $date_mysql;
                        $form_total = '';
                    }
                }
            }
        }
    }

    $rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY accrual_date DESC, id DESC",
        ARRAY_A
    );

    ?>
    <div class="wrap">
        <h1>Dividend accruals</h1>
        <p>Leg de totale dividendpot vast per maand (datum = eerste dag van de maand).</p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <h2><?php echo $is_edit ? 'Dividend accrual bewerken' : 'Nieuwe dividend accrual'; ?></h2>
        <form method="post" style="max-width: 520px;">
            <?php wp_nonce_field( 'ggr_save_dividend_accrual' ); ?>
            <input type="hidden" name="accrual_id" value="<?php echo esc_attr( $edit_id ); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="accrual_date">Datum</label></th>
                    <td><input type="date" id="accrual_date" name="accrual_date" value="<?php echo esc_attr( $form_date ); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="accrual_total">Dividend totaal (€)</label></th>
                    <td><input type="text" id="accrual_total" name="accrual_total" value="<?php echo esc_attr( $form_total ); ?>" placeholder="Bijv. 15000,00" required /></td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary" name="ggr_dividend_accrual_submit">
                    <?php echo $is_edit ? 'Bijwerken' : 'Opslaan'; ?>
                </button>
            </p>
        </form>

        <h2>Overzicht</h2>
        <?php if ( empty( $rows ) ) : ?>
            <p>Nog geen dividend accruals opgeslagen.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Dividend totaal</th>
                        <th>Participaties</th>
                        <th>Per participatie</th>
                        <th>Aangemaakt</th>
                        <th>Bijgewerkt</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $edit_url = add_query_arg(
                            array(
                                'page'    => 'ggr-dividend-accruals',
                                'edit_id' => (int) $row['id'],
                            ),
                            admin_url( 'admin.php' )
                        );

                        $delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'page'   => 'ggr-dividend-accruals',
                                    'action' => 'delete',
                                    'id'     => (int) $row['id'],
                                ),
                                admin_url( 'admin.php' )
                            ),
                            'ggr_delete_dividend_accrual_' . (int) $row['id']
                        );

                        $date_disp  = $row['accrual_date'] ? date_i18n( 'd-m-Y', strtotime( $row['accrual_date'] ) ) : '';
                        $total_disp = '€ ' . number_format( (float) $row['accrual_total'], 2, ',', '.' );
                        $parts_disp = isset( $row['total_participations'] )
                            ? number_format( (float) $row['total_participations'], 4, ',', '.' )
                            : '–';
                        $per_disp = isset( $row['per_participation'] )
                            ? '€ ' . number_format( (float) $row['per_participation'], 6, ',', '.' )
                            : '–';
                        $created_disp = $row['created_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $row['created_at'] ) ) : '';
                        $updated_disp = $row['updated_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $row['updated_at'] ) ) : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $date_disp ); ?></td>
                            <td><?php echo esc_html( $total_disp ); ?></td>
                            <td><?php echo esc_html( $parts_disp ); ?></td>
                            <td><?php echo esc_html( $per_disp ); ?></td>
                            <td><?php echo esc_html( $created_disp ); ?></td>
                            <td><?php echo esc_html( $updated_disp ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>">Bewerken</a> |
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   onclick="return confirm('Weet je zeker dat je deze accrual wilt verwijderen?');">
                                    Verwijderen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ============================================================================
 * REST API – webhook
 * ========================================================================== */

add_action( 'rest_api_init', 'ggr_register_dividend_accrual_endpoint' );

function ggr_register_dividend_accrual_endpoint() {
    register_rest_route(
        'ggr/v1',
        '/dividend-accrual',
        array(
            'methods'             => 'POST',
            'callback'            => 'ggr_api_update_dividend_accrual',
            'permission_callback' => 'ggr_api_authenticate_google_sheet',
        )
    );
}

function ggr_api_update_dividend_accrual( WP_REST_Request $request ) {
    $date_raw  = $request->get_param( 'date' );
    $total_raw = $request->get_param( 'total' );

    if ( empty( $date_raw ) || $total_raw === null ) {
        return new WP_Error(
            'missing_params',
            'Parameters "date" en "total" zijn verplicht.',
            array( 'status' => 400 )
        );
    }

    $date_mysql = ggr_dividend_accruals_parse_date( $date_raw );
    $total_value = (float) $total_raw;

    if ( ! $date_mysql ) {
        return new WP_Error(
            'invalid_date',
            'Ongeldige datum.',
            array( 'status' => 400 )
        );
    }

    if ( $total_value <= 0 ) {
        return new WP_Error(
            'invalid_total',
            'Dividend totaal moet groter zijn dan 0.',
            array( 'status' => 400 )
        );
    }

    $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
        ? ggr_portal_get_total_participations_all_users( $date_mysql )
        : 0.0;

    if ( $total_parts <= 0 ) {
        return new WP_Error(
            'missing_participations',
            'Geen participaties gevonden om de dividendwaarde te berekenen.',
            array( 'status' => 400 )
        );
    }

    $saved = ggr_dividend_accruals_upsert( $date_mysql, $total_value, $total_parts );

    if ( is_wp_error( $saved ) ) {
        return $saved;
    }

    return array(
        'date'                 => $date_mysql,
        'total'                => $total_value,
        'total_participations' => $total_parts,
        'per_participation'    => round( $total_value / $total_parts, 6 ),
    );
}
