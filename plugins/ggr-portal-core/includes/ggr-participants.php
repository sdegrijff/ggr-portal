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
                                <option value="private" <?php selected( $account_type, 'private' ); ?>>Privé</option>
                                <option value="business" <?php selected( $account_type, 'business' ); ?>>Zakelijk</option>
                                <option value="company" <?php selected( $account_type, 'company' ); ?>>Bedrijf (legacy)</option>
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
                            <label for="ggr_p_country">Land</label>
                            <input type="text" name="ggr_p_country" id="ggr_p_country"
                                   value="<?php echo esc_attr( $p_country ); ?>" />
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
    </table>
    <?php
}

add_action( 'personal_options_update', 'ggr_portal_save_account_fields_in_profile' );
add_action( 'edit_user_profile_update', 'ggr_portal_save_account_fields_in_profile' );

function ggr_portal_save_account_fields_in_profile( $user_id ) {
    if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }

    // Participant
    if ( isset( $_POST['ggr_first_name'] ) ) {
        update_user_meta( $user_id, 'first_name', sanitize_text_field( wp_unslash( $_POST['ggr_first_name'] ) ) );
    }
    if ( isset( $_POST['ggr_last_name'] ) ) {
        update_user_meta( $user_id, 'last_name', sanitize_text_field( wp_unslash( $_POST['ggr_last_name'] ) ) );
    }
    if ( isset( $_POST['ggr_phone'] ) ) {
        update_user_meta( $user_id, 'phone', sanitize_text_field( wp_unslash( $_POST['ggr_phone'] ) ) );
    }

    if ( isset( $_POST['ggr_email'] ) ) {
        $email = sanitize_email( wp_unslash( $_POST['ggr_email'] ) );
        if ( $email && is_email( $email ) ) {
            wp_update_user( [
                'ID'         => $user_id,
                'user_email' => $email,
            ] );
        }
    }
    
        // Onboarding extra's
    if ( isset( $_POST['ggr_account_type'] ) ) {
        $account_type = sanitize_text_field( wp_unslash( $_POST['ggr_account_type'] ) );
        if ( 'company' === $account_type ) {
            $account_type = 'business';
        }
        update_user_meta( $user_id, 'ggr_account_type', $account_type );
    }

    if ( isset( $_POST['ggr_nationality'] ) ) {
        update_user_meta(
            $user_id,
            'ggr_nationality',
            sanitize_text_field( wp_unslash( $_POST['ggr_nationality'] ) )
        );
    }

    if ( isset( $_POST['ggr_investment_amount'] ) ) {
        $amount_raw   = sanitize_text_field( wp_unslash( $_POST['ggr_investment_amount'] ) );
        $amount_clean = preg_replace( '/[^\d,\.]/', '', $amount_raw );

        if ( strpos( $amount_clean, ',' ) !== false && strpos( $amount_clean, '.' ) !== false ) {
            $amount_clean = str_replace( '.', '', $amount_clean );
            $amount_clean = str_replace( ',', '.', $amount_clean );
        } else {
            $amount_clean = str_replace( ',', '.', $amount_clean );
        }

        $amount_value = (float) $amount_clean;

        update_user_meta( $user_id, 'ggr_investment_amount', $amount_value );
        update_user_meta( $user_id, 'ggr_investment', $amount_raw );
    }

    $marketing_optin = ! empty( $_POST['ggr_marketing_optin'] ) ? 1 : 0;
    update_user_meta( $user_id, 'ggr_marketing_optin', $marketing_optin );

    if ( isset( $_POST['ggr_onboarding_status'] ) ) {
        $status = sanitize_key( wp_unslash( $_POST['ggr_onboarding_status'] ) );

        if ( function_exists( 'ggr_onboarding_update_status' ) ) {
            ggr_onboarding_update_status( $user_id, $status );
        } else {
            update_user_meta( $user_id, 'ggr_onboarding_status', $status );
        }
    }


    // Mede-participant
    if ( isset( $_POST['ggr_co_first_name'] ) ) {
        update_user_meta( $user_id, 'co_first_name', sanitize_text_field( wp_unslash( $_POST['ggr_co_first_name'] ) ) );
    }
    if ( isset( $_POST['ggr_co_last_name'] ) ) {
        update_user_meta( $user_id, 'co_last_name', sanitize_text_field( wp_unslash( $_POST['ggr_co_last_name'] ) ) );
    }
    if ( isset( $_POST['ggr_co_email'] ) ) {
        $co_email = sanitize_email( wp_unslash( $_POST['ggr_co_email'] ) );
        update_user_meta( $user_id, 'co_email', $co_email );
    }
    if ( isset( $_POST['ggr_co_phone'] ) ) {
        update_user_meta( $user_id, 'co_phone', sanitize_text_field( wp_unslash( $_POST['ggr_co_phone'] ) ) );
    }

    // Adres
    $map_p = [
        'ggr_p_street'  => 'address_street',
        'ggr_p_zip'     => 'address_postcode',
        'ggr_p_city'    => 'address_city',
        'ggr_p_country' => 'address_country',
    ];
    foreach ( $map_p as $field => $meta_key ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_user_meta(
                $user_id,
                $meta_key,
                sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
            );
        }
    }

    // Bank
    if ( isset( $_POST['ggr_bank_iban'] ) ) {
        update_user_meta(
            $user_id,
            'bank_account_iban',
            sanitize_text_field( wp_unslash( $_POST['ggr_bank_iban'] ) )
        );
    }
    if ( isset( $_POST['ggr_bank_name'] ) ) {
        update_user_meta(
            $user_id,
            'bank_account_name',
            sanitize_text_field( wp_unslash( $_POST['ggr_bank_name'] ) )
        );
    }

    // Bedrijf
    if ( isset( $_POST['ggr_company_name'] ) ) {
        $company = sanitize_text_field( wp_unslash( $_POST['ggr_company_name'] ) );
        update_user_meta( $user_id, 'company_name', $company );
        update_user_meta( $user_id, 'billing_company', $company );
    }
    if ( isset( $_POST['ggr_company_kvk'] ) ) {
        $kvk = sanitize_text_field( wp_unslash( $_POST['ggr_company_kvk'] ) );
        update_user_meta( $user_id, 'company_kvk', $kvk );
    }
}



