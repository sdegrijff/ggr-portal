<?php
/**
 * GGR Audit log
 *
 * - Registreert alle wijzigingen rondom participants
 * - Bewaar actor, rol, datum, actie en detailmeta
 * - Admin overzicht met filters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Versiebeheer voor de tabelstructuur
if ( ! defined( 'GGR_PORTAL_AUDIT_LOG_DB_VERSION' ) ) {
    define( 'GGR_PORTAL_AUDIT_LOG_DB_VERSION', '1.0.0' );
}

/**
 * Database tabel voor audit logging.
 */
function ggr_portal_create_audit_log_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ggr_audit_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            participant_id BIGINT(20) UNSIGNED NOT NULL,
            actor_id BIGINT(20) UNSIGNED NULL,
            actor_role VARCHAR(191) NOT NULL DEFAULT '',
            action VARCHAR(191) NOT NULL,
            description TEXT NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY participant_id (participant_id),
            KEY actor_role (actor_role),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Maak of update de tabel wanneer nodig.
 */
function ggr_portal_maybe_create_audit_log_table() {
    $installed_version = get_option( 'ggr_portal_audit_log_db_version' );

    if ( $installed_version !== GGR_PORTAL_AUDIT_LOG_DB_VERSION ) {
        ggr_portal_create_audit_log_table();
        update_option( 'ggr_portal_audit_log_db_version', GGR_PORTAL_AUDIT_LOG_DB_VERSION );
    }
}
add_action( 'plugins_loaded', 'ggr_portal_maybe_create_audit_log_table' );

/**
 * Haal bruikbare labels op voor weergave.
 */
function ggr_portal_get_audit_field_labels() {
    return array(
        'user_email'                  => 'E-mailadres',
        'roles'                       => 'Rol',
        'display_name'                => 'Weergavenaam',
        'first_name'                  => 'Voornaam',
        'last_name'                   => 'Achternaam',
        'phone'                       => 'Telefoonnummer',
        'ggr_participation_amount'    => 'Investering',
        'ggr_participation_profile'   => 'Profiel',
        'ggr_account_type'            => 'Accounttype',
        'ggr_has_co_participant'      => 'Mede-participant',
        'ggr_marketing_optin'         => 'Marketing opt-in',
        'ggr_payment_received'        => 'Betaling ontvangen',
        'ggr_first_trade_day'         => 'Eerste handelsdag',
        'ggr_onboarding_status'       => 'Onboarding status',
        'ggr_doc_feedback'            => 'Document feedback',
        'address_street'              => 'Adres',
        'address_postcode'            => 'Postcode',
        'address_city'                => 'Plaats',
        'address_country'             => 'Land',
        'bank_account_name'           => 'Rekeninghouder',
        'bank_account_iban'           => 'IBAN',
        'ggr_kyc_birth_date'          => 'Geboortedatum',
        'ggr_kyc_birth_place'         => 'Geboorteplaats',
        'ggr_kyc_birth_country'       => 'Geboorteland',
        'ggr_kyc_nationality'         => 'Nationaliteit',
        'ggr_kyc_bsn'                 => 'BSN',
        'ggr_origin_country'          => 'Land van herkomst',
        'ggr_origin_sources'          => 'Herkomst middelen',
        'ggr_origin_notes'            => 'Toelichting herkomst',
        'locale'                      => 'Taal',
        'ggr_co_first_name'           => 'Mede-participant voornaam',
        'ggr_co_last_name'            => 'Mede-participant achternaam',
        'ggr_co_email'                => 'Mede-participant e-mail',
        'ggr_co_phone'                => 'Mede-participant telefoon',
    );
}

/**
 * Snapshot van belangrijke participantvelden.
 */
