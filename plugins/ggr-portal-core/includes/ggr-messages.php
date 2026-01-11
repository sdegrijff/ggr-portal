<?php
/**
 * GGR Portal - Berichten module
 *
 * - CPT ggr_bericht
 * - Meta velden
 * - Shortcode [ggr_portal_berichten]
 * - Shortcodes [ggr_user_firstname] + [ggr_transacties_overzicht]
 * - Helper voor automatische maandelijkse transactieberichten
 * - Read/unread per gebruiker + batch-acties
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. CPT "ggr_bericht"
 */
add_action( 'init', 'ggr_register_bericht_cpt' );

function ggr_register_bericht_cpt() {
    $labels = array(
        'name'               => 'Berichten',
        'singular_name'      => 'Bericht',
        'menu_name'          => 'Berichten (Portal)',
        'name_admin_bar'     => 'Bericht',
        'add_new'            => 'Nieuw bericht',
        'add_new_item'       => 'Nieuw bericht toevoegen',
        'edit_item'          => 'Bericht bewerken',
        'new_item'           => 'Nieuw bericht',
        'view_item'          => 'Bericht bekijken',
        'search_items'       => 'Zoek berichten',
        'not_found'          => 'Geen berichten gevonden',
        'not_found_in_trash' => 'Geen berichten in prullenbak',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'supports'           => array( 'title', 'editor' ),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => false,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-email-alt',
    );

    register_post_type( 'ggr_bericht', $args );
}

add_filter( 'manage_edit-ggr_bericht_columns', 'ggr_bericht_admin_columns' );
add_action( 'manage_ggr_bericht_posts_custom_column', 'ggr_bericht_admin_column_render', 10, 2 );
add_action( 'restrict_manage_posts', 'ggr_bericht_admin_filters' );
add_action( 'pre_get_posts', 'ggr_bericht_admin_filter_query' );

function ggr_bericht_admin_columns( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $label ) {
        $new_columns[ $key ] = $label;
        if ( 'title' === $key ) {
            $new_columns['ggr_message_type']      = 'Type bericht';
            $new_columns['ggr_message_recipient'] = 'Ontvanger';
        }
    }

    if ( ! isset( $new_columns['ggr_message_type'] ) ) {
        $new_columns['ggr_message_type'] = 'Type bericht';
    }

    if ( ! isset( $new_columns['ggr_message_recipient'] ) ) {
        $new_columns['ggr_message_recipient'] = 'Ontvanger';
    }

    return $new_columns;
}

function ggr_bericht_admin_column_render( $column, $post_id ) {
    if ( 'ggr_message_type' === $column ) {
        $type = get_post_meta( $post_id, '_ggr_message_type', true );
        $labels = array(
            ''            => 'Algemeen',
            'release'     => 'Nieuwe release',
            'legal'       => 'Wijziging statuten / juridisch',
            'transaction' => 'Transactie / Transactienota',
            'other'       => 'Overig',
        );

        $label = isset( $labels[ $type ] ) ? $labels[ $type ] : '—';
        echo esc_html( $label );
        return;
    }

    if ( 'ggr_message_recipient' !== $column ) {
        return;
    }

    $audience = get_post_meta( $post_id, '_ggr_message_audience', true );
    $role     = get_post_meta( $post_id, '_ggr_message_role', true );
    $user_id  = (int) get_post_meta( $post_id, '_ggr_message_user_id', true );

    if ( 'user' === $audience && $user_id ) {
        $user = get_user_by( 'ID', $user_id );
        $name = $user ? $user->display_name : 'Onbekende participant';
        echo esc_html( 'Participant > ' . $name );
        return;
    }

    $recipient_label = 'Alle participanten';
    if ( in_array( $role, array( 'administrator', 'admin' ), true ) ) {
        $recipient_label = 'Admin';
    }

    echo esc_html( $recipient_label );
}

function ggr_bericht_admin_filters() {
    global $typenow;
    if ( 'ggr_bericht' !== $typenow ) {
        return;
    }

    $selected = isset( $_GET['ggr_message_audience_filter'] ) ? sanitize_key( wp_unslash( $_GET['ggr_message_audience_filter'] ) ) : '';
    ?>
    <label for="ggr_message_audience_filter" class="screen-reader-text">Filter ontvanger</label>
    <select name="ggr_message_audience_filter" id="ggr_message_audience_filter">
        <option value="" <?php selected( $selected, '' ); ?>>Alle ontvangers</option>
        <option value="template" <?php selected( $selected, 'template' ); ?>>Template (alle participanten)</option>
        <option value="specific" <?php selected( $selected, 'specific' ); ?>>Specifieke participant</option>
    </select>
    <?php
}

function ggr_bericht_admin_filter_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( 'ggr_bericht' !== $query->get( 'post_type' ) ) {
        return;
    }

    $filter = isset( $_GET['ggr_message_audience_filter'] ) ? sanitize_key( wp_unslash( $_GET['ggr_message_audience_filter'] ) ) : '';
    if ( ! in_array( $filter, array( 'template', 'specific' ), true ) ) {
        return;
    }

    $meta_query = (array) $query->get( 'meta_query' );

    if ( 'template' === $filter ) {
        $meta_query[] = array(
            'key'   => '_ggr_message_audience',
            'value' => 'role',
        );
        $meta_query[] = array(
            'key'   => '_ggr_message_role',
            'value' => 'participant',
        );
    }

    if ( 'specific' === $filter ) {
        $meta_query[] = array(
            'key'   => '_ggr_message_audience',
            'value' => 'user',
        );
    }

    $query->set( 'meta_query', $meta_query );
}

/**
 * 2. Meta boxes voor doelgroep / type / datum / transactieref / periode
 */
add_action( 'add_meta_boxes', 'ggr_bericht_add_meta_boxes' );

function ggr_bericht_add_meta_boxes() {
    add_meta_box(
        'ggr_bericht_meta',
        'Portal bericht-instellingen',
        'ggr_bericht_meta_box_callback',
        'ggr_bericht',
        'normal',
        'high'
    );
}

