<?php
/**
 * GGR Stock Price – Dagelijkse waarde per 1 participatie
 *
 * - Database tabel voor dagelijkse GGR prijs
 * - Admin pagina voor handmatige invoer + import/export
 * - Helper functies voor ophalen prijs per datum of periode
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GGR_STOCK_PRICE_DB_VERSION' ) ) {
    define( 'GGR_STOCK_PRICE_DB_VERSION', '2.1' );
}

add_action( 'plugins_loaded', 'ggr_maybe_upgrade_stock_price_table' );

/* ============================================================================
 * 1. DATABASE TABEL
 * ============================================================================
 *
 * LET OP:
 * Aanroepen in je hoofdplugin bij activatie:
 *
 * if ( function_exists( 'ggr_create_ggr_stock_price_table' ) ) {
 *     ggr_create_ggr_stock_price_table();
 * }
 */
function ggr_create_ggr_stock_price_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_stock_prices';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            price_date DATE NOT NULL,
            price_value DECIMAL(15,6) NOT NULL,
            fund_total DECIMAL(20,4) DEFAULT NULL,
            total_participations DECIMAL(20,4) DEFAULT NULL,
            statement_url TEXT DEFAULT NULL,            
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_price_date (price_date)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'ggr_stock_price_db_version', GGR_STOCK_PRICE_DB_VERSION );    
}

/**
 * Migratie: extra kolommen voor IBKR total en participaties.
 */
function ggr_maybe_upgrade_stock_price_table() {
    global $wpdb;

    $installed = get_option( 'ggr_stock_price_db_version', '1.0' );

    if ( version_compare( $installed, GGR_STOCK_PRICE_DB_VERSION, '>=' ) ) {
        return;
    }

    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    $schema = $wpdb->dbname ? $wpdb->dbname : DB_NAME;

    $columns = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s
              AND TABLE_NAME = %s
            ",
            $schema,
            $table_name
        ),
        ARRAY_A
    );

    if ( ! is_array( $columns ) ) {
        return;
    }

    $existing_columns = wp_list_pluck( $columns, 'COLUMN_NAME' );

    if ( ! in_array( 'fund_total', $existing_columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_name} ADD fund_total DECIMAL(20,4) DEFAULT NULL AFTER price_value" );
    }

    if ( ! in_array( 'total_participations', $existing_columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_name} ADD total_participations DECIMAL(20,4) DEFAULT NULL AFTER fund_total" );
    }

    if ( ! in_array( 'statement_url', $existing_columns, true ) ) {
        $wpdb->query( "ALTER TABLE {$table_name} ADD statement_url TEXT DEFAULT NULL AFTER total_participations" );
    }

    update_option( 'ggr_stock_price_db_version', GGR_STOCK_PRICE_DB_VERSION );
}
/* ============================================================================
 * 2. ADMIN MENU (top-level item)
 * ============================================================================
 */

add_action( 'admin_menu', 'ggr_register_stock_price_menu' );

function ggr_register_stock_price_menu() {
    add_menu_page(
        'GGR Stock Price',              // Pagina titel
        'GGR Stock Price',              // Menu titel in sidebar
        'read',                         // Capability
        'ggr-stock-price',              // Menu slug (?page=ggr-stock-price)
        'ggr_render_stock_price_page',  // Callback
        'dashicons-chart-line',         // Icoon
        26                              // Positie
    );
}

function ggr_stock_price_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'manage_options' );
}

/* ============================================================================
 * 3. ACTIE-AFHANDELING (VOOR OUTPUT) – export / delete / delete_all
 * ============================================================================
 */

add_action( 'admin_init', 'ggr_handle_stock_price_actions' );

function ggr_handle_stock_price_actions() {
    if ( ! is_admin() ) {
        return;
    }

    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ggr-stock-price' ) {
        return;
    }

    if ( ! ggr_stock_price_user_can_access() ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    // EXPORT (CSV)
    if (
        isset( $_GET['action'] ) &&
        $_GET['action'] === 'export' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_export_prices' )
    ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ggr-stock-prices.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'date', 'value' ) );

        $rows = $wpdb->get_results(
            "SELECT price_date, price_value 
             FROM {$table_name} 
             ORDER BY price_date ASC",
            ARRAY_A
        );

        if ( $rows ) {
            foreach ( $rows as $row ) {
                fputcsv( $output, array( $row['price_date'], $row['price_value'] ) );
            }
        }

        fclose( $output );
        exit;
    }

    // ALLE WAARDES VERWIJDEREN
    if (
        isset( $_GET['action'] ) &&
        $_GET['action'] === 'delete_all' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_delete_all_prices' )
    ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Je hebt geen toestemming om alle waardes te verwijderen.' );
        }

        $wpdb->query( "DELETE FROM {$table_name}" );

        $target_url = add_query_arg(
            array(
                'page' => 'ggr-stock-price',
                'msg'  => 'deleted_all',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $target_url );
        exit;
    }

    // ENKEL RECORD VERWIJDEREN
    if (
        isset( $_GET['action'], $_GET['id'] ) &&
        $_GET['action'] === 'delete' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'ggr_delete_price_' . (int) $_GET['id'] )
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
                'page' => 'ggr-stock-price',
                'msg'  => $msg,
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $target_url );
        exit;
    }
}

/* ============================================================================
 * 4. DATA HELPERS
 * ========================================================================= */

/**
 * Sla een GGR stock price op voor een datum (upsert op basis van price_date).
 *
 * @param string $date_mysql  Datum in Y-m-d formaat.
 * @param float  $price       Waarde per participatie.
 *
 * @return bool
 */