function ggr_portal_get_participant_audit_snapshot( $user_id ) {
    $user = get_user_by( 'ID', $user_id );
    $meta = get_user_meta( $user_id );

    $safe_meta = function( $key, $default = '' ) use ( $meta ) {
        return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : $default;
    };

    $origin_sources = get_user_meta( $user_id, 'ggr_origin_sources', true );
    if ( is_array( $origin_sources ) ) {
        sort( $origin_sources );
        $origin_sources = implode( ', ', $origin_sources );
    }

    $onboarding_status = function_exists( 'ggr_onboarding_get_status' )
        ? ggr_onboarding_get_status( $user_id )
        : $safe_meta( 'ggr_onboarding_status' );

    $snapshot = array(
        'user_email'                => $user ? $user->user_email : '',
        'roles'                     => $user ? implode( ', ', (array) $user->roles ) : '',
        'display_name'              => $user ? $user->display_name : '',
        'first_name'                => $safe_meta( 'first_name' ),
        'last_name'                 => $safe_meta( 'last_name' ),
        'phone'                     => $safe_meta( 'phone' ),
        'ggr_participation_amount'  => $safe_meta( 'ggr_participation_amount' ),
        'ggr_participation_profile' => $safe_meta( 'ggr_participation_profile' ),
        'ggr_account_type'          => $safe_meta( 'ggr_account_type' ),
        'ggr_has_co_participant'    => $safe_meta( 'ggr_has_co_participant', 'nee' ),
        'ggr_marketing_optin'       => $safe_meta( 'ggr_marketing_optin', 0 ),
        'ggr_payment_received'      => $safe_meta( 'ggr_payment_received', 0 ),
        'ggr_first_trade_day'       => $safe_meta( 'ggr_first_trade_day' ),
        'ggr_onboarding_status'     => $onboarding_status,
        'ggr_doc_feedback'          => $safe_meta( 'ggr_doc_feedback' ),
        'address_street'            => $safe_meta( 'address_street' ),
        'address_postcode'          => $safe_meta( 'address_postcode' ),
        'address_city'              => $safe_meta( 'address_city' ),
        'address_country'           => $safe_meta( 'address_country' ),
        'bank_account_name'         => $safe_meta( 'bank_account_name' ),
        'bank_account_iban'         => $safe_meta( 'bank_account_iban' ),
        'ggr_kyc_birth_date'        => $safe_meta( 'ggr_kyc_birth_date' ),
        'ggr_kyc_birth_place'       => $safe_meta( 'ggr_kyc_birth_place' ),
        'ggr_kyc_birth_country'     => $safe_meta( 'ggr_kyc_birth_country' ),
        'ggr_kyc_nationality'       => $safe_meta( 'ggr_kyc_nationality' ),
        'ggr_kyc_bsn'               => $safe_meta( 'ggr_kyc_bsn' ),
        'ggr_origin_country'        => $safe_meta( 'ggr_origin_country' ),
        'ggr_origin_sources'        => $origin_sources,
        'ggr_origin_notes'          => $safe_meta( 'ggr_origin_notes' ),
        'locale'                    => $safe_meta( 'locale' ),
        'ggr_co_first_name'         => $safe_meta( 'ggr_co_first_name' ),
        'ggr_co_last_name'          => $safe_meta( 'ggr_co_last_name' ),
        'ggr_co_email'              => $safe_meta( 'ggr_co_email' ),
        'ggr_co_phone'              => $safe_meta( 'ggr_co_phone' ),
    );

    return $snapshot;
}

/**
 * Formatteer waardes voor leesbare output.
 */
function ggr_portal_format_audit_value( $value ) {
    if ( $value === '' || $value === null ) {
        return '—';
    }

    if ( is_bool( $value ) || $value === 0 || $value === '0' || $value === 1 || $value === '1' ) {
        return (string) ( (int) $value === 1 ? 'Ja' : 'Nee' );
    }

    return wp_kses_post( $value );
}

/**
 * Bepaal welke velden gewijzigd zijn tussen twee snapshots.
 */