function ggr_bericht_meta_box_callback( $post ) {
    wp_nonce_field( 'ggr_bericht_meta_save', 'ggr_bericht_meta_nonce' );

    $type       = get_post_meta( $post->ID, '_ggr_message_type', true );
    $date       = get_post_meta( $post->ID, '_ggr_message_date', true );
    $trans_ref  = get_post_meta( $post->ID, '_ggr_message_transaction_ref', true );
    $period     = get_post_meta( $post->ID, '_ggr_message_period', true ); // YYYY-MM

    if ( empty( $date ) ) {
        $date = get_the_date( 'Y-m-d', $post );
    }
    ?>
    <p><strong>Doelgroep</strong></p>
    <p class="description">Kies de ontvanger voor dit bericht.</p>

    <?php
    $audience = get_post_meta( $post->ID, '_ggr_message_audience', true );
    $user_id  = (int) get_post_meta( $post->ID, '_ggr_message_user_id', true );
    $role     = get_post_meta( $post->ID, '_ggr_message_role', true );

    if ( ! $audience ) {
        $audience = $user_id ? 'user' : 'role';
    }
    if ( ! $role ) {
        $role = 'participant';
    }
    ?>

    <?php
    $recipient = 'all';
    if ( 'user' === $audience ) {
        $recipient = 'user';
    } elseif ( in_array( $role, array( 'administrator', 'admin' ), true ) ) {
        $recipient = 'admin';
    }
    ?>

    <p>
        <label for="ggr_message_recipient"><strong>Ontvanger</strong></label><br/>
        <select name="ggr_message_recipient" id="ggr_message_recipient">
            <option value="admin" <?php selected( $recipient, 'admin' ); ?>>Admin</option>
            <option value="all" <?php selected( $recipient, 'all' ); ?>>Alle participanten</option>
            <option value="user" <?php selected( $recipient, 'user' ); ?>>Specifieke participant</option>
        </select>
    </p>        
    <p id="ggr_message_user_row">
        <label for="ggr_message_user_id"><strong>Participant</strong></label><br/>
        <?php
        wp_dropdown_users(
            array(
                'name'             => 'ggr_message_user_id',
                'id'               => 'ggr_message_user_id',
                'selected'         => $user_id,
                'show_option_none' => '— Selecteer participant —',
                'role__in'         => array( 'participant' ),
            )
        );
        ?>
    </p>

    <input type="hidden" name="ggr_message_audience" id="ggr_message_audience" value="<?php echo esc_attr( $audience ); ?>" />
    <input type="hidden" name="ggr_message_role" id="ggr_message_role" value="<?php echo esc_attr( $role ); ?>" />

    <script>
        (function() {
            const recipientSelect = document.getElementById('ggr_message_recipient');
            const userRow = document.getElementById('ggr_message_user_row');
            const audienceInput = document.getElementById('ggr_message_audience');
            const roleInput = document.getElementById('ggr_message_role');

            const updateRecipient = () => {
                const value = recipientSelect.value;
                if (value === 'user') {
                    userRow.style.display = '';
                    audienceInput.value = 'user';
                    roleInput.value = 'participant';
                    return;
                }

                userRow.style.display = 'none';
                audienceInput.value = 'role';
                roleInput.value = value === 'admin' ? 'administrator' : 'participant';
            };

            recipientSelect.addEventListener('change', updateRecipient);
            updateRecipient();
        })();
    </script>

    <p><strong>Type bericht</strong></p>
    <p>
        <select name="ggr_message_type">
            <?php
            $types = array(
                ''            => 'Algemeen',
                'release'     => 'Nieuwe release',
                'legal'       => 'Wijziging statuten / juridisch',
                'transaction' => 'Transactie / Transactienota',
                'other'       => 'Overig',
            );
            foreach ( $types as $key => $label ) :
                ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <label for="ggr_message_date"><strong>Datum bericht</strong></label><br/>
        <input type="date" id="ggr_message_date" name="ggr_message_date"
               value="<?php echo esc_attr( $date ); ?>" />
        <span class="description">Wordt gebruikt voor sortering en weergave in de portal.</span>
    </p>

    <p>
        <label for="ggr_message_transaction_ref"><strong>Transactie referentie (optioneel)</strong></label><br/>
        <input type="text" id="ggr_message_transaction_ref" name="ggr_message_transaction_ref"
               value="<?php echo esc_attr( $trans_ref ); ?>" style="width: 300px;" />
        <span class="description">Bijv. intern transactienummer.</span>
    </p>

    <p>
        <label for="ggr_message_period"><strong>Periode (YYYY-MM)</strong></label><br/>
        <input type="text" id="ggr_message_period" name="ggr_message_period"
               value="<?php echo esc_attr( $period ); ?>" style="width: 120px;" />
        <span class="description">Wordt gebruikt voor maandelijkse transactienota (bijv. 2025-03).</span>
    </p>
    <?php
}

add_action( 'save_post_ggr_bericht', 'ggr_bericht_meta_save' );

function ggr_bericht_meta_save( $post_id ) {
    if ( ! isset( $_POST['ggr_bericht_meta_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_bericht_meta_nonce'], 'ggr_bericht_meta_save' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $recipient = isset( $_POST['ggr_message_recipient'] ) ? sanitize_key( $_POST['ggr_message_recipient'] ) : '';
    $audience  = isset( $_POST['ggr_message_audience'] ) ? sanitize_key( $_POST['ggr_message_audience'] ) : 'role';
    $role      = isset( $_POST['ggr_message_role'] ) ? sanitize_key( $_POST['ggr_message_role'] ) : 'participant';
    $user_id   = isset( $_POST['ggr_message_user_id'] ) ? absint( $_POST['ggr_message_user_id'] ) : 0;

    if ( in_array( $recipient, array( 'admin', 'all', 'user' ), true ) ) {
        $audience = 'user' === $recipient ? 'user' : 'role';
        $role = 'admin' === $recipient ? 'administrator' : 'participant';
    }
    if ( ! in_array( $audience, array( 'role', 'user' ), true ) ) {
        $audience = 'role';
    }
    if ( ! in_array( $role, array( 'participant', 'administrator' ), true ) ) {
        $role = 'participant';
    }
    $type      = isset( $_POST['ggr_message_type'] ) ? sanitize_text_field( $_POST['ggr_message_type'] ) : '';
    $date      = isset( $_POST['ggr_message_date'] ) ? sanitize_text_field( $_POST['ggr_message_date'] ) : '';
    $trans_ref = isset( $_POST['ggr_message_transaction_ref'] ) ? sanitize_text_field( $_POST['ggr_message_transaction_ref'] ) : '';
    $period    = isset( $_POST['ggr_message_period'] ) ? sanitize_text_field( $_POST['ggr_message_period'] ) : '';

    if ( 'user' === $audience && $user_id <= 0 ) {
        $audience = 'role';
    }

    update_post_meta( $post_id, '_ggr_message_audience', $audience );
    update_post_meta( $post_id, '_ggr_message_user_id', 'user' === $audience ? $user_id : 0 );
    update_post_meta( $post_id, '_ggr_message_role', $role );
    update_post_meta( $post_id, '_ggr_message_type', $type );
    update_post_meta( $post_id, '_ggr_message_date', $date );
    update_post_meta( $post_id, '_ggr_message_transaction_ref', $trans_ref );
    update_post_meta( $post_id, '_ggr_message_period', $period );
}

/**
 * 3. Role helper
 */
function ggr_portal_user_has_role( $user_id, $role ) {
    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->roles ) ) {
        return false;
    }
    return in_array( $role, (array) $user->roles, true );
}

/**
 * 3b. Helper: alle transacties van de AFREKENmaand van een bericht voor een user
 *     + cumulatieve berekeningen (participaties & positiewaarde).
 *
 * Belangrijk:
 * - We gebruiken primair _ggr_message_period (YYYY-MM) als afrekenmaand
 * - Alleen als die niet is gezet, vallen we terug op de maand van _ggr_message_date
 */
function ggr_portal_get_transactions_for_message_month( $user_id, $message_id ) {
    $user_id    = (int) $user_id;
    $message_id = (int) $message_id;

    if ( ! $user_id || ! $message_id ) {
        return array();
    }

    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return array();
    }

    // 1) Bepaal doelmaand (YYYY-MM)
    $period_meta = get_post_meta( $message_id, '_ggr_message_period', true );
    $target_ym   = '';

    if ( $period_meta && preg_match( '/^\d{4}-\d{2}$/', $period_meta ) ) {
        // Afrekenmaand expliciet vastgelegd (bijv. "2025-10")
        $target_ym = $period_meta;
    } else {
        // Fallback: maand van berichtdatum (oude gedrag)
        $raw_date = get_post_meta( $message_id, '_ggr_message_date', true );
        if ( ! $raw_date ) {
            $raw_date = get_the_date( 'Y-m-d', $message_id );
        }

        $dt = DateTime::createFromFormat( 'Y-m-d', $raw_date );
        if ( ! $dt ) {
            return array();
        }

        $target_ym = $dt->format( 'Y-m' );
    }

    // 2) Volledige historie ophalen
    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return array();
    }

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_distributie   = 0.0;
    $cumul_participaties = 0.0;
    $current_pos         = 0.0;

    $rows = array();

    foreach ( $history as $row ) {
        if ( empty( $row->datum ) ) {
            continue;
        }

        $row_dt = DateTime::createFromFormat( 'Y-m-d', $row->datum );
        if ( ! $row_dt ) {
            continue;
        }

        $old_pos   = $current_pos;
        $old_parts = $cumul_participaties;

        $cumul_inleg         += (float) $row->inlegbedrag;
        $cumul_opname        += (float) $row->opnamebedrag;
        $cumul_distributie   += (float) $row->distributievergoeding;
        $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;
        if ( function_exists( 'ggr_portal_truncate_participaties' ) ) {
            $cumul_participaties = ggr_portal_truncate_participaties( $cumul_participaties, 4 );
        }
        
        $netto_inleg  = $cumul_inleg - $cumul_opname;
        $current_pos  = $netto_inleg + $cumul_distributie;

        $parts_mutatie = $cumul_participaties - $old_parts;
        $pos_mutatie   = $current_pos - $old_pos;

        // Alleen rijen uit de DOELmaand (afrekenmaand) teruggeven
        if ( $row_dt->format( 'Y-m' ) === $target_ym ) {
            $has_participation_change = (float) $row->nieuwe_participaties !== 0.0 || (float) $row->verkochte_participaties !== 0.0;
            $has_dividend             = (float) $row->distributievergoeding !== 0.0;
            if ( ! $has_participation_change && ! $has_dividend ) {
                continue;
            }            
            $row->totaal_participaties = $cumul_participaties;
            $row->participatie_mutatie = $parts_mutatie;
            $row->positiewaarde        = $current_pos;
            $row->positie_mutatie      = $pos_mutatie;
            $row->kapitaalsuitkering   = (float) $row->distributievergoeding;
            $rows[]                    = $row;
        }
    }

    return $rows;
}