function ggr_upsert_stock_price( $date_mysql, $price, $extra = array() ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';
    $now        = current_time( 'mysql' );

    $fund_total          = array_key_exists( 'fund_total', $extra ) && $extra['fund_total'] !== null ? (float) $extra['fund_total'] : null;
    $total_participation = array_key_exists( 'total_participations', $extra ) && $extra['total_participations'] !== null ? (float) $extra['total_participations'] : null;
    $has_statement_url   = array_key_exists( 'statement_url', $extra );
    $statement_url       = $has_statement_url ? trim( (string) $extra['statement_url'] ) : '';
    $statement_url       = $statement_url !== '' ? esc_url_raw( $statement_url ) : '';
    
    if ( null === $total_participation && function_exists( 'ggr_portal_get_total_participations_all_users' ) ) {
        $computed_total = ggr_portal_get_total_participations_all_users( $date_mysql );
        $total_participation = ( $computed_total !== null ) ? (float) $computed_total : null;
    }

    // Bestaat er al een record voor deze datum?
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE price_date = %s LIMIT 1",
            $date_mysql
        )
    );

    if ( $existing_id ) {
        // Update bestaande snapshot
        $set_parts = array(
            'price_value = %f',
            'updated_at = %s',
        );
        $params = array(
            (float) $price,
            $now,
        );

        if ( null !== $fund_total ) {
            $set_parts[] = 'fund_total = %f';
            $params[]    = $fund_total;
        } else {
            $set_parts[] = 'fund_total = NULL';
        }

        if ( null !== $total_participation ) {
            $set_parts[] = 'total_participations = %f';
            $params[]    = $total_participation;
        } else {
            $set_parts[] = 'total_participations = NULL';
        }

        if ( $has_statement_url ) {
            if ( $statement_url !== '' ) {
                $set_parts[] = 'statement_url = %s';
                $params[]    = $statement_url;
            } else {
                $set_parts[] = 'statement_url = NULL';
            }
        }

        $params[] = (int) $existing_id;

        $sql      = "UPDATE {$table_name} SET " . implode( ', ', $set_parts ) . " WHERE id = %d";
        $prepared = $wpdb->prepare( $sql, $params );
        $updated  = $wpdb->query( $prepared );
        
        return $updated !== false;
    }

    // Nieuwe snapshot
    $columns      = array();
    $placeholders = array();
    $values       = array();

    // Helper to append column/placeholder/value keeping alignment
    $add_field = static function ( $column, $placeholder, $value ) use ( &$columns, &$placeholders, &$values ) {
        $columns[] = $column;
        $placeholders[] = $placeholder;

        if ( 'NULL' !== $placeholder ) {
            $values[] = $value;
        }
    };

    $add_field( 'price_date', '%s', $date_mysql );
    $add_field( 'price_value', '%f', (float) $price );
    $add_field( 'fund_total', null !== $fund_total ? '%f' : 'NULL', $fund_total );
    $add_field( 'total_participations', null !== $total_participation ? '%f' : 'NULL', $total_participation );
    $add_field( 'statement_url', $statement_url !== '' ? '%s' : 'NULL', $statement_url );    
    $add_field( 'created_at', '%s', $now );
    $add_field( 'updated_at', '%s', $now );

    // Build SQL with NULL literals baked in
    $prepared_placeholders = array();
    foreach ( $placeholders as $ph ) {
        $prepared_placeholders[] = ( 'NULL' === $ph ) ? 'NULL' : $ph;
    }

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table_name,
        implode( ', ', $columns ),
        implode( ', ', $prepared_placeholders )
    );
    
    $prepared_values = $values;
    $prepared        = $wpdb->prepare( $sql, $prepared_values );
    $inserted = $wpdb->query( $prepared );

    return (bool) $inserted;
}


/**
 * Herbereken total_participations voor alle waardes (optioneel vanaf een datum).
 *
 * @param string|null $start_date_only Vanaf welke datum (in elk formaat dat ggr_portal_parse_date_to_mysql accepteert).
 *
 * @return int Aantal rijen die zijn bijgewerkt.
 */
function ggr_stock_price_refresh_total_participations_from_date( $start_date_only = null ) {
    if ( ! function_exists( 'ggr_portal_get_total_participations_all_users' ) ) {
        return 0;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';
    $start_date = '';

    if ( $start_date_only ) {
        if ( function_exists( 'ggr_portal_parse_date_to_mysql' ) ) {
            $start_date = ggr_portal_parse_date_to_mysql( $start_date_only );
        } else {
            $timestamp  = strtotime( $start_date_only );
            $start_date = $timestamp ? date( 'Y-m-d', $timestamp ) : '';
        }
    }

    $sql = "SELECT id, price_date, total_participations FROM {$table_name}";
    if ( $start_date ) {
        $sql .= $wpdb->prepare( ' WHERE price_date >= %s', $start_date );
    }
    $sql .= ' ORDER BY price_date ASC';

    $rows = $wpdb->get_results( $sql, ARRAY_A );

    if ( empty( $rows ) ) {
        return 0;
    }

    $now          = current_time( 'mysql' );
    $updated_rows = 0;

    foreach ( $rows as $row ) {
        $calculated_total = ggr_portal_get_total_participations_all_users( $row['price_date'] );
        $calculated_total = round( $calculated_total, 4 );

        $stored_raw   = $row['total_participations'];
        $stored_value = ( $stored_raw !== null && $stored_raw !== '' ) ? round( (float) $stored_raw, 4 ) : null;

        $needs_update = ( null === $stored_value ) || abs( $stored_value - $calculated_total ) >= 0.0001;

        if ( ! $needs_update ) {
            continue;
        }

        $result = $wpdb->update(
            $table_name,
            array(
                'total_participations' => $calculated_total,
                'updated_at'           => $now,
            ),
            array( 'id' => (int) $row['id'] ),
            array( '%f', '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            $updated_rows++;
        }
    }

    return $updated_rows;
}


/**
 * Haal GGR waarde op voor een datum.
 *
 * Functioneel gedrag:
 * - Altijd de laatste bekende koers t/m die datum:
 *   * als er een koers op die datum is → die koers
 *   * anders de laatste koers vóór die datum
 * - Bestaat er helemaal geen koers → null
 *
 * @param string $date      Datum (elk formaat dat strtotime pakt).
 * @param bool   $fallback  Wordt niet meer gebruikt (alleen voor backwards compatibility).
 * @return float|null       Waarde per 1 GGR-participatie of null.
 */
function ggr_get_stock_price_for_date( $date, $fallback = true ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';
    $date       = date( 'Y-m-d', strtotime( $date ) );

    // Eén query: laatste koers t/m deze datum
    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT price_value
             FROM {$table_name}
             WHERE price_date <= %s
             ORDER BY price_date DESC
             LIMIT 1",
            $date
        )
    );

    return ( $value !== null && $value !== '' ) ? (float) $value : null;
}

