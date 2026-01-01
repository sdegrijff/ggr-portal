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
    define( 'GGR_DIVIDEND_ACCRUAL_DB_VERSION', '1.2' );
}

add_action( 'plugins_loaded', 'ggr_maybe_create_dividend_accrual_table' );

if ( ! defined( 'GGR_DIVIDEND_ACCRUAL_HISTORY_DB_VERSION' ) ) {
    define( 'GGR_DIVIDEND_ACCRUAL_HISTORY_DB_VERSION', '1.2' );
}

add_action( 'plugins_loaded', 'ggr_maybe_create_dividend_accrual_history_table' );

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
            accrual_gross DECIMAL(20,4) DEFAULT NULL,
            distribution_fee DECIMAL(20,4) DEFAULT NULL,
            total_participations DECIMAL(20,4) DEFAULT NULL,
            per_participation DECIMAL(15,6) DEFAULT NULL,
            source_currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
            source_gross DECIMAL(20,4) DEFAULT NULL,
            source_tax DECIMAL(20,4) DEFAULT NULL,
            source_net DECIMAL(20,4) DEFAULT NULL,
            fx_rate_usd_eur DECIMAL(20,6) DEFAULT NULL,
            computed_from_history TINYINT(1) NOT NULL DEFAULT 0,
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

function ggr_create_dividend_accrual_history_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action_id VARCHAR(100) NOT NULL,
            report_date DATE NOT NULL,
            gross_value DECIMAL(20,4) NOT NULL,
            tax_value DECIMAL(20,4) NOT NULL,
            net_amount DECIMAL(20,4) NOT NULL,
            statement_url TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_action_id (action_id)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'ggr_dividend_accrual_history_db_version', GGR_DIVIDEND_ACCRUAL_HISTORY_DB_VERSION );
}

function ggr_maybe_create_dividend_accrual_history_table() {
    $installed = get_option( 'ggr_dividend_accrual_history_db_version', '0.0' );

    if ( version_compare( $installed, GGR_DIVIDEND_ACCRUAL_HISTORY_DB_VERSION, '>=' ) ) {
        return;
    }

    ggr_upgrade_dividend_accrual_history_table( $installed );
    ggr_create_dividend_accrual_history_table();
}

function ggr_upgrade_dividend_accrual_history_table( $installed_version ) {
    if ( version_compare( $installed_version, '1.1', '>=' ) ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';

    $table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )
    );

    if ( ! $table_exists ) {
        return;
    }

    $duplicates = $wpdb->get_results(
        "SELECT action_id, MAX(id) AS keep_id, COUNT(*) AS total
         FROM {$table_name}
         GROUP BY action_id
         HAVING COUNT(*) > 1",
        ARRAY_A
    );

    if ( empty( $duplicates ) ) {
        return;
    }

    foreach ( $duplicates as $duplicate ) {
        $action_id = $duplicate['action_id'];
        $keep_id   = (int) $duplicate['keep_id'];

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE action_id = %s AND id <> %d",
                $action_id,
                $keep_id
            )
        );
    }
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

function ggr_dividend_accruals_parse_float( $raw_value ) {
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

function ggr_dividend_accruals_normalize_currency( $currency ) {
    $currency = strtoupper( trim( (string) $currency ) );

    return $currency !== '' ? $currency : 'EUR';
}

function ggr_dividend_accruals_get_month_start( $date ) {
    $timestamp = strtotime( (string) $date );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-01', $timestamp );
}

function ggr_dividend_accruals_get_month_end( $date ) {
    $timestamp = strtotime( (string) $date );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-t', $timestamp );
}

function ggr_dividend_accruals_get_previous_month_end( $date ) {
    $timestamp = strtotime( (string) $date );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-t', strtotime( 'last day of previous month', $timestamp ) );
}

function ggr_dividend_accruals_get_next_month_start( $month_start ) {
    $timestamp = strtotime( (string) $month_start );
    if ( ! $timestamp ) {
        return '';
    }

    return wp_date( 'Y-m-01', strtotime( 'first day of next month', $timestamp ) );
}

function ggr_dividend_accruals_normalize_accrual_date( $date ) {
    $date_mysql = ggr_dividend_accruals_parse_date( $date );

    if ( ! $date_mysql ) {
        return '';
    }

    return ggr_dividend_accruals_get_month_end( $date_mysql );
}

function ggr_dividend_accruals_get_by_date( $date ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';
    $date_mysql = ggr_dividend_accruals_normalize_accrual_date( $date );

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

    $total_parts = isset( $row['total_participations'] ) ? (float) $row['total_participations'] : 0.0;
    $total_value = isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] : 0.0;

    if ( $total_parts <= 0 || $total_value <= 0 ) {
        $per_participation = isset( $row['per_participation'] ) ? (float) $row['per_participation'] : 0.0;
        return $per_participation > 0 ? $per_participation : null;
    }

    return round( $total_value / $total_parts, 6 );
}

function ggr_dividend_accruals_upsert( $date, $gross_total, $total_participations = null, array $meta = array() ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accruals';
    $date_mysql = ggr_dividend_accruals_normalize_accrual_date( $date );

    if ( ! $date_mysql ) {
        return new WP_Error( 'invalid_date', 'Ongeldige datum.' );
    }

    $gross_value = (float) $gross_total;
    if ( $gross_value <= 0 ) {
        return new WP_Error( 'invalid_total', 'Bruto dividend moet groter zijn dan 0.' );
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

    $distribution_fee  = round( $gross_value * 0.1, 4 );
    $net_value         = round( $gross_value - $distribution_fee, 4 );
    $per_participation = round( $net_value / $total_participations, 6 );
    $now               = current_time( 'mysql' );

    $meta = wp_parse_args(
        $meta,
        array(
            'source_currency'      => 'EUR',
            'source_gross'         => null,
            'source_tax'           => null,
            'source_net'           => null,
            'fx_rate_usd_eur'      => null,
            'computed_from_history' => 0,
        )
    );

    $source_currency = ggr_dividend_accruals_normalize_currency( $meta['source_currency'] );
    $source_gross    = $meta['source_gross'] !== null ? (float) $meta['source_gross'] : null;
    $source_tax      = $meta['source_tax'] !== null ? (float) $meta['source_tax'] : null;
    $source_net      = $meta['source_net'] !== null ? (float) $meta['source_net'] : null;
    $fx_rate         = $meta['fx_rate_usd_eur'] !== null ? (float) $meta['fx_rate_usd_eur'] : null;
    $computed_from_history = ! empty( $meta['computed_from_history'] ) ? 1 : 0;

    if ( $fx_rate !== null && $fx_rate <= 0 ) {
        $fx_rate = null;
    }

    if ( $source_gross === null ) {
        $source_gross = $gross_value;
    }

    if ( $source_net === null ) {
        $source_net = $net_value;
    }

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
                'accrual_total'        => $net_value,
                'accrual_gross'        => $gross_value,
                'distribution_fee'     => $distribution_fee,
                'total_participations' => $total_participations,
                'per_participation'   => $per_participation,
                'source_currency'      => $source_currency,
                'source_gross'         => $source_gross,
                'source_tax'           => $source_tax,
                'source_net'           => $source_net,
                'fx_rate_usd_eur'      => $fx_rate,
                'computed_from_history' => $computed_from_history,
                'updated_at'          => $now,
            ),
            array( 'id' => (int) $existing_id ),
            array( '%f', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%f', '%f', '%d', '%s' ),
            array( '%d' )
        );

        return $updated !== false ? (int) $existing_id : new WP_Error( 'update_failed', 'Bijwerken is mislukt.' );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'accrual_date'         => $date_mysql,
            'accrual_total'        => $net_value,
            'accrual_gross'        => $gross_value,
            'distribution_fee'     => $distribution_fee,
            'total_participations' => $total_participations,
            'per_participation'    => $per_participation,
            'source_currency'      => $source_currency,
            'source_gross'         => $source_gross,
            'source_tax'           => $source_tax,
            'source_net'           => $source_net,
            'fx_rate_usd_eur'      => $fx_rate,
            'computed_from_history' => $computed_from_history,
            'created_at'           => $now,
            'updated_at'           => $now,
        ),
        array( '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%s' )
    );

    return $inserted ? (int) $wpdb->insert_id : new WP_Error( 'insert_failed', 'Opslaan is mislukt.' );
}

function ggr_dividend_accrual_history_upsert( $action_id, $report_date, $gross_value, $tax_value, $net_amount, $statement_url = '' ) {
    global $wpdb;

    $table_name  = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $action_id   = trim( (string) $action_id );
    $report_date = ggr_dividend_accruals_parse_date( $report_date );

    if ( ! $action_id || ! $report_date ) {
        return new WP_Error( 'invalid_data', 'Transaction ID en reportdate zijn verplicht.' );
    }

    $gross_value = (float) $gross_value;
    $tax_value   = (float) $tax_value;
    $net_amount  = (float) $net_amount;
    $statement_url = $statement_url ? esc_url_raw( $statement_url ) : null;
    $now         = current_time( 'mysql' );

    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE action_id = %s LIMIT 1",
            $action_id
        )
    );

    if ( $existing_id ) {
        $updated = $wpdb->update(
            $table_name,
            array(
                'report_date' => $report_date,
                'gross_value' => $gross_value,
                'tax_value'   => $tax_value,
                'net_amount'  => $net_amount,
                'statement_url' => $statement_url,
                'updated_at'  => $now,
            ),
            array( 'id' => (int) $existing_id ),
            array( '%s', '%f', '%f', '%f', '%s', '%s' ),
            array( '%d' )
        );

        return $updated !== false ? (int) $existing_id : new WP_Error( 'update_failed', 'Bijwerken is mislukt.' );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'action_id'     => $action_id,
            'report_date'   => $report_date,
            'gross_value'   => $gross_value,
            'tax_value'     => $tax_value,
            'net_amount'    => $net_amount,
            'statement_url' => $statement_url,
            'created_at'    => $now,
            'updated_at'    => $now,
        ),
        array( '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s' )
    );

    return $inserted ? (int) $wpdb->insert_id : new WP_Error( 'insert_failed', 'Opslaan is mislukt.' );
}