function ggr_portal_diff_audit_snapshots( $before, $after ) {
    $labels  = ggr_portal_get_audit_field_labels();
    $changes = array();

    foreach ( $after as $key => $new_value ) {
        $old_value = isset( $before[ $key ] ) ? $before[ $key ] : '';

        if ( $old_value === $new_value ) {
            continue;
        }

        $label    = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
        $changes[] = sprintf(
            '%s: "%s" → "%s"',
            $label,
            ggr_portal_format_audit_value( $old_value ),
            ggr_portal_format_audit_value( $new_value )
        );
    }

    return $changes;
}

/**
 * Sla een auditregel op.
 */
function ggr_portal_log_participant_action( $participant_id, $action, $description, $meta = array() ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'ggr_audit_logs';

    $participant_id = (int) $participant_id;
    $actor_id       = get_current_user_id();
    $actor          = $actor_id ? get_user_by( 'ID', $actor_id ) : null;
    $actor_role     = '';

    if ( $actor && ! empty( $actor->roles ) ) {
        $actor_role = implode( ', ', (array) $actor->roles );
    }

    $data = array(
        'participant_id' => $participant_id,
        'actor_id'       => $actor_id ?: null,
        'actor_role'     => $actor_role,
        'action'         => sanitize_key( $action ),
        'description'    => wp_strip_all_tags( $description ),
        'meta'           => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
        'created_at'     => current_time( 'mysql' ),
    );

    $format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

    $wpdb->insert( $table_name, $data, $format );
}

/**
 * Log een set veldaanpassingen.
 */
function ggr_portal_log_participant_profile_changes( $user_id, $before_snapshot, $action = 'profile_update' ) {
    if ( ! function_exists( 'ggr_portal_get_participant_audit_snapshot' ) ) {
        return;
    }

    $after_snapshot = ggr_portal_get_participant_audit_snapshot( $user_id );
    $changes        = ggr_portal_diff_audit_snapshots( $before_snapshot, $after_snapshot );

    if ( empty( $changes ) ) {
        return;
    }

    $description = sprintf( 'Profiel bijgewerkt (%d wijziging(en)).', count( $changes ) );

    ggr_portal_log_participant_action(
        $user_id,
        $action,
        $description,
        array(
            'changes' => $changes,
        )
    );
}

/**
 * Admin menu-item.
 */
add_action( 'admin_menu', 'ggr_portal_register_audit_log_page' );

function ggr_portal_register_audit_log_page() {
    add_users_page(
        'Audit log',
        'Audit log',
        'list_users',
        'ggr-audit-log',
        'ggr_portal_render_audit_log_page'
    );
}

/**
 * Hulp: haal alle beschikbare acties voor filters.
 */
function ggr_portal_get_audit_action_labels() {
    return array(
        'profile_update'    => 'Profiel',
        'password_reset'    => 'Wachtwoord',
        'document_review'   => 'Documentcontrole',
    );
}

/**
 * Toon audit log overzicht.
 */
