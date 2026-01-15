<?php
/**
 * GGR Management Fee – maandelijkse management fee administratie
 *
 * - Database tabel voor management fees per maand
 * - Admin pagina voor handmatige aanpassing
 * - Berekeningen op basis van dividend accruals + NAV management fees
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GGR_MANAGEMENT_FEE_DB_VERSION' ) ) {
    define( 'GGR_MANAGEMENT_FEE_DB_VERSION', '1.0' );
}

add_action( 'plugins_loaded', 'ggr_maybe_create_management_fee_table' );

function ggr_create_management_fee_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_management_fees';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            fee_month DATE NOT NULL,
            dividend_fee_total DECIMAL(20,4) NOT NULL DEFAULT 0,
            nav_fee_total DECIMAL(20,4) NOT NULL DEFAULT 0,
            total_fee DECIMAL(20,4) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_fee_month (fee_month)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'ggr_management_fee_db_version', GGR_MANAGEMENT_FEE_DB_VERSION );
}

function ggr_maybe_create_management_fee_table() {
    $installed = get_option( 'ggr_management_fee_db_version', '0.0' );

    if ( version_compare( $installed, GGR_MANAGEMENT_FEE_DB_VERSION, '>=' ) ) {
        return;
    }

    ggr_create_management_fee_table();
}

function ggr_management_fee_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'manage_options' );
}

function ggr_management_fee_parse_float( $raw_value ) {
    if ( $raw_value === null ) {
        return 0.0;
    }

    $value = trim( (string) $raw_value );
    if ( $value === '' ) {
        return 0.0;
    }

    $value     = str_replace( ' ', '', $value );
    $has_dot   = strpos( $value, '.' ) !== false;
    $has_comma = strpos( $value, ',' ) !== false;

    if ( $has_dot && $has_comma ) {
        if ( strrpos( $value, '.' ) > strrpos( $value, ',' ) ) {
            $value = str_replace( ',', '', $value );
        } else {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        }
    } elseif ( $has_comma ) {
        $value = str_replace( ',', '.', $value );
    }

    return (float) $value;
}

function ggr_management_fee_normalize_month_end( $date ) {
    $timestamp = strtotime( (string) $date );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-t', $timestamp );
}

function ggr_management_fee_get_month_start( $month_end ) {
    $timestamp = strtotime( (string) $month_end );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-01', $timestamp );
}

function ggr_management_fee_calculate_dividend_fee_total( $month_end ) {
    global $wpdb;

    $month_start = ggr_management_fee_get_month_start( $month_end );
    if ( ! $month_start ) {
        return 0.0;
    }

    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT accrual_total, accrual_gross, distribution_fee
             FROM {$table_name}
             WHERE accrual_date BETWEEN %s AND %s",
            $month_start,
            $month_end
        ),
        ARRAY_A
    );

    $total = 0.0;

    foreach ( $rows as $row ) {
        $gross = isset( $row['accrual_gross'] ) && $row['accrual_gross'] !== null
            ? (float) $row['accrual_gross']
            : ( isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] / 0.9 : 0.0 );

        $fee = isset( $row['distribution_fee'] ) && $row['distribution_fee'] !== null
            ? (float) $row['distribution_fee']
            : round( $gross * 0.1, 4 );

        $total += $fee;
    }

    return $total;
}

function ggr_management_fee_calculate_nav_fee_total( $month_end ) {
    global $wpdb;

    $month_start = ggr_management_fee_get_month_start( $month_end );
    if ( ! $month_start ) {
        return 0.0;
    }

    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT price_value, gross_price_value, management_fee_percent, fund_total, total_participations
             FROM {$table_name}
             WHERE price_date BETWEEN %s AND %s",
            $month_start,
            $month_end
        ),
        ARRAY_A
    );

    $total = 0.0;

    foreach ( $rows as $row ) {
        $fee_percent = isset( $row['management_fee_percent'] ) && $row['management_fee_percent'] !== null
            ? (float) $row['management_fee_percent']
            : ( function_exists( 'ggr_stock_price_get_default_management_fee_percent' )
                ? ggr_stock_price_get_default_management_fee_percent()
                : 0.004 );

        $total_value = null;

        if ( isset( $row['fund_total'] ) && $row['fund_total'] !== null ) {
            $total_value = (float) $row['fund_total'];
        } elseif ( isset( $row['gross_price_value'], $row['total_participations'] )
            && $row['gross_price_value'] !== null
            && $row['total_participations'] !== null ) {
            $total_value = (float) $row['gross_price_value'] * (float) $row['total_participations'];
        } elseif ( isset( $row['price_value'], $row['total_participations'] )
            && $row['price_value'] !== null
            && $row['total_participations'] !== null ) {
            $gross_value = function_exists( 'ggr_stock_price_calculate_gross_from_net' )
                ? ggr_stock_price_calculate_gross_from_net( (float) $row['price_value'], $fee_percent )
                : null;

            if ( $gross_value !== null ) {
                $total_value = (float) $gross_value * (float) $row['total_participations'];
            }
        }

        if ( $total_value === null ) {
            continue;
        }

        $fee_rate = $fee_percent / 100;
        if ( $fee_rate <= 0 ) {
            continue;
        }

        $total += $total_value * $fee_rate;
    }

    return $total;
}

function ggr_management_fee_calculate_month_totals( $month_end ) {
    $month_end = ggr_management_fee_normalize_month_end( $month_end );

    if ( ! $month_end ) {
        return array(
            'dividend_fee_total' => 0.0,
            'nav_fee_total'      => 0.0,
            'total_fee'          => 0.0,
        );
    }

    $dividend_total = ggr_management_fee_calculate_dividend_fee_total( $month_end );
    $nav_total      = ggr_management_fee_calculate_nav_fee_total( $month_end );
    $total_fee      = $dividend_total + $nav_total;

    return array(
        'dividend_fee_total' => $dividend_total,
        'nav_fee_total'      => $nav_total,
        'total_fee'          => $total_fee,
    );
}

function ggr_management_fee_calculate_mtd_totals( $date ) {
    $date = sanitize_text_field( (string) $date );
    if ( '' === $date ) {
        return array(
            'dividend_fee_total' => 0.0,
            'nav_fee_total'      => 0.0,
            'total_fee'          => 0.0,
        );
    }

    $dividend_total = ggr_management_fee_calculate_dividend_fee_total( $date );
    $nav_total      = ggr_management_fee_calculate_nav_fee_total( $date );
    $total_fee      = $dividend_total + $nav_total;

    return array(
        'dividend_fee_total' => $dividend_total,
        'nav_fee_total'      => $nav_total,
        'total_fee'          => $total_fee,
    );
}

function ggr_management_fee_format_money( $value ) {
    return '€ ' . number_format( (float) $value, 2, ',', '.' );
}

function ggr_management_fee_collect_months() {
    global $wpdb;

    $months = array();

    $dividend_months = $wpdb->get_col(
        "SELECT DISTINCT DATE_FORMAT(accrual_date, '%Y-%m-01') FROM {$wpdb->prefix}ggr_dividend_accruals"
    );

    $stock_months = $wpdb->get_col(
        "SELECT DISTINCT DATE_FORMAT(price_date, '%Y-%m-01') FROM {$wpdb->prefix}ggr_stock_prices"
    );

    $fee_months = $wpdb->get_col(
        "SELECT DISTINCT DATE_FORMAT(fee_month, '%Y-%m-01') FROM {$wpdb->prefix}ggr_management_fees"
    );

    $month_starts = array_filter( array_merge( $dividend_months ?: array(), $stock_months ?: array(), $fee_months ?: array() ) );

    foreach ( $month_starts as $month_start ) {
        $month_end = ggr_management_fee_normalize_month_end( $month_start );
        if ( $month_end ) {
            $months[ $month_end ] = true;
        }
    }

    if ( empty( $months ) ) {
        return array();
    }

    $month_end_dates = array_keys( $months );
    rsort( $month_end_dates );

    return $month_end_dates;
}

/* ============================================================================
 * ADMIN MENU
 * ========================================================================== */