/**
 * Haal de laatste koersdatum op t/m een datum.
 *
 * @param string $date Datum (elk formaat dat strtotime pakt).
 * @return string|null Datum in Y-m-d formaat of null.
 */
function ggr_get_stock_price_date_for_date( $date ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';
    $date       = date( 'Y-m-d', strtotime( $date ) );

    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT price_date
             FROM {$table_name}
             WHERE price_date <= %s
             ORDER BY price_date DESC
             LIMIT 1",
            $date
        )
    );

    return ( $value !== null && $value !== '' ) ? (string) $value : null;
}

/**
 * Haal een reeks GGR prijzen op voor een periode (alleen daadwerkelijke snapshots).
 *
 * @param string $from
 * @param string $to
 * @return array
 */
function ggr_get_stock_price_series( $from, $to ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    $from = date( 'Y-m-d', strtotime( $from ) );
    $to   = date( 'Y-m-d', strtotime( $to ) );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT price_date, price_value
             FROM {$table_name}
             WHERE price_date BETWEEN %s AND %s
             ORDER BY price_date ASC",
            $from,
            $to
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * Optioneel: haal de allerlaatste beschikbare koers op (meest recente dag in de tabel).
 *
 * @return float|null
 */
function ggr_get_latest_stock_price() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    $value = $wpdb->get_var(
        "SELECT price_value
         FROM {$table_name}
         ORDER BY price_date DESC
         LIMIT 1"
    );

    return ( $value !== null && $value !== '' ) ? (float) $value : null;
}

/**
 * Haal totaal en datum uit IBKR Flex XML (EquitySummaryByReportDateInBase).
 *
 * @param string $xml_raw De volledige XML-string.
 *
 * @return array|WP_Error {
 *     @type float  $total       Totale waarde (EUR) uit het Flex-rapport.
 *     @type string $report_date Datum in Y-m-d formaat.
 * }
 */
function ggr_parse_ibkr_flex_equity_summary( $xml_raw ) {
    $xml_raw = trim( (string) $xml_raw );

    if ( $xml_raw === '' ) {
        return new WP_Error( 'empty_xml', 'Geen IBKR XML ontvangen.' );
    }

    $libxml_previous_state = libxml_use_internal_errors( true );
    $xml                   = simplexml_load_string( $xml_raw );
    libxml_clear_errors();
    libxml_use_internal_errors( $libxml_previous_state );

    if ( false === $xml ) {
        return new WP_Error( 'invalid_xml', 'IBKR XML kon niet worden gelezen.' );
    }

    $nodes = $xml->xpath( '/FlexQueryResponse/FlexStatements/FlexStatement/EquitySummaryInBase/EquitySummaryByReportDateInBase' );
    if ( empty( $nodes ) || ! isset( $nodes[0] ) ) {
        return new WP_Error( 'missing_summary', 'EquitySummaryByReportDateInBase niet gevonden in IBKR XML.' );
    }

    $summary_node = $nodes[0];

    $total_raw       = isset( $summary_node['total'] ) ? (string) $summary_node['total'] : '';
    $report_date_raw = isset( $summary_node['reportDate'] ) ? (string) $summary_node['reportDate'] : '';

    if ( $total_raw === '' || $report_date_raw === '' ) {
        return new WP_Error( 'missing_values', 'Total of reportDate ontbreekt in IBKR XML.' );
    }

    $total_value = (float) str_replace( array( ' ', ',' ), array( '', '' ), $total_raw );
    if ( $total_value <= 0 ) {
        return new WP_Error( 'invalid_total', 'Ongeldige total-waarde in IBKR XML.' );
    }

    $report_date_mysql = '';

    if ( preg_match( '/^\d{8}$/', $report_date_raw ) ) {
        $dt = DateTime::createFromFormat( 'Ymd', $report_date_raw );
        if ( $dt instanceof DateTime ) {
            $report_date_mysql = $dt->format( 'Y-m-d' );
        }
    }

    if ( ! $report_date_mysql && function_exists( 'ggr_portal_parse_date_to_mysql' ) ) {
        $report_date_mysql = ggr_portal_parse_date_to_mysql( $report_date_raw );
    }

    if ( empty( $report_date_mysql ) ) {
        return new WP_Error( 'invalid_date', 'Ongeldige reportDate in IBKR XML: ' . $report_date_raw );
    }

    return array(
        'total'       => $total_value,
        'report_date' => $report_date_mysql,
    );
}

/* ============================================================================
 * 5. ADMIN PAGINA (UI + import/save/edit)
 * ============================================================================
 */

