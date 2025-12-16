<?php
/**
 * GGR Participants module
 *
 * - Participatie-historie (DB + adminscherm)
 * - Berekeningen (positiewaarde, rendement, etc.)
 * - Shortcodes voor laatste stand
 * - Participant-profiel adminpagina
 * - Participant-overzicht adminpagina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. DATABASE-TABEL VOOR PARTICIPATIE-HISTORIE
 */
function ggr_portal_create_history_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'user_participatie_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            datum DATE NOT NULL,
            transactie_code VARCHAR(64) NOT NULL DEFAULT '',
            inlegbedrag DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            opnamebedrag DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            nieuwe_participaties DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            verkochte_participaties DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            distributievergoeding DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY datum (datum),
            KEY transactie_code (transactie_code)
        ) {$charset_collate};
    ";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Helper: unieke transactiecode genereren
 */
function ggr_portal_generate_transactie_code( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! function_exists( 'wp_generate_password' ) ) {
        require_once ABSPATH . 'wp-includes/pluggable.php';
    }

    $random = wp_generate_password( 6, false, false );
    $time   = time();

    // Voorbeeld: TX-123-1710765432-ABC123
    return 'TX-' . $user_id . '-' . $time . '-' . $random;
}

/**
 * Helper: datum naar MySQL-formaat (YYYY-MM-DD)
 */
function ggr_portal_parse_date_to_mysql( $raw ) {
    $raw = trim( (string) $raw );
    if ( $raw === '' ) {
        return '';
    }

    $raw = str_replace( '/', '-', $raw );

    $dt = null;

    // 1) Al in Y-m-d formaat?
    if ( preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$/', $raw ) ) {
        $dt = DateTime::createFromFormat( 'Y-m-d', $raw );
    }
    // 2) d-m-Y
    elseif ( preg_match( '/^\d{1,2}-\d{1,2}-\d{4}$/', $raw ) ) {
        $dt = DateTime::createFromFormat( 'd-m-Y', $raw );
    } else {
        return '';
    }

    if ( ! $dt ) {
        return '';
    }

    return $dt->format( 'Y-m-d' );
}

/**
 * 2. HISTORIE CRUD HELPERS
 */

/**
 * Nieuwe rij toevoegen
 *
 * LET OP:
 * - Er wordt NIET meer per user een bericht aangemaakt.
 * - Een centrale maand-notificatie (indien nodig) gebeurt elders in de messages-module.
 */
function ggr_portal_add_history_entry( $user_id, $datum, $inlegbedrag, $opnamebedrag, $nieuwe, $verkochte, $distributie, $options = array() ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';

    // Datum forceren naar Y-m-d
    $datum_mysql = ggr_portal_parse_date_to_mysql( $datum );
    if ( $datum_mysql === '' ) {
        return false;
    }

    $user_id      = (int) $user_id;
    $inlegbedrag  = (float) str_replace( ',', '.', $inlegbedrag );
    $opnamebedrag = (float) str_replace( ',', '.', $opnamebedrag );
    $nieuwe       = (float) str_replace( ',', '.', $nieuwe );
    $verkochte    = (float) str_replace( ',', '.', $verkochte );
    $distributie  = (float) str_replace( ',', '.', $distributie );

    $transactie_code = ggr_portal_generate_transactie_code( $user_id );

    $data = array(
        'user_id'                 => $user_id,
        'datum'                   => $datum_mysql,
        'transactie_code'         => $transactie_code,
        'inlegbedrag'             => $inlegbedrag,
        'opnamebedrag'            => $opnamebedrag,
        'nieuwe_participaties'    => $nieuwe,
        'verkochte_participaties' => $verkochte,
        'distributievergoeding'   => $distributie,
        'created_at'              => current_time( 'mysql' ),
    );

    $formats = array( '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%s' );

    $inserted = $wpdb->insert( $table_name, $data, $formats );
    if ( $inserted === false ) {
        return false;
    }

    // Automatische melding bij een (extra) inleg.
    if ( function_exists( 'ggr_meldingen_add' ) && $inlegbedrag > 0 ) {
        ggr_meldingen_add(
            'Nieuwe inleg geregistreerd',
            sprintf( 'Er is een inleg van %s toegevoegd voor %s.', ggrp_fe_format_money( $inlegbedrag ), ggr_portal_get_nice_user_name( $user_id ) ),
            $user_id,
            array(
                'melding_type' => 'inleg',
            )
        );
    }

    return true;
}

/**
 * Historie voor één user
 */
function ggr_portal_get_history_for_user( $user_id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';
    $user_id    = (int) $user_id;

    $sql = $wpdb->prepare(
        "SELECT *
         FROM {$table_name}
         WHERE user_id = %d
         ORDER BY datum ASC, id ASC",
        $user_id
    );

    return $wpdb->get_results( $sql );
}

/**
 * Specifieke regel
 */
function ggr_portal_get_history_entry( $id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';
    $id         = (int) $id;

    $sql = $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d",
        $id
    );

    return $wpdb->get_row( $sql );
}

/**
 * Bestaande regel bijwerken
 */
function ggr_portal_update_history_entry( $id, $datum, $inlegbedrag, $opnamebedrag, $nieuwe, $verkochte, $distributie ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';

    $id = (int) $id;

    $datum_mysql = ggr_portal_parse_date_to_mysql( $datum );
    if ( $datum_mysql === '' ) {
        return false;
    }

    $inlegbedrag  = (float) str_replace( ',', '.', $inlegbedrag );
    $opnamebedrag = (float) str_replace( ',', '.', $opnamebedrag );
    $nieuwe       = (float) str_replace( ',', '.', $nieuwe );
    $verkochte    = (float) str_replace( ',', '.', $verkochte );
    $distributie  = (float) str_replace( ',', '.', $distributie );

    $data = array(
        'datum'                   => $datum_mysql,
        'inlegbedrag'             => $inlegbedrag,
        'opnamebedrag'            => $opnamebedrag,
        'nieuwe_participaties'    => $nieuwe,
        'verkochte_participaties' => $verkochte,
        'distributievergoeding'   => $distributie,
    );

    $formats = array( '%s', '%f', '%f', '%f', '%f', '%f' );

    $updated = $wpdb->update(
        $table_name,
        $data,
        array( 'id' => $id ),
        $formats,
        array( '%d' )
    );

    return $updated !== false;
}

/**
 * Historie-regel verwijderen
 */
function ggr_portal_delete_history_entry( $id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';
    $id         = (int) $id;

    return (bool) $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
}

/**
 * Alle historie voor user verwijderen
 */
function ggr_portal_delete_all_history_for_user( $user_id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'user_participatie_history';
    $user_id    = (int) $user_id;

    if ( ! $user_id ) {
        return false;
    }

    $deleted = $wpdb->delete( $table_name, array( 'user_id' => $user_id ), array( '%d' ) );

    return $deleted !== false;
}

/**
 * 3. EXPORT HANDLER
 */
add_action( 'admin_post_ggr_export_history', 'ggr_portal_handle_export_history' );

function ggr_portal_handle_export_history() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang.' );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ggr_export_history' ) ) {
        wp_die( 'Ongeldige export-aanvraag.' );
    }

    $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
    if ( ! $user_id ) {
        wp_die( 'Geen gebruiker meegegeven.' );
    }

    $history = ggr_portal_get_history_for_user( $user_id );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=participatie-historie-user-' . $user_id . '.csv' );

    $output = fopen( 'php://output', 'w' );

    fputcsv(
        $output,
        array(
            'id',
            'user_id',
            'transactie_code',
            'datum',
            'inlegbedrag',
            'opnamebedrag',
            'nieuwe_participaties',
            'verkochte_participaties',
            'distributievergoeding',
        )
    );

    if ( $history ) {
        foreach ( $history as $row ) {
            fputcsv(
                $output,
                array(
                    $row->id,
                    $row->user_id,
                    $row->transactie_code,
                    $row->datum,
                    $row->inlegbedrag,
                    $row->opnamebedrag,
                    $row->nieuwe_participaties,
                    $row->verkochte_participaties,
                    $row->distributievergoeding,
                )
            );
        }
    }

    fclose( $output );
    exit;
}

/**
 * 4. ADMIN-PAGINA: PARTICIPATIE HISTORIE
 */
add_action( 'admin_menu', 'ggr_portal_register_history_page' );

function ggr_portal_register_history_page() {
    add_users_page(
        'Participatie historie',
        'Participatie historie',
        'manage_options',
        'ggr-participatie-historie',
        'ggr_portal_render_history_page'
    );
}