/**
 * 3c. Helper: maandoverzicht voor een bericht
 * - Eind vorige maand  = cumulatieve stand t/m dag vóór berichtdatum
 * - Start nieuwe maand = cumulatieve stand t/m berichtdatum
 * - NAV = GGR stock price op einddatum vorige maand (indien beschikbaar)
 * - Kapitaalsuitkering = som distributies in de vorige maand (niet totaal historisch)
 */
function ggr_portal_get_month_overview_for_message( $user_id, $message_id ) {
    $user_id    = (int) $user_id;
    $message_id = (int) $message_id;

    if ( ! $user_id || ! $message_id ) {
        return false;
    }

    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return false;
    }

    // Berichtdatum ophalen
    $raw_date = get_post_meta( $message_id, '_ggr_message_date', true );
    if ( ! $raw_date ) {
        $raw_date = get_the_date( 'Y-m-d', $message_id );
    }

    $dt_msg = DateTime::createFromFormat( 'Y-m-d', $raw_date );
    if ( ! $dt_msg ) {
        return false;
    }

    // Einde vorige maand = dag vóór berichtdatum (we verwachten dat berichtdatum start nieuwe maand is)
    $prev_end = clone $dt_msg;
    $prev_end->modify( '-1 day' );
    $prev_month_ym = $prev_end->format( 'Y-m' );

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return false;
    }

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_distributie   = 0.0;
    $cumul_participaties = 0.0;
    $current_pos         = 0.0;

    $prev_parts       = 0.0;
    $prev_pos         = 0.0;
    $start_parts      = 0.0;
    $start_pos        = 0.0;
    $monthly_distrib  = 0.0; // alleen distributies in de vorige maand

    foreach ( $history as $row ) {
        if ( empty( $row->datum ) ) {
            continue;
        }

        $row_dt = DateTime::createFromFormat( 'Y-m-d', $row->datum );
        if ( ! $row_dt ) {
            continue;
        }

        $cumul_inleg       += (float) $row->inlegbedrag;
        $cumul_opname      += (float) $row->opnamebedrag;
        $cumul_distributie += (float) $row->distributievergoeding;

        $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;

        $netto_inleg = $cumul_inleg - $cumul_opname;
        $current_pos = $netto_inleg + $cumul_distributie;

        // Distributies in de vorige maand (alleen die maand, niet cumulatief)
        if ( $row_dt->format( 'Y-m' ) === $prev_month_ym ) {
            $monthly_distrib += (float) $row->distributievergoeding;
        }

        // Stand t/m einde vorige maand
        if ( $row_dt <= $prev_end ) {
            $prev_parts = $cumul_participaties;
            $prev_pos   = $current_pos;
        }

        // Stand t/m berichtdatum (start nieuwe maand)
        if ( $row_dt <= $dt_msg ) {
            $start_parts = $cumul_participaties;
            $start_pos   = $current_pos;
        }
    }

    // 1) NAV op basis van GGR stock price (voorkeur)
    $nav_stock = null;
    if ( function_exists( 'ggr_get_stock_price_for_date' ) ) {
        $nav_stock = ggr_get_stock_price_for_date( $prev_end->format( 'Y-m-d' ) );
    }

    // 2) Fallback: positie / aantal participaties per einde vorige maand
    $nav_from_position = ( $prev_parts > 0 )
        ? ( $prev_pos / $prev_parts )
        : 0.0;

    $nav = ( $nav_stock !== null ) ? $nav_stock : $nav_from_position;

    /**
     * Filter: laat externe code dit nog tweaken indien nodig.
     * Je krijgt zowel de stock-NAV als de positie-NAV mee.
     */
    $nav = apply_filters(
        'ggr_portal_nav_for_period',
        $nav,
        array(
            'user_id'            => $user_id,
            'message_id'         => $message_id,
            'prev_end_date'      => $prev_end,
            'msg_date'           => $dt_msg,
            'parts_prev'         => $prev_parts,
            'pos_prev'           => $prev_pos,
            'nav_from_stock'     => $nav_stock,
            'nav_from_position'  => $nav_from_position,
        )
    );

    // Netto aangekochte participaties t.o.v. einde vorige maand
    $new_parts = $start_parts - $prev_parts;

    // Totale waarde bij start nieuwe maand = NAV einde vorige maand x participaties bij start nieuwe maand
    $total_value = $start_pos;
    $prev_nav    = null;
    $lookup_date = $prev_end->format( 'Y-m-d' );

    if ( '01' === $dt_msg->format( 'd' ) ) {
        if ( function_exists( 'ggr_dividend_accruals_get_previous_month_end' ) ) {
            $lookup_date = ggr_dividend_accruals_get_previous_month_end( $dt_msg->format( 'Y-m-d' ) );
        }
    }

    if ( function_exists( 'ggr_get_stock_price_for_date' ) ) {
        $prev_nav = ggr_get_stock_price_for_date( $lookup_date, true );
    }

    if ( $prev_nav !== null && $prev_nav > 0 ) {
        $start_parts_value = $start_parts;
        if ( function_exists( 'ggr_portal_truncate_participaties' ) ) {
            $start_parts_value = ggr_portal_truncate_participaties( $start_parts_value, 4 );
        }
        $total_value = $start_parts_value * (float) $prev_nav;
    }

    return array(
        'prev_end_date'  => $prev_end,
        'msg_date'       => $dt_msg,
        'parts_prev'     => $prev_parts,
        'nav'            => $nav,
        'distrib_prev'   => $monthly_distrib, // alleen de maand, niet cumulatief
        'new_parts'      => $new_parts,
        'parts_start'    => $start_parts,
        'value_start'    => $total_value,
    );
}

/**
 * 4. Access check
 *    Extra regel: transactieberichten alleen tonen als user
 *    in die maand minimaal één transactie heeft.
 */
function ggr_portal_user_can_read_message( $post, $user_id ) {
    if ( ! $post instanceof WP_Post ) {
        $post = get_post( $post );
    }
    if ( ! $post || $post->post_type !== 'ggr_bericht' || $post->post_status !== 'publish' ) {
        return false;
    }

    $audience       = get_post_meta( $post->ID, '_ggr_message_audience', true );
    $target_user_id = absint( get_post_meta( $post->ID, '_ggr_message_user_id', true ) );
    $role           = get_post_meta( $post->ID, '_ggr_message_role', true );

    $allowed = false;

    if ( $audience === 'all' || $audience === '' ) {
        $allowed = true;
    } elseif ( $audience === 'user' && $target_user_id && $target_user_id === $user_id ) {
        $allowed = true;
    } elseif ( $audience === 'role' && $role ) {
        $allowed = ggr_portal_user_has_role( $user_id, $role );
    }

    if ( ! $allowed ) {
        return false;
    }

    // Extra filter voor transactienota's: alleen tonen als er in die maand transacties zijn
    $type          = get_post_meta( $post->ID, '_ggr_message_type', true );
    $is_single_tx  = (bool) get_post_meta( $post->ID, '_ggr_message_single_transaction', true );
    if ( $type === 'transaction' && function_exists( 'ggr_portal_get_history_for_user' ) ) {
        if ( $is_single_tx ) {
            return true;
        }
        $rows = ggr_portal_get_transactions_for_message_month( $user_id, $post->ID );
        if ( empty( $rows ) ) {
            return false;
        }
    }

    return true;
}