function ggr_dividend_accruals_get_history_monthly_totals( $month_start ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $month_start = ggr_dividend_accruals_get_month_start( $month_start );

    if ( ! $month_start ) {
        return null;
    }

    $month_end = ggr_dividend_accruals_get_next_month_start( $month_start );
    if ( ! $month_end ) {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT SUM(gross_value) AS gross_total, SUM(tax_value) AS tax_total, SUM(net_amount) AS net_total
             FROM {$table_name}
             WHERE report_date >= %s AND report_date < %s",
            $month_start,
            $month_end
        ),
        ARRAY_A
    );

    if ( ! $row ) {
        return null;
    }

    return array(
        'gross_total' => isset( $row['gross_total'] ) ? (float) $row['gross_total'] : 0.0,
        'tax_total'   => isset( $row['tax_total'] ) ? (float) $row['tax_total'] : 0.0,
        'net_total'   => isset( $row['net_total'] ) ? (float) $row['net_total'] : 0.0,
    );
}

function ggr_dividend_accruals_generate_month_from_history( $month_start, $fx_rate = null ) {
    $month_start = ggr_dividend_accruals_get_month_start( $month_start );
    if ( ! $month_start ) {
        return new WP_Error( 'invalid_month', 'Ongeldige maand voor dividend accrual berekening.' );
    }

    $totals = ggr_dividend_accruals_get_history_monthly_totals( $month_start );
    if ( ! $totals ) {
        return new WP_Error( 'missing_history', 'Geen transactie historie gevonden voor deze maand.' );
    }

    $source_net = (float) $totals['net_total'];
    $source_gross = (float) $totals['gross_total'];
    $source_tax = (float) $totals['tax_total'];

    if ( $source_net <= 0 && $source_gross <= 0 ) {
        return new WP_Error( 'missing_history', 'Geen transactie historie gevonden voor deze maand.' );
    }

    $existing = ggr_dividend_accruals_get_by_date( $month_start );
    if ( $existing && empty( $existing['computed_from_history'] ) ) {
        return $existing['id'];
    }

    if ( $fx_rate !== null && (float) $fx_rate > 0 ) {
        $fx_rate = (float) $fx_rate;
    } else {
        $fx_rate = null;
    }

    $gross_total = $source_net > 0 ? $source_net : $source_gross;
    if ( $fx_rate ) {
        $gross_total = round( $gross_total * $fx_rate, 4 );
    }

    $total_participations = function_exists( 'ggr_portal_get_total_participations_all_users' )
        ? ggr_portal_get_total_participations_all_users( $month_start )
        : 0.0;

    if ( $total_participations <= 0 ) {
        return new WP_Error( 'missing_participations', 'Geen participaties gevonden voor deze maand.' );
    }

    return ggr_dividend_accruals_upsert(
        $month_start,
        $gross_total,
        $total_participations,
        array(
            'source_currency'      => 'USD',
            'source_gross'         => $source_gross,
            'source_tax'           => $source_tax,
            'source_net'           => $source_net,
            'fx_rate_usd_eur'      => $fx_rate,
            'computed_from_history' => 1,
        )
    );
}

function ggr_dividend_accruals_backfill_history( $fx_rate = null ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $range = $wpdb->get_row(
        "SELECT MIN(report_date) AS min_date, MAX(report_date) AS max_date FROM {$table_name}",
        ARRAY_A
    );

    if ( ! $range || empty( $range['min_date'] ) || empty( $range['max_date'] ) ) {
        return;
    }

    $start_month = ggr_dividend_accruals_get_month_start( $range['min_date'] );
    $end_month = ggr_dividend_accruals_get_month_start( $range['max_date'] );

    if ( ! $start_month || ! $end_month ) {
        return;
    }

    $last_complete_month = wp_date( 'Y-m-01', strtotime( 'first day of previous month', current_time( 'timestamp' ) ) );
    if ( strtotime( $end_month ) > strtotime( $last_complete_month ) ) {
        $end_month = $last_complete_month;
    }

    $last_processed = get_option( 'ggr_dividend_accruals_history_backfill_until', '' );
    if ( $last_processed ) {
        $last_processed = ggr_dividend_accruals_get_month_start( $last_processed );
    }

    $cursor = $start_month;
    if ( $last_processed && strtotime( $last_processed ) >= strtotime( $start_month ) ) {
        $cursor = ggr_dividend_accruals_get_next_month_start( $last_processed );
    }

    while ( $cursor && strtotime( $cursor ) <= strtotime( $end_month ) ) {
        ggr_dividend_accruals_generate_month_from_history( $cursor, $fx_rate );
        $cursor = ggr_dividend_accruals_get_next_month_start( $cursor );
    }

    update_option( 'ggr_dividend_accruals_history_backfill_until', $end_month, false );
}

function ggr_dividend_accruals_backfill_history_all( $fx_rate = null ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $range = $wpdb->get_row(
        "SELECT MIN(report_date) AS min_date, MAX(report_date) AS max_date FROM {$table_name}",
        ARRAY_A
    );

    if ( ! $range || empty( $range['min_date'] ) || empty( $range['max_date'] ) ) {
        return new WP_Error( 'missing_history', 'Geen transactie historie gevonden om te verwerken.' );
    }

    $start_month = ggr_dividend_accruals_get_month_start( $range['min_date'] );
    $end_month = ggr_dividend_accruals_get_month_start( $range['max_date'] );

    if ( ! $start_month || ! $end_month ) {
        return new WP_Error( 'invalid_range', 'Ongeldige maand-range voor backfill.' );
    }

    $last_complete_month = wp_date( 'Y-m-01', strtotime( 'first day of previous month', current_time( 'timestamp' ) ) );
    if ( strtotime( $end_month ) > strtotime( $last_complete_month ) ) {
        $end_month = $last_complete_month;
    }

    if ( strtotime( $end_month ) < strtotime( $start_month ) ) {
        return new WP_Error( 'invalid_range', 'Geen complete maanden gevonden om te verwerken.' );
    }

    $result = array(
        'start_month' => $start_month,
        'end_month'   => $end_month,
        'created'     => 0,
        'skipped'     => 0,
        'missing'     => 0,
    );

    $cursor = $start_month;
    while ( $cursor && strtotime( $cursor ) <= strtotime( $end_month ) ) {
        $existing = ggr_dividend_accruals_get_by_date( $cursor );
        if ( $existing ) {
            $result['skipped']++;
            $cursor = ggr_dividend_accruals_get_next_month_start( $cursor );
            continue;
        }

        $computed = ggr_dividend_accruals_generate_month_from_history( $cursor, $fx_rate );
        if ( is_wp_error( $computed ) ) {
            $result['missing']++;
        } else {
            $result['created']++;
        }

        $cursor = ggr_dividend_accruals_get_next_month_start( $cursor );
    }

    update_option( 'ggr_dividend_accruals_history_backfill_until', $end_month, false );

    return $result;
}

function ggr_dividend_accruals_run_monthly_rollup() {
    $today = current_time( 'timestamp' );
    if ( wp_date( 'd', $today ) !== '01' ) {
        return;
    }

    $month_key = wp_date( 'Y-m', $today );
    $last_run  = get_option( 'ggr_dividend_accruals_monthly_last_run', '' );

    if ( $last_run === $month_key ) {
        return;
    }

    $previous_month = wp_date( 'Y-m-01', strtotime( 'first day of previous month', $today ) );
    ggr_dividend_accruals_generate_month_from_history( $previous_month );

    update_option( 'ggr_dividend_accruals_monthly_last_run', $month_key, false );
}