function ggr_portal_render_history_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Geen toegang.' );
    }

    $message = '';
    $error   = '';

    $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
    $edit_id = isset( $_GET['edit_id'] ) ? (int) $_GET['edit_id'] : 0;
    $entry   = null;

    /**
     * 4.1 Import verwerken (CSV upload met delimiter-detectie , of ;)
     */
    if (
        isset( $_POST['ggr_import_nonce'] )
        && wp_verify_nonce( $_POST['ggr_import_nonce'], 'ggr_import_history' )
    ) {
        $import_user_id = isset( $_POST['import_user_id'] ) ? (int) $_POST['import_user_id'] : 0;

        if ( ! $import_user_id ) {
            $error = 'Geen gebruiker geselecteerd voor import.';
        } elseif ( ! isset( $_FILES['ggr_import_file'] ) || empty( $_FILES['ggr_import_file']['tmp_name'] ) ) {
            $error = 'Geen bestand geselecteerd voor import.';
        } elseif ( $_FILES['ggr_import_file']['error'] !== UPLOAD_ERR_OK ) {
            $error = 'Upload mislukt.';
        } else {
            $tmp_name = $_FILES['ggr_import_file']['tmp_name'];
            $handle   = fopen( $tmp_name, 'r' );

            if ( ! $handle ) {
                $error = 'Bestand kon niet worden geopend.';
            } else {
                // delimiter bepalen o.b.v. eerste regel
                $firstLine = fgets( $handle );
                if ( $firstLine === false ) {
                    $error = 'Leeg bestand.';
                    fclose( $handle );
                } else {
                    $semicolon_count = substr_count( $firstLine, ';' );
                    $comma_count     = substr_count( $firstLine, ',' );
                    $delimiter       = ',';

                    if ( $semicolon_count > $comma_count ) {
                        $delimiter = ';';
                    }

                    // Terug naar begin bestand
                    rewind( $handle );

                    $rows_imported = 0;
                    $row_index     = 0;

                    while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
                        $row_index++;

                        // Header overslaan (eerste regel met 'datum' in kolom 3)
                        if ( $row_index === 1 ) {
                            if ( isset( $data[3] ) && stripos( $data[3], 'datum' ) !== false ) {
                                continue;
                            }
                        }

                        // minimaal 9 kolommen
                        if ( count( $data ) < 9 ) {
                            continue;
                        }

                        $csv_user_id = (int) $data[1];

                        // Alleen rijen voor de geselecteerde user
                        if ( $csv_user_id && $csv_user_id !== $import_user_id ) {
                            continue;
                        }

                        $datum_raw   = trim( $data[3] );
                        $inleg       = trim( $data[4] );
                        $opname      = trim( $data[5] );
                        $nieuwe      = trim( $data[6] );
                        $verkochte   = trim( $data[7] );
                        $distributie = trim( $data[8] );

                        if ( ! $datum_raw ) {
                            continue;
                        }

                        $ok = ggr_portal_add_history_entry(
                            $import_user_id,
                            $datum_raw,
                            $inleg,
                            $opname,
                            $nieuwe,
                            $verkochte,
                            $distributie
                        );

                        if ( $ok ) {
                            $rows_imported++;
                        }
                    }

                    fclose( $handle );

                    if ( $rows_imported > 0 ) {
                        $message = sprintf( '%d regels geïmporteerd.', $rows_imported );
                        $user_id = $import_user_id; // blijf op dezelfde user
                    } else {
                        $error = 'Geen geldige regels geïmporteerd.';
                    }
                }
            }
        }
    }

    /**
     * 4.2 Verwijderen (één regel) verwerken (POST)
     */
    if ( isset( $_POST['ggr_delete_history_nonce'] ) && wp_verify_nonce( $_POST['ggr_delete_history_nonce'], 'ggr_delete_history_action' ) ) {

        $user_id   = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
        $delete_id = isset( $_POST['delete_id'] ) ? (int) $_POST['delete_id'] : 0;
        $confirm   = isset( $_POST['confirm'] ) ? sanitize_text_field( $_POST['confirm'] ) : '';

        $delete_entry = $delete_id ? ggr_portal_get_history_entry( $delete_id ) : null;

        if ( $delete_entry && $user_id && (int) $delete_entry->user_id === $user_id ) {
            if ( $confirm === 'yes' ) {
                $ok = ggr_portal_delete_history_entry( $delete_id );
                if ( $ok ) {
                    $message = 'Historie-regel is verwijderd.';
                } else {
                    $error = 'Verwijderen mislukt.';
                }
            } else {
                $message = 'Verwijderen geannuleerd.';
            }
        } else {
            $error = 'Ongeldige verwijder-aanvraag.';
        }
    }

    /**
     * 4.3 Verwerken: alle historie verwijderen (POST)
     */
    if ( isset( $_POST['ggr_delete_all_history_nonce'] ) && wp_verify_nonce( $_POST['ggr_delete_all_history_nonce'], 'ggr_delete_all_history_action' ) ) {

        $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
        $confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( $_POST['confirm'] ) : '';

        if ( $user_id && $confirm === 'yes' ) {
            $ok = ggr_portal_delete_all_history_for_user( $user_id );
            if ( $ok ) {
                $message = 'Alle historie voor deze gebruiker is verwijderd.';
            } else {
                $error = 'Verwijderen van alle historie is mislukt.';
            }
        } elseif ( $user_id && $confirm === 'no' ) {
            $message = 'Verwijderen van alle historie geannuleerd.';
        } else {
            $error = 'Ongeldige aanvraag voor verwijderen van alle historie.';
        }
    }

    /**
     * 4.4 Bewerken: entry ophalen
     */
    if ( $edit_id ) {
        $entry = ggr_portal_get_history_entry( $edit_id );
        if ( $entry && $user_id && (int) $entry->user_id !== $user_id ) {
            $entry   = null;
            $edit_id = 0;
        }
    }

    /**
     * 4.5 Verwijder-aanvraag via GET → confirm UI (één regel)
     */
    $delete_id    = isset( $_GET['delete_id'] ) ? (int) $_GET['delete_id'] : 0;
    $delete_entry = null;

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $delete_id
        && isset( $_GET['_ggrdelnonce'] )
        && wp_verify_nonce( $_GET['_ggrdelnonce'], 'ggr_delete_history' )
    ) {
        $delete_entry = ggr_portal_get_history_entry( $delete_id );
        if ( $delete_entry && $user_id && (int) $delete_entry->user_id !== $user_id ) {
            $delete_entry = null;
            $delete_id    = 0;
        }
    } else {
        $delete_id    = 0;
        $delete_entry = null;
    }

    /**
     * 4.6 Verwijder alle historie via GET → confirm UI
     */
    $delete_all_request = 0;
    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && $user_id
        && isset( $_GET['delete_all'] )
        && (int) $_GET['delete_all'] === 1
        && isset( $_GET['_ggrdelallnonce'] )
        && wp_verify_nonce( $_GET['_ggrdelallnonce'], 'ggr_delete_all_history' )
    ) {
        $delete_all_request = 1;
    }

    /**
     * 4.7 Opslaan / bijwerken (nieuw + edit)
     */
    if ( isset( $_POST['ggr_history_nonce'] ) && wp_verify_nonce( $_POST['ggr_history_nonce'], 'ggr_save_history' ) ) {

        $user_id  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        $datum          = isset( $_POST['datum'] ) ? sanitize_text_field( $_POST['datum'] ) : '';
        $inlegbedrag    = isset( $_POST['inlegbedrag'] ) ? sanitize_text_field( $_POST['inlegbedrag'] ) : '';
        $opnamebedrag   = isset( $_POST['opnamebedrag'] ) ? sanitize_text_field( $_POST['opnamebedrag'] ) : '';
        $nieuwe         = isset( $_POST['nieuwe_participaties'] ) ? sanitize_text_field( $_POST['nieuwe_participaties'] ) : '';
        $verkochte      = isset( $_POST['verkochte_participaties'] ) ? sanitize_text_field( $_POST['verkochte_participaties'] ) : '';
        $distributie    = isset( $_POST['distributievergoeding'] ) ? sanitize_text_field( $_POST['distributievergoeding'] ) : '';

        if ( $user_id && $datum ) {

            if ( $entry_id ) {
                $ok = ggr_portal_update_history_entry(
                    $entry_id,
                    $datum,
                    $inlegbedrag,
                    $opnamebedrag,
                    $nieuwe,
                    $verkochte,
                    $distributie
                );

                if ( $ok ) {
                    $message = 'Historie-regel bijgewerkt.';
                    $edit_id = 0;
                    $entry   = null;
                } else {
                    $error = 'Bijwerken mislukt (controleer ook de datum-invoer).';
                }

            } else {
                $ok = ggr_portal_add_history_entry(
                    $user_id,
                    $datum,
                    $inlegbedrag,
                    $opnamebedrag,
                    $nieuwe,
                    $verkochte,
                    $distributie
                );

                if ( $ok ) {
                    $message = 'Historie-regel opgeslagen.';
                } else {
                    $error = 'Opslaan mislukt (controleer ook de datum-invoer).';
                }
            }

        } else {
            $error = 'Minimaal gebruiker en datum zijn verplicht.';
        }
    }

    // Export URL
    $export_url = '';
    if ( $user_id ) {
        $export_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'  => 'ggr_export_history',
                    'user_id' => $user_id,
                ],
                admin_url( 'admin-post.php' )
            ),
            'ggr_export_history'
        );
    }

    // Delete-all URL
    $delete_all_url = '';
    if ( $user_id ) {
        $delete_all_url = wp_nonce_url(
            add_query_arg(
                [
                    'page'       => 'ggr-participatie-historie',
                    'user_id'    => $user_id,
                    'delete_all' => 1,
                ],
                admin_url( 'users.php' )
            ),
            'ggr_delete_all_history',
            '_ggrdelallnonce'
        );
    }

    ?>
    <div class="wrap">
        <h1>Participatie historie</h1>

        <?php if ( $message ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="ggr-participatie-historie" />
            <label for="user_id">Kies gebruiker:</label>
            <?php
            wp_dropdown_users( [
                'name'              => 'user_id',
                'selected'          => $user_id,
                'show_option_none'  => '— selecteer een gebruiker —',
                'show'              => 'display_name',
            ] );
            ?>
            <button class="button button-primary" type="submit">Laden</button>
        </form>

        <?php if ( $user_id ) : ?>

            <?php
            $user    = get_user_by( 'ID', $user_id );
            $is_edit = ( $entry && $edit_id );
            ?>

            <h2>Historie voor: <?php echo esc_html( $user->display_name ); ?> (ID: <?php echo (int) $user_id; ?>)</h2>

            <?php if ( $delete_entry ) : ?>
                <div class="notice notice-warning" style="padding:15px; margin-bottom:20px;">
                    <p><strong>Weet je zeker dat je deze historie-regel wilt verwijderen?</strong></p>
                    <p>
                        Transactie ID: <?php echo esc_html( $delete_entry->transactie_code ); ?><br>
                        Datum: <?php echo esc_html( $delete_entry->datum ); ?><br>
                        Inleg (BIJ): € <?php echo number_format( $delete_entry->inlegbedrag, 2, ',', '.' ); ?><br>
                        Opname (AF): € <?php echo number_format( $delete_entry->opnamebedrag, 2, ',', '.' ); ?><br>
                        Nieuwe participaties (BIJ): <?php echo number_format( $delete_entry->nieuwe_participaties, 4, ',', '.' ); ?><br>
                        Verkochte participaties (AF): <?php echo number_format( $delete_entry->verkochte_participaties, 4, ',', '.' ); ?><br>
                        Distributievergoeding: € <?php echo number_format( $delete_entry->distributievergoeding, 2, ',', '.' ); ?>
                    </p>
                    <form method="post" style="display:inline-block; margin-right:10px;">
                        <?php wp_nonce_field( 'ggr_delete_history_action', 'ggr_delete_history_nonce' ); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
                        <input type="hidden" name="delete_id" value="<?php echo (int) $delete_entry->id; ?>" />
                        <input type="hidden" name="confirm" value="yes" />
                        <button type="submit" class="button button-primary">Ja, verwijderen</button>
                    </form>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'ggr_delete_history_action', 'ggr_delete_history_nonce' ); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
                        <input type="hidden" name="delete_id" value="<?php echo (int) $delete_entry->id; ?>" />
                        <input type="hidden" name="confirm" value="no" />
                        <button type="submit" class="button">Nee, annuleren</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ( $delete_all_request && $user_id ) : ?>
                <div class="notice notice-warning" style="padding:15px; margin-bottom:20px;">
                    <p><strong>Weet je zeker dat je <u>alle</u> historie voor deze gebruiker wilt verwijderen?</strong></p>
                    <p>Dit kan niet ongedaan worden gemaakt.</p>
                    <form method="post" style="display:inline-block; margin-right:10px;">
                        <?php wp_nonce_field( 'ggr_delete_all_history_action', 'ggr_delete_all_history_nonce' ); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
                        <input type="hidden" name="confirm" value="yes" />
                        <button type="submit" class="button button-primary">Ja, alles verwijderen</button>
                    </form>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'ggr_delete_all_history_action', 'ggr_delete_all_history_nonce' ); ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
                        <input type="hidden" name="confirm" value="no" />
                        <button type="submit" class="button">Nee, annuleren</button>
                    </form>
                </div>
            <?php endif; ?>

            <h3><?php echo $is_edit ? 'Historie-regel bewerken' : 'Nieuwe regel toevoegen'; ?></h3>

            <form method="post" style="max-width:800px;">
                <?php wp_nonce_field( 'ggr_save_history', 'ggr_history_nonce' ); ?>
                <input type="hidden" name="page" value="ggr-participatie-historie" />
                <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
                <input type="hidden" name="entry_id" value="<?php echo $is_edit ? (int) $entry->id : 0; ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="datum">Datum</label></th>
                        <td>
                            <input
                                type="date"
                                name="datum"
                                id="datum"
                                required
                                value="<?php echo $is_edit ? esc_attr( $entry->datum ) : ''; ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="inlegbedrag">Inlegbedrag (BIJ) (€)</label></th>
                        <td>
                            <input
                                type="text"
                                name="inlegbedrag"
                                id="inlegbedrag"
                                placeholder="bijv. 100000.00"
                                value="<?php echo $is_edit ? esc_attr( $entry->inlegbedrag ) : ''; ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="opnamebedrag">Opnamebedrag (AF) (€)</label></th>
                        <td>
                            <input
                                type="text"
                                name="opnamebedrag"
                                id="opnamebedrag"
                                placeholder="bijv. 0.00"
                                value="<?php echo $is_edit ? esc_attr( $entry->opnamebedrag ) : ''; ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="nieuwe_participaties">Nieuwe participaties (BIJ)</label></th>
                        <td>
                            <input
                                type="text"
                                name="nieuwe_participaties"
                                id="nieuwe_participaties"
                                placeholder="bijv. 1.4131"
                                value="<?php echo $is_edit ? esc_attr( $entry->nieuwe_participaties ) : ''; ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="verkochte_participaties">Verkochte participaties (AF)</label></th>
                        <td>
                            <input
                                type="text"
                                name="verkochte_participaties"
                                id="verkochte_participaties"
                                placeholder="bijv. 0.0000"
                                value="<?php echo $is_edit ? esc_attr( $entry->verkochte_participaties ) : ''; ?>"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="distributievergoeding">Distributievergoeding (€)</label></th>
                        <td>
                            <input
                                type="text"
                                name="distributievergoeding"
                                id="distributievergoeding"
                                placeholder="bijv. 1200.00"
                                value="<?php echo $is_edit ? esc_attr( $entry->distributievergoeding ) : ''; ?>"
                            />
                        </td>
                    </tr>
                </table>

                <p>
                    <button class="button button-primary" type="submit">
                        <?php echo $is_edit ? 'Bijwerken' : 'Opslaan'; ?>
                    </button>
                </p>
            </form>

            <?php
            // Historie ophalen (ALTJD oplopend vanuit DB)
            $history_raw = ggr_portal_get_history_for_user( $user_id );

            // Eerst cumulatief rekenen in oplopende volgorde,
            // daarna de rijen omdraaien voor weergave.
            $rows_for_table = array();

            if ( $history_raw ) {
                $cumul_inleg         = 0.0;
                $cumul_opname        = 0.0;
                $cumul_distributie   = 0.0;
                $cumul_participaties = 0.0;

                foreach ( $history_raw as $row ) {
                    $cumul_inleg         += (float) $row->inlegbedrag;
                    $cumul_opname        += (float) $row->opnamebedrag;
                    $cumul_distributie   += (float) $row->distributievergoeding;
                    $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;

                    $netto_inleg  = $cumul_inleg - $cumul_opname;
                    $units_totaal = $cumul_participaties;

                    // Marktwaarde obv GGR Stock Price (per 1 participatie)
                    $price       = null;
                    $stock_price = null;

                    if ( function_exists( 'ggr_get_stock_price_for_date' ) ) {
                        // true = gebruik dichtstbijzijnde eerdere koers als er die dag geen snapshot is
                        $price = ggr_get_stock_price_for_date( $row->datum, true );
                    }

                    if ( $price !== null ) {
                        $stock_price   = (float) $price;
                        $positiewaarde = $units_totaal * $stock_price;
                    } else {
                        // Fallback: oude logica als er (nog) geen koers beschikbaar is
                        $positiewaarde = $netto_inleg + $cumul_distributie;
                    }

                    // Dividendrendement: distributie van deze rij t.o.v. actuele marktwaarde
                    $dividend_rendement = '';
                    if ( $positiewaarde > 0 && $row->distributievergoeding > 0 ) {
                        $dividend_rendement = ( (float) $row->distributievergoeding / $positiewaarde ) * 100;
                    }

                    // Investeringsrendement: ALLEEN marktwaarde t.o.v. netto inleg (dividend niet in de teller)
                    $investeringsrendement = '';
                    if ( $netto_inleg > 0 && $positiewaarde > 0 ) {
                        $investeringsrendement = ( $positiewaarde / $netto_inleg - 1 ) * 100;
                    }

                    $rows_for_table[] = array(
                        'row'                    => $row,
                        'stock_price'            => $stock_price,
                        'positiewaarde'          => $positiewaarde,
                        'totaal_participaties'   => $units_totaal,
                        'dividend_rendement'     => $dividend_rendement,
                        'investeringsrendement'  => $investeringsrendement,
                    );
                }

                // Nieuwste datum bovenaan
                $rows_for_table = array_reverse( $rows_for_table );
            }
            ?>

            <div style="margin-top:30px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">Bestaande historie</h3>
                <div>
                    <?php if ( $user_id ) : ?>
                        <form method="post" enctype="multipart/form-data" style="display:inline-block; margin-right:8px;">
                            <?php wp_nonce_field( 'ggr_import_history', 'ggr_import_nonce' ); ?>
                            <input type="hidden" name="import_user_id" value="<?php echo (int) $user_id; ?>" />
                            <input type="file" name="ggr_import_file" accept=".csv" style="display:inline-block; margin-right:6px;" />
                            <button type="submit" class="button">Importeren</button>
                        </form>
                        <?php if ( $export_url ) : ?>
                            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">Exporteren</a>
                        <?php endif; ?>
                        <?php if ( $delete_all_url ) : ?>
                            <a href="<?php echo esc_url( $delete_all_url ); ?>" class="button button-link-delete" style="margin-left:8px;">
                                Alle historie verwijderen
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( ! empty( $rows_for_table ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Transactie ID</th>
                            <th>Datum</th>
                            <th>GGR Stock Price (€)</th>
                            <th>Inlegbedrag (BIJ) (€)</th>
                            <th>Opnamebedrag (AF) (€)</th>
                            <th>Positiewaarde in €</th>
                            <th>Nieuwe participaties (BIJ)</th>
                            <th>Verkochte participaties (AF)</th>
                            <th>Totaal participaties</th>
                            <th>Distributievergoeding (€)</th>
                            <th>Dividend rendement %</th>
                            <th>Investeringsrendement %</th>
                            <th>Acties</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ( $rows_for_table as $item ) : ?>
                            <?php
                            /** @var stdClass $row */
                            $row = $item['row'];

                            $stock_price           = $item['stock_price'];
                            $positiewaarde         = $item['positiewaarde'];
                            $totaal_participaties  = $item['totaal_participaties'];
                            $dividend_rendement    = $item['dividend_rendement'];
                            $investeringsrendement = $item['investeringsrendement'];

                            $edit_url = add_query_arg(
                                [
                                    'page'    => 'ggr-participatie-historie',
                                    'user_id' => $user_id,
                                    'edit_id' => $row->id,
                                ],
                                admin_url( 'users.php' )
                            );

                            $delete_url = wp_nonce_url(
                                add_query_arg(
                                    [
                                        'page'      => 'ggr-participatie-historie',
                                        'user_id'   => $user_id,
                                        'delete_id' => $row->id,
                                    ],
                                    admin_url( 'users.php' )
                                ),
                                'ggr_delete_history',
                                '_ggrdelnonce'
                            );

                            $d_admin     = DateTime::createFromFormat( 'Y-m-d', $row->datum );
                            $datum_admin = $d_admin ? $d_admin->format( 'd-m-Y' ) : $row->datum;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $row->transactie_code ); ?></td>
                                <td><?php echo esc_html( $datum_admin ); ?></td>

                                <td>
                                    <?php
                                    if ( $stock_price !== null ) {
                                        echo '€ ' . number_format( $stock_price, 2, ',', '.' );
                                    } else {
                                        echo '–';
                                    }
                                    ?>
                                </td>

                                <td><?php echo number_format( $row->inlegbedrag, 2, ',', '.' ); ?></td>
                                <td><?php echo number_format( $row->opnamebedrag, 2, ',', '.' ); ?></td>
                                <td><?php echo number_format( $positiewaarde, 2, ',', '.' ); ?></td>
                                <td><?php echo number_format( $row->nieuwe_participaties, 4, ',', '.' ); ?></td>
                                <td><?php echo number_format( $row->verkochte_participaties, 4, ',', '.' ); ?></td>
                                <td><?php echo number_format( $totaal_participaties, 4, ',', '.' ); ?></td>
                                <td><?php echo number_format( $row->distributievergoeding, 2, ',', '.' ); ?></td>

                                <td>
                                    <?php
                                    echo $dividend_rendement !== ''
                                        ? number_format( $dividend_rendement, 2, ',', '.' ) . ' %'
                                        : '-';
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo $investeringsrendement !== ''
                                        ? number_format( $investeringsrendement, 2, ',', '.' ) . ' %'
                                        : '-';
                                    ?>
                                </td>

                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>">Bewerken</a> |
                                    <a href="<?php echo esc_url( $delete_url ); ?>">Verwijderen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    </tbody>
                </table>
            <?php else : ?>
                <p>Nog geen historie voor deze gebruiker.</p>
            <?php endif; ?>

        <?php endif; ?>

    </div>
    <?php
}