/**
 * 5. Read / unread per gebruiker
 */

function ggr_portal_is_message_read( $user_id, $message_id ) {
    $user_id    = (int) $user_id;
    $message_id = (int) $message_id;

    if ( ! $user_id || ! $message_id ) {
        return false;
    }

    $key = '_ggr_bericht_read_' . $message_id;

    return (bool) get_user_meta( $user_id, $key, true );
}

function ggr_portal_mark_message_read( $user_id, $message_id ) {
    $user_id    = (int) $user_id;
    $message_id = (int) $message_id;

    if ( ! $user_id || ! $message_id ) {
        return;
    }

    $key = '_ggr_bericht_read_' . $message_id;
    update_user_meta( $user_id, $key, 1 );
}

function ggr_portal_mark_message_unread( $user_id, $message_id ) {
    $user_id    = (int) $user_id;
    $message_id = (int) $message_id;

    if ( ! $user_id || ! $message_id ) {
        return;
    }

    $key = '_ggr_bericht_read_' . $message_id;
    update_user_meta( $user_id, $key, 0 );
}

/**
 * 5b. AJAX: batch markeer gelezen / ongelezen
 */
add_action( 'wp_ajax_ggr_portal_mark_messages_read', 'ggr_portal_ajax_mark_messages_read' );
add_action( 'wp_ajax_ggr_portal_mark_messages_unread', 'ggr_portal_ajax_mark_messages_unread' );

function ggr_portal_ajax_mark_messages_read() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Niet ingelogd.' ) );
    }

    check_ajax_referer( 'ggr_messages_ajax', 'nonce' );

    $user_id = get_current_user_id();

    $ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
    $ids_raw = sanitize_text_field( $ids_raw );
    $ids     = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );

    if ( empty( $ids ) ) {
        wp_send_json_error( array( 'message' => 'Geen berichten geselecteerd.' ) );
    }

    foreach ( $ids as $id ) {
        ggr_portal_mark_message_read( $user_id, $id );
    }

    wp_send_json_success();
}

function ggr_portal_ajax_mark_messages_unread() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Niet ingelogd.' ) );
    }

    check_ajax_referer( 'ggr_messages_ajax', 'nonce' );

    $user_id = get_current_user_id();

    $ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
    $ids_raw = sanitize_text_field( $ids_raw );
    $ids     = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );

    if ( empty( $ids ) ) {
        wp_send_json_error( array( 'message' => 'Geen berichten geselecteerd.' ) );
    }

    foreach ( $ids as $id ) {
        ggr_portal_mark_message_unread( $user_id, $id );
    }

    wp_send_json_success();
}

/**
 * 6. Berichten voor user ophalen
 */
function ggr_portal_get_messages_for_user( $user_id, $limit = 100 ) {
    $args = array(
        'post_type'      => 'ggr_bericht',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'meta_key'       => '_ggr_message_date',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
    );

    $query = new WP_Query( $args );
    $posts = array();

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post ) {
            if ( ggr_portal_user_can_read_message( $post, $user_id ) ) {
                $posts[] = $post;
            }
        }
    }

    wp_reset_postdata();
    return $posts;
}

/**
 * 7. Shortcode [ggr_portal_berichten]
 */
add_shortcode( 'ggr_portal_berichten', 'ggr_portal_berichten_shortcode' );

function ggr_portal_berichten_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Log eerst in om uw berichten te bekijken.</p>';
    }

    $user_id    = get_current_user_id();
    $bericht_id = isset( $_GET['bericht'] ) ? absint( $_GET['bericht'] ) : 0;

    ob_start();

    if ( $bericht_id ) {
        ggr_portal_render_single_message( $bericht_id, $user_id );
    } else {
        ggr_portal_render_message_overview( $user_id );
    }

    return ob_get_clean();
}

/**
 * 7a. Overzicht-weergave
 */
function ggr_portal_render_message_overview( $user_id ) {
    $messages   = ggr_portal_get_messages_for_user( $user_id );
    $ajax_nonce = wp_create_nonce( 'ggr_messages_ajax' );
    $ajax_url   = admin_url( 'admin-ajax.php' );
    ?>
    <div class="ggr-berichten-wrapper" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-ajax-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
        <h1 class="ggr-berichten-title">Berichten</h1>

        <div class="ggr-berichten-card">
            <div class="ggr-berichten-toolbar">
                <button type="button" class="ggr-berichten-select-all" data-select-all="1">
                    Selecteer alle berichten
                </button>
                <div class="ggr-berichten-toolbar-actions">
                    <button type="button" class="ggr-berichten-mark-read">
                        Markeer als gelezen
                    </button>
                    <button type="button" class="ggr-berichten-mark-unread">
                        Markeer als ongelezen
                    </button>
                </div>
            </div>

            <?php if ( empty( $messages ) ) : ?>
                <p class="ggr-berichten-empty">U heeft nog geen berichten.</p>
            <?php else : ?>

                <div class="ggr-berichten-list">
                    <?php
                    $current_group = '';
                    foreach ( $messages as $post ) :
                        $raw_date   = get_post_meta( $post->ID, '_ggr_message_date', true );
                        $timestamp  = $raw_date ? strtotime( $raw_date . ' 00:00:00' ) : get_post_timestamp( $post );
                        $month_year = date_i18n( 'F Y', $timestamp );
                        $date_short = date_i18n( 'd M Y', $timestamp );

                        $is_read   = ggr_portal_is_message_read( $user_id, $post->ID );
                        $row_class = $is_read ? 'ggr-bericht-row--read' : 'ggr-bericht-row--unread';

                        if ( $month_year !== $current_group ) :
                            if ( $current_group !== '' ) {
                                echo '</div>'; // sluit vorige month-group
                            }
                            $current_group = $month_year;
                            ?>
                            <div class="ggr-berichten-month-group">
                                <div class="ggr-berichten-month-header">
                                    <?php echo esc_html( $month_year ); ?>
                                </div>
                        <?php
                        endif;
                        ?>
                        <label class="ggr-bericht-row <?php echo esc_attr( $row_class ); ?>" data-message-id="<?php echo (int) $post->ID; ?>">
                            <input type="checkbox" class="ggr-bericht-checkbox" />
                            <a href="<?php echo esc_url( add_query_arg( 'bericht', $post->ID ) ); ?>" class="ggr-bericht-main">
                                <span class="ggr-bericht-title">
                                    <?php echo esc_html( get_the_title( $post ) ); ?>
                                </span>
                                <span class="ggr-bericht-date">
                                    <?php echo esc_html( $date_short ); ?>
                                </span>
                            </a>
                        </label>
                    <?php endforeach; ?>

                    <?php if ( $current_group !== '' ) : ?>
                        </div> <!-- sluit laatste month-group -->
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        (function(){
            const wrapper = document.querySelector('.ggr-berichten-wrapper');
            if (!wrapper) return;

            const ajaxUrl   = wrapper.getAttribute('data-ajax-url');
            const ajaxNonce = wrapper.getAttribute('data-ajax-nonce');

            const selectAllBtn  = wrapper.querySelector('.ggr-berichten-select-all');
            const markReadBtn   = wrapper.querySelector('.ggr-berichten-mark-read');
            const markUnreadBtn = wrapper.querySelector('.ggr-berichten-mark-unread');

            function getCheckboxes() {
                return Array.prototype.slice.call(
                    wrapper.querySelectorAll('.ggr-bericht-checkbox')
                );
            }

            function getSelectedIds() {
                const boxes = getCheckboxes().filter(cb => cb.checked);
                return boxes.map(cb => {
                    const row = cb.closest('.ggr-bericht-row');
                    return row ? row.getAttribute('data-message-id') : null;
                }).filter(Boolean);
            }

            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function () {
                    const checkboxes = getCheckboxes();
                    if (checkboxes.length === 0) return;

                    const allChecked = checkboxes.every(cb => cb.checked);
                    checkboxes.forEach(cb => cb.checked = !allChecked);
                });
            }

            function updateRowClasses(ids, makeRead) {
                ids.forEach(function(id) {
                    const row = wrapper.querySelector('.ggr-bericht-row[data-message-id="' + id + '"]');
                    if (!row) return;
                    if (makeRead) {
                        row.classList.remove('ggr-bericht-row--unread');
                        row.classList.add('ggr-bericht-row--read');
                    } else {
                        row.classList.remove('ggr-bericht-row--read');
                        row.classList.add('ggr-bericht-row--unread');
                    }
                });
            }

            function sendAjax(action, ids, makeRead) {
                if (!ids.length) {
                    alert('Selecteer eerst één of meer berichten.');
                    return;
                }

                const body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', ajaxNonce);
                body.append('ids', ids.join(','));

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                }).then(function(resp) {
                    return resp.json();
                }).then(function(data) {
                    if (data && data.success) {
                        updateRowClasses(ids, makeRead);
                    } else if (data && data.data && data.data.message) {
                        alert(data.data.message);
                    } else {
                        alert('Er ging iets mis bij het bijwerken van de berichten.');
                    }
                }).catch(function() {
                    alert('Er ging iets mis bij het versturen van de aanvraag.');
                });
            }

            if (markReadBtn) {
                markReadBtn.addEventListener('click', function() {
                    const ids = getSelectedIds();
                    sendAjax('ggr_portal_mark_messages_read', ids, true);
                });
            }

            if (markUnreadBtn) {
                markUnreadBtn.addEventListener('click', function() {
                    const ids = getSelectedIds();
                    sendAjax('ggr_portal_mark_messages_unread', ids, false);
                });
            }
        })();
    </script>
    <?php
}