function ggr_dividend_accruals_schedule_rollup() {
    if ( ! wp_next_scheduled( 'ggr_dividend_accruals_monthly_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ggr_dividend_accruals_monthly_event' );
    }

    $last_backfill = get_option( 'ggr_dividend_accruals_history_backfill_until', '' );
    $last_complete_month = wp_date( 'Y-m-01', strtotime( 'first day of previous month', current_time( 'timestamp' ) ) );

    if ( ! $last_backfill || strtotime( $last_backfill ) < strtotime( $last_complete_month ) ) {
        if ( ! wp_next_scheduled( 'ggr_dividend_accruals_backfill_event' ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ggr_dividend_accruals_backfill_event' );
        }
    }
}
add_action( 'init', 'ggr_dividend_accruals_schedule_rollup' );
add_action( 'ggr_dividend_accruals_monthly_event', 'ggr_dividend_accruals_run_monthly_rollup' );
add_action( 'ggr_dividend_accruals_backfill_event', 'ggr_dividend_accruals_backfill_history' );

function ggr_ibkr_accruals_get_token() {
    if ( defined( 'GGR_IBKR_FLEX_TOKEN' ) && GGR_IBKR_FLEX_TOKEN ) {
        return trim( GGR_IBKR_FLEX_TOKEN );
    }

    $token = get_option( 'ggr_ibkr_flex_token' );

    return is_string( $token ) ? trim( $token ) : '';
}

function ggr_ibkr_accruals_get_query_id() {
    if ( defined( 'GGR_IBKR_FLEX_ACCRUALS_QUERY_ID' ) && GGR_IBKR_FLEX_ACCRUALS_QUERY_ID ) {
        return trim( GGR_IBKR_FLEX_ACCRUALS_QUERY_ID );
    }

    $query_id = get_option( 'ggr_ibkr_flex_accruals_query_id' );

    return is_string( $query_id ) ? trim( $query_id ) : '';
}

function ggr_ibkr_accruals_has_credentials() {
    return ggr_ibkr_accruals_get_token() && ggr_ibkr_accruals_get_query_id();
}

function ggr_ibkr_accruals_clear_cron() {
    while ( ( $timestamp = wp_next_scheduled( 'ggr_ibkr_accruals_fetch_event' ) ) !== false ) {
        wp_unschedule_event( $timestamp, 'ggr_ibkr_accruals_fetch_event' );
    }
}

function ggr_ibkr_accruals_schedule_cron() {
    if ( ! ggr_ibkr_accruals_has_credentials() ) {
        ggr_ibkr_accruals_clear_cron();
        return;
    }

    if ( ! wp_next_scheduled( 'ggr_ibkr_accruals_fetch_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ggr_ibkr_accruals_fetch_event' );
    }
}
add_action( 'init', 'ggr_ibkr_accruals_schedule_cron' );

function ggr_ibkr_accruals_set_last_run( $imported, $latest_report_date = '', $statement_url = '', $total_count = null, $duplicate_count = null ) {
    if ( $total_count === null ) {
        $total_count = $imported;
    }

    if ( $duplicate_count === null ) {
        $duplicate_count = 0;
    }

    update_option(
        'ggr_ibkr_accruals_last_run',
        array(
            'timestamp'     => current_time( 'timestamp' ),
            'count'         => (int) $total_count,
            'imported'      => (int) $imported,
            'duplicates'    => (int) $duplicate_count,
            'report_date'   => $latest_report_date,
            'statement_url' => $statement_url ? esc_url_raw( $statement_url ) : '',
        ),
        false
    );
}

function ggr_ibkr_accruals_get_status() {
    return array(
        'has_credentials' => ggr_ibkr_accruals_has_credentials(),
        'next_run'        => wp_next_scheduled( 'ggr_ibkr_accruals_fetch_event' ),
        'last_run'        => get_option( 'ggr_ibkr_accruals_last_run' ),
        'last_error'      => get_option( 'ggr_ibkr_accruals_last_error' ),
    );
}

function ggr_ibkr_accruals_get_base_url() {
    if ( function_exists( 'ggr_ibkr_nav_get_base_url' ) ) {
        return ggr_ibkr_nav_get_base_url();
    }

    return 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';
}

function ggr_ibkr_accruals_http_get( $url ) {
    if ( function_exists( 'ggr_ibkr_nav_http_get' ) ) {
        return ggr_ibkr_nav_http_get( $url );
    }

    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 30,
            'headers' => array(
                'Accept'     => 'application/xml',
                'User-Agent' => 'ggr-portal/ibkr-accruals',
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error(
            'ggr_ibkr_http_error',
            'IBKR Flex API gaf een foutstatus terug.',
            array(
                'status' => $code,
                'body'   => $body,
            )
        );
    }

    return $body;
}

function ggr_ibkr_accruals_request_reference_code( $token, $query_id ) {
    if ( function_exists( 'ggr_ibkr_nav_request_reference_code' ) ) {
        return ggr_ibkr_nav_request_reference_code( $token, $query_id );
    }

    $url      = ggr_ibkr_accruals_get_base_url() . '.SendRequest?t=' . rawurlencode( $token ) . '&q=' . rawurlencode( $query_id ) . '&v=3';
    $response = ggr_ibkr_accruals_http_get( $url );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $xml = simplexml_load_string( $response );

    if ( false === $xml ) {
        return new WP_Error( 'ggr_ibkr_invalid_xml', 'Ongeldige XML in SendRequest response.' );
    }

    $code = isset( $xml->ReferenceCode ) ? trim( (string) $xml->ReferenceCode ) : '';

    if ( ! $code ) {
        return new WP_Error( 'ggr_ibkr_missing_reference_code', 'ReferenceCode ontbreekt in SendRequest response.' );
    }

    return $code;
}

function ggr_ibkr_accruals_request_statement( $token, $reference_code ) {
    if ( function_exists( 'ggr_ibkr_nav_request_statement' ) ) {
        return ggr_ibkr_nav_request_statement( $token, $reference_code );
    }

    $url      = ggr_ibkr_accruals_get_statement_url( $token, $reference_code );
    $response = ggr_ibkr_accruals_http_get( $url );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return $response;
}

function ggr_ibkr_accruals_get_statement_url( $token, $reference_code ) {
    return ggr_ibkr_accruals_get_base_url() . '.GetStatement?t=' . rawurlencode( $token ) . '&q=' . rawurlencode( $reference_code ) . '&v=3';
}

function ggr_ibkr_accruals_format_error_message( WP_Error $error ) {
    if ( function_exists( 'ggr_ibkr_nav_format_error_message' ) ) {
        return ggr_ibkr_nav_format_error_message( $error );
    }

    return $error->get_error_message();
}

function ggr_ibkr_accruals_set_last_error( WP_Error $error, $context_message = '' ) {
    $message = ggr_ibkr_accruals_format_error_message( $error );

    if ( $context_message ) {
        $message = $context_message . ': ' . $message;
    }

    $data = $error->get_error_data();
    $statement_url = '';

    if ( is_array( $data ) && ! empty( $data['statement_url'] ) ) {
        $statement_url = esc_url_raw( $data['statement_url'] );
    }

    update_option(
        'ggr_ibkr_accruals_last_error',
        array(
            'timestamp' => time(),
            'code'      => $error->get_error_code(),
            'message'   => $message,
            'statement_url' => $statement_url,
        ),
        false
    );
}

function ggr_ibkr_accruals_clear_last_error() {
    delete_option( 'ggr_ibkr_accruals_last_error' );
}

function ggr_ibkr_accruals_refresh_last_run_from_history() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';
    $row = $wpdb->get_row(
        "SELECT COUNT(*) AS total_count, MAX(report_date) AS report_date FROM {$table_name}",
        ARRAY_A
    );

    $total_count = isset( $row['total_count'] ) ? (int) $row['total_count'] : 0;

    if ( $total_count <= 0 ) {
        delete_option( 'ggr_ibkr_accruals_last_run' );
        return;
    }

    $existing = get_option( 'ggr_ibkr_accruals_last_run' );
    $timestamp = is_array( $existing ) && isset( $existing['timestamp'] )
        ? (int) $existing['timestamp']
        : current_time( 'timestamp' );

    $last_run = array(
        'timestamp'   => $timestamp,
        'count'       => $total_count,
        'report_date' => ! empty( $row['report_date'] ) ? $row['report_date'] : '',
    );

    if ( is_array( $existing ) ) {
        if ( isset( $existing['statement_url'] ) ) {
            $last_run['statement_url'] = $existing['statement_url'];
        }
        if ( isset( $existing['duplicates'] ) ) {
            $last_run['duplicates'] = $existing['duplicates'];
        }
        if ( isset( $existing['imported'] ) ) {
            $last_run['imported'] = $existing['imported'];
        }
    }

    update_option( 'ggr_ibkr_accruals_last_run', $last_run, false );
}

function ggr_ibkr_accruals_get_attribute( SimpleXMLElement $node, array $keys ) {
    $attributes = $node->attributes( null, true );

    if ( ! $attributes ) {
        return '';
    }

    $lower_keys = array_map( 'strtolower', $keys );

    foreach ( $attributes as $key => $value ) {
        $normalized_key = strtolower( (string) $key );
        if ( strpos( $normalized_key, ':' ) !== false ) {
            $normalized_key = substr( $normalized_key, strrpos( $normalized_key, ':' ) + 1 );
        }
        if ( in_array( $normalized_key, $lower_keys, true ) ) {
            return trim( (string) $value );
        }
    }

    return '';
}

function ggr_ibkr_accruals_get_child_value( SimpleXMLElement $node, array $keys ) {
    $children = $node->children( null, true );

    if ( ! $children ) {
        return '';
    }

    $lower_keys = array_map( 'strtolower', $keys );

    foreach ( $children as $key => $value ) {
        $normalized_key = strtolower( (string) $key );
        if ( strpos( $normalized_key, ':' ) !== false ) {
            $normalized_key = substr( $normalized_key, strrpos( $normalized_key, ':' ) + 1 );
        }
        if ( in_array( $normalized_key, $lower_keys, true ) ) {
            return trim( (string) $value );
        }
    }

    return '';
}