function ggr_render_stock_price_page() {
    if ( ! ggr_stock_price_user_can_access() ) {
        wp_die( 'Je hebt geen toegang tot deze pagina.' );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_stock_prices';

    $notice = '';
    $error  = '';
    $ibkr_xml_input = '';

    $ibkr_token    = function_exists( 'ggr_ibkr_nav_get_token' ) ? ggr_ibkr_nav_get_token() : '';
    $ibkr_query_id = function_exists( 'ggr_ibkr_nav_get_query_id' ) ? ggr_ibkr_nav_get_query_id() : '';
    $ibkr_base_url = function_exists( 'ggr_ibkr_nav_get_base_url' ) ? ggr_ibkr_nav_get_base_url() : '';
    $ibkr_status   = function_exists( 'ggr_ibkr_nav_get_status' ) ? ggr_ibkr_nav_get_status() : array();
    $show_ibkr_sections = ! ( function_exists( 'ggr_admin_shell_is_allowed' ) && ggr_admin_shell_is_allowed() );
    $show_ibkr_manual_fetch = function_exists( 'ggr_ibkr_nav_has_credentials' )
        ? ggr_ibkr_nav_has_credentials()
        : ( $ibkr_token && $ibkr_query_id );
        
    $total_participations_today = function_exists( 'ggr_portal_get_total_participations_all_users' )
        ? ggr_portal_get_total_participations_all_users()
        : null;
        
    // Meldingen via ?msg=...
    if ( isset( $_GET['msg'] ) ) {
        switch ( $_GET['msg'] ) {
            case 'deleted_all':
                $notice = 'Alle GGR-waardes zijn verwijderd.';
                break;
            case 'deleted':
                $notice = 'Snapshot verwijderd.';
                break;
            case 'delete_failed':
                $error = 'Verwijderen is mislukt of record bestond niet meer.';
                break;
        }
    }

    $today      = current_time( 'Y-m-d' );
    $form_date  = $today;
    $form_total = '';
    $is_edit    = false;

    /* -----------------------------------------------------------
     * BEWERKEN (FORM PREFILL)
     * --------------------------------------------------------- */
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
            $form_date  = $row['price_date'];
            $form_total = isset( $row['fund_total'] ) ? number_format( (float) $row['fund_total'], 2, ',', '' ) : '';
            $is_edit    = true;
        }
    }

    /* -----------------------------------------------------------
     * IBKR FLEX API – credentials opslaan
     * --------------------------------------------------------- */
    if ( $show_ibkr_sections && isset( $_POST['ggr_ibkr_credentials_submit'] ) ) {
        check_admin_referer( 'ggr_ibkr_credentials' );

        $ibkr_token_input    = isset( $_POST['ggr_ibkr_flex_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_ibkr_flex_token'] ) ) : '';
        $ibkr_query_id_input = isset( $_POST['ggr_ibkr_flex_query_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_ibkr_flex_query_id'] ) ) : '';

        update_option( 'ggr_ibkr_flex_token', $ibkr_token_input );
        update_option( 'ggr_ibkr_flex_query_id', $ibkr_query_id_input );

        $ibkr_token    = $ibkr_token_input;
        $ibkr_query_id = $ibkr_query_id_input;

        if ( function_exists( 'ggr_ibkr_nav_schedule_cron' ) ) {
            if ( $ibkr_token && $ibkr_query_id ) {
                ggr_ibkr_nav_schedule_cron();
            } elseif ( function_exists( 'ggr_ibkr_nav_clear_cron' ) ) {
                ggr_ibkr_nav_clear_cron();
            }
        }

        if ( $ibkr_token && $ibkr_query_id ) {
            $notice = 'IBKR Flex token en Query ID zijn opgeslagen. Automatische dagelijkse import staat aan.';
        } else {
            $notice = 'IBKR Flex instellingen bijgewerkt. Vul zowel token als Query ID in voor automatische import.';
        }
    }

    /* -----------------------------------------------------------
     * IBKR FLEX API – handmatig ophalen
     * --------------------------------------------------------- */
    if ( $show_ibkr_manual_fetch && isset( $_POST['ggr_ibkr_manual_fetch_submit'] ) ) {
        check_admin_referer( 'ggr_ibkr_manual_fetch' );

        if ( function_exists( 'ggr_ibkr_nav_fetch_and_store' ) ) {
            $result = ggr_ibkr_nav_fetch_and_store();

            if ( is_wp_error( $result ) ) {
                $error_message = function_exists( 'ggr_ibkr_nav_format_error_message' )
                    ? ggr_ibkr_nav_format_error_message( $result )
                    : $result->get_error_message();
                $error = 'IBKR NAV ophalen is mislukt: ' . $error_message;
            } else {
                $notice = sprintf(
                    'IBKR NAV opgeslagen voor %s: € %s per participatie (totaal: € %s, participaties: %s).',
                    esc_html( $result['date'] ),
                    number_format( (float) $result['value'], 6, ',', '.' ),
                    isset( $result['total'] ) ? number_format( (float) $result['total'], 2, ',', '.' ) : '-',
                    isset( $result['total_participations'] ) ? number_format( (float) $result['total_participations'], 4, ',', '.' ) : '-'
                );

                $form_date  = $result['date'];
                $form_total = '';
                $is_edit    = false;
            }
        } else {
            $error = 'IBKR NAV module is niet beschikbaar.';
        }
    }

    /* -----------------------------------------------------------
     * IBKR FLEX XML → automatische koers (total / participaties)
     * --------------------------------------------------------- */
    if ( $show_ibkr_sections && isset( $_POST['ggr_ibkr_import_submit'] ) ) {
        check_admin_referer( 'ggr_ibkr_import' );

        $ibkr_xml_input = isset( $_POST['ggr_ibkr_xml'] ) ? wp_unslash( $_POST['ggr_ibkr_xml'] ) : '';
        $parsed_ibkr    = ggr_parse_ibkr_flex_equity_summary( $ibkr_xml_input );

        if ( is_wp_error( $parsed_ibkr ) ) {
            $error = $parsed_ibkr->get_error_message();
        } else {
            $report_date = $parsed_ibkr['report_date'];
            $fund_total  = (float) $parsed_ibkr['total'];

            $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                ? ggr_portal_get_total_participations_all_users( $report_date )
                : 0.0;

            if ( $total_parts <= 0 ) {
                $error = 'Geen participaties gevonden om de stock price mee te berekenen.';
            } else {
                $calculated_price = round( $fund_total / $total_parts, 6 );

                $saved = ggr_upsert_stock_price(
                    $report_date,
                    $calculated_price,
                    array(
                        'fund_total'           => $fund_total,
                        'total_participations' => $total_parts,
                    )
                );

                if ( $saved ) {
                    $notice = sprintf(
                        'IBKR Flex snapshot opgeslagen voor %s. Totaal: € %s, participaties: %s, NAV per participatie: € %s.',
                        $report_date,
                        number_format( $fund_total, 2, ',', '.' ),
                        number_format( $total_parts, 4, ',', '.' ),
                        number_format( $calculated_price, 6, ',', '.' )
                    );

                    $form_date      = $report_date;
                    $form_total     = '';
                    $ibkr_xml_input = '';
                    $is_edit        = false;
                } else {
                    $error = 'Kon de koers uit IBKR XML niet opslaan.';
                }
            }
        }
    }

    /* -----------------------------------------------------------
     * SAVE / UPDATE ACTIE (één waarde)
     * --------------------------------------------------------- */
    if ( isset( $_POST['ggr_price_submit'] ) ) {
        check_admin_referer( 'ggr_save_price' );

        $price_date_raw  = isset( $_POST['price_date'] ) ? sanitize_text_field( $_POST['price_date'] ) : '';
        $fund_total_raw  = isset( $_POST['fund_total'] ) ? sanitize_text_field( $_POST['fund_total'] ) : '';

        $price_date  = $price_date_raw ? date( 'Y-m-d', strtotime( $price_date_raw ) ) : '';
        $fund_total  = $fund_total_raw !== '' ? (float) str_replace( array( '.', ' ', ',' ), array( '', '', '.' ), $fund_total_raw ) : 0;

        // Form-velden terugvullen bij fout
        $form_date  = $price_date_raw;
        $form_total = $fund_total_raw;

        if ( ! $price_date || $fund_total_raw === '' ) {
            $error = 'Datum en totaalwaarde uit IBKR zijn verplicht.';
        } elseif ( $fund_total <= 0 ) {
            $error = 'Totaalwaarde moet groter zijn dan 0.';
        } else {
            // Bestaat er al een record voor deze datum?
            $existing_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE price_date = %s LIMIT 1",
                    $price_date
                )
            );

            $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                ? ggr_portal_get_total_participations_all_users( $price_date )
                : null;

            if ( null === $total_parts || $total_parts <= 0 ) {
                $total_parts = $existing_id
                    ? (float) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT total_participations FROM {$table_name} WHERE id = %d LIMIT 1",
                            $existing_id
                        )
                    )
                    : null;
            }

            if ( null === $total_parts || $total_parts <= 0 ) {
                $error = 'Geen participaties gevonden om de NAV mee te berekenen.';
            } else {
                $calculated_price = round( $fund_total / $total_parts, 6 );

                $saved = ggr_upsert_stock_price(
                    $price_date,
                    $calculated_price,
                    array(
                        'fund_total'           => $fund_total,
                        'total_participations' => $total_parts,
                    )
                );

                if ( $saved ) {
                    $notice = sprintf(
                        'Totaal uit IBKR voor %s is %s. NAV per participatie berekend op %s (participaties: %s).',
                        esc_html( $price_date ),
                        number_format( $fund_total, 2, ',', '.' ),
                        number_format( $calculated_price, 6, ',', '.' ),
                        number_format( $total_parts, 4, ',', '.' )
                    );

                    $form_date  = $price_date;
                    $form_total = '';
                    $is_edit    = false;
                } else {
                    $error  = $existing_id
                        ? 'Bijwerken van de IBKR-waarde is mislukt.'
                        : 'Opslaan van de IBKR-waarde is mislukt.';
                }
            }
        }
    }

    /* -----------------------------------------------------------
     * IMPORT (CSV)
     * --------------------------------------------------------- */
    if ( isset( $_POST['ggr_price_import_submit'] ) ) {
        check_admin_referer( 'ggr_import_price' );

        if ( ! isset( $_FILES['ggr_price_import_file'] ) || $_FILES['ggr_price_import_file']['error'] !== UPLOAD_ERR_OK ) {
            $error = 'Importbestand kon niet worden geladen.';
        } else {
            $file_tmp  = $_FILES['ggr_price_import_file']['tmp_name'];
            $contents  = file_get_contents( $file_tmp );
            $lines     = preg_split( '/\r\n|\r|\n/', $contents );
            $imported  = 0;
            $skipped   = 0;
            $skip_msgs = array();
            
            foreach ( $lines as $idx => $line ) {
                $line = trim( $line );
                if ( $line === '' ) {
                    continue;
                }

                // Eerste regel overslaan als header
                if ( $idx === 0 && ( stripos( $line, 'date' ) !== false || stripos( $line, 'datum' ) !== false ) ) {
                    continue;
                }

                // Probeer eerst ; daarna ,
                $parts = str_getcsv( $line, ';' );
                if ( count( $parts ) < 2 ) {
                    $parts = str_getcsv( $line, ',' );
                }
                if ( count( $parts ) < 2 ) {
                    continue;
                }

                $date_raw     = trim( $parts[0] );
                $fund_total_raw = trim( $parts[1] );

                if ( $date_raw === '' || $fund_total_raw === '' ) {
                    continue;
                }

                $date = date( 'Y-m-d', strtotime( $date_raw ) );
                $fund_total = (float) str_replace( array( '.', ' ', ',' ), array( '', '', '.' ), $fund_total_raw );
                
                if ( ! $date || $fund_total <= 0 ) {
                    continue;
                }

                $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                    ? ggr_portal_get_total_participations_all_users( $date )
                    : null;

                if ( null === $total_parts || $total_parts <= 0 ) {
                    $skipped++;
                    $skip_msgs[] = sprintf( '%s (geen participaties gevonden)', $date_raw );
                    continue;
                }

                $calculated_price = round( $fund_total / $total_parts, 6 );

                $saved = ggr_upsert_stock_price(
                    $date,
                    $calculated_price,
                    array(
                        'fund_total'           => $fund_total,
                        'total_participations' => $total_parts,
                    )
                );

                if ( $saved ) {
                    $imported++;
                } else {
                    $skipped++;
                    $skip_msgs[] = sprintf( '%s (opslaan mislukt)', $date_raw );                    
                }
            }

            if ( $imported > 0 ) {
                $notice = $imported . ' IBKR totalen geïmporteerd en NAV berekend.';
                if ( $skipped > 0 ) {
                    $notice .= ' ' . $skipped . ' regels overgeslagen: ' . implode( '; ', $skip_msgs );
                }
            } else {
                $error  = 'Geen geldige IBKR totalen gevonden in importbestand.';
                if ( ! empty( $skip_msgs ) ) {
                    $error .= ' Overgeslagen: ' . implode( '; ', $skip_msgs );
                }
            }
        }
    }

    /* -----------------------------------------------------------
     * OPHALEN ALLE SNAPSHOTS
     * --------------------------------------------------------- */
    $rows = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY price_date DESC",
        ARRAY_A
    );

    if ( function_exists( 'ggr_portal_get_total_participations_all_users' ) && ! empty( $rows ) ) {
        foreach ( $rows as &$row ) {
            $needs_backfill = ! array_key_exists( 'total_participations', $row )
                || $row['total_participations'] === null
                || $row['total_participations'] === '';

            if ( $needs_backfill ) {
                $computed_total_parts          = ggr_portal_get_total_participations_all_users( $row['price_date'] );
                $row['total_participations'] = (float) $computed_total_parts;

                $wpdb->update(
                    $table_name,
                    array( 'total_participations' => $computed_total_parts ),
                    array( 'id' => (int) $row['id'] ),
                    array( '%f' ),
                    array( '%d' )
                );
            }
        }
        unset( $row );
    }

    // URLs voor export en delete all
    $export_url = wp_nonce_url(
        add_query_arg(
            array(
                'page'   => 'ggr-stock-price',
                'action' => 'export',
            ),
            admin_url( 'admin.php' )
        ),
        'ggr_export_prices'
    );

    $delete_all_url = wp_nonce_url(
        add_query_arg(
            array(
                'page'   => 'ggr-stock-price',
                'action' => 'delete_all',
            ),
            admin_url( 'admin.php' )
        ),
        'ggr_delete_all_prices'
    );

    ?>
    <div class="wrap">
        <h1>NAV Per Participatie</h1>
        <p>
            Beheer hier de dagelijkse waarde per 1 GGR-participatie. Deze reeks kun je koppelen
            aan de participanten-historie voor echte marktwerking in het portaal.
        </p>

        <p>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">Exporteren (CSV)</a>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a href="<?php echo esc_url( $delete_all_url ); ?>"
                   class="button button-secondary" style="display: none;"
                   onclick="return confirm('Weet je zeker dat je álle GGR-waardes wilt verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                    Alle waardes verwijderen
                </a>
            <?php endif; ?>
        </p>

        <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( $notice ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( $error ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( $total_participations_today !== null ) : ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    Totaal aantal uitgegeven participaties (t/m vandaag):
                    <strong><?php echo esc_html( number_format( $total_participations_today, 4, ',', '.' ) ); ?></strong>
                </p>
            </div>
        <?php endif; ?>

         <?php if ( $show_ibkr_sections ) : ?>
            <h2>IBKR Flex API (automatisch)</h2>
            <p>Vul je Flex Web Service token en Query ID in om dagelijks automatisch de NAV op te halen via de IBKR Flex API. Je kunt ook direct een handmatige import starten.</p>

            <?php if ( ! empty( $ibkr_status ) ) : ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong>Cron status:</strong>
                        <?php if ( ! empty( $ibkr_status['has_credentials'] ) && ! empty( $ibkr_status['next_run'] ) ) : ?>
                            Dagelijkse IBKR import staat ingepland.
                            Volgende run: <strong><?php echo esc_html( wp_date( 'd-m-Y H:i', $ibkr_status['next_run'] ) ); ?></strong>.
                        <?php else : ?>
                            Automatische import staat nog niet ingepland. Vul token en Query ID in en sla op.
                        <?php endif; ?>
                    </p>
                    <?php if ( ! empty( $ibkr_status['last_run'] ) && is_array( $ibkr_status['last_run'] ) ) : ?>
                        <p>
                            Laatste succesvolle import: <strong><?php echo esc_html( $ibkr_status['last_run']['date'] ); ?></strong>
                            (NAV: € <?php echo esc_html( number_format( (float) $ibkr_status['last_run']['nav'], 6, ',', '.' ) ); ?>,
                            bijgewerkt op <?php echo esc_html( wp_date( 'd-m-Y H:i', (int) $ibkr_status['last_run']['timestamp'] ) ); ?>).
                            <?php if ( ! empty( $ibkr_status['last_run']['statement_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $ibkr_status['last_run']['statement_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    Flex statement openen
                                </a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $ibkr_status['last_error'] ) && is_array( $ibkr_status['last_error'] ) ) : ?>
                        <p>
                            Laatste fout: <strong><?php echo esc_html( wp_date( 'd-m-Y H:i', (int) $ibkr_status['last_error']['timestamp'] ) ); ?></strong>
                            (<?php echo esc_html( $ibkr_status['last_error']['message'] ); ?>).
                            <?php if ( ! empty( $ibkr_status['last_error']['statement_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $ibkr_status['last_error']['statement_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    Flex statement openen
                                </a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field( 'ggr_ibkr_credentials' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ggr_ibkr_flex_token">Flex Web Service token</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="ggr_ibkr_flex_token"
                                    name="ggr_ibkr_flex_token"
                                    value="<?php echo esc_attr( $ibkr_token ); ?>"
                                    class="regular-text"
                                />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="ggr_ibkr_flex_query_id">Flex Query ID</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="ggr_ibkr_flex_query_id"
                                    name="ggr_ibkr_flex_query_id"
                                    value="<?php echo esc_attr( $ibkr_query_id ); ?>"
                                    class="regular-text"
                                />
                                <p class="description">
                                    De query moet een Flex-rapport opleveren met NAV per participatie (bijv. Equity Summary).
                                </p>
                            </td>
                        </tr>

                        <?php if ( $ibkr_base_url ) : ?>
                            <tr>
                                <th scope="row">Flex API endpoint</th>
                                <td>
                                    <code><?php echo esc_html( $ibkr_base_url ); ?></code>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php submit_button( 'IBKR instellingen opslaan', 'secondary', 'ggr_ibkr_credentials_submit' ); ?>
            </form>

            <hr />

            <h2>IBKR Flex XML import</h2>
            <p>Plak hier de Flex Query XML. We lezen <code>total</code> en <code>reportDate</code> uit de <code>EquitySummaryByReportDateInBase</code>-node en berekenen de NAV als <code>total / totaal participaties</code>.</p>
            
            <form method="post">
                <?php wp_nonce_field( 'ggr_ibkr_import' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ggr_ibkr_xml">IBKR Flex XML</label></th>
                            <td>
                                <textarea
                                    id="ggr_ibkr_xml"
                                    name="ggr_ibkr_xml"
                                    rows="8"
                                    class="large-text code"
                                ><?php echo esc_textarea( $ibkr_xml_input ); ?></textarea>
                                <p class="description">
                                    Voorbeeld-node: <code>&lt;EquitySummaryByReportDateInBase total="12345" reportDate="20251218" ... /&gt;</code>.
                                </p>
                            </td>
                        </tr>
                        
                        <?php if ( $total_participations_today !== null ) : ?>
                            <tr>
                                <th scope="row">Totaal participaties</th>
                                <td>
                                    <strong><?php echo esc_html( number_format( $total_participations_today, 4, ',', '.' ) ); ?></strong><br />
                                    <span class="description">Bij het verwerken gebruiken we de waarde t/m de rapportdatum uit IBKR.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php submit_button( 'IBKR XML verwerken', 'secondary', 'ggr_ibkr_import_submit' ); ?>
            </form>

            <hr />
        <?php endif; ?>

        <h2><?php echo $is_edit ? 'Waarde bewerken' : 'Nieuwe / bestaande waarde invoeren'; ?></h2>

        <form method="post">
            <?php wp_nonce_field( 'ggr_save_price' ); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="price_date">Datum</label></th>
                        <td>
                            <input
                                type="date"
                                id="price_date"
                                name="price_date"
                                value="<?php echo esc_attr( $form_date ); ?>"
                            />
                            <p class="description">
                                Eén snapshot per dag. Bestaande waarde op deze datum wordt overschreven.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="fund_total">Totaal uit IBKR</label></th>
                        <td>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="fund_total"
                                name="fund_total"
                                value="<?php echo esc_attr( $form_total ); ?>"
                                placeholder="Bijv: 1000000"
                            />
                            <p class="description">
                                We berekenen automatisch de NAV per participatie aan de hand van het totaal en het aantal participaties op deze datum.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php wp_nonce_field( 'ggr_ibkr_manual_fetch' ); ?>
            <p class="submit">
                <?php submit_button( $is_edit ? 'GGR-waarde bijwerken' : 'GGR-waarde opslaan', 'primary', 'ggr_price_submit', false ); ?>
                <?php if ( $show_ibkr_manual_fetch ) : ?>
                    <?php submit_button( 'Laatste Flex statement ophalen', 'secondary', 'ggr_ibkr_manual_fetch_submit', false ); ?>
                <?php endif; ?>
            </p>
        </form>

        <hr />

        <h2>Recente GGR-waardes</h2>

        <h3>Importeer waardes (CSV)</h3>
        <p>Verwacht formaat: <code>date,total</code> (bijvoorbeeld: <code>2025-01-31,1000000</code>). We berekenen automatisch de NAV per participatie op basis van het aantal participaties op die datum. Eerste regel mag een header zijn.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'ggr_import_price' ); ?>
            <input type="file" name="ggr_price_import_file" accept=".csv,text/csv" />
            <?php submit_button( 'Importeren', 'secondary', 'ggr_price_import_submit', false ); ?>
        </form>

        <?php if ( empty( $rows ) ) : ?>
            <p>Er zijn nog geen GGR-waardes opgeslagen.</p>
        <?php else : ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col">Datum</th>
                        <th scope="col">Waarde per 1 GGR-participatie</th>
                        <th scope="col">Totaal uit IBKR</th>
                        <th scope="col">Totaal participaties</th>      
                        <th scope="col">Flex statement</th>                        
                        <th scope="col">Δ t.o.v. vorige (%)</th>
                        <th scope="col">Aangemaakt</th>
                        <th scope="col">Laatst bijgewerkt</th>
                        <th scope="col">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $count = count( $rows );
                    for ( $i = 0; $i < $count; $i++ ) :
                        $row       = $rows[ $i ];
                        $date_raw  = $row['price_date'];
                        $date_disp = date_i18n( 'd-m-Y', strtotime( $date_raw ) );

                        $value           = (float) $row['price_value'];
                        $value_disp      = number_format( $value, 4, ',', '.' );
                        $fund_total      = isset( $row['fund_total'] ) ? (float) $row['fund_total'] : null;
                        $total_parts     = isset( $row['total_participations'] ) ? (float) $row['total_participations'] : null;
                        $statement_url   = isset( $row['statement_url'] ) ? trim( (string) $row['statement_url'] ) : '';                        
                        $fund_total_disp = $fund_total !== null ? number_format( $fund_total, 2, ',', '.' ) : '-';
                        $total_parts_disp = $total_parts !== null ? number_format( $total_parts, 4, ',', '.' ) : '-';

                        // Vorige waarde in de reeks (DESC → volgende index)
                        $diff_disp = '-';
                        if ( $i + 1 < $count ) {
                            $prev_row   = $rows[ $i + 1 ];
                            $prev_value = (float) $prev_row['price_value'];

                            if ( $prev_value > 0 ) {
                                $diff = ( ( $value - $prev_value ) / $prev_value ) * 100;
                                $diff_disp = number_format( $diff, 2, ',', '.' ) . ' %';
                            }
                        }

                        $created_disp = $row['created_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $row['created_at'] ) ) : '';
                        $updated_disp = $row['updated_at'] ? date_i18n( 'd-m-Y H:i', strtotime( $row['updated_at'] ) ) : '';

                        $edit_url = add_query_arg(
                            array(
                                'page'    => 'ggr-stock-price',
                                'edit_id' => (int) $row['id'],
                            ),
                            admin_url( 'admin.php' )
                        );

                        $delete_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'page'   => 'ggr-stock-price',
                                    'action' => 'delete',
                                    'id'     => (int) $row['id'],
                                ),
                                admin_url( 'admin.php' )
                            ),
                            'ggr_delete_price_' . (int) $row['id']
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $date_disp ); ?></td>
                            <td><?php echo esc_html( $value_disp ); ?></td>
                            <td><?php echo esc_html( $fund_total_disp ); ?></td>
                            <td><?php echo esc_html( $total_parts_disp ); ?></td>
                            <td>
                                <?php if ( $statement_url ) : ?>
                                    <a href="<?php echo esc_url( $statement_url ); ?>" target="_blank" rel="noopener noreferrer">
                                        FS downloaden
                                    </a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>                            
                            <td><?php echo esc_html( $diff_disp ); ?></td>
                            <td><?php echo esc_html( $created_disp ); ?></td>
                            <td><?php echo esc_html( $updated_disp ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>">Bewerken</a> |
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   onclick="return confirm('Weet je zeker dat je deze snapshot wilt verwijderen?');">
                                    Verwijderen
                                </a>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ============================================================================
 * 6. REST API – webhook voor Google Sheets
 * ========================================================================== */

/**
 * Registreer endpoint: POST /wp-json/ggr/v1/stock-price
 */
add_action( 'rest_api_init', 'ggr_register_stock_price_endpoint' );

function ggr_register_stock_price_endpoint() {
    register_rest_route(
        'ggr/v1',
        '/stock-price',
        array(
            'methods'             => 'POST',
            'callback'            => 'ggr_api_update_stock_price',
            'permission_callback' => 'ggr_api_authenticate_google_sheet',
        )
    );
}

/**
 * Eenvoudige authenticatie via een gedeelde secret.
 */
function ggr_api_authenticate_google_sheet( WP_REST_Request $request ) {
    $secret = $request->get_header( 'x-ggr-secret' );
    if ( ! $secret ) {
        $secret = $request->get_param( 'secret' );
    }

    $expected = defined( 'GGR_SHEET_WEBHOOK_SECRET' ) ? GGR_SHEET_WEBHOOK_SECRET : '';

    if ( empty( $expected ) ) {
        return new WP_Error(
            'no_secret_configured',
            'Webhook secret is niet geconfigureerd.',
            array( 'status' => 500 )
        );
    }

    if ( ! hash_equals( $expected, (string) $secret ) ) {
        return new WP_Error(
            'forbidden',
            'Ongeldige secret.',
            array( 'status' => 403 )
        );
    }

    return true;
}

/**
 * Callback: datum + prijs ontvangen en opslaan.
 *
 * Verwacht JSON body:
 * {
 *   "date": "2025-11-29",
 *   "price": 99.38
 * }
 */
function ggr_api_update_stock_price( WP_REST_Request $request ) {

    $date_raw  = $request->get_param( 'date' );
    $price_raw = $request->get_param( 'price' );

    // -----------------------------------------
    // 1. Basisvalidatie
    // -----------------------------------------
    if ( empty( $date_raw ) || $price_raw === null ) {
        return new WP_Error(
            'missing_params',
            'Parameters "date" en "price" zijn verplicht.',
            array( 'status' => 400 )
        );
    }

    // -----------------------------------------
    // 2. Datum normaliseren → Y-m-d
    // -----------------------------------------
    if ( function_exists( 'ggr_portal_parse_date_to_mysql' ) ) {
        $date_mysql = ggr_portal_parse_date_to_mysql( $date_raw );
    } else {
        $ts         = strtotime( $date_raw );
        $date_mysql = $ts ? date( 'Y-m-d', $ts ) : '';
    }

    if ( empty( $date_mysql ) ) {
        return new WP_Error(
            'invalid_date',
            'Ongeldige datum: ' . $date_raw,
            array( 'status' => 400 )
        );
    }

    // -----------------------------------------
    // 3. Prijs normaliseren
    //    → Sheets stuurt meestal al een float zoals 99.38
    //    → Fallback: string varianten met € of komma
    // -----------------------------------------
    if ( is_numeric( $price_raw ) ) {

        // Komt als 99.38 of "99.38"
        $price_float = (float) $price_raw;

    } else {

        // Indien Sheets toch text stuurt zoals "€ 99,38"
        $p = trim( (string) $price_raw );
        $p = str_replace( array( '€', ' ' ), '', $p );
        $p = str_replace( ',', '.', $p );

        if ( ! is_numeric( $p ) ) {
            return new WP_Error(
                'invalid_price',
                'Ongeldige prijs: ' . $price_raw,
                array( 'status' => 400 )
            );
        }

        $price_float = (float) $p;
    }

    if ( $price_float <= 0 ) {
        return new WP_Error(
            'invalid_price',
            'Prijs moet groter zijn dan 0.',
            array( 'status' => 400 )
        );
    }

    // -----------------------------------------
    // 4. Opslaan in database (upsert)
    // -----------------------------------------
    $ok = ggr_upsert_stock_price( $date_mysql, $price_float );

    if ( ! $ok ) {
        return new WP_Error(
            'db_error',
            'Kon de GGR-waarde niet opslaan.',
            array( 'status' => 500 )
        );
    }

    // -----------------------------------------
    // 5. Succesresponse
    // -----------------------------------------
    return array(
        'success' => true,
        'date'    => $date_mysql,
        'price'   => $price_float,
    );
}