/**
 * 10. Backend: Participant profiel-pagina
 *
 * Onder Gebruikers > Participant profiel:
 * - Taal
 * - Naam & contactgegevens (incl. mede-participant)
 * - Adresgegevens
 * - Bankgegevens
 * - GGR details (read-only)
 * - Wachtwoord beheer
 * - Rol toewijzing
 * - Bedrijfsgegevens
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
 * Verwerken van POST (opslaan) vóór we de pagina tonen.
 */
add_action( 'admin_init', 'ggr_portal_handle_participant_profile_save' );

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

    // Taal
    if ( isset( $_POST['ggr_locale'] ) ) {
        $locale = sanitize_text_field( wp_unslash( $_POST['ggr_locale'] ) );
        if ( $locale === '' ) {
            delete_user_meta( $user_id, 'locale' );
        } else {
            update_user_meta( $user_id, 'locale', $locale );
        }
    }

    // Participant contact
    if ( isset( $_POST['ggr_first_name'] ) ) {
        update_user_meta( $user_id, 'first_name', sanitize_text_field( wp_unslash( $_POST['ggr_first_name'] ) ) );
    }
    if ( isset( $_POST['ggr_last_name'] ) ) {
        update_user_meta( $user_id, 'last_name', sanitize_text_field( wp_unslash( $_POST['ggr_last_name'] ) ) );
    }
    if ( isset( $_POST['ggr_phone'] ) ) {
        update_user_meta( $user_id, 'phone', sanitize_text_field( wp_unslash( $_POST['ggr_phone'] ) ) );
    }
    if ( isset( $_POST['ggr_email'] ) ) {
        $email = sanitize_email( wp_unslash( $_POST['ggr_email'] ) );
        if ( $email && is_email( $email ) && $email !== $user->user_email ) {
            wp_update_user( [
                'ID'         => $user_id,
                'user_email' => $email,
            ] );
        }
    }

    // Onboarding
    if ( isset( $_POST['ggr_account_type'] ) ) {
        $account_type = sanitize_text_field( wp_unslash( $_POST['ggr_account_type'] ) );
        if ( 'company' === $account_type ) {
            $account_type = 'business';
        }
        update_user_meta( $user_id, 'ggr_account_type', $account_type );
    }

    if ( isset( $_POST['ggr_nationality'] ) ) {
        update_user_meta(
            $user_id,
            'ggr_nationality',
            sanitize_text_field( wp_unslash( $_POST['ggr_nationality'] ) )
        );
    }

    if ( isset( $_POST['ggr_investment_amount'] ) ) {
        $amount_raw   = sanitize_text_field( wp_unslash( $_POST['ggr_investment_amount'] ) );
        $amount_clean = preg_replace( '/[^\d,\.]/', '', $amount_raw );

        if ( strpos( $amount_clean, ',' ) !== false && strpos( $amount_clean, '.' ) !== false ) {
            $amount_clean = str_replace( '.', '', $amount_clean );
            $amount_clean = str_replace( ',', '.', $amount_clean );
        } else {
            $amount_clean = str_replace( ',', '.', $amount_clean );
        }

        $amount_value = (float) $amount_clean;

        update_user_meta( $user_id, 'ggr_investment_amount', $amount_value );
        update_user_meta( $user_id, 'ggr_investment', $amount_raw );
    }

    $marketing_optin = ! empty( $_POST['ggr_marketing_optin'] ) ? 1 : 0;
    update_user_meta( $user_id, 'ggr_marketing_optin', $marketing_optin );

    if ( isset( $_POST['ggr_onboarding_status'] ) ) {
        $status = sanitize_key( wp_unslash( $_POST['ggr_onboarding_status'] ) );

        if ( function_exists( 'ggr_onboarding_update_status' ) ) {
            ggr_onboarding_update_status( $user_id, $status );
        } else {
            update_user_meta( $user_id, 'ggr_onboarding_status', $status );
        }
    }

    // Mede-participant
    if ( isset( $_POST['ggr_co_first_name'] ) ) {
        update_user_meta( $user_id, 'co_first_name', sanitize_text_field( wp_unslash( $_POST['ggr_co_first_name'] ) ) );
    }
    if ( isset( $_POST['ggr_co_last_name'] ) ) {
        update_user_meta( $user_id, 'co_last_name', sanitize_text_field( wp_unslash( $_POST['ggr_co_last_name'] ) ) );
    }
    if ( isset( $_POST['ggr_co_email'] ) ) {
        $co_email = sanitize_email( wp_unslash( $_POST['ggr_co_email'] ) );
        update_user_meta( $user_id, 'co_email', $co_email );
    }
    if ( isset( $_POST['ggr_co_phone'] ) ) {
        update_user_meta( $user_id, 'co_phone', sanitize_text_field( wp_unslash( $_POST['ggr_co_phone'] ) ) );
    }

    // Adres
    $map_p = [
        'ggr_p_street'  => 'address_street',
        'ggr_p_zip'     => 'address_postcode',
        'ggr_p_city'    => 'address_city',
        'ggr_p_country' => 'address_country',
    ];
    foreach ( $map_p as $field => $meta_key ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_user_meta(
                $user_id,
                $meta_key,
                sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
            );
        }
    }

    // Bank
    if ( isset( $_POST['ggr_bank_iban'] ) ) {
        update_user_meta(
            $user_id,
            'bank_account_iban',
            sanitize_text_field( wp_unslash( $_POST['ggr_bank_iban'] ) )
        );
    }
    if ( isset( $_POST['ggr_bank_name'] ) ) {
        update_user_meta(
            $user_id,
            'bank_account_name',
            sanitize_text_field( wp_unslash( $_POST['ggr_bank_name'] ) )
        );
    }

    // Wachtwoord
    if ( ! empty( $_POST['ggr_new_password'] ) ) {
        $new_pass = (string) wp_unslash( $_POST['ggr_new_password'] );
        wp_update_user( [
            'ID'        => $user_id,
            'user_pass' => $new_pass,
        ] );
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

    // Bedrijf
    if ( isset( $_POST['ggr_company_name'] ) ) {
        $company = sanitize_text_field( wp_unslash( $_POST['ggr_company_name'] ) );
        update_user_meta( $user_id, 'company_name', $company );
        update_user_meta( $user_id, 'billing_company', $company );
    }
    if ( isset( $_POST['ggr_company_kvk'] ) ) {
        $kvk = sanitize_text_field( wp_unslash( $_POST['ggr_company_kvk'] ) );
        update_user_meta( $user_id, 'company_kvk', $kvk );
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
            <h1>Participant profiel</h1>
            <form method="get" style="margin-top: 20px;">
                <input type="hidden" name="page" value="ggr-participant-profiel" />
                <label for="user_id">Kies participant:</label>
                <?php
                wp_dropdown_users( [
                    'name'             => 'user_id',
                    'id'               => 'user_id',
                    'show_option_none' => '— Selecteer participant —',
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
        echo '<div class="wrap"><h1>Participant profiel</h1><p>Gebruiker niet gevonden.</p></div>';
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

    // GGR details (nog gebaseerd op oude berekening)
    $ggr_latest = function_exists( 'ggr_portal_get_latest_calculated_values_for_user' )
        ? ggr_portal_get_latest_calculated_values_for_user( $user_id )
        : false;

    $all_roles     = get_editable_roles();
    $current_roles = (array) $user->roles;
    $current_role  = reset( $current_roles );

    ?>
    <div class="wrap ggr-participant-wrap">
        <h1>Participant profiel – <?php echo esc_html( $user->display_name ); ?> (ID: <?php echo (int) $user_id; ?>)</h1>

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
        </style>

        <!-- Snel wisselen -->
        <form method="get" class="ggr-participant-switcher" style="margin: 10px 0 20px;">
            <input type="hidden" name="page" value="ggr-participant-profiel" />
            <label for="ggr_participant_switch" style="margin-right:8px;">Ga naar andere participant:</label>
            <?php
            wp_dropdown_users( [
                'name'             => 'user_id',
                'id'               => 'ggr_participant_switch',
                'selected'         => $user_id,
                'role__in'         => [ 'participant', 'lead' ],
                'show'             => 'display_name',
                'show_option_none' => '— Kies participant —',
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
                <p>Participantgegevens opgeslagen.</p>
            </div>
        <?php endif; ?>

        <form method="post" class="ggr-participant-form">
            <?php wp_nonce_field( 'ggr_participant_profile_save', 'ggr_participant_profile_nonce' ); ?>
            <input type="hidden" name="ggr_participant_user_id" value="<?php echo (int) $user_id; ?>" />

            <!-- TAAL -->
            <h2 class="title">Taal</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ggr_locale">Interface taal</label></th>
                    <td>
                        <select name="ggr_locale" id="ggr_locale">
                            <option value="" <?php selected( $locale_meta, '' ); ?>>Site standaard</option>
                            <option value="nl_NL" <?php selected( $locale_meta, 'nl_NL' ); ?>>Nederlands</option>
                            <option value="en_US" <?php selected( $locale_meta, 'en_US' ); ?>>Engels (US)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <!-- CONTACTGEGEVENS: participant + mede-participant naast elkaar -->
            <h2 class="title">Contactgegevens</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Contactgegevens</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <div class="ggr-admin-col">
                                <h4>Participant</h4>

                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_first_name">Voornaam</label>
                                    <input name="ggr_first_name" id="ggr_first_name" type="text"
                                           value="<?php echo esc_attr( $first_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_last_name">Achternaam</label>
                                    <input name="ggr_last_name" id="ggr_last_name" type="text"
                                           value="<?php echo esc_attr( $last_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_email">E-mailadres</label>
                                    <input name="ggr_email" id="ggr_email" type="email"
                                           value="<?php echo esc_attr( $user->user_email ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_phone">Telefoonnummer</label>
                                    <input name="ggr_phone" id="ggr_phone" type="text"
                                           value="<?php echo esc_attr( $phone ); ?>" />
                                </div>
 
                            </div>

                            <div class="ggr-admin-col">
                                <h4>Mede-participant (optioneel)</h4>

                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_first_name">Voornaam</label>
                                    <input name="ggr_co_first_name" id="ggr_co_first_name" type="text"
                                           value="<?php echo esc_attr( $co_first ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_last_name">Achternaam</label>
                                    <input name="ggr_co_last_name" id="ggr_co_last_name" type="text"
                                           value="<?php echo esc_attr( $co_last ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_email">E-mailadres</label>
                                    <input name="ggr_co_email" id="ggr_co_email" type="email"
                                           value="<?php echo esc_attr( $co_email ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_co_phone">Telefoonnummer</label>
                                    <input name="ggr_co_phone" id="ggr_co_phone" type="text"
                                           value="<?php echo esc_attr( $co_phone ); ?>" />
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>

            <h2 class="title">Onboarding</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Onboarding gegevens</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <div class="ggr-admin-col">
                                <h4>Profielkeuzes</h4>

                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_account_type">Account type</label>
                                    <select name="ggr_account_type" id="ggr_account_type">
                                        <option value=""><?php esc_html_e( 'Maak een keuze', 'ggr-portal' ); ?></option>
                                        <option value="private" <?php selected( $account_type, 'private' ); ?>>Privé</option>
                                        <option value="business" <?php selected( $account_type, 'business' ); ?>>Zakelijk</option>
                                        <option value="company" <?php selected( $account_type, 'company' ); ?>>Bedrijf (legacy)</option>
                                    </select>
                                </div>

                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_nationality">Nationaliteit</label>
                                    <input name="ggr_nationality" id="ggr_nationality" type="text"
                                           value="<?php echo esc_attr( $nationality ); ?>" />
                                </div>
                            </div>

                            <div class="ggr-admin-col">
                                <h4>Aanvraagdetails</h4>

                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_investment_amount">Investeringsbedrag (wens) (€)</label>
                                    <input name="ggr_investment_amount" id="ggr_investment_amount" type="text"
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
            </table>

            <!-- ADRES LINKS, BANK + BEDRIJF RECHTS -->
            <h2 class="title">Adres, bank &amp; bedrijfsgegevens</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Overzicht</th>
                    <td>
                        <div class="ggr-admin-columns">
                            <!-- Adres -->
                            <div class="ggr-admin-col">
                                <h4>Adresgegevens</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_p_street">Straat + huisnummer</label>
                                    <input name="ggr_p_street" id="ggr_p_street" type="text"
                                           value="<?php echo esc_attr( $p_street ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_p_zip">Postcode</label>
                                    <input name="ggr_p_zip" id="ggr_p_zip" type="text"
                                           value="<?php echo esc_attr( $p_zip ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_p_city">Plaats</label>
                                    <input name="ggr_p_city" id="ggr_p_city" type="text"
                                           value="<?php echo esc_attr( $p_city ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_p_country">Land</label>
                                    <input name="ggr_p_country" id="ggr_p_country" type="text"
                                           value="<?php echo esc_attr( $p_country ); ?>" />
                                </div>
                            </div>

                            <!-- Bank + bedrijf -->
                            <div class="ggr-admin-col">
                                <h4>Bankgegevens</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_bank_iban">Rekeningnummer (IBAN)</label>
                                    <input name="ggr_bank_iban" id="ggr_bank_iban" type="text"
                                           value="<?php echo esc_attr( $bank_iban ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_bank_name">Tenaamstelling rekening</label>
                                    <input name="ggr_bank_name" id="ggr_bank_name" type="text"
                                           value="<?php echo esc_attr( $bank_name ); ?>" />
                                </div>

                                <h4 style="margin-top:18px;">Bedrijfsgegevens</h4>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_company_name">Bedrijfsnaam</label>
                                    <input name="ggr_company_name" id="ggr_company_name" type="text"
                                           value="<?php echo esc_attr( $company_name ); ?>" />
                                </div>
                                <div class="ggr-admin-inline-field">
                                    <label for="ggr_company_kvk">KvK-nummer</label>
                                    <input name="ggr_company_kvk" id="ggr_company_kvk" type="text"
                                           value="<?php echo esc_attr( $company_kvk ); ?>" />
                                </div>
                            </div>
                        </div>
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

            <?php submit_button( 'Participant opslaan' ); ?>
        </form>
    </div>
    <?php
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

    if ( in_array( 'participant', (array) $user->roles, true ) ) {
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