function ggr_ibkr_accruals_get_value( SimpleXMLElement $node, array $keys ) {
    $attribute = ggr_ibkr_accruals_get_attribute( $node, $keys );

    if ( '' !== $attribute ) {
        return $attribute;
    }

    return ggr_ibkr_accruals_get_child_value( $node, $keys );
}

function ggr_ibkr_accruals_dom_get_attribute( DOMElement $node, array $keys ) {
    $lower_keys = array_map( 'strtolower', $keys );

    if ( ! $node->hasAttributes() ) {
        return '';
    }

    foreach ( $node->attributes as $attribute ) {
        $attribute_name = strtolower( $attribute->localName ? $attribute->localName : $attribute->name );
        if ( in_array( $attribute_name, $lower_keys, true ) ) {
            return trim( (string) $attribute->value );
        }
    }

    return '';
}

function ggr_ibkr_accruals_parse_statement_dom( $body, $statement_date ) {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors( true );
    $loaded = $dom->loadXML( $body );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $loaded ) {
        return array(
            'entries'         => array(),
            'total_count'     => 0,
            'duplicate_count' => 0,
        );
    }

    $xpath = new DOMXPath( $dom );
    $nodes = $xpath->query( '//*[local-name()="CashTransaction"]' );

    if ( ! $nodes || 0 === $nodes->length ) {
        $nodes = $xpath->query( '//*[local-name()="ChangeInDividendAccrual"]' );
    }

    if ( ! $nodes || 0 === $nodes->length ) {
        return array(
            'entries'         => array(),
            'total_count'     => 0,
            'duplicate_count' => 0,
        );
    }

    $entries = array();
    $total_count = 0;
    $duplicate_count = 0;

    foreach ( $nodes as $node ) {
        if ( ! $node instanceof DOMElement ) {
            continue;
        }

        $action_id_raw = ggr_ibkr_accruals_dom_get_attribute( $node, array( 'transactionID', 'transactionId', 'transaction_id', 'actionID', 'actionId', 'action_id', 'id' ) );
        $report_raw    = ggr_ibkr_accruals_dom_get_attribute( $node, array( 'reportDate', 'report_date', 'date' ) );
        $gross_raw     = ggr_ibkr_accruals_dom_get_attribute( $node, array( 'amount', 'grossValue', 'grossAmount', 'gross' ) );

        $action_id = $action_id_raw ? trim( $action_id_raw ) : '';
        $report_date = $report_raw ? ggr_dividend_accruals_parse_date( $report_raw ) : $statement_date;
        $gross_value = ggr_dividend_accruals_parse_float( $gross_raw );
        $tax_value   = round( $gross_value * 0.15, 4 );
        $net_amount  = round( $gross_value - $tax_value, 4 );

        if ( ! $action_id || ! $report_date ) {
            continue;
        }

        $total_count++;

        if ( isset( $entries[ $action_id ] ) ) {
            $duplicate_count++;
            continue;
        }

        $entries[ $action_id ] = array(
            'action_id'   => $action_id,
            'report_date' => $report_date,
            'gross_value' => $gross_value,
            'tax_value'   => $tax_value,
            'net_amount'  => $net_amount,
        );
    }

    return array(
        'entries'         => array_values( $entries ),
        'total_count'     => $total_count,
        'duplicate_count' => $duplicate_count,
    );
}