/**
 * 7b. Detail-weergave — content met do_shortcode()
 */
function ggr_portal_render_single_message( $bericht_id, $user_id ) {
    $post = get_post( $bericht_id );

    if ( ! ggr_portal_user_can_read_message( $post, $user_id ) ) {
        echo '<p>Dit bericht is niet beschikbaar.</p>';
        return;
    }

    // Bij openen → markeer als gelezen
    ggr_portal_mark_message_read( $user_id, $bericht_id );

    $raw_date  = get_post_meta( $post->ID, '_ggr_message_date', true );
    $timestamp = $raw_date ? strtotime( $raw_date . ' 00:00:00' ) : get_post_timestamp( $post );
    $date_full = date_i18n( 'd M Y', $timestamp );

    $back_url = remove_query_arg( 'bericht' );
    ?>
    <div class="ggr-berichten-wrapper">
        <h1 class="ggr-berichten-title">Berichten</h1>
        <div class="ggr-berichten-card ggr-berichten-card--detail">

            <div class="ggr-bericht-detail-header">
                <a href="<?php echo esc_url( $back_url ); ?>" class="ggr-bericht-back">&larr; Berichten</a>
                <span class="ggr-bericht-detail-date"><?php echo esc_html( $date_full ); ?></span>
            </div>

            <h2 class="ggr-bericht-detail-title">
                <?php echo esc_html( get_the_title( $post ) ); ?>
            </h2>

            <div class="ggr-bericht-detail-body">
                <?php
                // Ruwe content uit de CPT
                $raw_content = $post->post_content;
            
                // 1) Shortcodes uitvoeren
                $content = do_shortcode( $raw_content );
            
                // 2) Onveilige HTML strippen, maar p / strong / etc. toestaan
                $content = wp_kses_post( $content );
            
                // 3) Alinea's maken op basis van lege regels
                $content = wpautop( $content );
            
                echo $content;
                ?>
            </div>

        </div>

        <div class="ggr-berichten-card ggr-berichten-card--options">
            <h3 class="ggr-bericht-options-title">Opties</h3>
            <hr class="ggr-bericht-options-divider"/>
            <?php
            $pdf_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'ggr_action' => 'download_message_pdf',
                        'bericht_id' => $post->ID,
                    ),
                    home_url( '/' )
                ),
                'ggr_download_message_pdf_' . $post->ID,
                'ggr_nonce'
            );
            ?>
            <a href="<?php echo esc_url( $pdf_url ); ?>"
               class="ggr-bericht-options-item ggr-bericht-options-item--pdf"
               target="_blank" rel="noopener">
                <span class="ggr-icon ggr-icon-pdf"></span>
                <span class="download-pdf">Download bericht als PDF</span>
            </a>

        </div>
    </div>
    <?php
}

/**
 * 7c. Download bericht als PDF (DOMPDF)
 */
add_action( 'init', 'ggr_portal_register_message_pdf_download' );
function ggr_portal_register_message_pdf_download() {
    if ( isset( $_GET['ggr_action'] ) && $_GET['ggr_action'] === 'download_message_pdf' ) {
        ggr_portal_handle_message_pdf_download();
        exit;
    }
}

function ggr_portal_handle_message_pdf_download() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Niet ingelogd.' );
    }

    $bericht_id = isset( $_GET['bericht_id'] ) ? absint( $_GET['bericht_id'] ) : 0;
    if ( ! $bericht_id ) {
        wp_die( 'Geen bericht opgegeven.' );
    }

    if ( ! isset( $_GET['ggr_nonce'] ) ||
         ! wp_verify_nonce( $_GET['ggr_nonce'], 'ggr_download_message_pdf_' . $bericht_id ) ) {
        wp_die( 'Ongeldige aanvraag.' );
    }

    $user_id = get_current_user_id();
    $post    = get_post( $bericht_id );

    if ( ! $post ) {
        wp_die( 'Bericht niet gevonden.' );
    }

    if ( ! ggr_portal_user_can_read_message( $post, $user_id ) ) {
        wp_die( 'Geen toegang tot dit bericht.' );
    }

    $html = ggr_portal_render_message_pdf_html( $post, $user_id );
    
    // Laad dompdf autoloader indien beschikbaar
    if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
        $dompdf_autoload = trailingslashit( GGR_PORTAL_CORE_PATH ) . 'dompdf/autoload.inc.php';
        if ( file_exists( $dompdf_autoload ) ) {
            require_once $dompdf_autoload;
        }
    }

    // Als dompdf beschikbaar is: echte PDF renderen
    if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
        $options = new \Dompdf\Options();
        $options->set( 'isRemoteEnabled', true );

        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->loadHtml( $html );
        $dompdf->render();

        $pdf_output = $dompdf->output();

        if ( ob_get_length() ) {
            @ob_end_clean();
        }

        $base = sanitize_title( get_the_title( $post ) );
        if ( ! $base ) {
            $base = 'bericht-' . $bericht_id;
        }
        $filename = $base . '.pdf';

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        header( 'Cache-Control: private, max-age=0, must-revalidate' );
        header( 'Pragma: public' );

        echo $pdf_output;
        exit;
    }

    // Fallback: HTML tonen (zou in jouw setup eigenlijk niet meer gebruikt moeten worden)
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo $html;
    exit;
}

/**
 * PDF HTML-template met blauwe balken boven/onder
 */