/**
 * 5. FRONTEND HELPER: LAATSTE STAND + BEREKENINGEN
 */
function ggr_portal_get_latest_calculated_values_for_user( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return false;
    }

    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return false;
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return false;
    }

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_distributie   = 0.0;
    $cumul_participaties = 0.0;

    $latest = null;

    foreach ( $history as $row ) {
        $cumul_inleg         += (float) $row->inlegbedrag;
        $cumul_opname        += (float) $row->opnamebedrag;
        $cumul_distributie   += (float) $row->distributievergoeding;
        $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;

        $netto_inleg  = $cumul_inleg - $cumul_opname;
        $units_totaal = $cumul_participaties;
        $datum        = $row->datum;

        // Positiewaarde obv GGR Stock Price (per 1 participatie), met fallback naar eerdere datum
        $price = null;
        if ( function_exists( 'ggr_get_stock_price_for_date' ) ) {
            $price = ggr_get_stock_price_for_date( $datum, true );
        }

        if ( $price !== null ) {
            $positiewaarde = $units_totaal * (float) $price;
        } else {
            // Fallback: oude logica als er (nog) geen koers beschikbaar is
            $positiewaarde = $netto_inleg + $cumul_distributie;
        }

        // Dividendrendement: obv cumulatief dividend t.o.v. huidige positiewaarde
        $dividend_rendement = null;
        if ( $positiewaarde > 0 && $cumul_distributie > 0 ) {
            $dividend_rendement = ( $cumul_distributie / $positiewaarde ) * 100;
        }

        // Investeringsrendement: ALLEEN marktwaarde t.o.v. netto inleg
        $investeringsrendement = null;
        if ( $netto_inleg > 0 && $positiewaarde > 0 ) {
            $investeringsrendement = ( $positiewaarde / $netto_inleg - 1 ) * 100;
        }

        $latest = array(
            'transactie_code'         => $row->transactie_code,
            'datum'                   => $datum,
            'inlegbedrag'             => (float) $row->inlegbedrag,
            'opnamebedrag'            => (float) $row->opnamebedrag,
            'positiewaarde'           => $positiewaarde,
            'nieuwe_participaties'    => (float) $row->nieuwe_participaties,
            'verkochte_participaties' => (float) $row->verkochte_participaties,
            'totaal_participaties'    => $units_totaal,
            'distributievergoeding'   => (float) $row->distributievergoeding,
            'dividend_rendement'      => $dividend_rendement,
            'investeringsrendement'   => $investeringsrendement,
        );
    }

    return $latest;
}

/**
 * 6. DAGELIJKSE WAARDERING / TIMESERIES
 */

/**
 * Dagelijkse waardering per participant.
 */
function ggr_portal_get_user_daily_valuations( $user_id, $from_date = null, $to_date = null ) {
    global $wpdb;

    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return array();
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return array();
    }

    // Bepaal standaard datumbereik: vanaf eerste transactie t/m vandaag
    $first_row  = reset( $history );
    $first_date = $first_row && ! empty( $first_row->datum ) ? $first_row->datum : null;

    if ( ! $first_date ) {
        return array();
    }

    if ( empty( $from_date ) ) {
        $from_date = $first_date;
    }

    if ( empty( $to_date ) ) {
        $ts      = current_time( 'timestamp' );
        $to_date = gmdate( 'Y-m-d', $ts );
    }

    // Stock price tabel (ggr-stock-price.php moet deze maken)
    $stock_table = $wpdb->prefix . 'ggr_stock_price';

    $stock_rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT valuation_date, price_per_unit
            FROM {$stock_table}
            WHERE valuation_date >= %s
              AND valuation_date <= %s
            ORDER BY valuation_date ASC
            ",
            $from_date,
            $to_date
        )
    );

    if ( empty( $stock_rows ) ) {
        return array();
    }

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_participaties = 0.0;

    $history_index = 0;
    $history_count = count( $history );

    $valuations = array();

    foreach ( $stock_rows as $stock ) {
        $date_stock = $stock->valuation_date;

        // Alle history-entries t/m deze datum verwerken
        while ( $history_index < $history_count ) {
            $h = $history[ $history_index ];

            if ( $h->datum > $date_stock ) {
                break;
            }

            $cumul_inleg         += (float) $h->inlegbedrag;
            $cumul_opname        += (float) $h->opnamebedrag;
            $cumul_participaties += (float) $h->nieuwe_participaties - (float) $h->verkochte_participaties;

            $history_index++;
        }

        $netto_inleg  = $cumul_inleg - $cumul_opname;
        $units_totaal = $cumul_participaties;

        if ( $netto_inleg <= 0 && $units_totaal <= 0 ) {
            continue;
        }

        $price         = (float) $stock->price_per_unit;
        $positiewaarde = $units_totaal * $price;

        $investeringsrendement = '';
        if ( $netto_inleg > 0 && $positiewaarde > 0 ) {
            $investeringsrendement = ( $positiewaarde / $netto_inleg - 1 ) * 100;
        }

        $valuations[] = array(
            'datum'                 => $date_stock,
            'ggr_stock_price'       => $price,
            'totaal_participaties'  => $units_totaal,
            'positiewaarde'         => $positiewaarde,
            'netto_inleg'           => $netto_inleg,
            'investeringsrendement' => $investeringsrendement,
        );
    }

    return $valuations;
}

/**
 * Aantal participaties op datum
 */
function ggr_get_units_for_user_on_date( $user_id, $date ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return 0.0;
    }

    $cutoff = date( 'Y-m-d', strtotime( $date ) );

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return 0.0;
    }

    $units = 0.0;

    foreach ( $history as $row ) {
        if ( $row->datum > $cutoff ) {
            break;
        }

        $nieuw   = isset( $row->nieuwe_participaties ) ? (float) $row->nieuwe_participaties   : 0.0;
        $verkoop = isset( $row->verkochte_participaties ) ? (float) $row->verkochte_participaties : 0.0;

        $units += $nieuw;
        $units -= $verkoop;
    }

    return $units;
}

/**
 * Timeserie van positie voor een user over een periode
 */
function ggr_get_user_position_timeseries( $user_id, $from_date, $to_date ) {
    global $wpdb;

    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return array();
    }

    $from = date( 'Y-m-d', strtotime( $from_date ) );
    $to   = date( 'Y-m-d', strtotime( $to_date ) );

    $table_prices = $wpdb->prefix . 'ggr_stock_price';

    // Pak alle dagen waarvoor een GGR Stock Price bestaat
    $dates = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT valuation_date 
             FROM {$table_prices}
             WHERE valuation_date BETWEEN %s AND %s
             ORDER BY valuation_date ASC",
            $from,
            $to
        )
    );

    if ( ! $dates ) {
        return array();
    }

    if ( ! function_exists( 'ggr_get_stock_price_for_date' ) ) {
        return array();
    }

    $series = array();

    foreach ( $dates as $date ) {
        $price = ggr_get_stock_price_for_date( $date, false );
        if ( $price === null ) {
            continue;
        }

        $units          = ggr_get_units_for_user_on_date( $user_id, $date );
        $position_value = $units * $price;

        $series[] = array(
            'date'           => $date,
            'price'          => (float) $price,
            'units'          => (float) $units,
            'position_value' => (float) $position_value,
        );
    }

    return $series;
}

/**
 * Samenvatting van de investering voor een user tot peildatum
 */
function ggr_get_user_investment_summary( $user_id, $as_of_date = null ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return array();
    }

    if ( ! $as_of_date ) {
        $as_of_date = current_time( 'Y-m-d' );
    }

    $cutoff = date( 'Y-m-d', strtotime( $as_of_date ) );

    $history = ggr_portal_get_history_for_user( $user_id );

    $invested   = 0.0;
    $withdrawn  = 0.0;
    $dividends  = 0.0;

    if ( $history ) {
        foreach ( $history as $row ) {
            if ( $row->datum > $cutoff ) {
                break;
            }

            $inleg   = isset( $row->inlegbedrag ) ? (float) $row->inlegbedrag : 0.0;
            $opname  = isset( $row->opnamebedrag ) ? (float) $row->opnamebedrag : 0.0;
            $dist    = isset( $row->distributievergoeding ) ? (float) $row->distributievergoeding : 0.0;

            $invested  += $inleg;
            $withdrawn += $opname;
            $dividends += $dist;
        }
    }

    $net_invested = $invested - $withdrawn;

    if ( ! function_exists( 'ggr_get_stock_price_for_date' ) ) {
        $price = null;
    } else {
        $price = ggr_get_stock_price_for_date( $cutoff, true );
    }

    $units          = ggr_get_units_for_user_on_date( $user_id, $cutoff );
    $position_value = ( $price !== null ) ? ( $units * $price ) : 0.0;
    $total_value    = $position_value + $dividends;

    $return_pct = null;
    if ( $net_invested > 0 ) {
        $return_pct = ( ( $total_value - $net_invested ) / $net_invested ) * 100.0;
    }

    return array(
        'as_of_date'      => $cutoff,
        'invested_total'  => (float) $invested,
        'withdrawn_total' => (float) $withdrawn,
        'net_invested'    => (float) $net_invested,
        'dividends_total' => (float) $dividends,
        'units'           => (float) $units,
        'price'           => ( $price !== null ) ? (float) $price : null,
        'position_value'  => (float) $position_value,
        'total_value'     => (float) $total_value,
        'return_pct'      => ( $return_pct !== null ) ? (float) $return_pct : null,
    );
}

/**
 * 7. SHORTCODES VOOR LAATSTE STAND
 */
function ggr_portal_latest_value_shortcode_handler( $atts, $content, $tag ) {
    $atts = shortcode_atts(
        array(
            'user_id'  => 0,
            'fallback' => '-',
        ),
        $atts,
        $tag
    );

    $user_id = (int) $atts['user_id'];
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return esc_html( $atts['fallback'] );
    }

    $data = ggr_portal_get_latest_calculated_values_for_user( $user_id );
    if ( ! $data ) {
        return esc_html( $atts['fallback'] );
    }

    if ( $tag === 'ggr_latest_datum' ) {
        if ( empty( $data['datum'] ) ) {
            return esc_html( $atts['fallback'] );
        }
        $d = DateTime::createFromFormat( 'Y-m-d', $data['datum'] );
        return $d ? esc_html( $d->format( 'd-m-Y' ) ) : esc_html( $data['datum'] );
    }

    $value      = null;
    $format     = 'raw';
    $decimals   = 0;
    $thousands  = '.';
    $decimalsep = ',';

    switch ( $tag ) {
        case 'ggr_latest_transactie_code':
            $value  = $data['transactie_code'];
            $format = 'raw';
            break;

        case 'ggr_latest_inleg':
            $value    = $data['inlegbedrag'];
            $format   = 'money';
            $decimals = 2;
            break;

        case 'ggr_latest_opname':
            $value    = $data['opnamebedrag'];
            $format   = 'money';
            $decimals = 2;
            break;

        case 'ggr_latest_positiewaarde':
            $value    = $data['positiewaarde'];
            $format   = 'money';
            $decimals = 2;
            break;

        case 'ggr_latest_nieuwe_participaties':
            $value    = $data['nieuwe_participaties'];
            $format   = 'participations';
            $decimals = 4;
            break;

        case 'ggr_latest_verkochte_participaties':
            $value    = $data['verkochte_participaties'];
            $format   = 'participations';
            $decimals = 4;
            break;

        case 'ggr_latest_totaal_participaties':
            $value    = $data['totaal_participaties'];
            $format   = 'participations';
            $decimals = 4;
            break;

        case 'ggr_latest_distributievergoeding':
            $value    = $data['distributievergoeding'];
            $format   = 'money';
            $decimals = 2;
            break;

        case 'ggr_latest_dividend_rendement':
            $value    = $data['dividend_rendement'];
            $format   = 'percent';
            $decimals = 2;
            break;

        case 'ggr_latest_investeringsrendement':
            $value    = $data['investeringsrendement'];
            $format   = 'percent';
            $decimals = 2;
            break;

        default:
            return esc_html( $atts['fallback'] );
    }

    if ( $value === null ) {
        return esc_html( $atts['fallback'] );
    }

    switch ( $format ) {
        case 'money':
            return '€ ' . esc_html( number_format( (float) $value, $decimals, $decimalsep, $thousands ) );

        case 'participations':
            return esc_html( number_format( (float) $value, $decimals, $decimalsep, $thousands ) );

        case 'percent':
            return esc_html( number_format( (float) $value, $decimals, $decimalsep, $thousands ) ) . ' %';

        default:
            return esc_html( $value );
    }
}

// Shortcodes registreren
add_shortcode( 'ggr_latest_datum',                  'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_transactie_code',        'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_inleg',                  'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_opname',                 'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_positiewaarde',          'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_nieuwe_participaties',   'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_verkochte_participaties','ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_totaal_participaties',   'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_distributievergoeding',  'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_dividend_rendement',     'ggr_portal_latest_value_shortcode_handler' );
add_shortcode( 'ggr_latest_investeringsrendement',  'ggr_portal_latest_value_shortcode_handler' );