function ggr_portal_render_audit_log_page() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_die( 'Geen toegang.' );
    }

    global $wpdb;

    $table_name   = $wpdb->prefix . 'ggr_audit_logs';
    $action_labels = ggr_portal_get_audit_action_labels();
    $roles         = array_keys( get_editable_roles() );

    $filters = array(
        'participant' => isset( $_GET['participant'] ) ? sanitize_text_field( wp_unslash( $_GET['participant'] ) ) : '',
        'actor_role'  => isset( $_GET['actor_role'] ) ? sanitize_text_field( wp_unslash( $_GET['actor_role'] ) ) : '',
        'action'      => isset( $_GET['action_filter'] ) ? sanitize_key( wp_unslash( $_GET['action_filter'] ) ) : '',
        'date_from'   => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
        'date_to'     => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
        'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
    );

    $where  = array();
    $params = array();

    if ( $filters['participant'] !== '' ) {
        if ( is_numeric( $filters['participant'] ) ) {
            $where[]  = 'participant_id = %d';
            $params[] = (int) $filters['participant'];
        } else {
            $user = get_user_by( 'email', $filters['participant'] );
            if ( $user ) {
                $where[]  = 'participant_id = %d';
                $params[] = (int) $user->ID;
            }
        }
    }

    if ( $filters['actor_role'] !== '' ) {
        $where[]  = 'actor_role LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $filters['actor_role'] ) . '%';
    }

    if ( $filters['action'] !== '' ) {
        $where[]  = 'action = %s';
        $params[] = $filters['action'];
    }

    if ( $filters['date_from'] !== '' ) {
        $where[]  = 'created_at >= %s';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if ( $filters['date_to'] !== '' ) {
        $where[]  = 'created_at <= %s';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    if ( $filters['search'] !== '' ) {
        $where[]  = '(description LIKE %s OR meta LIKE %s)';
        $like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $where_sql = '';

    if ( ! empty( $where ) ) {
        $where_sql = 'WHERE ' . implode( ' AND ', $where );
    }

    $sql = "SELECT * FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT 200";

    $logs = ! empty( $params )
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
        : $wpdb->get_results( $sql, ARRAY_A );
    ?>
    <div class="wrap">
        <h1>Audit log</h1>

        <form method="get" style="margin: 20px 0;" class="ggr-audit-log-filters">
            <input type="hidden" name="page" value="ggr-audit-log" />

            <label style="margin-right: 10px;">
                Participant (ID of e-mail)
                <input type="text" name="participant" value="<?php echo esc_attr( $filters['participant'] ); ?>" />
            </label>

            <label style="margin-right: 10px;">
                Actor rol
                <select name="actor_role">
                    <option value="">— Alles —</option>
                    <?php foreach ( $roles as $role_key ) : ?>
                        <option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $filters['actor_role'], $role_key ); ?>>
                            <?php echo esc_html( $role_key ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="margin-right: 10px;">
                Actie
                <select name="action_filter">
                    <option value="">— Alles —</option>
                    <?php foreach ( $action_labels as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['action'], $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="margin-right: 10px;">
                Datum vanaf
                <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
            </label>

            <label style="margin-right: 10px;">
                Datum t/m
                <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
            </label>

            <label style="margin-right: 10px;">
                Zoeken
                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" />
            </label>

            <button class="button button-primary" type="submit">Filter toepassen</button>
        </form>

        <?php if ( empty( $logs ) ) : ?>
            <p>Geen resultaten gevonden.</p>
            <?php return; endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Participant</th>
                    <th>Actor</th>
                    <th>Rol</th>
                    <th>Actie</th>
                    <th>Beschrijving</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $row ) :
                    $participant = $row['participant_id'] ? get_user_by( 'ID', (int) $row['participant_id'] ) : null;
                    $actor       = $row['actor_id'] ? get_user_by( 'ID', (int) $row['actor_id'] ) : null;
                    $meta        = $row['meta'] ? json_decode( $row['meta'], true ) : array();
                    ?>
                    <tr>
                        <td><?php echo esc_html( date_i18n( 'd-m-Y H:i', strtotime( $row['created_at'] ) ) ); ?></td>
                        <td>
                            <?php if ( $participant ) : ?>
                                <?php echo esc_html( $participant->display_name ); ?><br />
                                <small>ID: <?php echo (int) $participant->ID; ?></small>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $actor ) : ?>
                                <?php echo esc_html( $actor->display_name ); ?><br />
                                <small><?php echo esc_html( $actor->user_email ); ?></small>
                            <?php else : ?>
                                Systeem
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['actor_role'] ?: '—' ); ?></td>
                        <td><?php echo esc_html( isset( $action_labels[ $row['action'] ] ) ? $action_labels[ $row['action'] ] : $row['action'] ); ?></td>
                        <td><?php echo esc_html( $row['description'] ); ?></td>
                        <td>
                            <?php
                            if ( ! empty( $meta['changes'] ) && is_array( $meta['changes'] ) ) {
                                echo '<ul style="margin:0; padding-left: 18px;">';
                                foreach ( $meta['changes'] as $change_line ) {
                                    echo '<li>' . wp_kses_post( $change_line ) . '</li>';
                                }
                                echo '</ul>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