function ggr_portal_render_message_pdf_html( WP_Post $post, $user_id ) {

    $raw_date  = get_post_meta( $post->ID, '_ggr_message_date', true );
    $timestamp = $raw_date ? strtotime( $raw_date ) : current_time( 'timestamp' );
    $date_full = date_i18n( 'd-m-Y', $timestamp );

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        $user = wp_get_current_user();
    }

    $content = apply_filters( 'the_content', $post->post_content );
    $content = do_shortcode( $content );

    $company  = get_user_meta( $user_id, 'company_name', true ) ?: '';
    $street   = get_user_meta( $user_id, 'address_street', true );
    $postcode = get_user_meta( $user_id, 'address_postcode', true );
    $city     = get_user_meta( $user_id, 'address_city', true );
    $country  = get_user_meta( $user_id, 'address_country', true ) ?: 'Nederland';

    $greeting_name = function_exists( 'ggr_portal_get_greeting_name' )
        ? ggr_portal_get_greeting_name( $user )
        : trim( (string) $user->first_name );
    if ( $greeting_name === '' ) {
        $greeting_name = $user->display_name;
    }

    if ( $greeting_name !== '' && $user->last_name && $greeting_name !== $user->display_name ) {
        $full_name = trim( $greeting_name . ' ' . $user->last_name );
    } else {
        $full_name = $greeting_name ?: $user->display_name;
    }

    ob_start();
    ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo esc_html( get_the_title( $post ) ); ?></title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.4;
            color: #111827;
            text-align: justify;
            text-justify: inter-word;   /* vooral nuttig in sommige engines */
            hyphens: auto;              /* betere woordafbreking */
}
        }

        .top-bar,
        .bottom-bar {
            position: fixed;
            left: 0;
            right: 0;
            height: 10mm;
            background: #9fbac7;
        }

        .top-bar {
            top: 0;
        }

        .bottom-bar {
            bottom: 0;
        }

        .page-content {
            padding: 20mm 15mm 20mm;
            box-sizing: border-box;
        }

        /* watermark */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            width: 60mm;
            height: 60mm;
            margin-left: -30mm;
            margin-top: -30mm;
            opacity: 0.06;
            z-index: -1;
        }
        .watermark img {
            width: 100%;
            height: auto;
        }

        .header-row {
            display: table;
            width: 100%;
            margin-bottom: 6mm;
        }
        .header-logo,
        .header-contact {
            display: table-cell;
            vertical-align: top;
        }
        .header-logo img {
            height: 55px;
        }
        .header-contact {
            text-align: right;
            font-size: 12px;
            line-height: 1.4;
        }

        .header-divider {
            height: 2px;
            background: #111827;
            margin: 2mm 0 6mm 0;
            position: relative;
        }
        .header-divider::after {
            content: "";
            position: absolute;
            left: 25%;
            right: 25%;
            top: 0;
            height: 2px;
            background: #9fbac7;
        }

        .top-layout {
            display: table;
            width: 100%;
            margin-bottom: 8mm;
        }
        .top-left,
        .top-right {
            display: table-cell;
            vertical-align: top;
            font-size: 14px;
        }
        .top-left {
            width: 60%;
            padding-right: 5mm;
        }
        .top-right {
            width: 40%;
            text-align: right;
        }
        .top-right-date {
            font-weight: 600;
            margin-bottom: 2mm;
        }

        .address-block strong {
            font-size: 14px;
        }

        h1 {
            font-size: 14px;
            margin: 0 0 4mm 0;
            font-weight: 600;
        }

        .content p {
            margin: 0mm 2.5mm 2.5mm 0mm;
        }

        .content h3 {
            font-size: 14px;
            margin: 5mm 0 2mm;
        }

        .ggr-trans-table-wrapper {
            margin-top: 2mm;
        }
        .ggr-trans-table {
            margin-top: 4mm;
            margin-bottom: 4mm;
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .ggr-trans-table th {
            padding: 2mm 2.5mm;
            border: 0.2mm solid #e5e7eb;
        }
        .ggr-trans-table td {
            padding: 2mm 2.5mm;
            border: 0.2mm solid #e5e7eb;
        }
        .ggr-trans-table thead th {
            font-weight: 600;
            background: #f3f4f6;
            text-align: left;
        }
        .ggr-trans-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .footer {
            margin-top: 6mm;
            font-size: 8.5px;
            color: #9ca3af;
        }
    </style>
</head>
<body>

<div class="top-bar"></div>
<div class="bottom-bar"></div>

<div class="watermark">
    <img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png" alt="GGR Icon">
</div>

<div class="page-content">

    <div class="header-row">
        <div class="header-logo">
            <img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png"
                 alt="GGR Icon">
        </div>
        <div class="header-contact">
            +31 85 080 50 35<br>
            mif@ggrfunds.com<br>
            Rietbaan 2, 2908 LP Capelle a/d IJssel
        </div>
    </div>

    <div class="header-divider"></div>

    <div class="top-layout">
        <div class="top-left">
            <div class="address-block">
                <?php if ( $company !== '' ) : ?>
                    <strong><?php echo esc_html( $company ); ?></strong><br>
                <?php endif; ?>

                <?php echo esc_html( $full_name ); ?><br>

                <?php if ( $street ) : ?>
                    <?php echo esc_html( $street ); ?><br>
                <?php endif; ?>

                <?php if ( $postcode || $city ) : ?>
                    <?php echo esc_html( trim( $postcode . ', ' . $city, ' ,' ) ); ?><br>
                <?php endif; ?>

                <?php echo esc_html( $country ); ?>
            </div>
        </div>

        <div class="top-right">
            <div class="top-right-date">
                <?php echo esc_html( $date_full ); ?>
            </div>
        </div>
    </div>

    <h1>Betreft: <?php echo esc_html( get_the_title( $post ) ); ?></h1>

    <div class="content">
        <?php echo $content; ?>
    </div>

</div>
</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * 8. Automatische maandelijkse transactieberichten
 *
 * Let op:
 * - Berichten worden gepubliceerd op de 1e van de nieuwe maand
 * - Maar de transactienota heeft betrekking op de VORIGE maand
 *   => _ggr_message_period en titel zijn vorige maand
 *   => _ggr_message_date is de publicatiedatum (1e van de nieuwe maand)
 */
function ggr_portal_create_transaction_message( $user_id, $args = array() ) {
    $defaults = array(
        'reference' => '',
        'status'    => '',
        'amount'    => 0,
        'date'      => current_time( 'Y-m-d' ), // verwacht: publicatiedatum (1e van de nieuwe maand)
    );
    $data = wp_parse_args( $args, $defaults );

    // Publicatiedatum (bijv. 2025-12-01)
    $date_raw = $data['date'] ? $data['date'] : current_time( 'Y-m-d' );
    $dt_pub   = DateTime::createFromFormat( 'Y-m-d', $date_raw );
    if ( ! $dt_pub ) {
        $dt_pub = new DateTime( current_time( 'Y-m-d' ) );
    }

    // Afrekenmaand = vorige maand (bijv. november 2025)
    $dt_settle = clone $dt_pub;
    $dt_settle->modify( '-1 month' );

    // Periode-meta = vorige maand (YYYY-MM), voor filtering / unieke check
    $period      = $dt_settle->format( 'Y-m' );      // bijv. 2025-11
    $month_label = date_i18n( 'F Y', $dt_settle->getTimestamp() ); // "november 2025"

    // Publicatiedatum = eerste dag van de nieuwe maand
    $message_date = $dt_pub->format( 'Y-m-01' );

    // Bestaat er al een transactiebericht voor deze afrekenmaand?
    $existing = get_posts( array(
        'post_type'      => 'ggr_bericht',
        'post_status'    => array( 'draft', 'pending', 'publish' ),
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'   => '_ggr_message_type',
                'value' => 'transaction',
            ),
            array(
                'key'   => '_ggr_message_period',
                'value' => $period, // LET OP: vorige maand
            ),
        ),
        'fields' => 'ids',
    ) );

    if ( ! empty( $existing ) ) {
        return (int) $existing[0];
    }

    $body_lines   = array();
    $body_lines[] = 'Beste [ggr_user_firstname],';
    $body_lines[] = '';
    $body_lines[] = sprintf(
        'In dit bericht vind je de bevestiging van de mutaties in je participaties over %s.',
        esc_html( $month_label ) // vorige maand benoemen
    );
    $body_lines[] = '';
    $body_lines[] = '[Vul hier je begeleidende tekst voor deze maand in.]';
    $body_lines[] = '';
    $body_lines[] = '[ggr_transacties_overzicht]';
    $body_lines[] = '';
    $body_lines[] = 'Met dit afschrift bevestigen wij de toevoeging van uw nieuwe participaties aan uw deelname.';
    $body_lines[] = 'Bewaar dit document goed als bewijs van uw participatie in het fonds.';
    $body_lines[] = '';
    $body_lines[] = 'Voor vragen over dit afschrift of uw deelname kunt u contact opnemen via bovenstaande contactgegevens.';
    $body_lines[] = '';
    $body_lines[] = 'Met vriendelijke groet,';
    $body_lines[] = 'GGR Funds';

    $body = implode( "\n", $body_lines );

    $post_id = wp_insert_post( array(
        'post_type'    => 'ggr_bericht',
        'post_status'  => 'draft',
        'post_title'   => sprintf( 'Transactienota %s', $month_label ),
        // Sla RUWE tekst op, zonder wpautop
        'post_content' => $body,
    ), true );


    if ( ! $post_id || is_wp_error( $post_id ) ) {
        return 0;
    }

    update_post_meta( $post_id, '_ggr_message_audience', 'role' );
    update_post_meta( $post_id, '_ggr_message_user_id', 0 );
    update_post_meta( $post_id, '_ggr_message_role', 'participant' );
    update_post_meta( $post_id, '_ggr_message_type', 'transaction' );

    // Publicatiedatum (1e van de NIEUWE maand)
    update_post_meta( $post_id, '_ggr_message_date', $message_date );

    update_post_meta( $post_id, '_ggr_message_transaction_ref', '' );

    // Periode = AFREKENmaand (vorige maand) → voor maandoverzichten / unieke key
    update_post_meta( $post_id, '_ggr_message_period', $period );

    return (int) $post_id;
}