add_action( 'show_user_profile', 'ggr_portal_show_account_fields_in_profile' );
add_action( 'edit_user_profile', 'ggr_portal_show_account_fields_in_profile' );

function ggr_portal_show_account_fields_in_profile( $user ) {
    if ( ! current_user_can( 'list_users' ) ) {
        return;
    }

    // Participant
    $first_name = get_user_meta( $user->ID, 'first_name', true );
    $last_name  = get_user_meta( $user->ID, 'last_name', true );
    $phone      = get_user_meta( $user->ID, 'phone', true );
    
    // Onboarding extra's
    $account_type       = get_user_meta( $user->ID, 'ggr_account_type', true );
    $nationality        = get_user_meta( $user->ID, 'ggr_nationality', true );
    $investment         = get_user_meta( $user->ID, 'ggr_investment', true );
    $investment_amount  = get_user_meta( $user->ID, 'ggr_investment_amount', true );
    $marketing_optin    = (int) get_user_meta( $user->ID, 'ggr_marketing_optin', true );
    $onboarding_status  = function_exists( 'ggr_onboarding_get_status' ) ? ggr_onboarding_get_status( $user->ID ) : get_user_meta( $user->ID, 'ggr_onboarding_status', true );
    $onboarding_updated = get_user_meta( $user->ID, 'ggr_onboarding_updated_at', true );

    if ( $investment_amount === '' ) {
        $investment_amount = $investment;
    }

    // Mede-participant (optioneel)
    $co_first = get_user_meta( $user->ID, 'co_first_name', true );
    $co_last  = get_user_meta( $user->ID, 'co_last_name', true );
    $co_email = get_user_meta( $user->ID, 'co_email', true );
    $co_phone = get_user_meta( $user->ID, 'co_phone', true );

    // Adres
    $p_street  = get_user_meta( $user->ID, 'address_street', true );
    $p_zip     = get_user_meta( $user->ID, 'address_postcode', true );
    $p_city    = get_user_meta( $user->ID, 'address_city', true );
    $p_country = get_user_meta( $user->ID, 'address_country', true );

    // Bank
    $bank_iban = get_user_meta( $user->ID, 'bank_account_iban', true );
    $bank_name = get_user_meta( $user->ID, 'bank_account_name', true );

    // Bedrijf
    $company_name = get_user_meta( $user->ID, 'company_name', true );
    $company_kvk  = get_user_meta( $user->ID, 'company_kvk', true );

    // KYC + herkomst
    $kyc_birth_date    = get_user_meta( $user->ID, 'ggr_kyc_birth_date', true );
    $kyc_birth_place   = get_user_meta( $user->ID, 'ggr_kyc_birth_place', true );
    $kyc_birth_country = get_user_meta( $user->ID, 'ggr_kyc_birth_country', true );
    $kyc_country       = get_user_meta( $user->ID, 'ggr_kyc_country', true );
    $kyc_bsn           = get_user_meta( $user->ID, 'ggr_kyc_bsn', true );
    $kyc_id_expiry     = get_user_meta( $user->ID, 'ggr_kyc_id_expiry', true );
    $kyc_pep           = get_user_meta( $user->ID, 'ggr_kyc_pep', true );
    $kyc_us_person     = get_user_meta( $user->ID, 'ggr_kyc_us_person', true );
    $origin_sources    = get_user_meta( $user->ID, 'ggr_origin_sources', true );
    if ( ! is_array( $origin_sources ) ) {
        $origin_sources = array();
    }

    // Eenmalig simpele CSS injecteren
    static $css_done = false;
    if ( ! $css_done ) : $css_done = true; ?>
        <style>
            .ggr-admin-columns {
                display: flex;
                gap: 40px;
                flex-wrap: wrap;
                align-items: flex-start;
            }
            .ggr-admin-col {
                min-width: 260px;
                max-width: 380px;
            }
            .ggr-admin-col h4 {
                margin: 0 0 8px;
                font-size: 14px;
            }
            .ggr-admin-inline-field {
                margin-bottom: 10px;
            }
            .ggr-admin-inline-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 2px;
            }
            .ggr-admin-inline-field input {
                width: 100%;
            }
        </style>
    <?php endif; ?>

    <h2>GGR Portal – Accountgegevens</h2>

    <table class="form-table" role="presentation">
        <!-- CONTACTGEGEVENS: participant + mede-participant naast elkaar -->
        <tr>
            <th scope="row">Contactgegevens</th>
            <td>
                <div class="ggr-admin-columns">
                    <div class="ggr-admin-col">
                        <h4>Participant</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_first_name">Voornaam</label>
                            <input type="text" name="ggr_first_name" id="ggr_first_name"
                                   value="<?php echo esc_attr( $first_name ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_last_name">Achternaam</label>
                            <input type="text" name="ggr_last_name" id="ggr_last_name"
                                   value="<?php echo esc_attr( $last_name ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_email">E-mailadres (login)</label>
                            <input type="email" name="ggr_email" id="ggr_email"
                                   value="<?php echo esc_attr( $user->user_email ); ?>" />
                            <p class="description">Dit is ook het inlog e-mailadres.</p>
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_phone">Telefoonnummer</label>
                            <input type="text" name="ggr_phone" id="ggr_phone"
                                   value="<?php echo esc_attr( $phone ); ?>" />
                        </div>

                    </div>
                    

                    <div class="ggr-admin-col">
                        <h4>Mede-participant (optioneel)</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_co_first_name">Voornaam</label>
                            <input type="text" name="ggr_co_first_name" id="ggr_co_first_name"
                                   value="<?php echo esc_attr( $co_first ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_co_last_name">Achternaam</label>
                            <input type="text" name="ggr_co_last_name" id="ggr_co_last_name"
                                   value="<?php echo esc_attr( $co_last ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_co_email">E-mailadres</label>
                            <input type="email" name="ggr_co_email" id="ggr_co_email"
                                   value="<?php echo esc_attr( $co_email ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_co_phone">Telefoonnummer</label>
                            <input type="text" name="ggr_co_phone" id="ggr_co_phone"
                                   value="<?php echo esc_attr( $co_phone ); ?>" />
                        </div>
                    </div>
                </div>
            </td>
        </tr>

        <tr>
            <th scope="row">Onboarding</th>
            <td>
                <div class="ggr-admin-columns">
                    <div class="ggr-admin-col">
                        <h4>Profielkeuzes</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_account_type">Account type</label>
                            <select name="ggr_account_type" id="ggr_account_type">
                                <option value=""><?php esc_html_e( 'Maak een keuze', 'ggr-portal' ); ?></option>
                                <option value="private" <?php selected( $account_type, 'private' ); ?>>Particulier</option>
                                <option value="business" <?php selected( $account_type, 'business' ); ?>>Zakelijk</option>
                            </select>
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_nationality">Nationaliteit</label>
                            <input type="text" name="ggr_nationality" id="ggr_nationality"
                                   value="<?php echo esc_attr( $nationality ); ?>" />
                        </div>
                    </div>

                    <div class="ggr-admin-col">
                        <h4>Aanvraagdetails</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_investment_amount">Investeringsbedrag (wens) (€)</label>
                            <input type="text" name="ggr_investment_amount" id="ggr_investment_amount"
                                   value="<?php echo esc_attr( $investment_amount ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_marketing_optin">
                                <input type="checkbox" name="ggr_marketing_optin" id="ggr_marketing_optin" value="1" <?php checked( 1, $marketing_optin ); ?> />
                                Marketing- en investeringsupdates toegestaan
                            </label>
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_onboarding_status">Onboarding status</label>
                            <select name="ggr_onboarding_status" id="ggr_onboarding_status">
                                <?php
                                $stages = function_exists( 'ggr_onboarding_get_stages' ) ? ggr_onboarding_get_stages() : array();
                                if ( empty( $stages ) ) {
                                    $stages = array( $onboarding_status => $onboarding_status );
                                }
                                foreach ( $stages as $key => $label ) :
                                    ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $onboarding_status, $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $onboarding_updated ) : ?>
                                <p class="description">Laatst bijgewerkt: <?php echo esc_html( $onboarding_updated ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </td>
        </tr>

        <!-- ADRES LINKS, BANK + BEDRIJF RECHTS -->
        <tr>
            <th scope="row">Adres / Bank / Bedrijf</th>
            <td>
                <div class="ggr-admin-columns">
                    <!-- Adres -->
                    <div class="ggr-admin-col">
                        <h4>Adresgegevens</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_p_street">Straat + huisnummer</label>
                            <input type="text" name="ggr_p_street" id="ggr_p_street"
                                   value="<?php echo esc_attr( $p_street ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_p_zip">Postcode</label>
                            <input type="text" name="ggr_p_zip" id="ggr_p_zip"
                                   value="<?php echo esc_attr( $p_zip ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_p_city">Plaats</label>
                            <input type="text" name="ggr_p_city" id="ggr_p_city"
                                   value="<?php echo esc_attr( $p_city ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_country">Land</label>
                            <input type="text" name="ggr_kyc_country" id="ggr_kyc_country"
                                   value="<?php echo esc_attr( $kyc_country ? $kyc_country : $p_country ); ?>" />
                        </div>
                    </div>

                    <!-- Bank + Bedrijf -->
                    <div class="ggr-admin-col">
                        <h4>Bankgegevens</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_bank_iban">Rekeningnummer (IBAN)</label>
                            <input type="text" name="ggr_bank_iban" id="ggr_bank_iban"
                                   value="<?php echo esc_attr( $bank_iban ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_bank_name">Tenaamstelling rekening</label>
                            <input type="text" name="ggr_bank_name" id="ggr_bank_name"
                                   value="<?php echo esc_attr( $bank_name ); ?>" />
                        </div>

                        <h4 style="margin-top:18px;">Bedrijfsgegevens</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_company_name">Bedrijfsnaam</label>
                            <input type="text" name="ggr_company_name" id="ggr_company_name"
                                   value="<?php echo esc_attr( $company_name ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_company_kvk">KvK-nummer</label>
                            <input type="text" name="ggr_company_kvk" id="ggr_company_kvk"
                                   value="<?php echo esc_attr( $company_kvk ); ?>" />
                        </div>
                    </div>
                </div>
            </td>
        </tr>

        <!-- KYC + herkomst middelen -->
        <tr>
            <th scope="row">KYC & herkomst</th>
            <td>
                <div class="ggr-admin-columns">
                    <div class="ggr-admin-col">
                        <h4>Persoonsgegevens</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_birth_date">Geboortedatum</label>
                            <input type="date" name="ggr_kyc_birth_date" id="ggr_kyc_birth_date"
                                   value="<?php echo esc_attr( $kyc_birth_date ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_birth_place">Geboorteplaats</label>
                            <input type="text" name="ggr_kyc_birth_place" id="ggr_kyc_birth_place"
                                   value="<?php echo esc_attr( $kyc_birth_place ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_birth_country">Geboorteland</label>
                            <input type="text" name="ggr_kyc_birth_country" id="ggr_kyc_birth_country"
                                   value="<?php echo esc_attr( $kyc_birth_country ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_bsn">BSN</label>
                            <input type="text" name="ggr_kyc_bsn" id="ggr_kyc_bsn"
                                   value="<?php echo esc_attr( $kyc_bsn ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_id_expiry">Geldigheid ID</label>
                            <input type="date" name="ggr_kyc_id_expiry" id="ggr_kyc_id_expiry"
                                   value="<?php echo esc_attr( $kyc_id_expiry ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_pep">PEP</label>
                            <input type="text" name="ggr_kyc_pep" id="ggr_kyc_pep"
                                   value="<?php echo esc_attr( $kyc_pep ); ?>" />
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_kyc_us_person">US person</label>
                            <input type="text" name="ggr_kyc_us_person" id="ggr_kyc_us_person"
                                   value="<?php echo esc_attr( $kyc_us_person ); ?>" />
                        </div>
                    </div>

                    <div class="ggr-admin-col">
                        <h4>Herkomst middelen</h4>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_origin_country_preview">Land van herkomst</label>
                            <select id="ggr_origin_country_preview" disabled>
                                <option value="" <?php selected( '', $origin_country ); ?>>Maak een keuze</option>
                                <?php foreach ( $countries as $country ) : ?>
                                    <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $origin_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Bepaal de waarde bij stap 4 hieronder.</p>
                        </div>

                        <div class="ggr-admin-inline-field">
                            <label>Geselecteerde bronnen</label>
                            <div>
                                <?php
                                $origin_labels = array(
                                    'salary'   => 'In loondienst',
                                    'business' => 'Ondernemingsactiviteiten',
                                    'rental'   => 'Rente/dividend/huur',
                                    'savings'  => 'Vermogen/erfenis/pensioen',
                                    'sale'     => 'Opbrengst verkoop',
                                    'loan'     => 'Ontvangen lening',
                                    'other'    => 'Andere herkomst',
                                );
                                foreach ( $origin_labels as $key => $label ) :
                                    ?>
                                    <label style="display:block;">
                                        <input type="checkbox" name="ggr_origin_sources[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $origin_sources, true ) ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'ggr_portal_save_account_fields_in_profile' );
add_action( 'edit_user_profile_update', 'ggr_portal_save_account_fields_in_profile' );
add_action( 'admin_init', 'ggr_portal_handle_participant_profile_save' );

function ggr_portal_store_participant_profile_data( $user_id ) {
    $profile_timestamp = current_time( 'mysql' );

    $status_override = '';
    
    $participant_user  = get_user_by( 'ID', $user_id );
    $participant_email = $participant_user ? $participant_user->user_email : '';
    $existing_contract_signed = get_user_meta( $user_id, 'ggr_contract_signed_at', true );
    
    $doc_action   = isset( $_POST['ggr_doc_action'] ) ? sanitize_key( wp_unslash( $_POST['ggr_doc_action'] ) ) : '';
    $doc_feedback = isset( $_POST['ggr_doc_feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ggr_doc_feedback'] ) ) : '';
    
    $doc_to_delete = isset( $_POST['ggr_delete_document'] ) ? sanitize_key( wp_unslash( $_POST['ggr_delete_document'] ) ) : '';
    if ( $doc_to_delete ) {
        delete_user_meta( $user_id, $doc_to_delete );
    }

    if ( '' !== $doc_feedback ) {
        update_user_meta( $user_id, 'ggr_doc_feedback', $doc_feedback );
    } else {
        delete_user_meta( $user_id, 'ggr_doc_feedback' );
    }

    $sanitize_text = function( $key ) {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    };

    // E-mailadres
    if ( isset( $_POST['ggr_email'] ) ) {
        $email = sanitize_email( wp_unslash( $_POST['ggr_email'] ) );
        if ( $email && is_email( $email ) ) {
            wp_update_user( [
                'ID'         => $user_id,
                'user_email' => $email,
            ] );
        }
    }
    
    // Stap 1: bedrag
    if ( isset( $_POST['ggr_participation_amount'] ) ) {
        $amount_raw = sanitize_text_field( wp_unslash( $_POST['ggr_participation_amount'] ) );
        if ( function_exists( 'ggr_onboarding_parse_amount' ) ) {
            $amount_value = ggr_onboarding_parse_amount( $amount_raw );
        } else {
            $amount_value = (float) str_replace( ',', '.', str_replace( '.', '', $amount_raw ) );
        }

        update_user_meta( $user_id, 'ggr_participation_amount', $amount_value );
        update_user_meta( $user_id, 'ggr_investment_amount', $amount_value );
        update_user_meta( $user_id, 'ggr_investment', $amount_raw );
    }
    
    // Stap 2: profiel
    $participation_profile = isset( $_POST['ggr_participation_profile'] ) ? sanitize_key( wp_unslash( $_POST['ggr_participation_profile'] ) ) : '';
    if ( $participation_profile ) {
        update_user_meta( $user_id, 'ggr_participation_profile', $participation_profile );
        $account_type = ( 'zakelijk' === $participation_profile ) ? 'business' : 'private';
        update_user_meta( $user_id, 'ggr_account_type', $account_type );
    }

    $co_field_map = array(
        'ggr_co_first_name'    => array( 'ggr_co_first_name', 'co_first_name' ),
        'ggr_co_last_name'     => array( 'ggr_co_last_name', 'co_last_name' ),
        'ggr_co_email'         => array( 'ggr_co_email', 'co_email' ),
        'ggr_co_phone'         => array( 'ggr_co_phone', 'co_phone' ),
        'ggr_co_birth_date'    => array( 'ggr_co_birth_date' ),
        'ggr_co_birth_place'   => array( 'ggr_co_birth_place' ),
        'ggr_co_birth_country' => array( 'ggr_co_birth_country' ),
        'ggr_co_address'       => array( 'ggr_co_address' ),
        'ggr_co_postcode'      => array( 'ggr_co_postcode' ),
        'ggr_co_city_country'  => array( 'ggr_co_city_country' ),
        'ggr_co_country'       => array( 'ggr_co_country' ),
        'ggr_co_bsn'           => array( 'ggr_co_bsn' ),
        'ggr_co_pep'           => array( 'ggr_co_pep' ),
        'ggr_co_us_person'     => array( 'ggr_co_us_person' ),
    );

    $co_values      = array();
    $co_meta_keys   = array();
    foreach ( $co_field_map as $form_key => $meta_keys ) {
        $value                   = isset( $_POST[ $form_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $form_key ] ) ) : '';
        $co_values[ $form_key ]  = $value;
        $co_meta_keys[ $form_key ] = (array) $meta_keys;
        if ( '' !== $value ) {
            $co_has_values = true;
        }
    }

    $has_co = isset( $_POST['ggr_has_co_participant'] ) ? sanitize_key( wp_unslash( $_POST['ggr_has_co_participant'] ) ) : 'nee';
    if ( 'nee' === $has_co && $co_has_values ) {
        $has_co = 'ja';
    }    
    update_user_meta( $user_id, 'ggr_has_co_participant', $has_co );

    // Marketing opt-in
    $marketing_optin = ! empty( $_POST['ggr_marketing_optin'] ) ? 1 : 0;
    update_user_meta( $user_id, 'ggr_marketing_optin', $marketing_optin );

    // Contract ondertekening (admin)
    $contract_signed_admin = ! empty( $_POST['ggr_contract_signed_admin'] );
    if ( $contract_signed_admin && ! $existing_contract_signed ) {
        update_user_meta( $user_id, 'ggr_contract_signed_at', $profile_timestamp );
    }
    if ( isset( $_POST['ggr_contract_signature_admin'] ) ) {
        $signature_admin = sanitize_textarea_field( wp_unslash( $_POST['ggr_contract_signature_admin'] ) );
        if ( '' === $signature_admin ) {
            delete_user_meta( $user_id, 'ggr_contract_signature_admin' );
        } else {
            update_user_meta( $user_id, 'ggr_contract_signature_admin', $signature_admin );
        }
    }
    // Betaling en startdatum
    $payment_received = ! empty( $_POST['ggr_payment_received'] ) ? 1 : 0;
    update_user_meta( $user_id, 'ggr_payment_received', $payment_received );

    if ( isset( $_POST['ggr_first_trade_day'] ) ) {
        $first_trade_day = $sanitize_text( 'ggr_first_trade_day' );
        if ( $first_trade_day !== '' ) {
            update_user_meta( $user_id, 'ggr_first_trade_day', $first_trade_day );
        }
    }

    if ( $payment_received ) {
        update_user_meta( $user_id, 'ggr_payment_received_at', $profile_timestamp );
        $status_override = $status_override ? $status_override : 'active_participant';
    }

    // Onboarding status
    // Documentcontrole: override status en trigger e-mails.
    if ( $doc_action ) {
        if ( 'approve' === $doc_action ) {
            $status_override = 'sign_contract';

            if ( function_exists( 'ggr_portal_send_templated_email' ) ) {
                ggr_portal_send_templated_email(
                    'documents_approved',
                    $user_id,
                    array(
                        'contract_link' => home_url( '/onboarding/' ),
                    )
                );
            }

                    if ( function_exists( 'ggr_meldingen_add' ) ) {
                        ggr_meldingen_add(
                            'Documentatie goedgekeurd',
                            sprintf(
                                'De documentatie van %s (%s) is goedgekeurd. Status is bijgewerkt naar overeenkomst tekenen.',
                                ggr_portal_get_nice_user_name( $user_id ),
                                esc_html( $participant_email )
                            ),
                            $user_id,
                            array( 'onboarding_status' => 'sign_contract' )
                        );
            }

            if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
                ggr_portal_log_participant_action(
                    $user_id,
                    'document_review',
                    'Documenten goedgekeurd',
                    array(
                        'changes' => array(
                            'Documentstatus: "afgekeurd" → "goedgekeurd"',
                            'Feedback: "' . ggr_portal_format_audit_value( $doc_feedback ? $doc_feedback : '—' ) . '"',
                        ),
                    )
                );
            }

        } elseif ( 'reject' === $doc_action ) {
            $status_override = 'collecting';

            if ( function_exists( 'ggr_portal_send_templated_email' ) ) {
                ggr_portal_send_templated_email(
                    'documents_rejected',
                    $user_id,
                    array(
                        'rejection_feedback' => $doc_feedback,
                        'portal_link'        => home_url( '/onboarding/' ),
                    )
                );
            }

                    if ( function_exists( 'ggr_meldingen_add' ) ) {
                        ggr_meldingen_add(
                            'Documentatie afgekeurd',
                            sprintf(
                                'De documentatie van %s (%s) is afgekeurd met feedback: %s',
                                ggr_portal_get_nice_user_name( $user_id ),
                                esc_html( $participant_email ),
                                $doc_feedback ? $doc_feedback : '—'
                            ),
                            $user_id,
                            array( 'onboarding_status' => 'collecting' )
                        );
            }

            if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
                ggr_portal_log_participant_action(
                    $user_id,
                    'document_review',
                    'Documenten afgekeurd',
                    array(
                        'changes' => array(
                            'Documentstatus: "goedgekeurd" → "afgekeurd"',
                            'Feedback: "' . ggr_portal_format_audit_value( $doc_feedback ? $doc_feedback : '—' ) . '"',
                        ),
                    )
                );
            }
        }
    }

    // Onboarding status (met mogelijkheid tot override).
    if ( isset( $_POST['ggr_onboarding_status'] ) || $status_override ) {
        $status = $status_override ? $status_override : sanitize_key( wp_unslash( $_POST['ggr_onboarding_status'] ) );

        if ( function_exists( 'ggr_onboarding_update_status' ) ) {
            ggr_onboarding_update_status( $user_id, $status );
        } else {
            update_user_meta( $user_id, 'ggr_onboarding_status', $status );
        }
    }
    
    // Stap 3: persoonlijke gegevens (participant)
    $kyc_first_name = $sanitize_text( 'ggr_kyc_first_name' );
    $kyc_last_name  = $sanitize_text( 'ggr_kyc_last_name' );
    $kyc_phone      = $sanitize_text( 'ggr_kyc_phone' );

    if ( $kyc_first_name ) {
        update_user_meta( $user_id, 'ggr_kyc_first_name', $kyc_first_name );
        update_user_meta( $user_id, 'first_name', $kyc_first_name );
    }
    if ( $kyc_last_name ) {
        update_user_meta( $user_id, 'ggr_kyc_last_name', $kyc_last_name );
        update_user_meta( $user_id, 'last_name', $kyc_last_name );
    }
    if ( $kyc_phone ) {
        update_user_meta( $user_id, 'ggr_kyc_phone', $kyc_phone );
        update_user_meta( $user_id, 'phone', $kyc_phone );
    }

    $kyc_fields = array(
        'ggr_kyc_birth_date',
        'ggr_kyc_birth_place',
        'ggr_kyc_birth_country',
        'ggr_kyc_country',
        'ggr_kyc_address',
        'ggr_kyc_postcode',
        'ggr_kyc_city_country',
        'ggr_kyc_bsn',
        'ggr_kyc_iban_name',
        'ggr_kyc_iban',
        'ggr_kyc_pep',
        'ggr_kyc_us_person',
    );

    foreach ( $kyc_fields as $field_key ) {
        if ( isset( $_POST[ $field_key ] ) ) {
            update_user_meta( $user_id, $field_key, sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) ) );
        }
    }

    // Synchroniseer adres en bank met bestaande velden
    update_user_meta( $user_id, 'address_street', $sanitize_text( 'ggr_kyc_address' ) );
    update_user_meta( $user_id, 'address_postcode', $sanitize_text( 'ggr_kyc_postcode' ) );
    update_user_meta( $user_id, 'address_city', $sanitize_text( 'ggr_kyc_city_country' ) );
    update_user_meta( $user_id, 'address_country', $sanitize_text( 'ggr_kyc_country' ) );
    update_user_meta( $user_id, 'bank_account_name', $sanitize_text( 'ggr_kyc_iban_name' ) );
    update_user_meta( $user_id, 'bank_account_iban', $sanitize_text( 'ggr_kyc_iban' ) );

    // Zakelijk
    $company = $sanitize_text( 'ggr_kyc_company' );
    $kvk     = $sanitize_text( 'ggr_kyc_kvk' );
    if ( 'zakelijk' === $participation_profile ) {
        update_user_meta( $user_id, 'ggr_kyc_company', $company );
        update_user_meta( $user_id, 'ggr_kyc_kvk', $kvk );
        update_user_meta( $user_id, 'company_name', $company );
        update_user_meta( $user_id, 'billing_company', $company );
        update_user_meta( $user_id, 'company_kvk', $kvk );
    } else {
        update_user_meta( $user_id, 'ggr_kyc_company', '' );
        update_user_meta( $user_id, 'ggr_kyc_kvk', '' );
        update_user_meta( $user_id, 'company_name', '' );
        update_user_meta( $user_id, 'billing_company', '' );
        update_user_meta( $user_id, 'company_kvk', '' );
    }

    // Mede-participant
    if ( 'ja' === $has_co ) {
        foreach ( $co_values as $form_key => $value ) {
            foreach ( $co_meta_keys[ $form_key ] as $meta_key ) {
                update_user_meta( $user_id, $meta_key, $value );
            }
        }
    } else {
        foreach ( $co_meta_keys as $meta_keys ) {
            foreach ( $meta_keys as $meta_key ) {
                update_user_meta( $user_id, $meta_key, '' );
            }
        }
    }

    $origin_sources = isset( $_POST['ggr_origin_sources'] ) ? (array) wp_unslash( $_POST['ggr_origin_sources'] ) : array();
    $origin_sources = array_map( 'sanitize_key', $origin_sources );
    update_user_meta( $user_id, 'ggr_origin_sources', $origin_sources );
    $origin_country = isset( $_POST['ggr_origin_country'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_origin_country'] ) ) : '';
    update_user_meta( $user_id, 'ggr_origin_country', $origin_country );
    update_user_meta( $user_id, 'ggr_origin_notes', $sanitize_text( 'ggr_origin_notes' ) );

    // Taal
    if ( isset( $_POST['ggr_locale'] ) ) {
        $locale = sanitize_text_field( wp_unslash( $_POST['ggr_locale'] ) );
        if ( $locale === '' ) {
            delete_user_meta( $user_id, 'locale' );
        } else {
            update_user_meta( $user_id, 'locale', $locale );
        }
    }

    update_user_meta( $user_id, 'ggr_profile_updated_at', $profile_timestamp );
}

function ggr_portal_save_account_fields_in_profile( $user_id ) {
    if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    
    $before_snapshot = function_exists( 'ggr_portal_get_participant_audit_snapshot' )
        ? ggr_portal_get_participant_audit_snapshot( $user_id )
        : array();

    ggr_portal_store_participant_profile_data( $user_id );

    // Wachtwoord
    if ( ! empty( $_POST['ggr_new_password'] ) ) {
        $new_pass = (string) wp_unslash( $_POST['ggr_new_password'] );
        wp_update_user( [
            'ID'        => $user_id,
            'user_pass' => $new_pass,
        ] );

        if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
            ggr_portal_log_participant_action( $user_id, 'password_reset', 'Wachtwoord aangepast.', array() );
        }
    }

    // Rol
    if ( current_user_can( 'promote_users' ) && isset( $_POST['ggr_role'] ) ) {
        $new_role       = sanitize_text_field( wp_unslash( $_POST['ggr_role'] ) );
        $editable_roles = get_editable_roles();
        if ( isset( $editable_roles[ $new_role ] ) ) {
            $user_obj = new WP_User( $user_id );
            foreach ( $user_obj->roles as $role ) {
                $user_obj->remove_role( $role );
            }
            $user_obj->add_role( $new_role );
        }
    }

    if ( function_exists( 'ggr_portal_log_participant_profile_changes' ) ) {
        ggr_portal_log_participant_profile_changes( $user_id, $before_snapshot );
    }
}