add_action( 'admin_menu', 'ggr_register_management_fee_menu' );

function ggr_register_management_fee_menu() {
    add_menu_page(
        'Management Fee',
        'Management Fee',
        'read',
        'ggr-management-fee',
        'ggr_render_management_fee_page',
        'dashicons-chart-pie',
        28
    );
}

/* ============================================================================
 * ADMIN ACTIES
 * ========================================================================== */

add_action( 'admin_init', 'ggr_handle_management_fee_actions' );

function ggr_handle_management_fee_actions() {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ggr-management-fee' ) {
        return;
    }

    if ( ! ggr_management_fee_user_can_access() ) {
        return;
    }

    if (
        isset( $_GET['action'], $_GET['id'] ) &&
        $_GET['action'] === 'delete' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_delete_management_fee_' . (int) $_GET['id'] )
    ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ggr_management_fees';
        $id         = (int) $_GET['id'];

        $deleted = $wpdb->delete(
            $table_name,
            array( 'id' => $id ),
            array( '%d' )
        );

        $msg = $deleted ? 'deleted' : 'delete_failed';

        $target_url = add_query_arg(
            array(
                'page' => 'ggr-management-fee',
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

function ggr_render_management_fee_page() {
    if ( ! ggr_management_fee_user_can_access() ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_management_fees';

    $notice = '';
    $error  = '';

    if ( isset( $_GET['msg'] ) ) {
        if ( $_GET['msg'] === 'deleted' ) {
            $notice = 'Management fee verwijderd.';
        } elseif ( $_GET['msg'] === 'delete_failed' ) {
            $error = 'Verwijderen is mislukt of record bestond niet meer.';
        }
    }

    $today     = current_time( 'Y-m-d' );
    $form_date = $today;
    $form_dividend_fee = '';
    $form_nav_fee = '';
    $form_total_fee = '';
    $is_edit  = false;
    $edit_id  = 0;

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
            $form_date = $row['fee_month'];
            $form_dividend_fee = number_format( (float) $row['dividend_fee_total'], 2, ',', '' );
            $form_nav_fee = number_format( (float) $row['nav_fee_total'], 2, ',', '' );
            $form_total_fee = number_format( (float) $row['total_fee'], 2, ',', '' );
            $is_edit = true;
        }
    } elseif ( isset( $_GET['month'] ) ) {
        $requested_month = sanitize_text_field( wp_unslash( $_GET['month'] ) );
        $normalized_month = ggr_management_fee_normalize_month_end( $requested_month );
        if ( $normalized_month ) {
            $form_date = $normalized_month;
        }
    }

    $computed_month = ggr_management_fee_normalize_month_end( $form_date );
    $computed_totals = $computed_month ? ggr_management_fee_calculate_month_totals( $computed_month ) : null;
    $mtd_totals = null;
    $today_date = current_time( 'Y-m-d' );
    if ( $computed_month ) {
        $computed_month_key = wp_date( 'Y-m', strtotime( $computed_month ) );
        $current_month_key  = wp_date( 'Y-m', strtotime( $today_date ) );
        if ( $computed_month_key === $current_month_key ) {
            $mtd_totals = ggr_management_fee_calculate_mtd_totals( $today_date );
        }
    }
    
    if ( ! $is_edit && $computed_totals ) {
        $form_dividend_fee = $form_dividend_fee !== '' ? $form_dividend_fee : number_format( $computed_totals['dividend_fee_total'], 2, ',', '' );
        $form_nav_fee      = $form_nav_fee !== '' ? $form_nav_fee : number_format( $computed_totals['nav_fee_total'], 2, ',', '' );
        $form_total_fee    = $form_total_fee !== '' ? $form_total_fee : number_format( $computed_totals['total_fee'], 2, ',', '' );
    }

    if ( isset( $_POST['ggr_management_fee_submit'] ) ) {
        check_admin_referer( 'ggr_save_management_fee' );

        $date_raw = isset( $_POST['fee_month'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_month'] ) ) : '';
        $dividend_fee_raw = isset( $_POST['dividend_fee_total'] ) ? sanitize_text_field( wp_unslash( $_POST['dividend_fee_total'] ) ) : '';
        $nav_fee_raw = isset( $_POST['nav_fee_total'] ) ? sanitize_text_field( wp_unslash( $_POST['nav_fee_total'] ) ) : '';
        $total_fee_raw = isset( $_POST['total_fee'] ) ? sanitize_text_field( wp_unslash( $_POST['total_fee'] ) ) : '';
        $edit_id = isset( $_POST['fee_id'] ) ? (int) $_POST['fee_id'] : 0;

        $form_date = $date_raw;
        $form_dividend_fee = $dividend_fee_raw;
        $form_nav_fee = $nav_fee_raw;
        $form_total_fee = $total_fee_raw;

        $date_mysql = ggr_management_fee_normalize_month_end( $date_raw );
        $dividend_fee_value = ggr_management_fee_parse_float( $dividend_fee_raw );
        $nav_fee_value = ggr_management_fee_parse_float( $nav_fee_raw );
        $total_fee_value = $total_fee_raw !== ''
            ? ggr_management_fee_parse_float( $total_fee_raw )
            : $dividend_fee_value + $nav_fee_value;

        if ( ! $date_mysql ) {
            $error = 'Datum is verplicht.';
        } elseif ( $dividend_fee_value < 0 || $nav_fee_value < 0 || $total_fee_value < 0 ) {
            $error = 'Bedragen kunnen niet negatief zijn.';
        } else {
            $now = current_time( 'mysql' );

            if ( $edit_id ) {
                $existing_date = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table_name} WHERE fee_month = %s AND id <> %d LIMIT 1",
                        $date_mysql,
                        $edit_id
                    )
                );

                if ( $existing_date ) {
                    $error = 'Er bestaat al een management fee voor deze maand.';
                } else {
                    $updated = $wpdb->update(
                        $table_name,
                        array(
                            'fee_month'          => $date_mysql,
                            'dividend_fee_total' => $dividend_fee_value,
                            'nav_fee_total'      => $nav_fee_value,
                            'total_fee'          => $total_fee_value,
                            'updated_at'         => $now,
                        ),
                        array( 'id' => $edit_id ),
                        array( '%s', '%f', '%f', '%f', '%s' ),
                        array( '%d' )
                    );

                    if ( $updated !== false ) {
                        $notice = 'Management fee bijgewerkt.';
                        $is_edit = false;
                        $edit_id = 0;
                        $form_date = $date_mysql;
                    } else {
                        $error = 'Bijwerken is mislukt.';
                    }
                }
            } else {
                $existing_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table_name} WHERE fee_month = %s LIMIT 1",
                        $date_mysql
                    )
                );

                if ( $existing_id ) {
                    $error = 'Er bestaat al een management fee voor deze maand.';
                } else {
                    $inserted = $wpdb->insert(
                        $table_name,
                        array(
                            'fee_month'          => $date_mysql,
                            'dividend_fee_total' => $dividend_fee_value,
                            'nav_fee_total'      => $nav_fee_value,
                            'total_fee'          => $total_fee_value,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ),
                        array( '%s', '%f', '%f', '%f', '%s', '%s' )
                    );

                    if ( $inserted ) {
                        $notice = 'Management fee opgeslagen.';
                        $form_date = $date_mysql;
                    } else {
                        $error = 'Opslaan is mislukt.';
                    }
                }
            }
        }
    }

    $stored_rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY fee_month DESC",
        ARRAY_A
    );

    $stored_by_month = array();
    foreach ( $stored_rows as $row ) {
        $stored_by_month[ $row['fee_month'] ] = $row;
    }

    $months = ggr_management_fee_collect_months();

    ?>
    <div class="wrap">
        <h1>Management fee</h1>
        <p>Overzicht van de maandelijkse management fee op basis van Dividend Accruals (10%) en NAV fees.</p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <h2><?php echo $is_edit ? 'Management fee bewerken' : 'Nieuwe management fee'; ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;">
            <form method="post" style="max-width: 520px;flex:1;">
                <?php wp_nonce_field( 'ggr_save_management_fee' ); ?>
                <input type="hidden" name="fee_id" value="<?php echo esc_attr( $edit_id ); ?>" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="fee_month">Maand</label></th>
                        <td><input type="date" id="fee_month" name="fee_month" value="<?php echo esc_attr( $form_date ); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dividend_fee_total">Dividend fee totaal (€)</label></th>
                        <td><input type="text" id="dividend_fee_total" name="dividend_fee_total" value="<?php echo esc_attr( $form_dividend_fee ); ?>" placeholder="Bijv. 1500,00" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="nav_fee_total">NAV fee totaal (€)</label></th>
                        <td><input type="text" id="nav_fee_total" name="nav_fee_total" value="<?php echo esc_attr( $form_nav_fee ); ?>" placeholder="Bijv. 800,00" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="total_fee">Totaal management fee (€)</label></th>
                        <td><input type="text" id="total_fee" name="total_fee" value="<?php echo esc_attr( $form_total_fee ); ?>" placeholder="Bijv. 2300,00" /></td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="ggr_management_fee_submit">
                        <?php echo $is_edit ? 'Bijwerken' : 'Opslaan'; ?>
                    </button>
                </p>
            </form>
            <div style="min-width:260px;max-width:360px;">
                <h3 style="margin-top:0;">Berekening o.b.v. brondata</h3>
                <?php if ( $computed_totals ) : ?>
                    <p style="margin:0 0 6px;"><strong>Dividend fee (10%):</strong> <?php echo esc_html( ggr_management_fee_format_money( $computed_totals['dividend_fee_total'] ) ); ?></p>
                    <p style="margin:0 0 6px;"><strong>NAV fee:</strong> <?php echo esc_html( ggr_management_fee_format_money( $computed_totals['nav_fee_total'] ) ); ?></p>
                    <p style="margin:0;"><strong>Totaal:</strong> <?php echo esc_html( ggr_management_fee_format_money( $computed_totals['total_fee'] ) ); ?></p>
                    <?php if ( $mtd_totals ) : ?>
                        <hr style="margin:12px 0;">
                        <p style="margin:0 0 6px;"><strong>MTD dividend fee (t/m vandaag):</strong> <?php echo esc_html( ggr_management_fee_format_money( $mtd_totals['dividend_fee_total'] ) ); ?></p>
                        <p style="margin:0 0 6px;"><strong>MTD NAV fee (t/m vandaag):</strong> <?php echo esc_html( ggr_management_fee_format_money( $mtd_totals['nav_fee_total'] ) ); ?></p>
                        <p style="margin:0;"><strong>MTD totaal (t/m vandaag):</strong> <?php echo esc_html( ggr_management_fee_format_money( $mtd_totals['total_fee'] ) ); ?></p>
                    <?php endif; ?>                    
                <?php else : ?>
                    <p style="margin:0;">Geen brondata beschikbaar voor deze maand.</p>
                <?php endif; ?>
            </div>
        </div>

        <h2>Overzicht</h2>
        <?php if ( empty( $months ) ) : ?>
            <p>Nog geen management fees beschikbaar.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Maand</th>
                        <th>Dividend fee</th>
                        <th>NAV fee</th>
                        <th>Totaal management fee</th>
                        <th>Status</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $months as $month_end ) : ?>
                        <?php
                        $computed = ggr_management_fee_calculate_month_totals( $month_end );
                        $stored   = isset( $stored_by_month[ $month_end ] ) ? $stored_by_month[ $month_end ] : null;
                        $use_values = $stored
                            ? array(
                                'dividend_fee_total' => (float) $stored['dividend_fee_total'],
                                'nav_fee_total'      => (float) $stored['nav_fee_total'],
                                'total_fee'          => (float) $stored['total_fee'],
                            )
                            : $computed;

                        $status_label = $stored ? 'Handmatig' : 'Automatisch';
                        $month_label = $month_end ? date_i18n( 'F Y', strtotime( $month_end ) ) : '';

                        $edit_url = $stored
                            ? add_query_arg(
                                array(
                                    'page'    => 'ggr-management-fee',
                                    'edit_id' => (int) $stored['id'],
                                ),
                                admin_url( 'admin.php' )
                            )
                            : add_query_arg(
                                array(
                                    'page'  => 'ggr-management-fee',
                                    'month' => $month_end,
                                ),
                                admin_url( 'admin.php' )
                            );

                        $delete_url = $stored
                            ? wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page'   => 'ggr-management-fee',
                                        'action' => 'delete',
                                        'id'     => (int) $stored['id'],
                                    ),
                                    admin_url( 'admin.php' )
                                ),
                                'ggr_delete_management_fee_' . (int) $stored['id']
                            )
                            : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $month_label ); ?></td>
                            <td><?php echo esc_html( ggr_management_fee_format_money( $use_values['dividend_fee_total'] ) ); ?></td>
                            <td><?php echo esc_html( ggr_management_fee_format_money( $use_values['nav_fee_total'] ) ); ?></td>
                            <td><?php echo esc_html( ggr_management_fee_format_money( $use_values['total_fee'] ) ); ?></td>
                            <td><?php echo esc_html( $status_label ); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
                                    <?php echo $stored ? 'Bewerken' : 'Toevoegen'; ?>
                                </a>
                                <?php if ( $stored ) : ?>
                                    <a class="button button-small" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Weet je zeker dat je deze management fee wilt verwijderen?');">
                                        Verwijderen
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