/**
 * 8b. Losse transactieberichten per betaling.
 */
function ggr_portal_create_single_transaction_message( $user_id, $args = array() ) {
    $defaults = array(
        'reference' => '',
        'amount'    => 0,
        'date'      => current_time( 'Y-m-d' ),
        'title'     => 'Transactiebevestiging',
        'body'      => '',
    );
    $data = wp_parse_args( $args, $defaults );

    $user_id   = (int) $user_id;
    $reference = sanitize_text_field( $data['reference'] );
    if ( ! $user_id || '' === $reference ) {
        return 0;
    }

    $existing = get_posts( array(
        'post_type'      => 'ggr_bericht',
        'post_status'    => array( 'draft', 'pending', 'publish' ),
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'   => '_ggr_message_transaction_ref',
                'value' => $reference,
            ),
            array(
                'key'   => '_ggr_message_user_id',
                'value' => $user_id,
            ),
        ),
        'fields' => 'ids',
    ) );

    if ( ! empty( $existing ) ) {
        return (int) $existing[0];
    }

    $date_raw = $data['date'] ? $data['date'] : current_time( 'Y-m-d' );
    $dt       = DateTime::createFromFormat( 'Y-m-d', $date_raw );
    if ( ! $dt ) {
        $dt = new DateTime( current_time( 'Y-m-d' ) );
    }
    $message_date = $dt->format( 'Y-m-d' );
    $period       = $dt->format( 'Y-m' );

    $amount_value = (float) $data['amount'];
    $amount_label = function_exists( 'ggrp_fe_format_money' )
        ? ggrp_fe_format_money( $amount_value )
        : '€ ' . number_format( $amount_value, 2, ',', '.' );

    $body = $data['body'];
    if ( '' === $body ) {
        $body_lines   = array();
        $body_lines[] = 'Beste [ggr_user_firstname],';
        $body_lines[] = '';
        $body_lines[] = sprintf( 'We hebben je storting van %s ontvangen.', $amount_label );
        $body_lines[] = 'Deze transactie wordt verwerkt op de eerstvolgende handelsdag.';
        $body_lines[] = '';
        $body_lines[] = 'Met vriendelijke groet,';
        $body_lines[] = 'GGR Funds';
        $body         = implode( "\n", $body_lines );
    }

    $post_id = wp_insert_post(
        array(
            'post_type'    => 'ggr_bericht',
            'post_status'  => 'draft',
            'post_title'   => sanitize_text_field( $data['title'] ),
            'post_content' => $body,
        ),
        true
    );

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        return 0;
    }

    update_post_meta( $post_id, '_ggr_message_audience', 'user' );
    update_post_meta( $post_id, '_ggr_message_user_id', $user_id );
    update_post_meta( $post_id, '_ggr_message_role', 'participant' );
    update_post_meta( $post_id, '_ggr_message_type', 'transaction' );
    update_post_meta( $post_id, '_ggr_message_date', $message_date );
    update_post_meta( $post_id, '_ggr_message_transaction_ref', $reference );
    update_post_meta( $post_id, '_ggr_message_period', $period );
    update_post_meta( $post_id, '_ggr_message_single_transaction', 1 );

    $post_id = wp_insert_post(
        array(
            'post_type'    => 'ggr_bericht',
            'post_status'  => 'draft',
            'post_title'   => sanitize_text_field( $data['title'] ),
            'post_content' => $body,
        ),
        true
    );
    
    return (int) $post_id;
}

/**
 * 9. Shortcode: voornaam van ingelogde gebruiker
 */
function ggr_portal_message_user_firstname_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'fallback' => 'investeerder',
        ),
        $atts,
        'ggr_user_firstname'
    );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return esc_html( $atts['fallback'] );
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return esc_html( $atts['fallback'] );
    }

    $greeting_name = function_exists( 'ggr_portal_get_greeting_name' )
        ? ggr_portal_get_greeting_name( $user )
        : trim( (string) $user->first_name );
    if ( $greeting_name === '' ) {
        $greeting_name = $user->display_name ? $user->display_name : $atts['fallback']; $atts['fallback'];
    }

    return esc_html( $greeting_name );
}
add_shortcode( 'ggr_user_firstname', 'ggr_portal_message_user_firstname_shortcode' );

/**
 * 10. Shortcode: maandoverzicht bij transactiebericht
 *
 * Toont één horizontale tabel met:
 * - Stand + NAV per laatste dag vorige maand
 * - Kapitaalsuitkering uit de settlement-transactie (1e dag nieuwe maand)
 * - Nieuwe participaties + totale waarde per 1e dag nieuwe maand
 *
 * Business rules:
 * - _ggr_message_period (YYYY-MM) = afrekenmaand
 * - Settlement-transactie = eerste transactie op de 1e van de nieuwe maand
 *   → daar halen we distributievergoeding, nieuwe/totaal participaties en waarde uit
 */