function ggr_portal_handle_participant_profile_save() {
    if (
        ! isset( $_POST['ggr_participant_profile_nonce'], $_POST['ggr_participant_user_id'] )
        || ! wp_verify_nonce( $_POST['ggr_participant_profile_nonce'], 'ggr_participant_profile_save' )
    ) {
        return;
    }

    if ( ! current_user_can( 'list_users' ) ) {
        return;
    }

    $user_id = (int) $_POST['ggr_participant_user_id'];
    $user    = get_user_by( 'ID', $user_id );

    if ( ! $user ) {
        return;
    }
    
    $before_snapshot = function_exists( 'ggr_portal_get_participant_audit_snapshot' )
        ? ggr_portal_get_participant_audit_snapshot( $user_id )
        : array();
        
    ggr_portal_store_participant_profile_data( $user_id );

    // Wachtwoord
    if ( ! empty( $_POST['ggr_new_password'] ) ) {
        $new_pass = (string) wp_unslash( $_POST['ggr_new_password'] );
        wp_update_user( [
            'ID'        => $user_id,
            'user_pass' => $new_pass,
        ] );

        if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
            ggr_portal_log_participant_action( $user_id, 'password_reset', 'Wachtwoord aangepast.', array() );
        }
    }

    // Rol
    if ( current_user_can( 'promote_users' ) && isset( $_POST['ggr_role'] ) ) {
        $new_role       = sanitize_text_field( wp_unslash( $_POST['ggr_role'] ) );
        $editable_roles = get_editable_roles();
        if ( isset( $editable_roles[ $new_role ] ) ) {
            $user_obj = new WP_User( $user_id );
            foreach ( $user_obj->roles as $role ) {
                $user_obj->remove_role( $role );
            }
            $user_obj->add_role( $new_role );
        }
    }
    
    if ( function_exists( 'ggr_portal_log_participant_profile_changes' ) ) {
        ggr_portal_log_participant_profile_changes( $user_id, $before_snapshot );
    }

    // Redirect
    $redirect = add_query_arg(
        [
            'page'    => 'ggr-participant-profiel',
            'user_id' => $user_id,
            'updated' => 1,
        ],
        admin_url( 'users.php' )
    );
    wp_safe_redirect( $redirect );
    exit;
}

