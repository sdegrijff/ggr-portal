<?php
/**
 * GGR Portal – Email delivery tracking
 *
 * - Logt verzonden e-mails via wp_mail()
 * - Adminpagina met overzicht van afleverstatus
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GGR_PORTAL_EMAIL_DELIVERY_DB_VERSION' ) ) {
    define( 'GGR_PORTAL_EMAIL_DELIVERY_DB_VERSION', '1.0.0' );
}

/**
 * Database tabel voor e-mail delivery logs.
 */
function ggr_portal_create_email_delivery_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_email_delivery_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(32) NOT NULL DEFAULT 'success',
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            error_message TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY sent_at (sent_at)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Maak of update de tabel wanneer nodig.
 */
function ggr_portal_maybe_create_email_delivery_table() {
    $installed_version = get_option( 'ggr_portal_email_delivery_db_version' );

    if ( $installed_version !== GGR_PORTAL_EMAIL_DELIVERY_DB_VERSION ) {
        ggr_portal_create_email_delivery_table();
        update_option( 'ggr_portal_email_delivery_db_version', GGR_PORTAL_EMAIL_DELIVERY_DB_VERSION );
    }
}
add_action( 'plugins_loaded', 'ggr_portal_maybe_create_email_delivery_table' );

/**
 * Voeg een logregel toe.
 */
function ggr_portal_log_email_delivery( $mail_args, $status, $error_message = '' ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_email_delivery_logs';

    $recipients = '';
    if ( isset( $mail_args['to'] ) ) {
        if ( is_array( $mail_args['to'] ) ) {
            $recipients = implode( ', ', array_map( 'sanitize_email', $mail_args['to'] ) );
        } else {
            $recipients = sanitize_email( $mail_args['to'] );
        }
    }

    $subject = isset( $mail_args['subject'] ) ? wp_strip_all_tags( $mail_args['subject'] ) : '';

    $wpdb->insert(
        $table_name,
        array(
            'status'        => sanitize_key( $status ),
            'recipient'     => $recipients,
            'subject'       => $subject,
            'error_message' => $error_message ? wp_strip_all_tags( $error_message ) : null,
            'sent_at'       => current_time( 'mysql' ),
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );
}

/**
 * Succesvolle e-mails loggen.
 */
function ggr_portal_handle_mail_success( $mail_args ) {
    ggr_portal_log_email_delivery( $mail_args, 'success' );
}
add_action( 'wp_mail_succeeded', 'ggr_portal_handle_mail_success', 10, 1 );

/**
 * Mislukte e-mails loggen.
 */
function ggr_portal_handle_mail_failure( $error ) {
    if ( ! $error instanceof WP_Error ) {
        return;
    }

    $mail_args = $error->get_error_data( 'wp_mail_failed' );
    if ( ! is_array( $mail_args ) ) {
        $mail_args = array();
    }

    $error_message = $error->get_error_message();

    ggr_portal_log_email_delivery( $mail_args, 'failed', $error_message );
}
add_action( 'wp_mail_failed', 'ggr_portal_handle_mail_failure', 10, 1 );

/**
 * Admin-menu voor delivery tracking.
 */
add_action( 'admin_menu', 'ggr_portal_register_track_delivery_page' );

function ggr_portal_register_track_delivery_page() {
    add_menu_page(
        'Track delivery',
        'Track delivery',
        'read',
        'ggr-track-delivery',
        'ggr_portal_render_track_delivery_page',
        'dashicons-email-alt',
        59
    );
}

function ggr_portal_track_delivery_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'list_users' );
}

/**
 * Adminpagina: delivery overzicht.
 */
function ggr_portal_render_track_delivery_page() {
    if ( ! ggr_portal_track_delivery_user_can_access() ) {
        wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'ggr-portal-core' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'ggr_email_delivery_logs';

    $status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $allowed_statuses = array( 'success', 'failed' );

    $query = "SELECT * FROM {$table_name}";
    $args  = array();

    if ( $status_filter && in_array( $status_filter, $allowed_statuses, true ) ) {
        $query .= ' WHERE status = %s';
        $args[] = $status_filter;
    }

    $query .= ' ORDER BY sent_at DESC LIMIT 250';

    $logs = $args ? $wpdb->get_results( $wpdb->prepare( $query, $args ) ) : $wpdb->get_results( $query );

    $date_format = get_option( 'date_format' );
    $time_format = get_option( 'time_format' );

    echo '<div class="wrap ggr-track-delivery">';
    echo '<h1>Track delivery</h1>';
    echo '<p>Overzicht van verzonden e-mails via het portal, inclusief afleverstatus, ontvanger en datum.</p>';

    echo '<form method="get" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="ggr-track-delivery" />';
    echo '<label for="ggr-track-delivery-status" style="margin-right:8px;">Status</label>';
    echo '<select id="ggr-track-delivery-status" name="status">';
    echo '<option value="">Alles</option>';
    echo '<option value="success"' . selected( $status_filter, 'success', false ) . '>Success</option>';
    echo '<option value="failed"' . selected( $status_filter, 'failed', false ) . '>Failed</option>';
    echo '</select>';
    echo '<button class="button" type="submit" style="margin-left:8px;">Filter</button>';
    echo '</form>';

    if ( empty( $logs ) ) {
        echo '<p>Geen e-mails gevonden.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Status</th>';
    echo '<th>Ontvanger</th>';
    echo '<th>Onderwerp</th>';
    echo '<th>Datum</th>';
    echo '</tr></thead><tbody>';

    foreach ( $logs as $log ) {
        $status_label = $log->status === 'success' ? 'Success' : 'Failed';
        $status_class = $log->status === 'success' ? 'ggr-track-delivery-status--success' : 'ggr-track-delivery-status--failed';
        $sent_label   = $log->sent_at ? date_i18n( $date_format . ' ' . $time_format, strtotime( $log->sent_at ) ) : '—';

        echo '<tr>';
        echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td>';
        echo '<td>' . esc_html( $log->recipient ? $log->recipient : '—' ) . '</td>';
        echo '<td>' . esc_html( $log->subject ? $log->subject : '—' ) . '</td>';
        echo '<td>' . esc_html( $sent_label ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