function ggr_transacties_overzicht_shortcode( $atts ) {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user_id = get_current_user_id();

    // 1. Bepaal huidig bericht
    $current_message_id = 0;
    if ( isset( $_GET['bericht'] ) ) {
        $current_message_id = absint( $_GET['bericht'] );
    } elseif ( isset( $_GET['bericht_id'] ) ) {
        $current_message_id = absint( $_GET['bericht_id'] );
    }

    if ( ! $current_message_id ) {
        return '';
    }

    // 2. Periode-meta (YYYY-MM) = afrekenmaand
    $period = get_post_meta( $current_message_id, '_ggr_message_period', true );

    // Fallback: als periode ontbreekt, afleiden uit berichtdatum
    $raw_date = get_post_meta( $current_message_id, '_ggr_message_date', true );
    if ( ! $raw_date ) {
        $raw_date = get_the_date( 'Y-m-d', $current_message_id );
    }
    $dt_pub = DateTime::createFromFormat( 'Y-m-d', $raw_date );
    if ( ! $dt_pub ) {
        return '';
    }

    if ( $period && preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
        $dt_prev_start = DateTime::createFromFormat( 'Y-m-d', $period . '-01' );
    } else {
        // publicatiedatum 01-12 → afrekenmaand november
        $dt_prev_start = clone $dt_pub;
        $dt_prev_start->modify( '-1 month' )->modify( 'first day of this month' );
        $period = $dt_prev_start->format( 'Y-m' );
    }

    $dt_prev_end = clone $dt_prev_start;
    $dt_prev_end->modify( 'last day of this month' );   // 30/31 van afrekenmaand

    // Eerste dag nieuwe maand = dag na eind vorige maand
    $dt_new_start = clone $dt_prev_end;
    $dt_new_start->modify( '+1 day' );                  // 01-xx-volgende maand

    $label_prev_end  = date_i18n( 'd-m-Y', $dt_prev_end->getTimestamp() );
    $label_new_start = date_i18n( 'd-m-Y', $dt_new_start->getTimestamp() );
    $month_label     = date_i18n( 'F Y', $dt_prev_end->getTimestamp() );

    // 3. Historie ophalen
    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return '<p>Er zijn geen transacties voor deze afwikkelmaand.</p>';
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return '<p>Er zijn geen transacties voor deze afwikkelmaand.</p>';
    }

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_distributie   = 0.0; // blijft cumulatief, maar gebruiken we hier niet direct
    $cumul_participaties = 0.0;
    $current_pos         = 0.0;

    $prev_parts          = 0.0; // stand per laatste dag vorige maand
    $prev_pos            = 0.0; // positiewaarde per laatste dag vorige maand
    $start_parts         = 0.0; // stand per 1e dag nieuwe maand
    $monthly_distrib     = 0.0; // OPGEBOUWDE kapitaalsuitkering = distributie op settlement (1e dag nieuwe maand)
    $new_parts           = 0.0; // nieuwe participaties uit transacties op 1e dag nieuwe maand
    $new_parts_found     = false;
    $positie_value_from_tx = null;    
    $new_start_ymd = $dt_new_start->format( 'Y-m-d' );

    foreach ( $history as $row ) {
        if ( empty( $row->datum ) ) {
            continue;
        }

        $row_dt = DateTime::createFromFormat( 'Y-m-d', $row->datum );
        if ( ! $row_dt ) {
            continue;
        }

        // cumulatieven bijwerken
        $cumul_inleg         += (float) $row->inlegbedrag;
        $cumul_opname        += (float) $row->opnamebedrag;
        $cumul_distributie   += (float) $row->distributievergoeding;
        $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;
        $netto_inleg = $cumul_inleg - $cumul_opname;
        $current_pos = $netto_inleg + $cumul_distributie;
        $row_ymd = $row_dt->format( 'Y-m-d' );

        // stand per einde vorige maand (laatste waarde t/m dt_prev_end)
        if ( $row_dt <= $dt_prev_end ) {
            $prev_parts = $cumul_participaties;
        }

        // stand per 1e dag nieuwe maand (inclusief settlement-transactie zelf)
        if ( $row_dt <= $dt_new_start ) {
            $start_parts = $cumul_participaties;
        }

        // Kapitaalsuitkering: settlement-transacties op 1e dag nieuwe maand
        if ( $row_ymd === $new_start_ymd ) {
            $monthly_distrib += (float) $row->distributievergoeding;
        }

        // Nieuwe maand: transacties op 1e dag nieuwe maand
        if ( $row_ymd === $new_start_ymd ) {
            $new_parts       += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;
            $new_parts_found  = true;
            $positie_value_from_tx = $current_pos;            
        }
    }

    if ( ! $new_parts_found && $prev_parts == 0 && $start_parts == 0 ) {
        // Geen relevante data → dan heeft deze nota eigenlijk geen inhoud
        return '<p>Er zijn geen transacties voor deze afwikkelmaand.</p>';
    }

    // 4. NAV uit GGR stock price tabel
    $nav_prev = null;

    if ( function_exists( 'ggr_get_stock_price_for_date' ) ) {
        $nav_prev = ggr_get_stock_price_for_date( $dt_prev_end->format( 'Y-m-d' ) );
    }

    // 5. Waarden berekenen
    // new_parts is al rechtstreeks uit 1e dag nieuwe maand gehaald
    if ( ! $new_parts_found ) {
        // fallback: als er geen settlement-transactie gevonden is, afleiden uit verschil
        $new_parts = $start_parts - $prev_parts;
    }

    if ( $nav_prev !== null ) {
        $current_pos = $start_parts * (float) $nav_prev;
    }

    if ( $new_parts_found ) {
        $positie_value_from_tx = $current_pos;
    }

    $positie_value = $positie_value_from_tx;
    if ( $positie_value === null && $nav_prev !== null ) {
        $positie_value = $prev_parts * (float) $nav_prev;
    }

    // 6. Output formatteren
ob_start();
?>
    <h3>Jouw transactieoverzicht</h3>

    <div class="ggr-trans-table-wrapper">
        <table class="ggr-trans-table">
            <thead>
            <tr>
                <th>
                    Aantal participaties<br>
                    per <?php echo esc_html( $label_prev_end ); ?>
                </th>

                <!-- HIER: NAV nu op basis van settlement-datum -->
                <th>
                    NAV per participatie<br>
                    <?php echo esc_html( $label_prev_end ); ?> 
                </th>

                <th>
                    Opgebouwde kapitaalsuitkering<br>
                    per <?php echo esc_html( $label_prev_end ); ?> 
                </th>

                <th>
                    Nieuw aangekochte participaties<br>
                    per <?php echo esc_html( $label_new_start ); ?>
                </th>

                <th>
                    Totaal aantal participaties<br>
                    per <?php echo esc_html( $label_new_start ); ?>
                </th>

                <th>
                    Positiewaarde per<br>
                    <?php echo esc_html( $label_new_start ); ?> 
                </th>
            </tr>
            </thead>

            <tbody>
<tr>
    <!-- Kolom 1: aantal participaties -->
    <td
        data-label="Aantal participaties"
        data-date="<?php echo esc_attr( $label_prev_end ); ?>"
    >
        <span class="ggr-trans-value">
            <?php echo number_format( (float) $prev_parts, 4, ',', '.' ); ?>
        </span>
    </td>

    <!-- Kolom 2: NAV -->
    <td
        data-label="NAV per participatie (EUR)"
        data-date="<?php echo esc_attr( $label_prev_end ); ?>"
    >
        <span class="ggr-trans-value">
            <?php
            $nav_display = $nav_prev;
            if ( $nav_display !== null ) {
                echo number_format( (float) $nav_display, 4, ',', '.' );
            } else {
                echo '-';
            }
            ?>
        </span>
    </td>

    <!-- Kolom 3: kapitaalsuitkering -->
    <td
        data-label="Kapitaalsuitkering (EUR)"
        data-date="<?php echo esc_attr( $label_prev_end ); ?>"
    >
        <span class="ggr-trans-value">
            <?php echo '€ ' . number_format( (float) $monthly_distrib, 2, ',', '.' ); ?>
        </span>
    </td>

    <!-- Kolom 4: nieuwe participaties -->
    <td
        data-label="Mutatie participaties"
        data-date="<?php echo esc_attr( $label_new_start ); ?>"
    >
        <span class="ggr-trans-value">
            <?php echo number_format( (float) $new_parts, 4, ',', '.' ); ?>
        </span>
    </td>

    <!-- Kolom 5: totaal participaties -->
    <td
        data-label="Totaal aantal participaties"
        data-date="<?php echo esc_attr( $label_new_start ); ?>"
    >
        <span class="ggr-trans-value">
            <?php echo number_format( (float) $start_parts, 4, ',', '.' ); ?>
        </span>
    </td>

    <!-- Kolom 6: totale waarde -->
    <td
        data-label="Positiewaarde (EUR)"
        data-date="<?php echo esc_attr( $label_prev_end ); ?>"
    >
        <span class="ggr-trans-value">
            <?php
            if ( $positie_value !== null ) {
                echo '€ ' . number_format( (float) $positie_value, 2, ',', '.' );
            } else {
                echo '-';
            }
            ?>
        </span>
    </td>
</tr>
</tbody>


        </table>
    </div>
<?php
return ob_get_clean();


}
add_shortcode( 'ggr_transacties_overzicht', 'ggr_transacties_overzicht_shortcode' );