/**
 * Renderfunctie voor de participant-profielpagina
 */
function ggr_portal_render_participant_profile_page() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_die( 'Geen toegang.' );
    }

    $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;

    // Keuzescherm als er nog geen user_id is
    if ( ! $user_id ) {
        ?>
        <div class="wrap">
            <h1>Profiel</h1>
            <form method="get" style="margin-top: 20px;">
                <input type="hidden" name="page" value="ggr-participant-profiel" />
                <label for="user_id">Kies gebruiker:</label>
                <?php
                wp_dropdown_users( [
                    'name'             => 'user_id',
                    'id'               => 'user_id',
                    'show_option_none' => '— Selecteer gebruiker —',
                    'role__in'         => [ 'participant', 'lead' ],
                    'show'             => 'display_name',
                ] );
                ?>
                <button class="button button-primary" type="submit">Laden</button>
            </form>
        </div>
        <?php
        return;
    }

    $user = get_user_by( 'ID', $user_id );
    if ( ! $user ) {
        echo '<div class="wrap"><h1>Profiel</h1><p>Gebruiker niet gevonden.</p></div>';
        return;
    }

        $meta         = get_user_meta( $user_id );
    $locale_meta  = isset( $meta['locale'][0] )       ? $meta['locale'][0]       : '';
    $first_name   = isset( $meta['first_name'][0] )   ? $meta['first_name'][0]   : '';
    $last_name    = isset( $meta['last_name'][0] )    ? $meta['last_name'][0]    : '';
    $phone        = isset( $meta['phone'][0] )        ? $meta['phone'][0]        : '';
    $company_name = isset( $meta['company_name'][0] ) ? $meta['company_name'][0] : '';
    $company_kvk  = isset( $meta['company_kvk'][0] )  ? $meta['company_kvk'][0]  : '';

    // Onboarding extra's
    $account_type       = isset( $meta['ggr_account_type'][0] ) ? $meta['ggr_account_type'][0] : '';
    $nationality        = isset( $meta['ggr_nationality'][0] )  ? $meta['ggr_nationality'][0]  : '';
    $investment         = isset( $meta['ggr_investment'][0] )   ? $meta['ggr_investment'][0]   : '';
    $investment_amount  = isset( $meta['ggr_investment_amount'][0] ) ? $meta['ggr_investment_amount'][0] : '';
    $marketing_optin    = isset( $meta['ggr_marketing_optin'][0] ) ? (int) $meta['ggr_marketing_optin'][0] : 0;
    $onboarding_status  = function_exists( 'ggr_onboarding_get_status' ) ? ggr_onboarding_get_status( $user_id ) : ( isset( $meta['ggr_onboarding_status'][0] ) ? $meta['ggr_onboarding_status'][0] : '' );
    $onboarding_updated = isset( $meta['ggr_onboarding_updated_at'][0] ) ? $meta['ggr_onboarding_updated_at'][0] : '';
    $doc_feedback       = isset( $meta['ggr_doc_feedback'][0] ) ? $meta['ggr_doc_feedback'][0] : '';
    $contract_signed_at = isset( $meta['ggr_contract_signed_at'][0] ) ? $meta['ggr_contract_signed_at'][0] : '';
    $contract_preview_url = isset( $meta['ggr_contract_preview_url'][0] ) ? $meta['ggr_contract_preview_url'][0] : '';    
    $payment_confirmation_at = isset( $meta['ggr_payment_confirmation_at'][0] ) ? $meta['ggr_payment_confirmation_at'][0] : '';
    $payment_received   = isset( $meta['ggr_payment_received'][0] ) ? (int) $meta['ggr_payment_received'][0] : 0;
    $payment_received_at = isset( $meta['ggr_payment_received_at'][0] ) ? $meta['ggr_payment_received_at'][0] : '';
    $first_trade_day    = isset( $meta['ggr_first_trade_day'][0] ) ? $meta['ggr_first_trade_day'][0] : '';
    
    if ( $investment_amount === '' ) {
        $investment_amount = $investment;
    }


    // Mede-participant
    $co_first = isset( $meta['co_first_name'][0] ) ? $meta['co_first_name'][0] : '';
    $co_last  = isset( $meta['co_last_name'][0] )  ? $meta['co_last_name'][0]  : '';
    $co_email = isset( $meta['co_email'][0] )      ? $meta['co_email'][0]      : '';
    $co_phone = isset( $meta['co_phone'][0] )      ? $meta['co_phone'][0]      : '';

    // Adres
    $p_street  = isset( $meta['address_street'][0] )   ? $meta['address_street'][0]   : '';
    $p_zip     = isset( $meta['address_postcode'][0] ) ? $meta['address_postcode'][0] : '';
    $p_city    = isset( $meta['address_city'][0] )     ? $meta['address_city'][0]     : '';
    $p_country = isset( $meta['address_country'][0] )  ? $meta['address_country'][0]  : '';

    // Bank
    $bank_iban = isset( $meta['bank_account_iban'][0] ) ? $meta['bank_account_iban'][0] : '';
    $bank_name = isset( $meta['bank_account_name'][0] ) ? $meta['bank_account_name'][0] : '';

    $participation_profile = isset( $meta['ggr_participation_profile'][0] ) ? $meta['ggr_participation_profile'][0] : '';
    $has_co_participant    = isset( $meta['ggr_has_co_participant'][0] ) ? $meta['ggr_has_co_participant'][0] : 'nee';
    $participation_amount  = isset( $meta['ggr_participation_amount'][0] ) ? $meta['ggr_participation_amount'][0] : '';

    $kyc_first_name   = isset( $meta['ggr_kyc_first_name'][0] )   ? $meta['ggr_kyc_first_name'][0]   : $first_name;
    $kyc_last_name    = isset( $meta['ggr_kyc_last_name'][0] )    ? $meta['ggr_kyc_last_name'][0]    : $last_name;
    $kyc_phone        = isset( $meta['ggr_kyc_phone'][0] )        ? $meta['ggr_kyc_phone'][0]        : $phone;    
    $kyc_birth_date   = isset( $meta['ggr_kyc_birth_date'][0] )   ? $meta['ggr_kyc_birth_date'][0]   : '';
    $kyc_address      = isset( $meta['ggr_kyc_address'][0] )      ? $meta['ggr_kyc_address'][0]      : $p_street;
    $kyc_postcode     = isset( $meta['ggr_kyc_postcode'][0] )     ? $meta['ggr_kyc_postcode'][0]     : $p_zip;
    $kyc_city_country = isset( $meta['ggr_kyc_city_country'][0] ) ? $meta['ggr_kyc_city_country'][0] : $p_city;
    $kyc_country      = isset( $meta['ggr_kyc_country'][0] )      ? $meta['ggr_kyc_country'][0]      : $p_country;
    $kyc_birth_place  = isset( $meta['ggr_kyc_birth_place'][0] )  ? $meta['ggr_kyc_birth_place'][0]  : '';
    $kyc_bsn          = isset( $meta['ggr_kyc_bsn'][0] )          ? $meta['ggr_kyc_bsn'][0]          : '';
    $kyc_iban_name    = isset( $meta['ggr_kyc_iban_name'][0] )    ? $meta['ggr_kyc_iban_name'][0]    : $bank_name;
    $kyc_iban         = isset( $meta['ggr_kyc_iban'][0] )         ? $meta['ggr_kyc_iban'][0]         : $bank_iban;
    $kyc_company      = isset( $meta['ggr_kyc_company'][0] )      ? $meta['ggr_kyc_company'][0]      : $company_name;
    $kyc_kvk          = isset( $meta['ggr_kyc_kvk'][0] )          ? $meta['ggr_kyc_kvk'][0]          : $company_kvk;
    $kyc_pep          = isset( $meta['ggr_kyc_pep'][0] )          ? $meta['ggr_kyc_pep'][0]          : '';
    $kyc_us_person    = isset( $meta['ggr_kyc_us_person'][0] )    ? $meta['ggr_kyc_us_person'][0]    : '';

    $co_first_name = isset( $meta['ggr_co_first_name'][0] ) ? $meta['ggr_co_first_name'][0] : $co_first;
    $co_last_name  = isset( $meta['ggr_co_last_name'][0] )  ? $meta['ggr_co_last_name'][0]  : $co_last;
    $co_birth_date = isset( $meta['ggr_co_birth_date'][0] ) ? $meta['ggr_co_birth_date'][0] : '';
    $co_phone      = isset( $meta['ggr_co_phone'][0] )      ? $meta['ggr_co_phone'][0]      : $co_phone;
    $co_address    = isset( $meta['ggr_co_address'][0] )    ? $meta['ggr_co_address'][0]    : '';
    $co_postcode   = isset( $meta['ggr_co_postcode'][0] )   ? $meta['ggr_co_postcode'][0]   : '';
    $co_city       = isset( $meta['ggr_co_city_country'][0] ) ? $meta['ggr_co_city_country'][0] : '';
    $co_country    = isset( $meta['ggr_co_country'][0] ) ? $meta['ggr_co_country'][0] : '';
    $co_birth_country = isset( $meta['ggr_co_birth_country'][0] ) ? $meta['ggr_co_birth_country'][0] : '';
    $co_birth_place= isset( $meta['ggr_co_birth_place'][0] ) ? $meta['ggr_co_birth_place'][0] : '';
    $co_bsn        = isset( $meta['ggr_co_bsn'][0] ) ? $meta['ggr_co_bsn'][0] : '';
    $co_pep        = isset( $meta['ggr_co_pep'][0] ) ? $meta['ggr_co_pep'][0] : '';
    $co_us_person  = isset( $meta['ggr_co_us_person'][0] ) ? $meta['ggr_co_us_person'][0] : '';

    $origin_notes   = isset( $meta['ggr_origin_notes'][0] ) ? $meta['ggr_origin_notes'][0] : '';
    $origin_sources = get_user_meta( $user_id, 'ggr_origin_sources', true );
    if ( ! is_array( $origin_sources ) ) {
        $origin_sources = array();
    }
    $origin_country = isset( $meta['ggr_origin_country'][0] ) ? $meta['ggr_origin_country'][0] : ( $kyc_country ? $kyc_country : $p_country );
    
    $profile_updated_raw = isset( $meta['ggr_profile_updated_at'][0] ) ? $meta['ggr_profile_updated_at'][0] : '';
    $last_login_raw      = isset( $meta['ggr_last_login_at'][0] )     ? $meta['ggr_last_login_at'][0]     : '';

    $format_datetime = function( $value ) {
        if ( ! $value ) {
            return '';
        }

        if ( function_exists( 'ggr_portal_format_datetime_nl' ) ) {
            return ggr_portal_format_datetime_nl( $value );
        }

        if ( function_exists( 'ggrp_fe_format_nl_datetime' ) ) {
            return ggrp_fe_format_nl_datetime( $value );
        }

        $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

        if ( ! $timestamp ) {
            return '';
        }

        return date_i18n( 'd-m-Y H:i', $timestamp );
    };

    $format_date_only = function( $value ) use ( $format_datetime ) {
        if ( ! $value ) {
            return '';
        }

        if ( function_exists( 'ggr_portal_format_date_nl' ) ) {
            return ggr_portal_format_date_nl( $value );
        }

        $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

        if ( ! $timestamp ) {
            return '';
        }

        return date_i18n( 'd-m-Y', $timestamp );
    };

    $onboarding_updated_label = $format_datetime( $onboarding_updated );
    $profile_updated_label    = $format_datetime( $profile_updated_raw );
    $last_login_label        = $format_datetime( $last_login_raw );
    
        // Onboarding documenten
    $document_labels = array(
        'ggr_doc_id'             => 'Identiteitsbewijs',
        'ggr_doc_funds'          => 'Bewijs herkomst middelen',
        'ggr_doc_registration'   => 'KVK-uittreksel',
        'ggr_doc_ubo'            => 'UBO-register / aandeelhouderslijst',
        'ggr_doc_share_register' => 'Aandeelhoudersregister / overeenkomst',
        'ggr_doc_other'          => 'Overige documenten',
    );

    $uploaded_documents = array();
    foreach ( $document_labels as $meta_key => $label ) {
        $doc_url = isset( $meta[ $meta_key ][0] ) ? $meta[ $meta_key ][0] : '';
        if ( $doc_url ) {
            $uploaded_documents[ $meta_key ] = array(
                'label'    => $label,
                'url'      => $doc_url,
                'meta_key' => $meta_key,
            );
        }
    }


    // GGR details (nog gebaseerd op oude berekening)
    $ggr_latest = function_exists( 'ggr_portal_get_latest_calculated_values_for_user' )
        ? ggr_portal_get_latest_calculated_values_for_user( $user_id )
        : false;

    $all_roles     = get_editable_roles();
    $current_roles = (array) $user->roles;
    $current_role  = reset( $current_roles );

    ?>
    <div class="wrap ggr-participant-wrap">
        <h1>Profiel – <?php echo esc_html( $user->display_name ); ?> (ID: <?php echo (int) $user_id; ?>)</h1>

        <!-- same flex CSS als in profiel-blok -->
        <style>
            .ggr-admin-columns {
                display: flex;
                gap: 40px;
                flex-wrap: wrap;
                align-items: flex-start;
            }
            .ggr-admin-col {
                min-width: 260px;
                max-width: 420px;
            }
            .ggr-admin-col h4 {
                margin: 0 0 8px;
                font-size: 14px;
            }
            .ggr-admin-inline-field {
                margin-bottom: 10px;
            }
            .ggr-admin-inline-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 2px;
            }
            .ggr-admin-inline-field input,
            .ggr-admin-inline-field select {
                width: 100%;
            }
            .ggr-admin-inline-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                margin-top: 6px;
            }
            .ggr-admin-meta-note {
                color: #4b5563;
                font-style: italic;
                margin-top: 6px;
            }
            .ggr-admin-doc-list {
                margin: 0;
                padding-left: 18px;
            }
            .ggr-admin-doc-list li {
                margin-bottom: 6px;
            }
            .ggr-admin-top-actions {
                display: flex;
                justify-content: flex-end;
                margin: 0 0 12px;
            }
        </style>

        <!-- Snel wisselen -->
        <form method="get" class="ggr-participant-switcher" style="margin: 10px 0 20px;">
            <input type="hidden" name="page" value="ggr-participant-profiel" />
            <label for="ggr_participant_switch" style="margin-right:8px;">Ga naar andere gebruiker:</label>
            <?php
            wp_dropdown_users( [
                'name'             => 'user_id',
                'id'               => 'ggr_participant_switch',
                'selected'         => $user_id,
                'role__in'         => [ 'participant', 'lead' ],
                'show'             => 'display_name',
                'show_option_none' => '— Kies gebruiker —',
            ] );
            ?>
            <noscript><button class="button">Openen</button></noscript>
        </form>

        <script>
        (function() {
            var select = document.getElementById('ggr_participant_switch');
            if (!select) return;
            select.addEventListener('change', function () {
                if (!this.value) return;
                var url = new URL(window.location.href);
                url.searchParams.set('page', 'ggr-participant-profiel');
                url.searchParams.set('user_id', this.value);
                window.location.href = url.toString();
            });
        })();
        </script>

        <?php if ( isset( $_GET['updated'] ) && (int) $_GET['updated'] === 1 ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Profielgegevens opgeslagen.</p>
            </div>
        <?php endif; ?>

        <?php
        $countries = function_exists( 'ggr_get_countries_nl' ) ? ggr_get_countries_nl() : array( 'Nederland' );
        ?>

        <form method="post" class="ggr-participant-form">
            <?php wp_nonce_field( 'ggr_participant_profile_save', 'ggr_participant_profile_nonce' ); ?>
            <input type="hidden" name="ggr_participant_user_id" value="<?php echo (int) $user_id; ?>" />
            <div class="ggr-admin-top-actions">
                <button type="submit" class="button button-primary">Wijzigingen opslaan</button>
            </div>
                        <h2 class="title">Overzicht</h2>           
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Status & voorkeuren</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <div class="ggr-admin-col">
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_onboarding_status">Onboarding status</label>
                                    <select name="ggr_onboarding_status" id="ggr_onboarding_status">
                                        <?php
                                        $stages = function_exists( 'ggr_onboarding_get_stages' ) ? ggr_onboarding_get_stages() : array();
                                        if ( empty( $stages ) ) {
                                            $stages = array( $onboarding_status => $onboarding_status );
                                        }
                                        foreach ( $stages as $key => $label ) :
                                            ?>
                                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $onboarding_status, $key ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ( $onboarding_updated_label ) : ?>
                                        <p class="description">Onboarding bijgewerkt: <?php echo esc_html( $onboarding_updated_label ); ?></p>
                                    <?php endif; ?>
                                    <?php if ( $profile_updated_label ) : ?>
                                        <p class="description">Profiel bijgewerkt: <?php echo esc_html( $profile_updated_label ); ?></p>
                                    <?php endif; ?>
                                    <?php if ( $last_login_label ) : ?>
                                        <p class="description">Laatste login: <?php echo esc_html( $last_login_label ); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>
                                        <input type="checkbox" name="ggr_marketing_optin" id="ggr_marketing_optin" value="1" <?php checked( 1, $marketing_optin ); ?> />
                                        Marketing- en investeringsupdates toegestaan
                                    </label>
                                </div>
                            </div>
                            <div class="ggr-admin-col">
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_locale">Interface taal</label>
                                    <select name="ggr_locale" id="ggr_locale">
                                        <option value="" <?php selected( $locale_meta, '' ); ?>>Site standaard</option>
                                        <option value="nl_NL" <?php selected( $locale_meta, 'nl_NL' ); ?>>Nederlands</option>
                                        <option value="en_US" <?php selected( $locale_meta, 'en_US' ); ?>>Engels (US)</option>
                                    </select>
                                </div>
                                <p class="description">Deze voorkeur bepaalt de taal van het portal.</p> 
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <h2 class="title">Documentatie (stap 1 t/m 5)</h2>           
            <h4 class="title">Stap 1: Investeringsbedrag</h4>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ggr_participation_amount">Beoogd bedrag (€)</label></th>
                    <td>
                        <input name="ggr_participation_amount" id="ggr_participation_amount" type="text" value="<?php echo esc_attr( $participation_amount ); ?>" />
                        <p class="description">Minimale inschrijving: € 5.000.</p>
                    </td>
                </tr>
            </table>

            <h4 class="title">Stap 2: Profielkeuzes</h4>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Profiel</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <div class="ggr-admin-col">
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_participation_profile">Deelname als</label>
                                    <select name="ggr_participation_profile" id="ggr_participation_profile">
                                        <option value="">Kies...</option>
                                        <option value="prive" <?php selected( $participation_profile, 'prive' ); ?>>Privé</option>
                                        <option value="zakelijk" <?php selected( $participation_profile, 'zakelijk' ); ?>>Zakelijk</option>
                                    </select>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>Mede-participant</label>
                                    <div>
                                        <label style="margin-right:10px;">
                                            <input type="radio" name="ggr_has_co_participant" value="ja" <?php checked( $has_co_participant, 'ja' ); ?> /> Ja
                                        </label>
                                        <label>
                                            <input type="radio" name="ggr_has_co_participant" value="nee" <?php checked( $has_co_participant, 'nee' ); ?> /> Nee
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="ggr-admin-col">
                                <h4>Zakelijke gegevens</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_company">Bedrijfsnaam</label>
                                    <input type="text" name="ggr_kyc_company" id="ggr_kyc_company" value="<?php echo esc_attr( $kyc_company ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_kvk">KvK-nummer</label>
                                    <input type="text" name="ggr_kyc_kvk" id="ggr_kyc_kvk" value="<?php echo esc_attr( $kyc_kvk ); ?>" />
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <h4 class="title">Stap 3: Persoonlijke gegevens</h4>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Contact & identiteit</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <div class="ggr-admin-col">
                                <h4>Participant</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_first_name">Voornaam</label>
                                    <input name="ggr_kyc_first_name" id="ggr_kyc_first_name" type="text" value="<?php echo esc_attr( $kyc_first_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_last_name">Achternaam</label>
                                    <input name="ggr_kyc_last_name" id="ggr_kyc_last_name" type="text" value="<?php echo esc_attr( $kyc_last_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_email">E-mailadres</label>
                                    <input name="ggr_email" id="ggr_email" type="email" value="<?php echo esc_attr( $user->user_email ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_phone">Telefoonnummer</label>
                                    <input name="ggr_kyc_phone" id="ggr_kyc_phone" type="text" value="<?php echo esc_attr( $kyc_phone ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_birth_date">Geboortedatum</label>
                                    <input name="ggr_kyc_birth_date" id="ggr_kyc_birth_date" type="date" value="<?php echo esc_attr( $kyc_birth_date ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_birth_place">Geboorteplaats</label>
                                    <input name="ggr_kyc_birth_place" id="ggr_kyc_birth_place" type="text" value="<?php echo esc_attr( $kyc_birth_place ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_birth_country">Geboorteland</label>
                                    <select name="ggr_kyc_birth_country" id="ggr_kyc_birth_country">
                                        <option value="" <?php selected( '', $kyc_birth_country ); ?>>Maak een keuze</option>                                        
                                        <?php foreach ( $countries as $country ) : ?>
                                            <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $kyc_birth_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_address">Adres</label>
                                    <input name="ggr_kyc_address" id="ggr_kyc_address" type="text" value="<?php echo esc_attr( $kyc_address ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_postcode">Postcode</label>
                                    <input name="ggr_kyc_postcode" id="ggr_kyc_postcode" type="text" value="<?php echo esc_attr( $kyc_postcode ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_city_country">Plaats</label>
                                    <input name="ggr_kyc_city_country" id="ggr_kyc_city_country" type="text" value="<?php echo esc_attr( $kyc_city_country ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_country">Land</label>
                                    <select name="ggr_kyc_country" id="ggr_kyc_country">
                                        <option value="" <?php selected( '', $kyc_country ); ?>>Maak een keuze</option>                                        
                                        <?php foreach ( $countries as $country ) : ?>
                                            <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $kyc_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_bsn">BSN</label>
                                    <input name="ggr_kyc_bsn" id="ggr_kyc_bsn" type="text" value="<?php echo esc_attr( $kyc_bsn ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_iban_name">Tenaamstelling IBAN</label>
                                    <input name="ggr_kyc_iban_name" id="ggr_kyc_iban_name" type="text" value="<?php echo esc_attr( $kyc_iban_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_kyc_iban">IBAN</label>
                                    <input name="ggr_kyc_iban" id="ggr_kyc_iban" type="text" value="<?php echo esc_attr( $kyc_iban ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>Politiek prominent persoon</label>
                                    <div>
                                        <label style="margin-right:10px;">
                                            <input type="radio" name="ggr_kyc_pep" value="ja" <?php checked( $kyc_pep, 'ja' ); ?> /> Ja
                                        </label>
                                        <label>
                                            <input type="radio" name="ggr_kyc_pep" value="nee" <?php checked( $kyc_pep, 'nee' ); ?> /> Nee
                                        </label>
                                    </div>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>US person</label>
                                    <div>
                                        <label style="margin-right:10px;">
                                            <input type="radio" name="ggr_kyc_us_person" value="ja" <?php checked( $kyc_us_person, 'ja' ); ?> /> Ja
                                        </label>
                                        <label>
                                            <input type="radio" name="ggr_kyc_us_person" value="nee" <?php checked( $kyc_us_person, 'nee' ); ?> /> Nee
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="ggr-admin-col">
                                <h4>Mede-participant</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_first_name">Voornaam</label>
                                    <input name="ggr_co_first_name" id="ggr_co_first_name" type="text" value="<?php echo esc_attr( $co_first_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_last_name">Achternaam</label>
                                    <input name="ggr_co_last_name" id="ggr_co_last_name" type="text" value="<?php echo esc_attr( $co_last_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_email">E-mailadres</label>
                                    <input name="ggr_co_email" id="ggr_co_email" type="email" value="<?php echo esc_attr( $co_email ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_phone">Telefoonnummer</label>
                                    <input name="ggr_co_phone" id="ggr_co_phone" type="text" value="<?php echo esc_attr( $co_phone ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_birth_date">Geboortedatum</label>
                                    <input name="ggr_co_birth_date" id="ggr_co_birth_date" type="date" value="<?php echo esc_attr( $co_birth_date ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_birth_place">Geboorteplaats</label>
                                    <input name="ggr_co_birth_place" id="ggr_co_birth_place" type="text" value="<?php echo esc_attr( $co_birth_place ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_birth_country">Geboorteland</label>
                                    <select name="ggr_co_birth_country" id="ggr_co_birth_country">
                                        <option value="" <?php selected( '', $co_birth_country ); ?>>Maak een keuze</option>                                        
                                        <?php foreach ( $countries as $country ) : ?>
                                            <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $co_birth_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_address">Adres</label>
                                    <input name="ggr_co_address" id="ggr_co_address" type="text" value="<?php echo esc_attr( $co_address ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_postcode">Postcode</label>
                                    <input name="ggr_co_postcode" id="ggr_co_postcode" type="text" value="<?php echo esc_attr( $co_postcode ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_city_country">Plaats</label>
                                    <input name="ggr_co_city_country" id="ggr_co_city_country" type="text" value="<?php echo esc_attr( $co_city ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_country">Land</label>
                                    <select name="ggr_co_country" id="ggr_co_country">
                                        <option value="" <?php selected( '', $co_country ); ?>>Maak een keuze</option>                                        
                                        <?php foreach ( $countries as $country ) : ?>
                                            <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $co_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_bsn">BSN</label>
                                    <input name="ggr_co_bsn" id="ggr_co_bsn" type="text" value="<?php echo esc_attr( $co_bsn ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>Politiek prominent persoon</label>
                                    <div>
                                        <label style="margin-right:10px;">
                                            <input type="radio" name="ggr_co_pep" value="ja" <?php checked( $co_pep, 'ja' ); ?> /> Ja
                                        </label>
                                        <label>
                                            <input type="radio" name="ggr_co_pep" value="nee" <?php checked( $co_pep, 'nee' ); ?> /> Nee
                                        </label>
                                    </div>
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label>US person</label>
                                    <div>
                                        <label style="margin-right:10px;">
                                            <input type="radio" name="ggr_co_us_person" value="ja" <?php checked( $co_us_person, 'ja' ); ?> /> Ja
                                        </label>
                                        <label>
                                            <input type="radio" name="ggr_co_us_person" value="nee" <?php checked( $co_us_person, 'nee' ); ?> /> Nee
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <h4 class="title">Stap 4: Herkomst vermogen</h4>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Herkomst vermogen</th>
                    <td>
                        <?php if ( ! is_array( $origin_sources ) ) { $origin_sources = array(); } ?>                        
                        <div class="ggr-admin-inline-field">
                            <label for="ggr_origin_country">Land van herkomst</label>
                            <select name="ggr_origin_country" id="ggr_origin_country">
                                <option value="" <?php selected( '', $origin_country ); ?>>Maak een keuze</option>
                                <?php foreach ( $countries as $country ) : ?>
                                    <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $origin_country, $country ); ?>><?php echo esc_html( $country ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ggr-admin-inline-field">
                            <label>Bronnen</label>
                            <div>
                                <?php
                                $origin_labels = array(
                                    'salary'   => 'In loondienst',
                                    'business' => 'Ondernemingsactiviteiten',
                                    'rental'   => 'Rente/dividend/huur',
                                    'savings'  => 'Vermogen/erfenis/pensioen',
                                    'sale'     => 'Opbrengst verkoop',
                                    'loan'     => 'Ontvangen lening',
                                    'other'    => 'Andere herkomst',
                                );
                                foreach ( $origin_labels as $key => $label ) :
                                    ?>
                                    <label style="display:block;">
                                        <input type="checkbox" name="ggr_origin_sources[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $origin_sources, true ) ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="ggr-admin-inline-field">
                            <label for="ggr_origin_notes">Toelichting</label>
                            <textarea name="ggr_origin_notes" id="ggr_origin_notes" rows="3" style="width:100%;"><?php echo esc_textarea( $origin_notes ); ?></textarea>
                        </div>
                    </td>
                </tr>
            </table>

            <h4 class="title">Stap 5: Documenten</h4>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Uploads</th>
                    <td>
                        <?php if ( ! empty( $uploaded_documents ) ) : ?>
                            <ul class="ggr-admin-doc-list">
                                <?php foreach ( $uploaded_documents as $doc ) : ?>
                                    <li>
                                        <strong><?php echo esc_html( $doc['label'] ); ?>:</strong>
                                        <a href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank" rel="noopener noreferrer">Bekijken</a>
                                        <button type="submit"
                                                class="button-link-delete"
                                                name="ggr_delete_document"
                                                value="<?php echo esc_attr( $doc['meta_key'] ); ?>"
                                                onclick="return confirm('Weet je zeker dat je dit document wilt verwijderen?');">
                                            Verwijderen
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p>Er zijn nog geen documenten geüpload.</p>
                        <?php endif; ?>

                        <div class="ggr-admin-inline-field">
                            <label for="ggr_doc_feedback">Opmerking voor lead (bij afkeuren)</label>
                            <textarea name="ggr_doc_feedback" id="ggr_doc_feedback" rows="3" style="width:100%;"><?php echo esc_textarea( $doc_feedback ); ?></textarea>
                            <p class="description">Wordt meegenomen in de afwijs-e-mail en kan gebruikt worden als toelichting.</p>
                        </div>

                        <div class="ggr-admin-inline-actions">
                            <button type="submit" name="ggr_doc_action" value="approve" class="button button-primary">Documentatie goedgekeurd</button>
                            <button type="submit" name="ggr_doc_action" value="reject" class="button">Afkeuren en terug naar documentatie</button>
                        </div>
                    </td>
                </tr>
            </table>

            <h2 class="title">Contract ondertekend</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Status</th>
                    <td>
                        <label style="display:block; margin-bottom:8px;">
                            <input type="checkbox" name="ggr_contract_signed_admin" value="1" <?php checked( (bool) $contract_signed_at, true ); ?> />
                            Contract ondertekend
                        </label>
                        <div class="ggr-admin-inline-field">
                            <label for="ggr_contract_signature_admin">Handtekening / notitie</label>
                            <textarea name="ggr_contract_signature_admin" id="ggr_contract_signature_admin" rows="3" style="width:100%;"><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_contract_signature_admin', true ) ); ?></textarea>
                            <p class="description">Gebruik dit veld voor een opgeslagen handtekening of referentie naar het ondertekende document.</p>
                        </div>

                        <?php if ( $contract_signed_label ) : ?>
                            <p class="ggr-admin-meta-note">Lead heeft de overeenkomst bevestigd op: <?php echo esc_html( $contract_signed_label ); ?>.</p>
                        <?php endif; ?>

                        <?php if ( $contract_preview_url ) : ?>
                            <p class="ggr-admin-meta-note">Ondertekend document: <a href="<?php echo esc_url( $contract_preview_url ); ?>" target="_blank" rel="noopener noreferrer">bekijk document</a>.</p>                            
                        <?php if ( $existing_signature_image = get_user_meta( $user_id, 'ggr_contract_signature', true ) ) : ?>
                            <p class="ggr-admin-meta-note">Opgeslagen handtekening van deelnemer:</p>
                            <img src="<?php echo esc_url( $existing_signature_image ); ?>" alt="Handtekening" style="max-width:320px; border:1px solid #e5e7eb; padding:6px; border-radius:4px; background:#fff;">
                        <?php endif; ?>
                        <?php if ( $existing_signature_text = get_user_meta( $user_id, 'ggr_contract_signature_text', true ) ) : ?>
                            <p class="ggr-admin-meta-note">Getypte handtekening: <?php echo esc_html( $existing_signature_text ); ?></p>
                        <?php endif; ?>
                        <?php endif; ?>                        
                    </td>
                </tr>
            </table>
            
            <h2 class="title">Betaling & start</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Ontvangst betaling</th>
                    <td>
                        <label style="display:block; margin-bottom:8px;">
                            <input type="checkbox" name="ggr_payment_received" value="1" <?php checked( $payment_received, 1 ); ?> /> Betaling ontvangen en gecontroleerd
                        </label>
                        <?php if ( $payment_confirmation_label ) : ?>
                            <p class="ggr-admin-meta-note">Lead gaf aan betaald te hebben op: <?php echo esc_html( $payment_confirmation_label ); ?>.</p>
                        <?php endif; ?>                        
                        <div class="ggr-admin-inline-field">
                            <label for="ggr_first_trade_day">Eerste handelsdag</label>
                            <input type="date" id="ggr_first_trade_day" name="ggr_first_trade_day" value="<?php echo esc_attr( $first_trade_day ); ?>" />
                            <p class="description">Wordt gebruikt om de actieve startdatum van de participant vast te leggen.</p>
                            <?php if ( $first_trade_day_label ) : ?>
                                <p class="description">Weergave: <?php echo esc_html( $first_trade_day_label ); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ( $payment_received_at_label ) : ?>
                            <p class="ggr-admin-meta-note">Ontvangen gemarkeerd op: <?php echo esc_html( $payment_received_at_label ); ?>.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
<!-- GGR DETAILS -->
            <h2 class="title">GGR details</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Laatste stand</th>
                    <td>
                        <?php if ( $ggr_latest ) : ?>
                            <table class="widefat striped" style="max-width:600px;">
                                <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Positiewaarde</th>
                                    <th>Totaal participaties</th>
                                    <th>Dividend totaal</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>
                                        <?php
                                        $d = DateTime::createFromFormat( 'Y-m-d', $ggr_latest['datum'] );
                                        echo $d ? esc_html( $d->format( 'd-m-Y' ) ) : esc_html( $ggr_latest['datum'] );
                                        ?>
                                    </td>
                                    <td><?php echo '€ ' . number_format( (float) $ggr_latest['positiewaarde'], 2, ',', '.' ); ?></td>
                                    <td><?php echo number_format( (float) $ggr_latest['totaal_participaties'], 4, ',', '.' ); ?></td>
                                    <td><?php echo '€ ' . number_format( (float) $ggr_latest['distributievergoeding'], 2, ',', '.' ); ?></td>
                                </tr>
                                </tbody>
                            </table>
                        <?php else : ?>
                            <p>Nog geen participatiehistorie gevonden voor deze gebruiker.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <!-- WACHTWOORD -->
            <h2 class="title">Wachtwoord beheer</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ggr_new_password">Nieuw wachtwoord</label></th>
                    <td>
                        <input name="ggr_new_password" id="ggr_new_password" type="text"
                               class="regular-text" autocomplete="off" />
                        <p class="description">Laat leeg om het huidige wachtwoord te behouden.</p>
                    </td>
                </tr>
            </table>

            <!-- ROL -->
            <h2 class="title">Rol toewijzing</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ggr_role">Rol</label></th>
                    <td>
                        <?php if ( current_user_can( 'promote_users' ) ) : ?>
                            <select name="ggr_role" id="ggr_role">
                                <?php foreach ( $all_roles as $role_key => $role_info ) : ?>
                                    <option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $role_key, $current_role ); ?>>
                                        <?php echo esc_html( $role_info['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <p><?php echo esc_html( ucfirst( $current_role ) ); ?></p>
                            <p class="description">Je hebt geen rechten om rollen te wijzigen.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Profiel opslaan' ); ?>
        </form>
    </div>
    <?php
}


/**
 * Admin-pagina: participant-profiel
 */
add_action( 'admin_menu', 'ggr_portal_register_participant_profile_page' );

function ggr_portal_register_participant_profile_page() {
    add_users_page(
        'Participant profiel',
        'Participant profiel',
        'list_users',
        'ggr-participant-profiel',
        'ggr_portal_render_participant_profile_page'
    );
}


/**
 * Optioneel: redirect user-edit.php voor participants naar de GGR participant-profielpagina
 */
add_action( 'load-user-edit.php', 'ggr_portal_redirect_user_edit_to_participant_page' );

function ggr_portal_redirect_user_edit_to_participant_page() {
    if ( ! current_user_can( 'list_users' ) ) {
        return;
    }

    $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
    if ( ! $user_id ) {
        return;
    }

    $user = get_user_by( 'ID', $user_id );
    if ( ! $user ) {
        return;
    }

    $profile_roles = array( 'participant', 'lead' );

    if ( array_intersect( $profile_roles, (array) $user->roles ) ) {
        $target = add_query_arg(
            [
                'page'    => 'ggr-participant-profiel',
                'user_id' => $user_id,
            ],
            admin_url( 'users.php' )
        );
        wp_safe_redirect( $target );
        exit;
    }
}

/**
 * 11. Participant overzicht (snelle data)
 *
 * Onder Gebruikers > Participant overzicht:
 * - Voornaam
 * - Achternaam
 * - Email
 * - Telefoonnummer
 * - Eerste transactiedatum
 * - Investeringsrendement
 */

add_action( 'admin_menu', 'ggr_portal_register_participant_overview_page' );

function ggr_portal_register_participant_overview_page() {
    add_users_page(
        'Participant overzicht',
        'Participant overzicht',
        'list_users',
        'ggr-participant-overzicht',
        'ggr_portal_render_participant_overview_page'
    );
}

function ggr_portal_render_participant_overview_page() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_die( 'Geen toegang.' );
    }

    $participants = get_users( [
        'role'    => 'participant',
        'orderby' => 'display_name',
        'order'   => 'ASC',
    ] );
    ?>
    <div class="wrap">
        <h1>Participant overzicht</h1>

        <?php if ( empty( $participants ) ) : ?>
            <p>Geen participants gevonden.</p>
            <?php return; endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Voornaam</th>
                    <th>Achternaam</th>
                    <th>E-mailadres</th>
                    <th>Telefoonnummer</th>
                    <th>Laatste login</th>                    
                    <th>Eerste transactiedatum</th>
                    <th>Totaal participaties</th>
                    <th>Positiewaarde (&euro;)</th>
                    <th>Totaal dividend (&euro;)</th>
                    <th>Investeringsrendement %</th>
                    <th>Acties</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ( $participants as $user ) : ?>
                <?php
                $uid   = $user->ID;
                $first = get_user_meta( $uid, 'first_name', true );
                $last  = get_user_meta( $uid, 'last_name', true );
                $phone = get_user_meta( $uid, 'phone', true );

                $last_login_raw   = get_user_meta( $uid, 'ggr_last_login_at', true );
                $last_login_label = '–';
                if ( $last_login_raw ) {
                    $timestamp = is_numeric( $last_login_raw ) ? (int) $last_login_raw : strtotime( $last_login_raw );
                    if ( $timestamp ) {
                        $last_login_label = date_i18n( 'd-m-Y H:i', $timestamp );
                    }
                }

                // Historie ophalen om eerste transactiedatum te bepalen
                $history = function_exists( 'ggr_portal_get_history_for_user' )
                    ? ggr_portal_get_history_for_user( $uid )
                    : [];

                $first_date_label = '–';
                if ( ! empty( $history ) ) {
                    $first_row = $history[0];
                    $d = DateTime::createFromFormat( 'Y-m-d', $first_row->datum );
                    $first_date_label = $d ? $d->format( 'd-m-Y' ) : $first_row->datum;
                }

                // Defaults voor de nieuwe kolommen
                $totaal_part_label    = '–';
                $positiewaarde_label  = '–';
                $dividend_label       = '–';
                $inv_rend_label       = '–';

                // Samenvatting obv bestaande helper
                if ( function_exists( 'ggr_get_user_investment_summary' ) ) {
                    $summary = ggr_get_user_investment_summary( $uid );

                    if ( ! empty( $summary ) ) {
                        // Totaal participaties
                        if ( isset( $summary['units'] ) ) {
                            $totaal_part_label = number_format(
                                (float) $summary['units'],
                                4,
                                ',',
                                '.'
                            );
                        }

                        // Positiewaarde
                        if ( isset( $summary['position_value'] ) ) {
                            $positiewaarde_label = '€ ' . number_format(
                                (float) $summary['position_value'],
                                2,
                                ',',
                                '.'
                            );
                        }

                        // Totaal dividend
                        if ( isset( $summary['dividends_total'] ) ) {
                            $dividend_label = '€ ' . number_format(
                                (float) $summary['dividends_total'],
                                2,
                                ',',
                                '.'
                            );
                        }

                        // Investeringsrendement %
                        if ( array_key_exists( 'return_pct', $summary ) && $summary['return_pct'] !== null ) {
                            $inv_rend_label = number_format(
                                (float) $summary['return_pct'],
                                2,
                                ',',
                                '.'
                            ) . ' %';
                        }
                    }
                }

                // Link naar participant-profiel