function ggr_ibkr_accruals_parse_statement_regex( $body, $statement_date ) {
    $matches = array();
    $pattern = '/<(?:[a-zA-Z0-9_.:-]+:)?(?:CashTransaction|ChangeInDividendAccrual)\b([^>]*?)(?:\/>|>)/i';
    preg_match_all( $pattern, $body, $matches );

    if ( empty( $matches[1] ) ) {
        return array(
            'entries'         => array(),
            'total_count'     => 0,
            'duplicate_count' => 0,
        );
    }

    $entries = array();
    $total_count = 0;
    $duplicate_count = 0;

    foreach ( $matches[1] as $attribute_block ) {
        $attr_matches = array();
        preg_match_all( '/([a-zA-Z0-9_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attribute_block, $attr_matches, PREG_SET_ORDER );

        if ( empty( $attr_matches ) ) {
            continue;
        }

        $attributes = array();
        foreach ( $attr_matches as $attr_match ) {
            $attribute_name = strtolower( $attr_match[1] );
            $attribute_value = isset( $attr_match[2] ) && $attr_match[2] !== '' ? $attr_match[2] : $attr_match[3];
            $attributes[ $attribute_name ] = $attribute_value;
            if ( strpos( $attribute_name, ':' ) !== false ) {
                $attributes[ substr( $attribute_name, strrpos( $attribute_name, ':' ) + 1 ) ] = $attribute_value;
            }
        }

        $action_id_raw = $attributes['transactionid'] ?? $attributes['transaction_id'] ?? $attributes['actionid'] ?? $attributes['action_id'] ?? $attributes['id'] ?? '';
        $report_raw    = $attributes['reportdate'] ?? $attributes['report_date'] ?? $attributes['date'] ?? '';
        $gross_raw     = $attributes['amount'] ?? $attributes['grossvalue'] ?? $attributes['grossamount'] ?? $attributes['gross'] ?? '';

        $action_id = $action_id_raw ? trim( $action_id_raw ) : '';
        $report_date = $report_raw ? ggr_dividend_accruals_parse_date( $report_raw ) : $statement_date;
        $gross_value = ggr_dividend_accruals_parse_float( $gross_raw );
        $tax_value   = round( $gross_value * 0.15, 4 );
        $net_amount  = round( $gross_value - $tax_value, 4 );

        if ( ! $action_id || ! $report_date ) {
            continue;
        }

        $total_count++;

        if ( isset( $entries[ $action_id ] ) ) {
            $duplicate_count++;
            continue;
        }

        $entries[ $action_id ] = array(
            'action_id'   => $action_id,
            'report_date' => $report_date,
            'gross_value' => $gross_value,
            'tax_value'   => $tax_value,
            'net_amount'  => $net_amount,
        );
    }

    return array(
        'entries'         => array_values( $entries ),
        'total_count'     => $total_count,
        'duplicate_count' => $duplicate_count,
    );
}

function ggr_ibkr_accruals_parse_statement( $body ) {
    $body = (string) $body;
    $start_pos = strpos( $body, '<' );
    if ( $start_pos !== false ) {
        $body = substr( $body, $start_pos );
    }

    $xml = simplexml_load_string( $body );

    if ( false === $xml ) {
        return new WP_Error( 'ggr_ibkr_invalid_xml', 'Ongeldige XML in GetStatement response.' );
    }

    $statement_date = function_exists( 'ggr_ibkr_nav_extract_date_from_xml' )
        ? ggr_ibkr_nav_extract_date_from_xml( $xml )
        : current_time( 'Y-m-d' );

    $nodes = $xml->xpath( '//*[@transactionID or @transactionId or @transaction_id or @actionID or @actionId or @action_id or @grossValue or @grossAmount or @amount or actionID or actionId or action_id or reportDate or report_date or grossValue or grossAmount or amount]' );
    
    if ( empty( $nodes ) ) {
        $nodes = $xml->xpath( '//*' );
    }

    if ( empty( $nodes ) ) {
        $nodes = $xml->xpath( '//*[local-name()!=""]' );
    }

    $cash_nodes = $xml->xpath( '//CashTransaction' );
    $accrual_nodes = $xml->xpath( '//ChangeInDividendAccrual' );

    if ( empty( $cash_nodes ) ) {
        $cash_nodes = $xml->xpath( '//*[local-name()="CashTransaction"]' );
    }
    
    if ( empty( $accrual_nodes ) ) {
        $accrual_nodes = $xml->xpath( '//*[local-name()="ChangeInDividendAccrual"]' );
    }
    $nodes_to_parse = ! empty( $cash_nodes )
        ? $cash_nodes
        : ( ! empty( $accrual_nodes ) ? $accrual_nodes : $nodes );

    $entries = array();
    $total_count = 0;
    $duplicate_count = 0;

    foreach ( $nodes_to_parse as $node ) {
        if ( ! $node instanceof SimpleXMLElement ) {
            continue;
        }

        $action_id_raw = ggr_ibkr_accruals_get_value( $node, array( 'transactionID', 'transactionId', 'transaction_id', 'actionID', 'actionId', 'action_id', 'id' ) );
        $report_raw    = ggr_ibkr_accruals_get_value( $node, array( 'reportDate', 'report_date', 'date' ) );
        $gross_raw     = ggr_ibkr_accruals_get_value( $node, array( 'amount', 'grossValue', 'grossAmount', 'gross' ) );

        $action_id = $action_id_raw ? trim( $action_id_raw ) : '';
        $report_date = $report_raw ? ggr_dividend_accruals_parse_date( $report_raw ) : $statement_date;
        $gross_value = ggr_dividend_accruals_parse_float( $gross_raw );
        $tax_value   = round( $gross_value * 0.15, 4 );
        $net_amount  = round( $gross_value - $tax_value, 4 );

        if ( ! $action_id || ! $report_date ) {
            continue;
        }

        $total_count++;

        if ( isset( $entries[ $action_id ] ) ) {
            $duplicate_count++;
            continue;
        }

        $entries[ $action_id ] = array(
            'action_id'   => $action_id,
            'report_date' => $report_date,
            'gross_value' => $gross_value,
            'tax_value'   => $tax_value,
            'net_amount'  => $net_amount,
        );
    }

    if ( $total_count === 0 ) {
        $dom_parsed = ggr_ibkr_accruals_parse_statement_dom( $body, $statement_date );

        if ( $dom_parsed['total_count'] > 0 ) {
            return $dom_parsed;
        }

        $regex_parsed = ggr_ibkr_accruals_parse_statement_regex( $body, $statement_date );

        if ( $regex_parsed['total_count'] > 0 ) {
            return $regex_parsed;
        }
        
        return new WP_Error( 'ggr_ibkr_missing_rows', 'Geen accruals gevonden in Flex statement.' );
    }

    return array(
        'entries'         => array_values( $entries ),
        'total_count'     => $total_count,
        'duplicate_count' => $duplicate_count,
    );
}

function ggr_ibkr_accruals_fetch_entries( $token = null, $query_id = null ) {
    $token    = $token ?: ggr_ibkr_accruals_get_token();
    $query_id = $query_id ?: ggr_ibkr_accruals_get_query_id();

    if ( ! $token || ! $query_id ) {
        return new WP_Error( 'ggr_ibkr_missing_credentials', 'Flex token of Accruals Query ID ontbreekt.' );
    }

    $reference_code = ggr_ibkr_accruals_request_reference_code( $token, $query_id );

    if ( is_wp_error( $reference_code ) ) {
        return $reference_code;
    }

    $statement_url = ggr_ibkr_accruals_get_statement_url( $token, $reference_code );
    $statement_body = ggr_ibkr_accruals_request_statement( $token, $reference_code );

    if ( is_wp_error( $statement_body ) ) {
        $statement_body->add_data( array( 'statement_url' => $statement_url ) );
        return $statement_body;
    }

    $parsed = ggr_ibkr_accruals_parse_statement( $statement_body );

    if ( is_wp_error( $parsed ) ) {
        $parsed->add_data( array( 'statement_url' => $statement_url ) );
        return $parsed;
    }

    return array(
        'entries'         => $parsed['entries'],
        'total_count'     => $parsed['total_count'],
        'duplicate_count' => $parsed['duplicate_count'],
        'statement_url'   => $statement_url,
    );
}

function ggr_ibkr_accruals_store_entries( array $entries, $statement_url = '' ) {
    $imported           = 0;
    $latest_report_date = '';
    $statement_url      = $statement_url ? esc_url_raw( $statement_url ) : '';

    foreach ( $entries as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $saved = ggr_dividend_accrual_history_upsert(
            $entry['action_id'],
            $entry['report_date'],
            $entry['gross_value'],
            $entry['tax_value'],
            $entry['net_amount'],
            $statement_url
        );

        if ( $saved ) {
            $imported++;
        }

        if ( ! empty( $entry['report_date'] ) ) {
            $report_date = $entry['report_date'];
            if ( ! $latest_report_date || strtotime( $report_date ) > strtotime( $latest_report_date ) ) {
                $latest_report_date = $report_date;
            }
        }
    }

    return array(
        'imported'    => $imported,
        'report_date' => $latest_report_date,
    );
}

function ggr_ibkr_accruals_fetch_and_store() {
    $result = ggr_ibkr_accruals_fetch_entries();

    if ( is_wp_error( $result ) ) {
        ggr_ibkr_accruals_set_last_error( $result, 'IBKR accruals ophalen is mislukt' );
        return $result;
    }

    $store_result = ggr_ibkr_accruals_store_entries( $result['entries'], $result['statement_url'] );
    ggr_ibkr_accruals_set_last_run(
        $store_result['imported'],
        $store_result['report_date'],
        $result['statement_url'],
        $result['total_count'],
        $result['duplicate_count']
    );
    ggr_ibkr_accruals_clear_last_error();

    return $store_result;
}
add_action( 'ggr_ibkr_accruals_fetch_event', 'ggr_ibkr_accruals_fetch_and_store' );

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
    $table_name         = $wpdb->prefix . 'ggr_dividend_accruals';
    $history_table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';

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

    if (
        isset( $_GET['history_action'], $_GET['history_id'] ) &&
        $_GET['history_action'] === 'delete' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_delete_dividend_accrual_history_' . (int) $_GET['history_id'] )
    ) {
        $id = (int) $_GET['history_id'];

        $deleted = $wpdb->delete(
            $history_table_name,
            array( 'id' => $id ),
            array( '%d' )
        );

        if ( $deleted ) {
            ggr_ibkr_accruals_refresh_last_run_from_history();
        }

        $msg = $deleted ? 'deleted' : 'delete_failed';

        $target_url = add_query_arg(
            array(
                'page'        => 'ggr-dividend-accruals',
                'history_msg' => $msg,
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
    $table_name         = $wpdb->prefix . 'ggr_dividend_accruals';
    $history_table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';

    $notice = '';
    $error  = '';
    $rollup_notice = '';
    $rollup_error = '';
    $history_notice = '';
    $history_error  = '';

    if ( isset( $_GET['msg'] ) ) {
        if ( $_GET['msg'] === 'deleted' ) {
            $notice = 'Dividend accrual verwijderd.';
        } elseif ( $_GET['msg'] === 'delete_failed' ) {
            $error = 'Verwijderen is mislukt of record bestond niet meer.';
        }
    }

    if ( isset( $_GET['history_msg'] ) ) {
        if ( $_GET['history_msg'] === 'deleted' ) {
            $history_notice = 'Transactie historie verwijderd.';
        } elseif ( $_GET['history_msg'] === 'delete_failed' ) {
            $history_error = 'Verwijderen is mislukt of record bestond niet meer.';
        }
    }

    $today      = current_time( 'Y-m-d' );
    $form_date  = $today;
    $form_gross = '';
    $form_source_currency = 'EUR';
    $form_source_net = '';
    $form_fx_rate = '';
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
            $form_date = $row['accrual_date'];
            if ( isset( $row['accrual_gross'] ) && $row['accrual_gross'] !== null ) {
                $form_gross = number_format( (float) $row['accrual_gross'], 2, ',', '' );
            } else {
                $form_gross = number_format( (float) $row['accrual_total'] / 0.9, 2, ',', '' );
            }
            $form_source_currency = ! empty( $row['source_currency'] ) ? strtoupper( (string) $row['source_currency'] ) : 'EUR';
            if ( isset( $row['source_net'] ) && $row['source_net'] !== null ) {
                $form_source_net = number_format( (float) $row['source_net'], 2, ',', '' );
            }
            if ( isset( $row['fx_rate_usd_eur'] ) && $row['fx_rate_usd_eur'] !== null ) {
                $form_fx_rate = number_format( (float) $row['fx_rate_usd_eur'], 6, ',', '' );
            }
            $is_edit    = true;
        }
    }

    if ( isset( $_POST['ggr_dividend_accrual_submit'] ) ) {
        check_admin_referer( 'ggr_save_dividend_accrual' );

        $date_raw  = isset( $_POST['accrual_date'] ) ? sanitize_text_field( wp_unslash( $_POST['accrual_date'] ) ) : '';
        $gross_raw = isset( $_POST['accrual_gross'] ) ? sanitize_text_field( wp_unslash( $_POST['accrual_gross'] ) ) : '';
        $source_currency_raw = isset( $_POST['source_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['source_currency'] ) ) : 'EUR';
        $source_net_raw = isset( $_POST['source_net_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['source_net_amount'] ) ) : '';
        $fx_rate_raw = isset( $_POST['fx_rate_usd_eur'] ) ? sanitize_text_field( wp_unslash( $_POST['fx_rate_usd_eur'] ) ) : '';
        $edit_id   = isset( $_POST['accrual_id'] ) ? (int) $_POST['accrual_id'] : 0;

        $form_date  = $date_raw;
        $form_gross = $gross_raw;
        $form_source_currency = ggr_dividend_accruals_normalize_currency( $source_currency_raw );
        $form_source_net = $source_net_raw;
        $form_fx_rate = $fx_rate_raw;

        $date_mysql = ggr_dividend_accruals_normalize_accrual_date( $date_raw );
        $source_currency = ggr_dividend_accruals_normalize_currency( $source_currency_raw );
        $source_net = $source_net_raw !== '' ? ggr_dividend_accruals_parse_float( $source_net_raw ) : null;
        $fx_rate = $fx_rate_raw !== '' ? ggr_dividend_accruals_parse_float( $fx_rate_raw ) : null;
        $gross_value = $gross_raw !== '' ? ggr_dividend_accruals_parse_float( $gross_raw ) : 0.0;
        $use_source_net = ( 'USD' === $source_currency && $source_net !== null && $source_net > 0 );

        if ( $use_source_net ) {
            $gross_value = $source_net;
            if ( $fx_rate !== null && $fx_rate > 0 ) {
                $gross_value = round( $gross_value * $fx_rate, 4 );
            }
        }

        if ( ! $date_mysql || ( $gross_raw === '' && ! $use_source_net ) ) {
            $error = 'Datum en bruto dividend zijn verplicht.';
        } elseif ( $use_source_net && $source_net <= 0 ) {
            $error = 'Bron netto bedrag moet groter zijn dan 0.';
        } elseif ( ! $use_source_net && $gross_value <= 0 ) {
            $error = 'Bruto dividend moet groter zijn dan 0.';
        } elseif ( $fx_rate !== null && $fx_rate <= 0 ) {
            $error = 'USD/EUR koers moet groter zijn dan 0.';
        } else {
            $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                ? ggr_portal_get_total_participations_all_users( $date_mysql )
                : 0.0;

            if ( $total_parts <= 0 ) {
                $error = 'Geen participaties gevonden om de dividendwaarde te berekenen.';
            } else {
                $distribution_fee  = round( $gross_value * 0.1, 4 );
                $net_value         = round( $gross_value - $distribution_fee, 4 );
                $per_participation = round( $net_value / $total_parts, 6 );
                $now               = current_time( 'mysql' );
                $source_gross = $use_source_net ? $source_net : $gross_value;
                $source_net_value = $use_source_net ? $source_net : $net_value;
                $source_tax = null;
                if ( 'USD' !== $source_currency ) {
                    $source_currency = 'EUR';
                }

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
                                'accrual_total'        => $net_value,
                                'accrual_gross'        => $gross_value,
                                'distribution_fee'     => $distribution_fee,
                                'total_participations' => $total_parts,
                                'per_participation'    => $per_participation,
                                'source_currency'      => $source_currency,
                                'source_gross'         => $source_gross,
                                'source_tax'           => $source_tax,
                                'source_net'           => $source_net_value,
                                'fx_rate_usd_eur'      => ( $fx_rate !== null && $fx_rate > 0 ) ? $fx_rate : null,
                                'computed_from_history' => 0,                            
                                'updated_at'           => $now,
                            ),
                            array( 'id' => $edit_id ),
                            array( '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%f', '%f', '%d', '%s' ),
                            array( '%d' )
                        );

                        if ( $updated !== false ) {
                            $notice   = 'Dividend accrual bijgewerkt.';
                            $is_edit  = false;
                            $edit_id  = 0;
                            $form_date  = $date_mysql;
                            $form_gross = '';
                            $form_source_currency = 'EUR';
                            $form_source_net = '';
                            $form_fx_rate = '';                            
                        } else {
                            $error = 'Bijwerken is mislukt.';
                        }
                    }
                } else {
                    $saved = ggr_dividend_accruals_upsert(
                        $date_mysql,
                        $gross_value,
                        $total_parts,
                        array(
                            'source_currency'      => $source_currency,
                            'source_gross'         => $source_gross,
                            'source_tax'           => $source_tax,
                            'source_net'           => $source_net_value,
                            'fx_rate_usd_eur'      => ( $fx_rate !== null && $fx_rate > 0 ) ? $fx_rate : null,
                            'computed_from_history' => 0,
                        )
                    );

                    if ( is_wp_error( $saved ) ) {
                        $error = $saved->get_error_message();
                    } else {
                        $notice   = 'Dividend accrual opgeslagen.';
                        $form_date  = $date_mysql;
                        $form_gross = '';
                        $form_source_currency = 'EUR';
                        $form_source_net = '';
                        $form_fx_rate = '';                        
                    }
                }
            }
        }
    }

    $history_form_action_id   = '';
    $history_form_report_date = $today;
    $history_form_gross        = '';
    $history_edit_id           = 0;
    $history_is_edit           = false;

    if ( isset( $_GET['history_edit_id'] ) ) {
        $history_edit_id = (int) $_GET['history_edit_id'];

        $history_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$history_table_name} WHERE id = %d LIMIT 1",
                $history_edit_id
            ),
            ARRAY_A
        );

        if ( $history_row ) {
            $history_form_action_id   = $history_row['action_id'];
            $history_form_report_date = $history_row['report_date'];
            $history_form_gross        = number_format( (float) $history_row['gross_value'], 2, ',', '' );
            $history_is_edit           = true;
        }
    }

    if ( isset( $_POST['ggr_accrual_history_submit'] ) ) {
        check_admin_referer( 'ggr_save_accrual_history' );

        $history_action_id_raw = isset( $_POST['history_action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_action_id'] ) ) : '';
        $history_date_raw      = isset( $_POST['history_report_date'] ) ? sanitize_text_field( wp_unslash( $_POST['history_report_date'] ) ) : '';
        $history_gross_raw     = isset( $_POST['history_gross_value'] ) ? sanitize_text_field( wp_unslash( $_POST['history_gross_value'] ) ) : '';
        $history_edit_id       = isset( $_POST['history_id'] ) ? (int) $_POST['history_id'] : 0;

        $history_form_action_id   = $history_action_id_raw;
        $history_form_report_date = $history_date_raw;
        $history_form_gross        = $history_gross_raw;
        $history_date_mysql = ggr_dividend_accruals_parse_date( $history_date_raw );
        $history_gross      = ggr_dividend_accruals_parse_float( $history_gross_raw );
        $history_tax        = round( $history_gross * 0.15, 4 );
        $history_net        = round( $history_gross - $history_tax, 4 );

        if ( ! $history_action_id_raw || ! $history_date_mysql ) {
            $history_error = 'Transaction ID en reportdate zijn verplicht.';
        } elseif ( $history_gross_raw === '' ) {
            $history_error = 'Gross value is verplicht.';
        } else {
            $existing_action = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$history_table_name} WHERE action_id = %s LIMIT 1",
                    $history_action_id_raw
                )
            );

            if ( $history_edit_id && $existing_action && (int) $existing_action !== $history_edit_id ) {
                $history_error = 'Er bestaat al een transactie historie met deze Transaction ID.';
            } else {
                if ( $history_edit_id ) {
                    $updated = $wpdb->update(
                        $history_table_name,
                        array(
                            'action_id'   => $history_action_id_raw,
                            'report_date' => $history_date_mysql,
                            'gross_value' => $history_gross,
                            'tax_value'   => $history_tax,
                            'net_amount'  => $history_net,
                            'updated_at'  => current_time( 'mysql' ),
                        ),
                        array( 'id' => $history_edit_id ),
                        array( '%s', '%s', '%f', '%f', '%f', '%s' ),
                        array( '%d' )
                    );

                    if ( $updated !== false ) {
                        $history_notice = 'Transactie historie bijgewerkt.';
                    $history_is_edit = false;
                    $history_edit_id = 0;
                    $history_form_action_id = '';
                    $history_form_report_date = $today;
                    $history_form_gross = '';
                } else {
                    $history_error = 'Bijwerken is mislukt.';
                }
            } else {
                    $saved = ggr_dividend_accrual_history_upsert(
                        $history_action_id_raw,
                        $history_date_mysql,
                        $history_gross,
                        $history_tax,
                        $history_net
                    );

                    if ( is_wp_error( $saved ) ) {
                        $history_error = $saved->get_error_message();
                    } else {
                    $history_notice = 'Transactie historie opgeslagen.';
                    $history_form_action_id = '';
                    $history_form_report_date = $today;
                    $history_form_gross = '';
                }
            }
        }
        }
    }

    if ( isset( $_POST['ggr_ibkr_accruals_credentials_submit'] ) ) {
        check_admin_referer( 'ggr_ibkr_accruals_credentials' );

        $ibkr_token_input        = isset( $_POST['ggr_ibkr_flex_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_ibkr_flex_token'] ) ) : '';
        $ibkr_accruals_query_id  = isset( $_POST['ggr_ibkr_flex_accruals_query_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_ibkr_flex_accruals_query_id'] ) ) : '';

        update_option( 'ggr_ibkr_flex_token', $ibkr_token_input );
        update_option( 'ggr_ibkr_flex_accruals_query_id', $ibkr_accruals_query_id );

        $history_notice = 'IBKR Flex instellingen voor transactie historie opgeslagen.';
    }

    if ( isset( $_POST['ggr_ibkr_accruals_fetch_submit'] ) ) {
        check_admin_referer( 'ggr_ibkr_accruals_fetch' );

        $result = ggr_ibkr_accruals_fetch_entries();

        if ( is_wp_error( $result ) ) {
            $history_error = '';
            ggr_ibkr_accruals_set_last_error( $result, 'IBKR accruals ophalen is mislukt' );
        } else {
            $store_result = ggr_ibkr_accruals_store_entries( $result['entries'], $result['statement_url'] );
            ggr_ibkr_accruals_set_last_run( $store_result['imported'], $store_result['report_date'], $result['statement_url'] );
            $history_notice = sprintf(
                'IBKR transactie historie geïmporteerd: %d items gevonden, %d duplicates uitgesloten, %d items opgeslagen.',
                (int) $result['total_count'],
                (int) $result['duplicate_count'],
                (int) $store_result['imported']
            );
            ggr_ibkr_accruals_clear_last_error();
        }
    }

    if ( isset( $_POST['ggr_dividend_accruals_backfill_submit'] ) ) {
        check_admin_referer( 'ggr_dividend_accruals_backfill' );

        $backfill = ggr_dividend_accruals_backfill_history_all();

        if ( is_wp_error( $backfill ) ) {
            $rollup_error = $backfill->get_error_message();
        } else {
            $rollup_notice = sprintf(
                'Backfill afgerond: %d nieuwe maanden opgeslagen, %d overgeslagen (bestond al), %d zonder historie.',
                (int) $backfill['created'],
                (int) $backfill['skipped'],
                (int) $backfill['missing']
            );
        }
    }

    $rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY accrual_date DESC, id DESC",
        ARRAY_A
    );

    $history_rows = $wpdb->get_results(
        "SELECT * FROM {$history_table_name} ORDER BY report_date DESC, id DESC",
        ARRAY_A
    );

    $ibkr_accruals_token = ggr_ibkr_accruals_get_token();
    $ibkr_accruals_query_id = ggr_ibkr_accruals_get_query_id();
    $ibkr_accruals_status = ggr_ibkr_accruals_get_status();
    
    $totals = array(
        'gross' => 0.0,
        'fee'   => 0.0,
        'net'   => 0.0,
    );

    foreach ( $rows as $row ) {
        $gross = isset( $row['accrual_gross'] ) && $row['accrual_gross'] !== null
            ? (float) $row['accrual_gross']
            : ( isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] / 0.9 : 0.0 );
        $fee = isset( $row['distribution_fee'] ) && $row['distribution_fee'] !== null
            ? (float) $row['distribution_fee']
            : round( $gross * 0.1, 4 );
        $net = isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] : 0.0;

        $totals['gross'] += $gross;
        $totals['fee']   += $fee;
        $totals['net']   += $net;

        if ( ! empty( $row['source_currency'] ) && strtoupper( (string) $row['source_currency'] ) === 'USD' ) {
            if ( empty( $row['fx_rate_usd_eur'] ) ) {
                $needs_fx_notice = true;
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>Dividend accruals</h1>
        <p>Leg de bruto dividendpot vast per maand (datum = laatste dag van de maand).</p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $rollup_notice ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $rollup_notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( $rollup_error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $rollup_error ); ?></p></div>
        <?php endif; ?>

        <h2><?php echo $is_edit ? 'Dividend accrual bewerken' : 'Nieuwe dividend accrual'; ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;">
            <form method="post" style="max-width: 520px;flex:1;">
                <?php wp_nonce_field( 'ggr_save_dividend_accrual' ); ?>
                <input type="hidden" name="accrual_id" value="<?php echo esc_attr( $edit_id ); ?>" />
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="accrual_date">Datum</label></th>
                        <td><input type="date" id="accrual_date" name="accrual_date" value="<?php echo esc_attr( $form_date ); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="accrual_gross">Bruto dividend (€)</label></th>
                        <td><input type="text" id="accrual_gross" name="accrual_gross" value="<?php echo esc_attr( $form_gross ); ?>" placeholder="Bijv. 15000,00" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source_currency">Bron valuta</label></th>
                        <td>
                            <select id="source_currency" name="source_currency">
                                <option value="EUR" <?php selected( $form_source_currency, 'EUR' ); ?>>EUR</option>
                                <option value="USD" <?php selected( $form_source_currency, 'USD' ); ?>>USD</option>
                            </select>
                            <p class="description">Gebruik USD als de accruals uit de IBKR historie komen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source_net_amount">Bron netto</label></th>
                        <td>
                            <input type="text" id="source_net_amount" name="source_net_amount" value="<?php echo esc_attr( $form_source_net ); ?>" placeholder="Bijv. 12000,00" />
                            <p class="description">Optioneel: basisbedrag in USD om later te converteren naar EUR.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fx_rate_usd_eur">USD/EUR koers</label></th>
                        <td>
                            <input type="text" id="fx_rate_usd_eur" name="fx_rate_usd_eur" value="<?php echo esc_attr( $form_fx_rate ); ?>" placeholder="Bijv. 0,92" />
                            <p class="description">Optioneel: voeg een koers toe om USD automatisch om te rekenen naar EUR.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="ggr_dividend_accrual_submit">
                        <?php echo $is_edit ? 'Bijwerken' : 'Opslaan'; ?>
                    </button>
                </p>
            </form>
            <div style="min-width:260px;max-width:360px;">
                <h3 style="margin-top:0;">Maandelijkse run</h3>
                <p style="margin:0 0 8px;">
                    Op elke eerste dag van de maand wordt automatisch de vorige maand uit de IBKR transactie historie
                    opgeteld en als Dividend Accrual vastgelegd (met datum = laatste dag van de vorige maand).
                </p>
                <p style="margin:0;">
                    Je kunt deze bedragen altijd aanpassen. Voor USD-bedragen kun je optioneel een USD/EUR koers opslaan
                    zodat de conversie later handmatig of via API kan worden bijgewerkt.
                </p>
                <form method="post" style="margin:10px 0 0;">
                    <?php wp_nonce_field( 'ggr_dividend_accruals_backfill' ); ?>
                    <button type="submit" class="button button-secondary" name="ggr_dividend_accruals_backfill_submit">
                        Alle ontbrekende maanden bijwerken
                    </button>
                </form>
            </div>
            <div style="min-width:240px;max-width:320px;">
                <h3 style="margin-top:0;">Financieel overzicht</h3>
                <?php if ( $needs_fx_notice ) : ?>
                    <p style="margin:0 0 8px;color:#b32d2e;">
                        Let op: er zijn USD-bedragen zonder koers. Vul een USD/EUR koers in om de EUR-totalen te actualiseren.
                    </p>
                <?php endif; ?>
                <p style="margin:0 0 6px;"><strong>Totaal bruto:</strong> € <?php echo esc_html( number_format( $totals['gross'], 2, ',', '.' ) ); ?></p>
                <p style="margin:0 0 6px;"><strong>Distributievergoeding (10%):</strong> € <?php echo esc_html( number_format( $totals['fee'], 2, ',', '.' ) ); ?></p>
                <p style="margin:0;"><strong>Totaal netto:</strong> € <?php echo esc_html( number_format( $totals['net'], 2, ',', '.' ) ); ?></p>
            </div>
        </div>

        <h2>Overzicht</h2>
        <?php if ( empty( $rows ) ) : ?>
            <p>Nog geen dividend accruals opgeslagen.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Bruto dividend</th>
                        <th>Distributievergoeding</th>
                        <th>Netto dividend</th>
                        <th>Bron valuta</th>
                        <th>Bron netto</th>
                        <th>USD/EUR koers</th>
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
                        $gross_value = isset( $row['accrual_gross'] ) && $row['accrual_gross'] !== null
                            ? (float) $row['accrual_gross']
                            : (float) $row['accrual_total'] / 0.9;
                        $fee_value = isset( $row['distribution_fee'] ) && $row['distribution_fee'] !== null
                            ? (float) $row['distribution_fee']
                            : round( $gross_value * 0.1, 4 );
                        $net_value = (float) $row['accrual_total'];
                        $gross_disp = '€ ' . number_format( $gross_value, 2, ',', '.' );
                        $fee_disp   = '€ ' . number_format( $fee_value, 2, ',', '.' );
                        $net_disp   = '€ ' . number_format( $net_value, 2, ',', '.' );
                        $source_currency = ! empty( $row['source_currency'] ) ? strtoupper( (string) $row['source_currency'] ) : 'EUR';
                        $source_net = isset( $row['source_net'] ) && $row['source_net'] !== null ? (float) $row['source_net'] : null;
                        $source_net_disp = $source_net !== null
                            ? ( $source_currency === 'USD' ? '$ ' : '€ ' ) . number_format( $source_net, 2, ',', '.' )
                            : '–';
                        $fx_rate_disp = isset( $row['fx_rate_usd_eur'] ) && $row['fx_rate_usd_eur'] !== null
                            ? number_format( (float) $row['fx_rate_usd_eur'], 6, ',', '.' )
                            : '–';
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
                            <td><?php echo esc_html( $gross_disp ); ?></td>
                            <td><?php echo esc_html( $fee_disp ); ?></td>
                            <td><?php echo esc_html( $net_disp ); ?></td>
                            <td><?php echo esc_html( $source_currency ); ?></td>
                            <td><?php echo esc_html( $source_net_disp ); ?></td>
                            <td><?php echo esc_html( $fx_rate_disp ); ?></td>
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

        <hr />

        <h2>Dividend Transactie historie</h2>
        <p>Beheer de IBKR dividend transacties (Transaction ID, reportdate, gross value, tax en net amount).</p>

        <?php if ( $history_notice ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $history_notice ); ?></p></div>
        <?php endif; ?>

        <?php if ( $history_error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $history_error ); ?></p></div>
        <?php endif; ?>

        <h3>IBKR Flex API (Dividend transactie historie)</h3>
        <p>Gebruik de aparte Flex Query ID voor Dividend transactie historie. Het token is hetzelfde als bij NAV.</p>

        <?php if ( ! empty( $ibkr_accruals_status ) ) : ?>
            <div class="notice notice-info">
                <p>
                    <strong>Volgende Dividend Accruals:</strong>
                    <?php if ( ! empty( $ibkr_accruals_status['has_credentials'] ) && ! empty( $ibkr_accruals_status['next_run'] ) ) : ?>
                        <?php echo esc_html( wp_date( 'd-m-Y H:i', $ibkr_accruals_status['next_run'] ) ); ?>
                    <?php else : ?>
                        Automatische import staat nog niet ingepland. Vul token en Query ID in en sla op.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ( ! empty( $ibkr_accruals_status['last_run'] ) && is_array( $ibkr_accruals_status['last_run'] ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong>Laatste Dividend Accruals: </strong><?php echo esc_html( wp_date( 'd-m-Y H:i', (int) $ibkr_accruals_status['last_run']['timestamp'] ) ); ?>
                        (<?php echo esc_html( (int) $ibkr_accruals_status['last_run']['count'] ); ?> items gevonden
                        <?php if ( isset( $ibkr_accruals_status['last_run']['duplicates'] ) ) : ?>
                            , <?php echo esc_html( (int) $ibkr_accruals_status['last_run']['duplicates'] ); ?> duplicates
                        <?php endif; ?>
                        <?php if ( isset( $ibkr_accruals_status['last_run']['imported'] ) ) : ?>
                            , <?php echo esc_html( (int) $ibkr_accruals_status['last_run']['imported'] ); ?> opgeslagen
                        <?php endif; ?>
                        <?php if ( ! empty( $ibkr_accruals_status['last_run']['report_date'] ) ) : ?>
                            , reportdatum: <?php echo esc_html( wp_date( 'd-m-Y', strtotime( $ibkr_accruals_status['last_run']['report_date'] ) ) ); ?>
                        <?php endif; ?>
                        ).
                        <?php if ( ! empty( $ibkr_accruals_status['last_run']['statement_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $ibkr_accruals_status['last_run']['statement_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                Flex statement openen
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php if ( ! empty( $ibkr_accruals_status['last_error'] ) && is_array( $ibkr_accruals_status['last_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p>
                        Laatste fout: <strong><?php echo esc_html( wp_date( 'd-m-Y H:i', (int) $ibkr_accruals_status['last_error']['timestamp'] ) ); ?></strong>
                        (<?php echo esc_html( $ibkr_accruals_status['last_error']['message'] ); ?>).
                        <?php if ( ! empty( $ibkr_accruals_status['last_error']['statement_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $ibkr_accruals_status['last_error']['statement_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                Flex statement openen
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'ggr_ibkr_accruals_credentials' ); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ggr_ibkr_flex_token">Flex Web Service token</label></th>
                        <td>
                            <input
                                type="text"
                                id="ggr_ibkr_flex_token"
                                name="ggr_ibkr_flex_token"
                                value="<?php echo esc_attr( $ibkr_accruals_token ); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ggr_ibkr_flex_accruals_query_id">Flex Query ID (Dividend transactie historie)</label></th>
                        <td>
                            <input
                                type="text"
                                id="ggr_ibkr_flex_accruals_query_id"
                                name="ggr_ibkr_flex_accruals_query_id"
                                value="<?php echo esc_attr( $ibkr_accruals_query_id ); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button( 'IBKR instellingen opslaan', 'secondary', 'ggr_ibkr_accruals_credentials_submit' ); ?>
        </form>

        <form method="post" style="margin-top: 1rem;">
            <?php wp_nonce_field( 'ggr_ibkr_accruals_fetch' ); ?>
            <?php submit_button( 'Handmatig ophalen via IBKR API', 'secondary', 'ggr_ibkr_accruals_fetch_submit' ); ?>
        </form>

        <h3><?php echo $history_is_edit ? 'Transactie historie bewerken' : 'Nieuwe transactie historie'; ?></h3>
        <form method="post" style="max-width: 720px;">
            <?php wp_nonce_field( 'ggr_save_accrual_history' ); ?>
            <input type="hidden" name="history_id" value="<?php echo esc_attr( $history_edit_id ); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="history_action_id">Transaction ID</label></th>
                    <td><input type="text" id="history_action_id" name="history_action_id" value="<?php echo esc_attr( $history_form_action_id ); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="history_report_date">Reportdate</label></th>
                    <td><input type="date" id="history_report_date" name="history_report_date" value="<?php echo esc_attr( $history_form_report_date ); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="history_gross_value">Gross value ($)</label></th>
                    <td>
                        <input type="text" id="history_gross_value" name="history_gross_value" value="<?php echo esc_attr( $history_form_gross ); ?>" required />
                        <p class="description">Tax (15%) en netto worden automatisch berekend op basis van het bedrag.</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary" name="ggr_accrual_history_submit">
                    <?php echo $history_is_edit ? 'Bijwerken' : 'Opslaan'; ?>
                </button>
            </p>
        </form>

        <h3>Overzicht dividend transactie historie</h3>
        <?php if ( empty( $history_rows ) ) : ?>
            <p>Nog geen dividend transactie historie opgeslagen.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Reportdate</th>
                        <th>Gross value</th>
                        <th>Tax</th>
                        <th>Net amount</th>
                        <th>Flex statement</th>
                        <th>Aangemaakt</th>
                        <th>Bijgewerkt</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history_rows as $history_row ) : ?>
                        <?php
                        $history_edit_url = add_query_arg(
                            array(
                                'page'            => 'ggr-dividend-accruals',
                                'history_edit_id' => (int) $history_row['id'],
                            ),
                            admin_url( 'admin.php' )
                        );

                        $history_delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'page'           => 'ggr-dividend-accruals',
                                    'history_action' => 'delete',
                                    'history_id'     => (int) $history_row['id'],
                                ),
                                admin_url( 'admin.php' )
                            ),
                            'ggr_delete_dividend_accrual_history_' . (int) $history_row['id']
                        );

                        $history_report = $history_row['report_date'] ? date_i18n( 'd-m-Y', strtotime( $history_row['report_date'] ) ) : '';
                        $history_gross_disp = '$ ' . number_format( (float) $history_row['gross_value'], 2, ',', '.' );
                        $history_tax_disp   = '$ ' . number_format( (float) $history_row['tax_value'], 2, ',', '.' );
                        $history_net_disp   = '$ ' . number_format( (float) $history_row['net_amount'], 2, ',', '.' );
                        $history_statement_url = isset( $history_row['statement_url'] ) ? trim( (string) $history_row['statement_url'] ) : '';
                        $history_created    = $history_row['created_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $history_row['created_at'] ) ) : '';
                        $history_updated    = $history_row['updated_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $history_row['updated_at'] ) ) : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $history_row['action_id'] ); ?></td>
                            <td><?php echo esc_html( $history_report ); ?></td>
                            <td><?php echo esc_html( $history_gross_disp ); ?></td>
                            <td><?php echo esc_html( $history_tax_disp ); ?></td>
                            <td><?php echo esc_html( $history_net_disp ); ?></td>
                            <td>
                                <?php if ( $history_statement_url ) : ?>
                                    <a href="<?php echo esc_url( $history_statement_url ); ?>" target="_blank" rel="noopener noreferrer">
                                        FS downloaden
                                    </a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $history_created ); ?></td>
                            <td><?php echo esc_html( $history_updated ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $history_edit_url ); ?>">Bewerken</a> |
                                <a href="<?php echo esc_url( $history_delete_url ); ?>"
                                   onclick="return confirm('Weet je zeker dat je deze transactie historie wilt verwijderen?');">
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

    register_rest_route(
        'ggr/v1',
        '/dividend-accrual-history',
        array(
            'methods'             => 'GET',
            'callback'            => 'ggr_api_get_dividend_accrual_history',
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
    $gross_value = (float) $total_raw;

    if ( ! $date_mysql ) {
        return new WP_Error(
            'invalid_date',
            'Ongeldige datum.',
            array( 'status' => 400 )
        );
    }

    if ( $gross_value <= 0 ) {
        return new WP_Error(
            'invalid_total',
            'Bruto dividend moet groter zijn dan 0.',
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

    $saved = ggr_dividend_accruals_upsert(
        $date_mysql,
        $gross_value,
        $total_parts,
        array(
            'source_currency'      => 'EUR',
            'source_gross'         => $gross_value,
            'source_tax'           => null,
            'source_net'           => null,
            'fx_rate_usd_eur'      => null,
            'computed_from_history' => 0,
        )
    );

    if ( is_wp_error( $saved ) ) {
        return $saved;
    }

    $distribution_fee = round( $gross_value * 0.1, 4 );
    $net_value         = round( $gross_value - $distribution_fee, 4 );

    return array(
        'date'                 => $date_mysql,
        'gross'                => $gross_value,
        'distribution_fee'     => $distribution_fee,
        'net'                  => $net_value,
        'total_participations' => $total_parts,
        'per_participation'    => round( $net_value / $total_parts, 6 ),
    );
}

function ggr_api_get_dividend_accrual_history( WP_REST_Request $request ) {
    $report_date_raw = $request->get_param( 'report_date' );

    if ( empty( $report_date_raw ) ) {
        return new WP_Error(
            'missing_params',
            'Parameter "report_date" is verplicht.',
            array( 'status' => 400 )
        );
    }

    $report_date = ggr_dividend_accruals_parse_date( $report_date_raw );

    if ( ! $report_date ) {
        return new WP_Error(
            'invalid_date',
            'Ongeldige reportdate.',
            array( 'status' => 400 )
        );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_dividend_accrual_history';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT action_id, report_date, gross_value, tax_value, net_amount
             FROM {$table_name}
             WHERE report_date = %s
             ORDER BY action_id ASC, id ASC",
            $report_date
        ),
        ARRAY_A
    );

    return array(
        'report_date' => $report_date,
        'items'       => $rows ? $rows : array(),
        'count'       => $rows ? count( $rows ) : 0,
    );
}