$profile_url = add_query_arg(
    [
        'page'    => 'ggr-participant-profiel',
        'user_id' => $uid,
    ],
    admin_url( 'users.php' )
);

// Link naar participatie-historie
$history_url = add_query_arg(
    [
        'page'    => 'ggr-participatie-historie',
        'user_id' => $uid,
    ],
    admin_url( 'users.php' )
);
?>
<tr>
    <td><?php echo (int) $uid; ?></td>
    <td><?php echo esc_html( $first ?: '–' ); ?></td>
    <td><?php echo esc_html( $last ?: '–' ); ?></td>
    <td>
        <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
            <?php echo esc_html( $user->user_email ); ?>
        </a>
    </td>
    <td><?php echo esc_html( $phone ?: '–' ); ?></td>
    <td><?php echo esc_html( $last_login_label ); ?></td>    
    <td><?php echo esc_html( $first_date_label ); ?></td>

    <td><?php echo esc_html( $totaal_part_label ); ?></td>
    <td><?php echo esc_html( $positiewaarde_label ); ?></td>
    <td><?php echo esc_html( $dividend_label ); ?></td>
    <td><?php echo esc_html( $inv_rend_label ); ?></td>

    <td>
        <a href="<?php echo esc_url( $profile_url ); ?>">Bekijk profiel</a> |
        <a href="<?php echo esc_url( $history_url ); ?>">Bekijk historie</a>
    </td>
</tr>

            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action( 'admin_menu', function() {
    // Alleen submenu’s verbergen, de pagina’s zelf blijven bestaan
    remove_submenu_page( 'users.php', 'ggr-participant-profiel' );
    remove_submenu_page( 'users.php', 'ggr-participatie-historie' );
}, 99 );
