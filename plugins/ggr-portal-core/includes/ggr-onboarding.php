<?php
/**
 * GGR Portal – Onboarding module
 *
 * - Onboarding pipeline voor (leads)
 * - Status per user: registreren → active participant
 * - Admin: table view + board (kanban) view met drag & drop
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Beschikbare onboarding-stages
 */
function ggr_onboarding_get_stages() {
    return array(
        'register'           => 'Formulier ingevuld',
        'confirmed'          => 'Account bevestigd',
        'collecting'         => 'Documentatie aanleveren',
        'sign_contract'      => 'Overeenkomst tekenen',
        'validating'         => 'Informatie controleren',        
        'transfer_completed' => 'Geld overmaken',
        'active_participant' => 'Participant geworden',
    );
}

/**
 * Huidige onboarding-status van user ophalen
 */
function ggr_onboarding_get_status( $user_id ) {
    $user_id = (int) $user_id;

    if ( ! $user_id ) {
        return 'register';
    }

    $status = get_user_meta( $user_id, 'ggr_onboarding_status', true );
    $stages = ggr_onboarding_get_stages();

    // Default logica:
    // - als status niet gezet is maar user heeft al historie → active_participant
    if ( ! $status ) {
        if ( function_exists( 'ggr_portal_get_history_for_user' ) ) {
            $history = ggr_portal_get_history_for_user( $user_id );
            if ( ! empty( $history ) ) {
                $status = 'active_participant';
            }
        }
    }

    if ( ! $status || ! isset( $stages[ $status ] ) ) {
        $status = 'register';
    }

    if ( 'confirmed' === $status && ! get_user_meta( $user_id, 'ggr_email_verified', true ) ) {
        $status = 'register';
    }
    
    return $status;
}

/**
 * Onboarding-status updaten
 */
function ggr_onboarding_update_status( $user_id, $status ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) {
        return;
    }

    $stages = ggr_onboarding_get_stages();
    if ( ! isset( $stages[ $status ] ) ) {
        return;
    }

    update_user_meta( $user_id, 'ggr_onboarding_status', $status );
    update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
    if ( 'active_participant' === $status && ! get_user_meta( $user_id, 'ggr_participant_enrolled_at', true ) ) {
        update_user_meta( $user_id, 'ggr_participant_enrolled_at', current_time( 'mysql' ) );
    }
    
    if ( function_exists( 'ggr_hubspot_sync_user' ) ) {
        ggr_hubspot_sync_user( $user_id, $status );
    }
}

function ggr_onboarding_format_datetime_label( $value, $with_time = true ) {
    if ( ! $value ) {
        return '';
    }

    if ( function_exists( 'ggr_portal_format_datetime_nl' ) && $with_time ) {
        return ggr_portal_format_datetime_nl( $value );
    }

    if ( function_exists( 'ggr_portal_format_date_nl' ) && ! $with_time ) {
        return ggr_portal_format_date_nl( $value );
    }

    $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

    if ( ! $timestamp ) {
        return '';
    }

    return $with_time
        ? date_i18n( 'd-m-Y H:i', $timestamp )
        : date_i18n( 'd-m-Y', $timestamp );
}

/**
 * Admin-menu: Onboarding pagina
 */
add_action( 'admin_menu', 'ggr_onboarding_register_admin_page' );

function ggr_onboarding_register_admin_page() {
    add_users_page(
        'Onboarding',
        'Onboarding',
        'read',
        'ggr-onboarding',
        'ggr_onboarding_render_admin_page'
    );
}

function ggr_onboarding_user_can_access() {
    if ( function_exists( 'ggr_admin_shell_user_can_access' ) ) {
        return ggr_admin_shell_user_can_access();
    }

    return current_user_can( 'list_users' );
}

/**
 * Bepalen of huidige view 'board' is
 */
function ggr_onboarding_get_view() {
    $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'table';
    if ( ! in_array( $view, array( 'table', 'board' ), true ) ) {
        $view = 'table';
    }
    return $view;
}

/**
 * Onboarding admin pagina renderen
 */
function ggr_onboarding_render_admin_page() {
    if ( ! ggr_onboarding_user_can_access() ) {
        wp_die( 'Geen toegang.' );
    }

    $stages = ggr_onboarding_get_stages();
    $view   = ggr_onboarding_get_view();

    // Alle gebruikers met rol 'lead' ophalen
    $args  = array(
        'role'    => 'lead',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 500, // kritisch: dit kan zwaar worden bij >500 users
    );
    $users = get_users( $args );
    ?>
    <div class="wrap">
        <h1>Onboarding deelnemers</h1>
        <p>
            Beheer hier de onboarding-fase per participant. Zodra iemand
            "Active participant" is, valt hij onder de CRM-functie.
        </p>

        <?php
        // View switch (nav tabs)
        $base_url = add_query_arg(
            array(
                'page' => 'ggr-onboarding',
            ),
            admin_url( 'users.php' )
        );
        ?>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url( add_query_arg( 'view', 'table', $base_url ) ); ?>"
               class="nav-tab <?php echo ( 'table' === $view ) ? 'nav-tab-active' : ''; ?>">
                Table view
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'view', 'board', $base_url ) ); ?>"
               class="nav-tab <?php echo ( 'board' === $view ) ? 'nav-tab-active' : ''; ?>">
                Board view
            </a>
        </h2>

        <?php if ( empty( $users ) ) : ?>
            <p>Geen participanten gevonden.</p>
            <?php return; endif; ?>

        <?php if ( 'table' === $view ) : ?>

            <?php ggr_onboarding_render_table_view( $users, $stages ); ?>

        <?php else : ?>

            <?php ggr_onboarding_render_board_view( $users, $stages ); ?>

        <?php endif; ?>
    </div>
    <?php
}

/**
 * Table view
 */
function ggr_onboarding_render_table_view( $users, $stages ) {
    ?>
    <table class="widefat striped">
        <thead>
        <tr>
            <th>ID</th>
            <th>Naam</th>
            <th>E-mail</th>
            <th>Huidige fase</th>
            <th>Laatste wijziging</th>
            <th>Actie</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ( $users as $user ) :
            $uid          = $user->ID;
            $status       = ggr_onboarding_get_status( $uid );
            $status_label = isset( $stages[ $status ] ) ? $stages[ $status ] : $status;
            $updated      = get_user_meta( $uid, 'ggr_onboarding_updated_at', true );
            $updated_label = ggr_onboarding_format_datetime_label( $updated );
            $profile_url  = add_query_arg(
                array(
                    'page'    => 'ggr-participant-profiel',
                    'user_id' => $uid,
                ),
                admin_url( 'users.php' )
            );
            ?>
            <tr>
                <td><?php echo esc_html( $uid ); ?></td>
                <td>
                    <a href="<?php echo esc_url( $profile_url ); ?>">
                        <?php echo esc_html( $user->display_name ); ?>
                    </a>
                </td>
                <td>
                    <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
                        <?php echo esc_html( $user->user_email ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $status_label ); ?></td>
                <td><?php echo $updated_label ? esc_html( $updated_label ) : '–'; ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'ggr_onboarding_update', 'ggr_onboarding_nonce' ); ?>
                        <input type="hidden" name="action" value="ggr_onboarding_update_status" />
                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $uid ); ?>" />

                        <select name="onboarding_status">
                            <?php foreach ( $stages as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="button button-primary">Opslaan</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Board view (kanban)
 */
function ggr_onboarding_render_board_view( $users, $stages ) {
    // Users per stage groeperen
    $users_by_stage = array();
    foreach ( $stages as $stage_key => $stage_label ) {
        $users_by_stage[ $stage_key ] = array();
    }

    foreach ( $users as $user ) {
        $uid    = $user->ID;
        $status = ggr_onboarding_get_status( $uid );
        if ( ! isset( $users_by_stage[ $status ] ) ) {
            $users_by_stage[ $status ] = array();
        }
        $users_by_stage[ $status ][] = $user;
    }

    $ajax_nonce = wp_create_nonce( 'ggr_onboarding_move' );
    ?>
    <style>
        .ggr-onboard-board-wrapper {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            overflow-x: auto;
            padding: 8px 0 16px;
        }
        .ggr-onboard-column {
            min-width: 260px;
            max-width: 300px;
            background: #f9fafb;
            border: 1px solid #d7dde2;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            max-height: 70vh;
        }
        .ggr-onboard-column-header {
            padding: 8px 10px;
            border-bottom: 1px solid #d7dde2;
            background: #fff;
            font-weight: 600;
            font-size: 13px;
        }
        .ggr-onboard-column-header small {
            font-weight: normal;
            color: #777;
        }
        .ggr-onboard-column-list {
            padding: 8px;
            margin: 0;
            list-style: none;
            overflow-y: auto;
            flex: 1;
        }
        .ggr-onboard-card {
            background: #fff;
            border-radius: 3px;
            border: 1px solid #d7dde2;
            margin-bottom: 8px;
            padding: 8px 10px;
            cursor: move;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .ggr-onboard-card.is-dragging {
            opacity: 0.6;
        }
        .ggr-onboard-card-header {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .ggr-onboard-card-body {
            font-size: 12px;
            color: #555;
        }
        .ggr-onboard-card-body a {
            text-decoration: none;
        }
        .ggr-onboard-card-meta {
            margin-top: 4px;
            font-size: 11px;
            color: #888;
        }
        .ggr-onboard-column-list.ui-sortable-placeholder {
            border: 2px dashed #cbd4e4;
        }
        .ggr-onboard-card.ui-sortable-placeholder {
            visibility: visible !important;
            background: #edf2fb;
            border: 1px dashed #8190b0;
        }
        .ggr-onboard-board-legend {
            margin-bottom: 8px;
            font-size: 12px;
            color: #555;
        }
        .ggr-onboard-board-legend span {
            margin-right: 12px;
        }
        .ggr-onboard-board-legend strong {
            font-weight: 600;
        }
    </style>

    <p class="ggr-onboard-board-legend">
        <span>Drag &amp; drop kaarten tussen kolommen om de fase te updaten.</span>
        <span><strong>Kritisch:</strong> dit is een eenvoudige board-weergave. Bij heel veel participanten wordt dit snel onoverzichtelijk – filter/scope later per batch of fonds.</span>
    </p>

    <div class="ggr-onboard-board-wrapper"
         data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
         data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
        <?php foreach ( $stages as $stage_key => $stage_label ) :
            $stage_users = isset( $users_by_stage[ $stage_key ] ) ? $users_by_stage[ $stage_key ] : array();
            ?>
            <div class="ggr-onboard-column">
                <div class="ggr-onboard-column-header">
                    <?php echo esc_html( $stage_label ); ?>
                    <small>(<?php echo count( $stage_users ); ?>)</small>
                </div>
                <ul class="ggr-onboard-column-list"
                    data-stage="<?php echo esc_attr( $stage_key ); ?>">
                    <?php foreach ( $stage_users as $user ) :
                        $uid          = $user->ID;
                        $updated      = get_user_meta( $uid, 'ggr_onboarding_updated_at', true );
                        $updated_label = ggr_onboarding_format_datetime_label( $updated );                        
                        $profile_url  = add_query_arg(
                            array(
                                'page'    => 'ggr-participant-profiel',
                                'user_id' => $uid,
                            ),
                            admin_url( 'users.php' )
                        );
                        ?>
                        <li class="ggr-onboard-card"
                            data-user-id="<?php echo esc_attr( $uid ); ?>">
                            <div class="ggr-onboard-card-header">
                                <a href="<?php echo esc_url( $profile_url ); ?>" target="_blank">
                                    <?php echo esc_html( $user->display_name ); ?>
                                </a>
                            </div>

                            <div class="ggr-onboard-card-body">
                                <div>
                                    <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
                                        <?php echo esc_html( $user->user_email ); ?>
                                    </a>
                                </div>
                                <div class="ggr-onboard-card-meta">
                                    ID: <?php echo esc_html( $uid ); ?><br>
                                    Laatste wijziging:
                                    <span class="ggr-onboard-card-updated">
                                        <?php echo $updated_label ? esc_html( $updated_label ) : '–'; ?>
                                    </span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        (function($){
            $(function(){
                var $board = $('.ggr-onboard-board-wrapper');
                if (!$board.length) {
                    return;
                }

                var ajaxUrl = $board.data('ajax-url');
                var nonce   = $board.data('nonce');

                // jQuery UI Sortable is in WP gebundeld, maar niet altijd geladen
                // → als sortable niet bestaat, breken we netjes af.
                if (typeof $.fn.sortable === 'undefined') {
                    console.warn('jQuery UI Sortable is not loaded. Board drag & drop is disabled.');
                    return;
                }

                $('.ggr-onboard-column-list').sortable({
                    connectWith: '.ggr-onboard-column-list',
                    placeholder: 'ggr-onboard-card ui-sortable-placeholder',
                    forcePlaceholderSize: true,
                    tolerance: 'pointer',
                    start: function(e, ui){
                        ui.item.addClass('is-dragging');
                    },
                    stop: function(e, ui){
                        ui.item.removeClass('is-dragging');
                    },
                    update: function(e, ui){
                        // Alleen bij het nieuwe parent-element een call doen
                        if (!ui.sender) {
                            var $item  = ui.item;
                            var userId = $item.data('user-id');
                            var stage  = $item.closest('.ggr-onboard-column-list').data('stage');

                            if (!userId || !stage) {
                                return;
                            }

                            $.post(ajaxUrl, {
                                action: 'ggr_onboarding_move_card',
                                nonce: nonce,
                                user_id: userId,
                                status: stage
                            }).done(function(resp){
                                if (resp && resp.success && resp.data && resp.data.updated_at) {
                                    $item.find('.ggr-onboard-card-updated').text(resp.data.updated_at);
                                    // Teller in kolom header bijwerken
                                    refreshColumnCounts();
                                } else if (resp && resp.data && resp.data.message) {
                                    alert(resp.data.message);
                                }
                            }).fail(function(){
                                alert('Er ging iets mis bij het opslaan van de nieuwe fase.');
                            });
                        }
                    }
                });

                function refreshColumnCounts() {
                    $('.ggr-onboard-column').each(function(){
                        var $col   = $(this);
                        var count  = $col.find('.ggr-onboard-card').length;
                        var $small = $col.find('.ggr-onboard-column-header small');
                        $small.text('(' + count + ')');
                    });
                }
            });
        })(jQuery);
    </script>
    <?php
}

/**
 * Handler voor POST vanuit onboarding-pagina (table view)
 */
add_action( 'admin_post_ggr_onboarding_update_status', 'ggr_onboarding_handle_update' );

function ggr_onboarding_handle_update() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_die( 'Geen toegang.' );
    }

    check_admin_referer( 'ggr_onboarding_update', 'ggr_onboarding_nonce' );

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    $status  = isset( $_POST['onboarding_status'] )
        ? sanitize_text_field( wp_unslash( $_POST['onboarding_status'] ) )
        : '';

    if ( $user_id && $status ) {
        ggr_onboarding_update_status( $user_id, $status );
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'page' => 'ggr-onboarding',
            ),
            admin_url( 'users.php' )
        )
    );
    exit;
}

/**
 * AJAX handler voor drag & drop in board view
 */
add_action( 'wp_ajax_ggr_onboarding_move_card', 'ggr_onboarding_ajax_move_card' );

function ggr_onboarding_ajax_move_card() {
    if ( ! current_user_can( 'list_users' ) ) {
        wp_send_json_error(
            array( 'message' => 'Geen toegang.' ),
            403
        );
    }

    check_ajax_referer( 'ggr_onboarding_move', 'nonce' );

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    $status  = isset( $_POST['status'] )
        ? sanitize_text_field( wp_unslash( $_POST['status'] ) )
        : '';

    if ( ! $user_id || ! $status ) {
        wp_send_json_error(
            array( 'message' => 'Ongeldige parameters.' ),
            400
        );
    }

    $stages = ggr_onboarding_get_stages();
    if ( ! isset( $stages[ $status ] ) ) {
        wp_send_json_error(
            array( 'message' => 'Onbekende stage.' ),
            400
        );
    }

    ggr_onboarding_update_status( $user_id, $status );
    $updated = get_user_meta( $user_id, 'ggr_onboarding_updated_at', true );
    $updated_label = ggr_onboarding_format_datetime_label( $updated );

    wp_send_json_success(
        array(
            'updated_at' => $updated_label ? $updated_label : '',
        )
    );
}

/**
 * Huidige URL helper voor verificatielink
 */
function ggr_onboarding_get_current_url() {
    global $wp;

    if ( isset( $wp->request ) ) {
        return home_url( add_query_arg( array(), $wp->request ) );
    }

    // Fallback
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
    $uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

    return $scheme . $host . $uri;
}

/**
 * Parse an amount string to float, supporting formatted euro inputs like "€ 50.000".
 */
function ggr_onboarding_parse_amount( $raw_amount ) {
    if ( ! is_string( $raw_amount ) && ! is_numeric( $raw_amount ) ) {
        return 0;
    }

    $normalized = preg_replace( '/[^0-9,\.]/', '', (string) $raw_amount );
    if ( $normalized === null ) {
        return 0;
    }

    // Verwijder duizendtallen en zorg dat de laatste komma/punt als decimaal werkt.
    $normalized = str_replace( '.', '', $normalized );
    $normalized = str_replace( ',', '.', $normalized );

    return (float) $normalized;
}

/**
 * Split a full name into first and last name parts for user meta syncing.
 */
function ggr_onboarding_split_name( $full_name ) {
    $full_name = trim( (string) $full_name );
    if ( $full_name === '' ) {
        return array( '', '' );
    }

    $parts = preg_split( '/\s+/', $full_name );
    if ( ! is_array( $parts ) || empty( $parts ) ) {
        return array( $full_name, '' );
    }

    $first_name = array_shift( $parts );
    $last_name  = implode( ' ', $parts );

    return array( $first_name, $last_name );
}

function ggr_onboarding_get_origin_options() {
    return array(
        'salary'   => array(
            'meta_key'    => 'ggr_origin_salary',
            'label'       => 'Ik ben in loondienst',
            'hint'        => 'Bijvoorbeeld werkgever en bruto jaarinkomen.',
            'placeholder' => 'Vermeld werkgever en hoogte/jaarinkomen.',
        ),
        'business' => array(
            'meta_key'    => 'ggr_origin_business',
            'label'       => 'Ondernemingsactiviteiten',
            'hint'        => 'Omschrijving van de onderneming en inkomsten.',
            'placeholder' => 'Omschrijf de activiteit en ontvangen bedragen.',
        ),
        'rental'   => array(
            'meta_key'    => 'ggr_origin_rental_dividend',
            'label'       => 'Opbrengsten rente/dividend/huur',
            'hint'        => 'Bijvoorbeeld huurinkomsten of dividenden.',
            'placeholder' => 'Bijv. huurinkomsten uit vastgoed, dividend, rente.',
        ),
        'savings'  => array(
            'meta_key'    => 'ggr_origin_savings',
            'label'       => 'Vermogen, erfenis of pensioen/ontslagvergoeding',
            'hint'        => 'Bijvoorbeeld vermogen of ontvangen uitkering.',
            'placeholder' => 'Specificeer vermogen of ontvangen erfenis/uitkering.',
        ),
        'sale'     => array(
            'meta_key'    => 'ggr_origin_sale',
            'label'       => 'Opbrengst verkoop (bijv. vastgoed/aandelen)',
            'hint'        => 'Noem het object en de verkoopopbrengst.',
            'placeholder' => 'Noem het object en het verkoopbedrag.',
        ),
        'loan'     => array(
            'meta_key'    => 'ggr_origin_loan',
            'label'       => 'Ontvangen lening',
            'hint'        => 'Geef de verstrekker en voorwaarden aan.',
            'placeholder' => 'Geef de verstrekkende partij en voorwaarden aan.',
        ),
        'other'    => array(
            'meta_key'    => 'ggr_origin_other',
            'label'       => 'Andere herkomst',
            'hint'        => 'Overige toelichting op de herkomst.',
            'placeholder' => 'Vul in indien de herkomst anders is dan hierboven beschreven.',
        ),
    );
}

function ggr_onboarding_build_review_sections( $user_id, $user, $participation_profile, $amount_display ) {
    $has_co = get_user_meta( $user_id, 'ggr_has_co_participant', true );
    $has_co = $has_co ? $has_co : 'nee';
    $extra_step_required  = (bool) get_user_meta( $user_id, 'ggr_collecting_extra_required', true );
    $extra_step_label     = get_user_meta( $user_id, 'ggr_collecting_extra_step_label', true );    
    $extra_question_label = get_user_meta( $user_id, 'ggr_collecting_extra_label', true );
    $extra_upload_label   = get_user_meta( $user_id, 'ggr_collecting_extra_upload_label', true );
    $extra_response       = get_user_meta( $user_id, 'ggr_collecting_extra_response', true );
    $extra_upload_label   = $extra_upload_label ? $extra_upload_label : 'Aanvullende upload';
    $extra_step_default_label = 'Aanvullende informatie';
    $extra_step_label     = $extra_step_label ? $extra_step_label : $extra_step_default_label;
    $extra_question_label = $extra_question_label ? $extra_question_label : $extra_step_label;
    $extra_section_title  = $extra_step_label;
    
    $user_name = function_exists( 'ggr_portal_get_nice_user_name' )
        ? ggr_portal_get_nice_user_name( $user )
        : trim( $user->first_name . ' ' . $user->last_name );

    if ( ! $user_name ) {
        $user_name = $user->display_name;
    }

    $boolean_label = function( $value, $empty_label = 'Niet opgegeven' ) {
        if ( $value === '' || $value === null ) {
            return $empty_label;
        }

        return ( 'ja' === $value ) ? 'Ja' : 'Nee';
    };

    $profile_label = 'Niet opgegeven';
    if ( 'zakelijk' === $participation_profile ) {
        $profile_label = 'Zakelijk';
    } elseif ( 'prive' === $participation_profile ) {
        $profile_label = 'Privé';
    }

    $sections = array(
        array(
            'title' => 'Inschrijving',
            'items' => array(
                'Naam'                 => $user_name,
                'Investeringsbedrag'   => $amount_display ? $amount_display : '—',
                'Participatieprofiel'  => $profile_label,
                'Mede-participant'     => ( 'ja' === $has_co ) ? 'Ja' : ( 'nee' === $has_co ? 'Nee' : 'Niet opgegeven' ),
            ),
        ),
    );

    $kyc_birth_date   = get_user_meta( $user_id, 'ggr_kyc_birth_date', true );
    $kyc_birth_place  = get_user_meta( $user_id, 'ggr_kyc_birth_place', true );
    $kyc_birth_country= get_user_meta( $user_id, 'ggr_kyc_birth_country', true );
    $kyc_nationality  = get_user_meta( $user_id, 'ggr_kyc_nationality', true );
    $kyc_pep          = get_user_meta( $user_id, 'ggr_kyc_pep', true );
    $kyc_us_person    = get_user_meta( $user_id, 'ggr_kyc_us_person', true );
    $co_investment_note = get_user_meta( $user_id, 'ggr_co_investment_note', true );
    
    $sections[] = array(
        'title' => 'Persoonlijke gegevens',
        'items' => array(
            'Voornaam'     => get_user_meta( $user_id, 'ggr_kyc_first_name', true ) ?: '—',
            'Achternaam'   => get_user_meta( $user_id, 'ggr_kyc_last_name', true ) ?: '—',
            'Geboortedatum'=> ggr_onboarding_format_datetime_label( $kyc_birth_date, false ) ?: '—',
            'Geboorteplaats' => $kyc_birth_place ? $kyc_birth_place : '—',
            'Geboorteland' => $kyc_birth_country ? $kyc_birth_country : '—',
            'Nationaliteit'=> $kyc_nationality ? $kyc_nationality : '—',
            'PEP'          => $boolean_label( $kyc_pep ),
            'US person'    => $boolean_label( $kyc_us_person ),
        ),
    );

    $sections[] = array(
        'title' => 'Adres & contact',
        'items' => array(
            'Adres'        => get_user_meta( $user_id, 'ggr_kyc_address', true ) ?: '—',
            'Postcode'     => get_user_meta( $user_id, 'ggr_kyc_postcode', true ) ?: '—',
            'Plaats'       => get_user_meta( $user_id, 'ggr_kyc_city_country', true ) ?: '—',
            'Land'         => get_user_meta( $user_id, 'ggr_kyc_country', true ) ?: '—',
            'Telefoon'     => get_user_meta( $user_id, 'ggr_kyc_phone', true ) ?: '—',
            'E-mail'       => $user->user_email ? $user->user_email : '—',
            'IBAN'         => get_user_meta( $user_id, 'ggr_kyc_iban', true ) ?: '—',
            'Tenaamstelling IBAN' => get_user_meta( $user_id, 'ggr_kyc_iban_name', true ) ?: '—',
        ),
    );

    if ( 'zakelijk' === $participation_profile ) {
        $sections[] = array(
            'title' => 'Zakelijke gegevens',
            'items' => array(
                'Bedrijfsnaam' => get_user_meta( $user_id, 'ggr_kyc_company', true ) ?: '—',
                'KvK-nummer'   => get_user_meta( $user_id, 'ggr_kyc_kvk', true ) ?: '—',
            ),
        );
    }

    if ( 'ja' === $has_co ) {
        $sections[] = array(
            'title' => 'Mede-participant',
            'items' => array(
                'Voornaam'      => get_user_meta( $user_id, 'ggr_co_first_name', true ) ?: '—',
                'Achternaam'    => get_user_meta( $user_id, 'ggr_co_last_name', true ) ?: '—',
                'Geboortedatum' => ggr_onboarding_format_datetime_label( get_user_meta( $user_id, 'ggr_co_birth_date', true ), false ) ?: '—',
                'Geboorteplaats'=> get_user_meta( $user_id, 'ggr_co_birth_place', true ) ?: '—',
                'Geboorteland'  => get_user_meta( $user_id, 'ggr_co_birth_country', true ) ?: '—',
                'Telefoon'      => get_user_meta( $user_id, 'ggr_co_phone', true ) ?: '—',
                'BSN'           => get_user_meta( $user_id, 'ggr_co_bsn', true ) ?: '—',
                'PEP'           => $boolean_label( get_user_meta( $user_id, 'ggr_co_pep', true ) ),
                'US person'     => $boolean_label( get_user_meta( $user_id, 'ggr_co_us_person', true ) ),
                'Toelichting (bijv. investeren voor kind)' => $co_investment_note ? wp_strip_all_tags( $co_investment_note ) : '—',                
            ),
        );
    }

    $origin_options = ggr_onboarding_get_origin_options();
    $origin_sources = get_user_meta( $user_id, 'ggr_origin_sources', true );
    if ( ! is_array( $origin_sources ) ) {
        $origin_sources = array();
    }
    $origin_labels = array();
    foreach ( $origin_sources as $source_key ) {
        if ( isset( $origin_options[ $source_key ]['label'] ) ) {
            $origin_labels[] = $origin_options[ $source_key ]['label'];
        }
    }

    $origin_notes = get_user_meta( $user_id, 'ggr_origin_notes', true );
    $sections[] = array(
        'title' => 'Herkomst vermogen',
        'items' => array(
            'Land van herkomst' => get_user_meta( $user_id, 'ggr_origin_country', true ) ?: '—',
            'Bronnen'           => $origin_labels ? implode( ', ', $origin_labels ) : 'Niet opgegeven',
            'Toelichting'       => $origin_notes ? wp_strip_all_tags( $origin_notes ) : '—',
        ),
    );

    if ( $extra_step_required ) {
        $sections[] = array(
            'title' => $extra_section_title,
            'items' => array(
                'Toelichting' => $extra_response ? wp_strip_all_tags( $extra_response ) : '—',
                $extra_upload_label => get_user_meta( $user_id, 'ggr_doc_extra', true ) ? 'Geüpload' : 'Nog niet aangeleverd',
            ),
        );
    }

    $document_labels = array(
        'ggr_doc_id'             => 'Identiteitsbewijs',
        'ggr_doc_funds'          => 'Bewijs herkomst middelen',
        'ggr_doc_registration'   => 'KVK-uittreksel',
        'ggr_doc_ubo'            => 'UBO-register / aandeelhouderslijst',
        'ggr_doc_share_register' => 'Aandeelhoudersregister / overeenkomst',
        'ggr_doc_other'          => 'Overige documenten',
    );
    if ( $extra_step_required ) {
        $document_labels['ggr_doc_extra'] = $extra_upload_label;
    }
    
    $doc_items = array();
    foreach ( $document_labels as $meta_key => $label ) {
        $doc_items[ $label ] = get_user_meta( $user_id, $meta_key, true ) ? 'Geüpload' : 'Nog niet aangeleverd';
    }

    $sections[] = array(
        'title' => 'Documenten',
        'items' => $doc_items,
    );

    return $sections;
}

/**
 * Alle landen (Nederlands) voor nationaliteit dropdown
 * (nu: voornamelijk Europese landen – breid uit als je echt de hele wereld wilt)
 */
function ggr_get_countries_nl() {
    return array(
        'Andorra',
        'België',
        'Bulgarije',
        'Cyprus',
        'Denemarken',
        'Duitsland',
        'Estland',
        'Finland',
        'Frankrijk',
        'Georgië',
        'Griekenland',
        'Hongarije',
        'Ierland',
        'IJsland',
        'Italië',
        'Kroatië',
        'Letland',
        'Liechtenstein',
        'Litouwen',
        'Luxemburg',
        'Malta',
        'Moldavië',
        'Monaco',
        'Montenegro',
        'Nederland',
        'Noord-Macedonië',
        'Noorwegen',
        'Oekraïne',
        'Oostenrijk',
        'Polen',
        'Portugal',
        'Roemenië',
        'San Marino',
        'Servië',
        'Slovenië',
        'Slowakije',
        'Spanje',
        'Tsjechië',
        'Verenigd Koninkrijk',
        'Zweden',
        'Zwitserland',
    );
}

/**
 * Bouw een beveiligde downloadlink voor een onboarding-PDF.
 */
function ggr_onboarding_get_pdf_download_url( $type = 'application', $user_id = 0 ) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    $args    = array(
        'ggr_action' => 'download_onboarding_pdf',
        'type'       => $type,
        'user_id'    => $user_id,
    );

    $url = add_query_arg( $args, home_url( '/' ) );

    return wp_nonce_url( $url, 'ggr_download_onboarding_pdf_' . $type . '_' . $user_id, 'ggr_nonce' );
}

add_action( 'init', 'ggr_onboarding_register_pdf_download' );
function ggr_onboarding_register_pdf_download() {
    if ( isset( $_GET['ggr_action'] ) && 'download_onboarding_pdf' === $_GET['ggr_action'] ) {
        ggr_onboarding_handle_pdf_download();
        exit;
    }
}

function ggr_onboarding_handle_pdf_download() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Niet ingelogd.' );
    }

    $type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'application';
    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : get_current_user_id();
    $nonce   = isset( $_GET['ggr_nonce'] ) ? wp_unslash( $_GET['ggr_nonce'] ) : '';

    if ( ! wp_verify_nonce( $nonce, 'ggr_download_onboarding_pdf_' . $type . '_' . $user_id ) ) {
        wp_die( 'Ongeldige aanvraag.' );
    }

    $current_user_id = get_current_user_id();
    if ( $user_id !== $current_user_id && ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_user', $user_id ) ) {
        wp_die( 'Geen toegang tot dit document.' );
    }

    $html = ggr_onboarding_render_pdf_html( $user_id, $type );
    if ( ! $html ) {
        wp_die( 'Kon het document niet opbouwen.' );
    }

    if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
        $dompdf_autoload = trailingslashit( GGR_PORTAL_CORE_PATH ) . 'dompdf/autoload.inc.php';
        if ( file_exists( $dompdf_autoload ) ) {
            require_once $dompdf_autoload;
        }
    }

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

        $filename_map = array(
            'memorandum' => 'informatie-memorandum',
            'eid'        => 'essentiele-informatie',
            'disclaimer' => 'disclaimer',
            'application'=> 'inschrijfformulier',
        );

        $filename_base = isset( $filename_map[ $type ] ) ? $filename_map[ $type ] : 'onboarding-document';
        $filename      = $filename_base . '-' . $user_id . '.pdf';

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        header( 'Cache-Control: private, max-age=0, must-revalidate' );
        header( 'Pragma: public' );

        echo $pdf_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    header( 'Content-Type: text/html; charset=UTF-8' );
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

/**
 * Genereer de HTML voor de verschillende onboarding-PDF's.
 */
function ggr_onboarding_render_pdf_html( $user_id, $type = 'application' ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return '';
    }

    $participation_profile = get_user_meta( $user_id, 'ggr_participation_profile', true );
    $amount_meta           = get_user_meta( $user_id, 'ggr_participation_amount', true );
    $amount_display        = $amount_meta !== '' ? '€ ' . number_format( (float) $amount_meta, 0, ',', '.' ) : 'Nog niet opgegeven';
    $origin_country        = get_user_meta( $user_id, 'ggr_origin_country', true );
    $origin_sources        = get_user_meta( $user_id, 'ggr_origin_sources', true );
    $origin_sources        = is_array( $origin_sources ) ? $origin_sources : array();
    $origin_notes          = get_user_meta( $user_id, 'ggr_origin_notes', true );

    $origin_labels = array();
    foreach ( ggr_onboarding_get_origin_options() as $key => $option ) {
        $origin_labels[ $key ] = $option['label'];
    }

    $origin_sources_labels = array();
    foreach ( $origin_sources as $source_key ) {
        if ( isset( $origin_labels[ $source_key ] ) ) {
            $origin_sources_labels[] = $origin_labels[ $source_key ];
        }
    }

    $participant_name = trim( get_user_meta( $user_id, 'ggr_kyc_first_name', true ) . ' ' . get_user_meta( $user_id, 'ggr_kyc_last_name', true ) );
    if ( ! $participant_name ) {
        $participant_name = trim( $user->first_name . ' ' . $user->last_name );
    }

    $co_name = trim( get_user_meta( $user_id, 'co_first_name', true ) . ' ' . get_user_meta( $user_id, 'co_last_name', true ) );
    $profile_label = ( 'zakelijk' === $participation_profile ) ? 'Zakelijk' : ( 'prive' === $participation_profile ? 'Privé' : 'Onbekend' );
    $address_line  = trim(
        get_user_meta( $user_id, 'ggr_kyc_address', true ) . ', ' .
        get_user_meta( $user_id, 'ggr_kyc_postcode', true ) . ' ' .
        get_user_meta( $user_id, 'ggr_kyc_city_country', true )
    );

    $signature_image     = get_user_meta( $user_id, 'ggr_contract_signature', true );
    $signature_text      = get_user_meta( $user_id, 'ggr_contract_signature_text', true );
    $signature_timestamp = get_user_meta( $user_id, 'ggr_contract_signed_at', true );
    $signature_label     = $signature_timestamp ? ggr_onboarding_format_datetime_label( $signature_timestamp ) : '';
    $co_signature_image  = get_user_meta( $user_id, 'ggr_co_contract_signature', true );
    $co_signature_text   = get_user_meta( $user_id, 'ggr_co_contract_signature_text', true );
    $co_investment_note  = get_user_meta( $user_id, 'ggr_co_investment_note', true );
    
    $sections = ggr_onboarding_build_review_sections( $user_id, $user, $participation_profile, $amount_display );

    ob_start();
    ?>
    <html>
    <head>
        <meta charset="utf-8" />
        <style>
            @page {
                size: A4;
                margin: 0;
            }

            html, body {
                margin: 0;
                padding: 0;
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.5;
                color: #0f172a;
            }

            .top-bar,
            .bottom-bar {
                position: fixed;
                left: 0;
                right: 0;
                height: 10mm;
                background: #9fbac7;
            }

            .top-bar { top: 0; }
            .bottom-bar { bottom: 0; }

            .page-content {
                padding: 20mm 16mm 18mm;
                box-sizing: border-box;
            }

            .watermark {
                position: fixed;
                top: 50%;
                left: 50%;
                width: 58mm;
                height: 58mm;
                margin-left: -29mm;
                margin-top: -29mm;
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
                vertical-align: middle;
            }

            .header-logo img { height: 50px; }

            .header-contact {
                text-align: right;
                font-size: 11px;
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

            h1 {
                font-size: 18px;
                margin: 0 0 2mm;
                font-weight: 700;
                color: #0b2149;
            }

            h2 {
                font-size: 14px;
                margin: 0 0 2mm;
                color: #0b2149;
            }

            .lead { margin: 0 0 3mm; }

            .pill {
                display: inline-block;
                background: #0b2149;
                color: #fff;
                padding: 3px 10px;
                border-radius: 999px;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
                margin-bottom: 2mm;
            }

            .section {
                margin-bottom: 6mm;
                padding: 4mm;
                border: 0.2mm solid #e5e7eb;
                border-radius: 6px;
                background: #f9fafb;
            }

            .section-title {
                font-weight: 700;
                margin: 0 0 2mm;
                font-size: 13px;
            }

            .info-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
                margin-top: 2mm;
            }

            .info-table th,
            .info-table td {
                border: 0.2mm solid #e5e7eb;
                padding: 2mm 2.5mm;
                vertical-align: top;
            }

            .info-table th {
                background: #eef2f7;
                font-weight: 700;
                text-align: left;
                color: #0b2149;
            }

            .muted { color: #475569; }

            .origin-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 2mm;
                margin-top: 2mm;
            }

            .origin-item {
                border: 0.2mm solid #e5e7eb;
                border-radius: 5px;
                padding: 2mm;
                background: #fff;
                min-height: 24mm;
            }

            .origin-label { font-weight: 600; }
            .checkmark { color: #16a34a; font-weight: 700; margin-right: 2mm; }

            .signature-blocks {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 4mm;
                margin-top: 3mm;
            }

            .signature-box {
                border: 0.2mm dashed #cbd5e1;
                border-radius: 6px;
                min-height: 34mm;
                padding: 2.5mm;
                background: #fff;
            }

            .signature-box img {
                max-height: 26mm;
                width: auto;
                display: block;
                margin-bottom: 1mm;
            }

            .signature-line { height: 0.4mm; background: #cbd5e1; margin: 2mm 0 1mm; }
            .signature-name { font-weight: 600; color: #0b2149; }

            .footer-note {
                margin-top: 3mm;
                font-size: 9px;
                color: #6b7280;
            }
        </style>
    </head>
 <body>
<?php if ( 'application' === $type ) : ?>
	<?php
	// =========================
	// Helpers / derived values
	// =========================
	$profile_raw   = (string) get_user_meta( $user_id, 'ggr_participation_profile', true ); // verwacht: 'prive' of 'zakelijk' (pas aan indien andere waarden)
	$is_private    = ( 'prive' === strtolower( $profile_raw ) ) || ( 'privé' === strtolower( $profile_raw ) ) || ( 'private' === strtolower( $profile_raw ) );
	$is_business   = ( 'zakelijk' === strtolower( $profile_raw ) ) || ( 'business' === strtolower( $profile_raw ) );

	$place_raw     = (string) get_user_meta( $user_id, 'ggr_kyc_city', true );
	if ( ! $place_raw ) {
		$place_raw = (string) get_user_meta( $user_id, 'ggr_kyc_place', true ); // optioneel meta veld
	}

	$origin_notes  = get_user_meta( $user_id, 'ggr_origin_notes', true ); // algemene toelichting (pas meta key aan indien nodig)
	?>

	<div class="top-bar"></div>
	<div class="bottom-bar"></div>

	<div class="watermark">
		<img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png" alt="GGR Icon" />
	</div>

	<div class="page-content">
		<div class="header-row">
			<div class="header-logo">
				<img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png" alt="GGR Income Fund" />
			</div>
			<div class="header-contact">
				+31 85 080 50 35<br />
				info@ggrincome.com<br />
				Alexanderstraat 90, 6812BH Arnhem
			</div>
		</div>

		<div class="header-divider"></div>

		<span class="pill">Inschrijfformulier</span>

		<div class="section">
			<div class="section-title">1. Verzoek om uitgifte Participaties</div>
			<p class="lead">
				Ondergetekende (de “Participant”) wil Participaties aankopen in het GGR Monthly Income Fund (het “Fonds”)
				voor een bedrag van <strong><?php echo esc_html( $amount_display ); ?></strong>.
			</p>
		</div>

		<div class="section">
			<div class="section-title">2. Wordt er vanaf zakelijk of privé geparticipeerd</div>

			<!-- Weergave zoals op formulier met keuzevakjes -->
			<p style="margin: 0 0 2mm 0;">
				<span class="checkmark"><?php echo $is_private ? '&#10003;' : '&#9634;'; ?></span> Privé
				&nbsp;&nbsp;&nbsp;
				<span class="checkmark"><?php echo $is_business ? '&#10003;' : '&#9634;'; ?></span> Zakelijk
				<span class="muted">(Vul persoonlijke gegevens in als contactpersoon van het bedrijf)</span>
			</p>

			<!-- behoud eventueel label -->
			<?php if ( ! empty( $profile_label ) ) : ?>
				<p class="muted" style="margin: 0;">Geselecteerd profiel: <strong><?php echo esc_html( $profile_label ); ?></strong></p>
			<?php endif; ?>
		</div>

		<div class="section">
			<div class="section-title">3. Persoonlijke gegevens</div>
			<table class="info-table">
				<thead>
					<tr>
						<th>Gegevens</th>
						<th>Participant</th>
						<th>Mede-participant</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>Volledige naam</td>
						<td><?php echo esc_html( $participant_name ? $participant_name : '—' ); ?></td>
						<td><?php echo $co_name ? esc_html( $co_name ) : 'Niet van toepassing'; ?></td>
					</tr>
					<tr>
						<td>Geboortedatum</td>
						<td><?php echo esc_html( ggr_onboarding_format_datetime_label( get_user_meta( $user_id, 'ggr_kyc_birth_date', true ), false ) ?: '—' ); ?></td>
						<td><?php echo esc_html( ggr_onboarding_format_datetime_label( get_user_meta( $user_id, 'ggr_co_birth_date', true ), false ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Adres</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_address', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_address', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Postcode</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_postcode', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_postcode', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Woonplaats + Land</td>
						<td><?php echo esc_html( $address_line ? $address_line : '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_city_country', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Burgerservicenummer</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_bsn', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_bsn', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Telefoonnummer</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_phone', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_phone', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>E-mail</td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_email', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Nationaliteit</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_nationality', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_nationality', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Tenaamstelling IBAN</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_iban_name', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_iban_name', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>IBAN</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_iban', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_iban', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Bedrijfsnaam (optioneel)</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_company', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_company', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>KVK nummer (optioneel)</td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_kyc_kvk', true ) ?: '—' ); ?></td>
						<td><?php echo esc_html( get_user_meta( $user_id, 'ggr_co_kvk', true ) ?: '—' ); ?></td>
					</tr>
					<tr>
						<td>Politiek Prominent Persoon</td>
						<td><?php echo esc_html( ( get_user_meta( $user_id, 'ggr_kyc_pep', true ) === 'ja' ) ? 'Ja' : 'Nee' ); ?></td>
						<td><?php echo esc_html( ( get_user_meta( $user_id, 'ggr_co_pep', true ) === 'ja' ) ? 'Ja' : 'Nee' ); ?></td>
					</tr>
					<tr>
						<td>US person</td>
						<td><?php echo esc_html( ( get_user_meta( $user_id, 'ggr_kyc_us_person', true ) === 'ja' ) ? 'Ja' : 'Nee' ); ?></td>
						<td><?php echo esc_html( ( get_user_meta( $user_id, 'ggr_co_us_person', true ) === 'ja' ) ? 'Ja' : 'Nee' ); ?></td>
					</tr>

					<?php if ( $co_name || $co_investment_note ) : ?>
						<tr>
							<td>Toelichting mede-participant</td>
							<td>—</td>
							<td><?php echo $co_investment_note ? esc_html( $co_investment_note ) : '—'; ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php
		$origin_options = ggr_onboarding_get_origin_options();
		$origin_sources = get_user_meta( $user_id, 'ggr_origin_sources', true );
		$origin_sources = is_array( $origin_sources ) ? $origin_sources : array();

		// Filter: alleen aangevinkte herkomstbronnen tonen
		$selected_origin_options = array();
		foreach ( $origin_options as $key => $option ) {
			if ( in_array( $key, $origin_sources, true ) ) {
				$selected_origin_options[ $key ] = $option;
			}
		}
		?>

		<div class="section">
			<div class="section-title">4. Herkomst van het in het Fonds te beleggen geld</div>

			<p class="muted">
				Wij vragen hiernaar om te voldoen aan onze verplichtingen uit hoofde van de Wet ter voorkoming van witwassen en financiering van terrorisme (de “Wwft”).
				Uiteraard zullen wij de door u verschafte informatie vertrouwelijk behandelen en alleen gebruiken voor de doeleinden zoals vermeld in onze privacyverklaring.
			</p>

			<?php if ( ! empty( $selected_origin_options ) ) : ?>
				<!-- Alleen aangevinkte opties -->
				<div class="origin-grid">
					<?php foreach ( $selected_origin_options as $key => $option ) :
						$notes = get_user_meta( $user_id, $option['meta_key'], true );
						?>
						<div class="origin-item">
							<div class="origin-label">
								<span class="checkmark">&#10003;</span>
								<?php echo esc_html( $option['label'] ); ?>
							</div>

							<?php if ( $notes ) : ?>
								<div class="muted">Toelichting: <?php echo esc_html( $notes ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p>—</p>
			<?php endif; ?>

			<!-- Toelichtingblok zoals op formulier -->
			<p style="margin-top: 2mm;"><strong>Toelichting</strong> (in te vullen door Participant en, indien van toepassing, mede-Participant)</p>
			<p><?php echo $origin_notes ? nl2br( esc_html( $origin_notes ) ) : '—'; ?></p>

			<p class="muted">Het is mogelijk dat wij, ter voldoening van onze wettelijke verificatieplicht, ondersteunende documenten bij u opvragen.</p>
		</div>

		<div class="section">
			<div class="section-title">7. Overige verklaringen en ondertekeningen</div>
			<ul style="margin: 0 0 3mm 4mm; padding-left: 2mm;">
				<li>Ik ben bekend met de inhoud van het Investment Memorandum van het Fonds.</li>
				<li>Door toetreding tot het Fonds aanvaard ik de rechten en verplichtingen als participant zoals beschreven in het Informatie Memorandum &amp; voorwaarden en ben ik gebonden aan de inhoud daarvan.</li>
				<li>Ik voldoe aan het volgende beleggersprofiel: bereid en in staat om het risico van (aanzienlijke) waardevermindering te nemen; geen inkomsten uit deze belegging nodig; uittreding slechts eenmaal per maand mogelijk; lange beleggingshorizon (minimaal 5 jaar).</li>
				<li>Ik ben ermee akkoord dat de door mij verstrekte gegevens gebruikt worden door de Beheerder in verband met de administratie en bewaking van mijn rechten en plichten en ter voldoening aan wettelijke verplichtingen.</li>
				<li>Als er een wijziging optreedt in de door mij in dit formulier verstrekte gegevens, zal ik de beheerder daarvan onmiddellijk op de hoogte stellen.</li>
			</ul>

			<div class="info-table" style="margin-top: 0;">
				<table class="info-table" aria-label="Plaats en datum">
					<tr>
						<td style="width: 50%;">Plaats: <?php echo $place_raw ? esc_html( $place_raw ) : '___________________________'; ?></td>
						<td>Datum: <?php echo $signature_label ? esc_html( $signature_label ) : '___________________________'; ?></td>
					</tr>
				</table>
			</div>

			<div class="signature-blocks">
				<div class="signature-box">
					<strong>Handtekening Participant</strong>
					<?php if ( $signature_image ) : ?>
						<img src="<?php echo esc_url( $signature_image ); ?>" alt="Handtekening participant" />
					<?php endif; ?>
					<div class="signature-line"></div>
					<div class="signature-name"><?php echo esc_html( $signature_text ? $signature_text : $participant_name ); ?></div>
				</div>

				<div class="signature-box">
					<strong>Handtekening Mede-participant</strong>
					<?php if ( $co_signature_image ) : ?>
						<img src="<?php echo esc_url( $co_signature_image ); ?>" alt="Handtekening mede-participant" />
					<?php endif; ?>
					<div class="signature-line"></div>
					<div class="signature-name"><?php echo esc_html( $co_signature_text ? $co_signature_text : ( $co_name ? $co_name : '—' ) ); ?></div>
				</div>
			</div>
		</div>

		<div class="footer-note">Dit inschrijfformulier is automatisch gegenereerd op basis van de onboarding-gegevens en wordt gebruikt in de ondertekenfase.</div>
	</div>
    <?php elseif ( 'memorandum' === $type ) : ?>
        <span class="pill">Informatie memorandum</span>
        <h1>Informatie memorandum</h1>
        <p>Dit is een placeholder voor het informatie memorandum. Vervang deze tekst later door de definitieve inhoud van het fondsdocument. Gebruik dit PDF-bestand om de actuele versie met deelnemers te delen.</p>
        <p class="footnote">Documentnummer: <?php echo esc_html( $user_id ); ?>-<?php echo esc_html( date_i18n( 'Ymd' ) ); ?></p>
    <?php elseif ( 'eid' === $type ) : ?>
        <span class="pill">Essentiële informatiedocument (EID)</span>
        <h1>Essentiële informatiedocument</h1>
        <p>Dit PDF-bestand bevat een tijdelijke tekst voor het EID. Voeg hier later de officiële inhoud toe zodat deelnemers het document kunnen openen en downloaden.</p>
        <p class="footnote">Documentnummer: <?php echo esc_html( $user_id ); ?>-<?php echo esc_html( date_i18n( 'Ymd' ) ); ?></p>
    <?php else : ?>
        <span class="pill">Disclaimer</span>
        <h1>Disclaimer</h1>
        <p>Gebruik dit document om de disclaimer te tonen. De huidige tekst is een voorbeeldparagraaf die later vervangen kan worden door de definitieve versie.</p>
        <p class="footnote">Documentnummer: <?php echo esc_html( $user_id ); ?>-<?php echo esc_html( date_i18n( 'Ymd' ) ); ?></p>
    <?php endif; ?>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Statische onboarding-flow
 *
 * Shortcode: [ggr_onboarding_flow]
 */
add_shortcode( 'ggr_onboarding_flow', 'ggr_onboarding_flow_shortcode' );

function ggr_onboarding_flow_shortcode() {
    ob_start();
    ?>
    <div class="ggr-onboarding-shell">
        <div class="ggr-onboarding-card">
            <style>
                .ggr-onboarding-step-tab.is-disabled {
                    pointer-events: none;
                    opacity: 0.6;
                    cursor: not-allowed;
                }
            </style>            
            <div class="ggr-onboarding-header">
                <div class="ggr-onboarding-logo">
                    <img
                        src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png"
                        alt="GGR Income Fund"
                    />
                </div>
                <div class="ggr-onboarding-title-block">
                    <h1>Onboarding als investeerder</h1>
                    <p>Volg de stappen om je aanvraag netjes en compleet bij ons aan te leveren.</p>
                    <div class="ggr-onboarding-status-row">
                        <span class="ggr-onboarding-status-badge">
                            <i class="ri-check-line" aria-hidden="true"></i>
                            <span>Documentatie aanleveren</span>
                        </span>
                        <span class="ggr-onboarding-status-meta">Gemiddelde doorlooptijd: 10-15 minuten</span>
                    </div>
                </div>
            </div>

            <div class="ggr-onboarding-body ggr-onboarding-body--full">
                <aside class="ggr-onboarding-steps">
                    <h2>Stappen</h2>
                    <p>Je kunt per stap de gevraagde gegevens invullen en documenten voorbereiden.</p>
                    <ul class="ggr-onboarding-step-list">
                        <li class="ggr-onboarding-step">
                            <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--current">1</div>
                            <div class="ggr-onboarding-step-content">
                                <h3>Verzoek om uitgifte Participaties</h3>
                                <p>Start met het gewenste investeringsbedrag.</p>
                                <span class="ggr-onboarding-chip">Start</span>
                            </div>
                        </li>
                        <li class="ggr-onboarding-step">
                            <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--upcoming">2</div>
                            <div class="ggr-onboarding-step-content">
                                <h3>Privé of zakelijk</h3>
                                <p>Geef aan of je als persoon of via een entiteit participeert.</p>
                                <span class="ggr-onboarding-chip">Kies type</span>
                            </div>
                        </li>
                        <li class="ggr-onboarding-step">
                            <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--upcoming">3</div>
                            <div class="ggr-onboarding-step-content">
                                <h3>Persoonlijke gegevens</h3>
                                <p>Vul de gegevens van (mede-)participants in.</p>
                                <span class="ggr-onboarding-chip">Identiteit</span>
                            </div>
                        </li>
                        <li class="ggr-onboarding-step">
                            <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--upcoming">4</div>
                            <div class="ggr-onboarding-step-content">
                                <h3>Herkomst van geld</h3>
                                <p>Beschrijf de herkomst van het te beleggen bedrag.</p>
                                <span class="ggr-onboarding-chip">KYC</span>                                
                            </div>
                        </li>
                        <li class="ggr-onboarding-step">
                            <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--upcoming">5</div>
                            <div class="ggr-onboarding-step-content">
                                <h3>Mee te sturen documenten</h3>
                                <p>Lever de benodigde bijlagen aan op basis van je participatietype.</p>
                                <span class="ggr-onboarding-chip">Bijlagen</span>
                            </div>
                        </li>
                    </ul>
                    <div class="ggr-onboarding-meta">
                        <div class="ggr-onboarding-meta-row">
                            <strong>Opslaan</strong>
                            <span>Concepten worden lokaal bewaard.</span>
                        </div>
                        <div class="ggr-onboarding-meta-row">
                            <strong>Hulp nodig?</strong>
                            <span>Mail naar onboarding@ggr.nl</span>
                        </div>
                    </div>
                </aside>

                <div class="ggr-onboarding-content">
                    <div class="ggr-onboarding-highlight">
                        <div>
                            <p class="ggr-onboarding-kicker">Doorloop de stappen in Collectie</p>
                            <h2>Snel starten</h2>
                            <p>Download het inschrijfformulier en gebruik deze pagina als checklist voor alle vereiste gegevens.</p>
                        </div>
                        <a class="ggr-onboarding-primary-btn" href="#" download>
                            <i class="ri-file-download-line" aria-hidden="true"></i>
                            Inschrijfformulier downloaden
                        </a>
                    </div>

                    <form class="ggr-onboarding-form" action="#" method="post">
                        <section id="stap-1" class="ggr-onboarding-section">
                            <h2>1. Verzoek om uitgifte Participaties</h2>
                            <p>Ondergetekende (de "Participant") wil Participaties aankopen in het GGR Income Fund (het "Fonds") voor een bedrag van:</p>
                            <div class="ggr-onboarding-grid">
                                <div class="ggr-onboarding-field">
                                    <label for="ggr-investment-amount">Gewenst bedrag (€)</label>
                                    <input type="text" id="ggr-investment-amount" name="ggr_investment_amount" placeholder="Bijv. 10.000" />
                                    <p class="ggr-onboarding-note">Een eerste inschrijving is mogelijk vanaf € 5.000.</p>
                                </div>
                                <div class="ggr-onboarding-field">
                                    <label for="ggr-investment-start">Gewenste startdatum</label>
                                    <input type="date" id="ggr-investment-start" name="ggr_investment_start" />
                                    <p class="ggr-onboarding-note">We plannen de uitgifte zo dicht mogelijk op deze datum.</p>
                                </div>
                                <div class="ggr-onboarding-field">
                                    <label for="ggr-investment-frequency">Frequentie</label>
                                    <select id="ggr-investment-frequency" name="ggr_investment_frequency">
                                        <option value="eenmalig">Eenmalig</option>
                                        <option value="periodiek">Periodiek</option>
                                    </select>
                                    <p class="ggr-onboarding-note">Bij periodiek beleggen stemmen we de incassodata met je af.</p>
                                </div>
                            </div>
                        </section>

                        <section id="stap-2" class="ggr-onboarding-section">
                            <h2>2. Wordt er vanaf zakelijk of privé geparticipeerd?</h2>
                            <p>Selecteer het type participant. Vul bij een zakelijke participatie ook de contactpersoon in.</p>
                            <div class="ggr-onboarding-radio-group">
                                <label class="ggr-onboarding-radio">
                                    <input type="radio" name="ggr_participation_type" value="prive" />
                                    <span>Privé</span>
                                </label>
                                <label class="ggr-onboarding-radio">
                                    <input type="radio" name="ggr_participation_type" value="zakelijk" />
                                    <span>Zakelijk <small>(Vul persoonlijke gegevens in als contactpersoon van het bedrijf)</small></span>
                                </label>
                            </div>
                            <div class="ggr-onboarding-table-wrapper">
                                <table class="ggr-onboarding-table">
                                    <thead>
                                        <tr>
                                            <th>Zakelijke gegevens (indien van toepassing)</th>
                                            <th>In te vullen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Bedrijfsnaam</td>
                                            <td><input type="text" name="ggr_company_name" /></td>
                                        </tr>
                                        <tr>
                                            <td>KvK nummer</td>
                                            <td><input type="text" name="ggr_company_kvk" /></td>
                                        </tr>
                                        <tr>
                                            <td>BTW nummer</td>
                                            <td><input type="text" name="ggr_company_vat" /></td>
                                        </tr>
                                        <tr>
                                            <td>Vestigingsadres</td>
                                            <td><input type="text" name="ggr_company_address" /></td>
                                        </tr>
                                        <tr>
                                            <td>UBO's (namen en percentages)</td>
                                            <td><input type="text" name="ggr_company_ubos" placeholder="Bijv. Jansen 60%, de Vries 40%" /></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="stap-3" class="ggr-onboarding-section">
                            <h2>3. Persoonlijke gegevens</h2>
                            <p>Vul de persoonlijke gegevens in van de participant en, indien van toepassing, een mede-participant.</p>
                            <div class="ggr-onboarding-table-wrapper">
                                <table class="ggr-onboarding-table">
                                    <thead>
                                        <tr>
                                            <th>Gegevens</th>
                                            <th>Participant</th>
                                            <th>Mede-participant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Volledige naam</td>
                                            <td><input type="text" name="ggr_name_participant" /></td>
                                            <td><input type="text" name="ggr_name_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Geboortedatum</td>
                                            <td><input type="date" name="ggr_dob_participant" /></td>
                                            <td><input type="date" name="ggr_dob_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Adres</td>
                                            <td><input type="text" name="ggr_address_participant" /></td>
                                            <td><input type="text" name="ggr_address_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Postcode</td>
                                            <td><input type="text" name="ggr_zip_participant" /></td>
                                            <td><input type="text" name="ggr_zip_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Woonplaats</td>
                                            <td><input type="text" name="ggr_city_participant" /></td>
                                            <td><input type="text" name="ggr_city_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Telefoon</td>
                                            <td><input type="tel" name="ggr_phone_participant" /></td>
                                            <td><input type="tel" name="ggr_phone_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>E-mailadres</td>
                                            <td><input type="email" name="ggr_email_participant" /></td>
                                            <td><input type="email" name="ggr_email_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Identificatienummer paspoort/ID</td>
                                            <td><input type="text" name="ggr_id_participant" /></td>
                                            <td><input type="text" name="ggr_id_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>IBAN (voor uitkeringen)</td>
                                            <td><input type="text" name="ggr_iban_participant" /></td>
                                            <td><input type="text" name="ggr_iban_co_participant" /></td>
                                        </tr>
                                        <tr>
                                            <td>Nationaliteit</td>
                                            <td><input type="text" name="ggr_nationality_participant" /></td>
                                            <td><input type="text" name="ggr_nationality_co_participant" /></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section id="stap-4" class="ggr-onboarding-section">
                            <h2>4. Herkomst van het geld</h2>
                            <p>Geef de herkomst van het te beleggen bedrag aan en licht dit kort toe.</p>
                            <div class="ggr-onboarding-grid">
                                <div class="ggr-onboarding-field">
                                    <label>Herkomst</label>
                                    <div class="ggr-onboarding-checkboxes">
                                        <label><input type="checkbox" name="ggr_source_income" /> Inkomen</label>
                                        <label><input type="checkbox" name="ggr_source_savings" /> Spaargeld</label>
                                        <label><input type="checkbox" name="ggr_source_investments" /> Andere investeringen</label>
                                        <label><input type="checkbox" name="ggr_source_inheritance" /> Erfenis</label>
                                        <label><input type="checkbox" name="ggr_source_other" /> Anders</label>
                                    </div>
                                </div>
                                <div class="ggr-onboarding-field">
                                    <label for="ggr-source-notes">Toelichting</label>
                                    <textarea id="ggr-source-notes" name="ggr_source_notes" rows="4" placeholder="Beschrijf kort waar het geld vandaan komt."></textarea>
                                </div>
                            </div>
                        </section>

                        <section id="stap-5" class="ggr-onboarding-section">
                            <h2>5. Mee te sturen documenten</h2>
                            <p>Afhankelijk van je participatietype lever je de volgende documenten aan.</p>

                            <div class="ggr-onboarding-grid">
                                <div class="ggr-onboarding-field">
                                    <h3>Voor privé-participatie</h3>
                                    <ul class="ggr-onboarding-doc-list">
                                        <li>Kopie geldig paspoort of ID-kaart</li>
                                        <li>Bewijs van adres (bijv. energierekening)</li>
                                        <li>Bankafschrift met IBAN</li>
                                        <li>Eventueel: bewijs van herkomst middelen</li>
                                    </ul>
                                </div>
                                <div class="ggr-onboarding-field">
                                    <h3>Voor zakelijke participatie</h3>
                                    <ul class="ggr-onboarding-doc-list">
                                        <li>Uittreksel KvK (max 3 maanden oud)</li>
                                        <li>Kopie paspoort/ID contactpersoon</li>
                                        <li>UBO-overzicht en verificatie</li>
                                        <li>Bankafschrift van bedrijfsrekening</li>
                                        <li>Eventueel: aandeelhoudersregister</li>
                                    </ul>
                                </div>
                            </div>

                            <div class="ggr-onboarding-highlight ggr-onboarding-highlight--secondary">
                                <div>
                                    <h3>Akkoordverklaring</h3>
                                    <p>Door het formulier te verzenden verklaar je akkoord te zijn met de voorwaarden van het GGR Income Fund en bevestig je dat de gegevens juist zijn.</p>
                                </div>
                                <label class="ggr-onboarding-checkbox-inline">
                                    <input type="checkbox" name="ggr_terms" />
                                    <span>Ik ga akkoord en wil mijn deelname afronden</span>
                                </label>
                            </div>
                        </section>

                        <div class="ggr-onboarding-actions">
                            <button type="submit" class="ggr-onboarding-primary-btn">
                                <i class="ri-send-plane-2-line" aria-hidden="true"></i>
                                Verzoek indienen
                            </button>
                            <a class="ggr-onboarding-link" href="#stap-1">Terug naar boven</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Front-end onboarding registratie + e-mailverificatie
 *
 * Shortcode: [ggr_onboarding_register]
 */
add_shortcode( 'ggr_onboarding_register', 'ggr_onboarding_register_shortcode' );

function ggr_onboarding_register_shortcode() {
    if ( is_user_logged_in() ) {
        // Kritisch: al ingelogd → geen registratie aanbieden
        return '<p>Je bent al ingelogd.</p>';
    }

    $user_id = get_current_user_id();
    
    $messages = array(
        'error'   => array(),
        'success' => '',
    );

    $contract_signed_at      = get_user_meta( $user_id, 'ggr_contract_signed_at', true );
    $payment_confirmation_at = get_user_meta( $user_id, 'ggr_payment_confirmation_at', true );
    $payment_received        = get_user_meta( $user_id, 'ggr_payment_received', true );

    $participation_profile = get_user_meta( $user_id, 'ggr_participation_profile', true );
    
    // 1. E-mailverificatie (GET) afhandelen als aanwezig
    if ( isset( $_GET['ggr_verify'], $_GET['uid'], $_GET['token'] ) ) {
        $verify_result = ggr_onboarding_handle_email_verification();

        if ( is_wp_error( $verify_result ) ) {
            $messages['error'][] = $verify_result->get_error_message();
        } else {
            $messages['success'] = 'Je e-mailadres is bevestigd. Je kunt nu inloggen.';
        }
    }

    // 2. Registratie afhandelen (POST)
    if ( isset( $_POST['ggr_onboarding_register_submit'] ) ) {
        $process_result = ggr_onboarding_handle_registration_submit();

        if ( is_wp_error( $process_result ) ) {
            $messages['error'][] = $process_result->get_error_message();
        } else {
            $messages['success'] = 'Je registratie is ontvangen. Controleer je e-mail om je adres te bevestigen.';
        }
    }

    // 3. UI renderen in dezelfde shell als login
    ob_start();
    ?>
    <div class="ggr-login-wrapper">
        <div class="ggr-login-shell">
            
             <!-- LOGO BOVEN DE CARD -->
        <div class="ggr-logo-top">
            <img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png" alt="GGR Logo">
        </div>
            
            <div class="ggr-login-card">

                <h1 class="ggr-login-title">Registreren als investeerder</h1>
                <p class="ggr-login-subtitle">
                    Maak een account aan om toegang te krijgen tot het GGR Portal.
                </p>

                <?php if ( ! empty( $messages['error'] ) ) : ?>
                    <div class="ggr-login-notice ggr-login-notice--error">
                        <?php foreach ( $messages['error'] as $err ) : ?>
                            <p><?php echo esc_html( $err ); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $messages['success'] ) ) : ?>
                    <div class="ggr-login-notice ggr-login-notice--info">
                        <p><?php echo esc_html( $messages['success'] ); ?></p>
                    </div>
                    <?php
                    if ( isset( $_POST['ggr_onboarding_register_submit'] ) ) {
                        echo '</div></div></div>';
                        return ob_get_clean();
                    }
                endif;

                $countries           = ggr_get_countries_nl();
                $current_nationality = isset( $_POST['ggr_nationality'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_nationality'] ) ) : '';
                ?>
                


    <form method="post" class="ggr-onboarding-register-form ggr-login-fields">
    <?php wp_nonce_field( 'ggr_onboarding_register', 'ggr_onboarding_register_nonce' ); ?>

    <!-- Honeypot -->
    <input type="text" name="ggr_hp" value="" style="display:none !important;" autocomplete="off">

    <p class="ggr-field">
    <label for="ggr_account_type">Account type *</label>
    <select id="ggr_account_type"
            name="ggr_account_type"
            class="input"
            required>
        <option value="">Maak een keuze</option>
        <option value="private"  <?php selected( $_POST['ggr_account_type'] ?? '', 'private' ); ?>>Particulier</option>
        <option value="business" <?php selected( $_POST['ggr_account_type'] ?? '', 'business' ); ?>>Zakelijk</option>
        </select>
    </p>


    <!-- Voornaam / achternaam naast elkaar -->
    <div style="display:flex; gap:16px;">
        <p class="ggr-field" style="flex:1 1 0;">
            <label for="ggr_first_name">Voornaam *</label>
            <input type="text" id="ggr_first_name" name="ggr_first_name"
                   value="<?php echo isset( $_POST['ggr_first_name'] ) ? esc_attr( wp_unslash( $_POST['ggr_first_name'] ) ) : ''; ?>"
                   required>
        </p>

        <p class="ggr-field" style="flex:1 1 0;">
            <label for="ggr_last_name">Achternaam *</label>
            <input type="text" id="ggr_last_name" name="ggr_last_name"
                   value="<?php echo isset( $_POST['ggr_last_name'] ) ? esc_attr( wp_unslash( $_POST['ggr_last_name'] ) ) : ''; ?>"
                   required>
        </p>
    </div>

    <p class="ggr-field">
        <label for="ggr_email">E-mailadres *</label>
        <input type="email" id="ggr_email" name="ggr_email"
               value="<?php echo isset( $_POST['ggr_email'] ) ? esc_attr( wp_unslash( $_POST['ggr_email'] ) ) : ''; ?>"
               required>
    </p>

    <p class="ggr-field">
        <label for="ggr_phone">Telefoonnummer *</label>
        <input type="text" id="ggr_phone" name="ggr_phone"
               value="<?php echo isset( $_POST['ggr_phone'] ) ? esc_attr( wp_unslash( $_POST['ggr_phone'] ) ) : ''; ?>"
               required>
    </p>

    <p class="ggr-field">
    <label for="ggr_nationality">Nationaliteit *</label>

    <div style="position:relative;">
        <select id="ggr_nationality"
                name="ggr_nationality"
                class="input"
                required
                style="
                    width: 100%;
                    box-sizing: border-box;
                    border-radius: 8px;
                    border: 1px solid #d1d5db;
                    padding: 10px 12px;
                    font-size: 14px;
                    color: #111827;
                    background: #ffffff;
                    transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
                ">
            <option value="">Maak een keuze</option>
            <?php foreach ( $countries as $country ) : ?>
                <option value="<?php echo esc_attr( $country ); ?>"
                    <?php selected( $current_nationality, $country ); ?>>
                    <?php echo esc_html( $country ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</p>


        <p class="ggr-field">
        <label for="ggr_investment">Hoeveel wil je investeren (€) *</label>
        <input
            type="text"
            inputmode="decimal"
            id="ggr_investment"
            name="ggr_investment"
            placeholder="€ 50.000"
            class="input"
            value="<?php echo isset( $_POST['ggr_investment'] ) ? esc_attr( wp_unslash( $_POST['ggr_investment'] ) ) : ''; ?>"
            required
        >
    </p>


  <p class="ggr-field">
    <label for="ggr_password">Wachtwoord *</label>

    <div class="ggr-input-with-icon"
         style="position:relative; width:100%; display:block;">
        <input
            type="password"
            id="ggr_password"
            name="ggr_password"
            required
            style="width:100%; box-sizing:border-box; padding-right:40px;"
        >

        <span
            class="ggr-info-icon"
            tabindex="0"
            data-tooltip="Een sterk wachtwoord is vereist, met minimaal 8 en maximaal 128 tekens. Het moet minstens één hoofdletter, één kleine letter, één speciaal teken en één cijfer bevatten."
            style="
                position:absolute;
                right:12px;
                top:50%;
                transform:translateY(-50%);
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:20px;
                height:20px;
                border-radius:999px;
                cursor:pointer;
                font-size:14px;
                z-index:2;
            "
        >
            <i class="ri-information-line" aria-hidden="true" style="font-size:13px; line-height:1;"></i>
        </span>
    </div>
</p>


    <p class="ggr-field">
        <label for="ggr_password2">Herhaal wachtwoord *</label>
        <input type="password" id="ggr_password2" name="ggr_password2" required>
    </p>

    <p class="ggr-field ggr-onboard-checkbox">
        <label>
            <input type="checkbox" name="ggr_terms" value="1" <?php checked( ! empty( $_POST['ggr_terms'] ) ); ?> required>
            Ik ga akkoord met de voorwaarden &amp; privacy policy.
        </label>
    </p>

    <p class="ggr-field ggr-onboard-checkbox">
        <label>
            <input type="checkbox" name="ggr_marketing" value="1" <?php checked( ! empty( $_POST['ggr_marketing'] ) ); ?>>
            Ik wil marketing- en investeringsupdates ontvangen.
        </label>
    </p>

    <div class="ggr-login-actions">
        <button type="submit"
                name="ggr_onboarding_register_submit"
                value="1"
                class="ggr-login-submit">
            Registreren
        </button>
    </div>


                                </form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var icons = document.querySelectorAll('.ggr-onboarding-register-form .ggr-info-icon');

    icons.forEach(function (icon) {
        var text = icon.getAttribute('data-tooltip');
        if (!text) return;

        var tooltip = null;

        function showTooltip() {
            if (tooltip) return;

            tooltip = document.createElement('div');
            tooltip.textContent = text;

            // Inline styling zodat je geen extra CSS nodig hebt
            tooltip.style.position = 'absolute';
            tooltip.style.zIndex = '9999';
            tooltip.style.background = '#111827';
            tooltip.style.color = '#ffffff';
            tooltip.style.padding = '8px 10px';
            tooltip.style.borderRadius = '8px';
            tooltip.style.fontSize = '12px';
            tooltip.style.lineHeight = '1.4';
            tooltip.style.maxWidth = '260px';
            tooltip.style.boxShadow = '0 10px 25px rgba(0,0,0,0.35)';

            document.body.appendChild(tooltip);

            var rect = icon.getBoundingClientRect();
            var top  = rect.bottom + window.scrollY + 6;
            var left = rect.right + window.scrollX - tooltip.offsetWidth;

            if (left < 8) {
                left = 8;
            }

            tooltip.style.top  = top + 'px';
            tooltip.style.left = left + 'px';
        }

        function hideTooltip() {
            if (!tooltip) return;
            tooltip.remove();
            tooltip = null;
        }

        icon.addEventListener('mouseenter', showTooltip);
        icon.addEventListener('mouseleave', hideTooltip);
        icon.addEventListener('focus', showTooltip);
        icon.addEventListener('blur', hideTooltip);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('ggr_investment');
    if (!input) return;

    function formatEuro(value) {
        if (!value) return '';
        // Alle niet-cijfers weggooien
        var digits = value.toString().replace(/\D/g, '');
        if (!digits) return '';

        var number = parseInt(digits, 10);
        if (isNaN(number)) return '';

        // In NL-formaat met twee decimalen
        return '€ ' + number.toLocaleString('nl-NL', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }

    function unformatEuro(value) {
        if (!value) return '';
        // € en spaties eruit
        value = value.toString().replace(/[€\s]/g, '');
        // punten (duizendtallen) eruit, komma wordt decimal separator
        value = value.replace(/\./g, '').replace(',', '.');
        return value;
    }

    // Als er na een foutmelding al een waarde staat → direct formatteren
    if (input.value) {
        input.value = formatEuro(input.value);
    }

    // Op focus: terug naar “ruwe” waarde zodat invoeren niet irritant is
    input.addEventListener('focus', function () {
        var raw = unformatEuro(this.value);
        this.value = raw;
    });

    // Op blur: weer als geld tonen
    input.addEventListener('blur', function () {
        this.value = formatEuro(this.value);
    });

    // Voor submit: waarde normaliseren zodat PHP ermee overweg kan
    var form = input.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var raw = unformatEuro(input.value);
            input.value = raw; // bijv. "100000" of "100000.00"
        });
    }
});
</script>


            </div>
         <!-- TERUG NAAR INLOGGEN -->
        <div style="text-align:center; margin-top:18px;">
            <a href="/inloggen" class="ggr-login-back-link">Terug naar inloggen</a>
        </div>
        </div>
    </div>
        <script>
    document.addEventListener('DOMContentLoaded', function() {
        const moneyInput = document.querySelector('.ggr-onboarding-money');
        if (moneyInput) {
            const formatEuro = (val) => {
                if (!val) { return ''; }
                const numeric = val.toString().replace(/[^0-9,.]/g, '').replace(/\./g, '').replace(',', '.');
                const number = parseFloat(numeric);
                if (Number.isNaN(number) || number <= 0) { return ''; }
                return '€ ' + Math.round(number).toLocaleString('nl-NL');
            };

            const normalize = () => {
                const cleaned = moneyInput.value.replace(/[^0-9,.]/g, '').replace(/\./g, '').replace(',', '.');
                moneyInput.value = cleaned;
            };

            const applyFormat = () => {
                const formatted = formatEuro(moneyInput.value);
                if (formatted) {
                    moneyInput.value = formatted;
                }
            };

            moneyInput.addEventListener('focus', normalize);
            moneyInput.addEventListener('blur', applyFormat);
            applyFormat();
        }

        const companyFields = document.querySelectorAll('[data-company-field]');
        const businessDocs = document.querySelectorAll('[data-business-doc]');
        const profileRadios = document.querySelectorAll('input[name="ggr_participation_profile"]');

        const toggleBusinessVisibility = () => {
            const selected = document.querySelector('input[name="ggr_participation_profile"]:checked');
            const isBusiness = selected && selected.value === 'zakelijk';

            companyFields.forEach((field) => {
                const isRequired = field.dataset.companyRequired === 'true';
                field.classList.toggle('is-hidden', !isBusiness);
                field.querySelectorAll('input').forEach((input) => {
                    input.disabled = !isBusiness;
                    input.required = isBusiness && isRequired;
                    if (!isBusiness) {
                        input.value = '';
                    }
                });
            });

            businessDocs.forEach((field) => {
                field.classList.toggle('is-hidden', !isBusiness);
                field.querySelectorAll('input').forEach((input) => {
                    input.disabled = !isBusiness;
                });
            });
        };

        profileRadios.forEach((radio) => {
            radio.addEventListener('change', toggleBusinessVisibility);
        });
        toggleBusinessVisibility();

        const docForm = document.querySelector('form[data-documents-form]');
        if (docForm) {
            docForm.addEventListener('submit', function(event) {
                const submitter = event.submitter;
                if (submitter && submitter.dataset.confirmDocs) {
                    const confirmed = window.confirm('Zeker dat alles naar waarheid is ingevuld?');
                    if (!confirmed) {
                        event.preventDefault();
                    }
                }
            });
        }

        const initializeOnboardingToast = (onboardingToast) => {
            if (!onboardingToast || onboardingToast.dataset.toastInitialized === 'true') {
                return;
            }

            onboardingToast.dataset.toastInitialized = 'true';
            onboardingToast.classList.add('is-visible');
            let isHiding = false;

            const removeToast = () => {
                if (onboardingToast.parentElement) {
                    onboardingToast.remove();
                }
            };

            const hideToast = () => {
                if (isHiding) {
                    return;
                }

                isHiding = true;
                onboardingToast.classList.remove('is-visible');
                onboardingToast.addEventListener('transitionend', removeToast, { once: true });
                window.setTimeout(removeToast, 500);
            };

            const closeButton = onboardingToast.querySelector('[data-toast-close]')
                || onboardingToast.querySelector('.ggr-onboarding-toast__close')
                || onboardingToast.querySelector('.ggr-login-toast__close');
            const scheduleAutoHide = () => window.setTimeout(hideToast, 6000);
            let hideTimer = scheduleAutoHide();

            onboardingToast.addEventListener('mouseenter', () => {
                if (hideTimer) {
                    window.clearTimeout(hideTimer);
                }
            });

            onboardingToast.addEventListener('mouseleave', () => {
                if (!isHiding) {
                    hideTimer = scheduleAutoHide();
                }
            });
            
            onboardingToast.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hideToast();
                }
            });

            if (closeButton) {
                closeButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    hideToast();
                });
            }

            onboardingToast.addEventListener('click', (event) => {
                if (event.target === onboardingToast) {
                    hideToast();
                }
            });
        };

        document.querySelectorAll('[data-ggr-onboarding-toast]').forEach(initializeOnboardingToast);

        const toastObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof HTMLElement)) {
                        return;
                    }

                    if (node.matches('[data-ggr-onboarding-toast]')) {
                        initializeOnboardingToast(node);
                    } else {
                        node.querySelectorAll('[data-ggr-onboarding-toast]').forEach(initializeOnboardingToast);
                    }
                });
            });
        });

        toastObserver.observe(document.body, { childList: true, subtree: true });
    });
    </script>
    <?php

    return ob_get_clean();
}

/**
 * E-mailverificatie afhandelen
 */
function ggr_onboarding_handle_email_verification() {

    $user_id = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
    $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    if ( ! $user_id || ! $token ) {
        return new WP_Error( 'invalid_params', 'Ongeldige verificatielink.' );
    }

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return new WP_Error( 'user_not_found', 'Gebruiker niet gevonden.' );
    }

    $stored_token  = get_user_meta( $user_id, 'ggr_onboarding_email_token', true );
    $token_expires = (int) get_user_meta( $user_id, 'ggr_onboarding_email_token_expires', true );
    $now           = current_time( 'timestamp' );

    if ( ! $stored_token || ! $token_expires ) {
        return new WP_Error( 'no_token', 'Deze verificatielink is niet (meer) geldig.' );
    }

    if ( $now > $token_expires ) {
        return new WP_Error( 'token_expired', 'Deze verificatielink is verlopen.' );
    }

    if ( ! hash_equals( $stored_token, $token ) ) {
        return new WP_Error( 'token_mismatch', 'Ongeldige verificatiecode.' );
    }

    // Token geldig → opschonen + vlag zetten
    delete_user_meta( $user_id, 'ggr_onboarding_email_token' );
    delete_user_meta( $user_id, 'ggr_onboarding_email_token_expires' );
    update_user_meta( $user_id, 'ggr_email_verified', 1 );
    update_user_meta( $user_id, 'ggr_email_verified_at', current_time( 'mysql' ) );    

    // Onboarding status op "confirmed"
    if ( function_exists( 'ggr_onboarding_update_status' ) ) {
        ggr_onboarding_update_status( $user_id, 'confirmed' );
    } else {
        update_user_meta( $user_id, 'ggr_onboarding_status', 'confirmed' );
    }

    return true;
}

/**
 * Afhandeling registratie POST
 */
function ggr_onboarding_handle_registration_submit() {

    // Nonce check
    if (
        ! isset( $_POST['ggr_onboarding_register_nonce'] )
        || ! wp_verify_nonce( $_POST['ggr_onboarding_register_nonce'], 'ggr_onboarding_register' )
    ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    // Honeypot
    if ( ! empty( $_POST['ggr_hp'] ) ) {
        return new WP_Error( 'spam_blocked', 'Er ging iets mis bij de registratie.' );
    }

    /**
     * Nieuwe velden ophalen
     */
    $account_type = sanitize_text_field( $_POST['ggr_account_type'] ?? '' );
    $first_name   = sanitize_text_field( $_POST['ggr_first_name'] ?? '' );
    $last_name    = sanitize_text_field( $_POST['ggr_last_name'] ?? '' );
    $email        = sanitize_email( $_POST['ggr_email'] ?? '' );
    $phone        = sanitize_text_field( $_POST['ggr_phone'] ?? '' );
    $nationality  = sanitize_text_field( $_POST['ggr_nationality'] ?? '' );
    
    /**
     * Align account_type met CRM:
     * - oude waarde "company" (als die ooit nog binnenkomt) mappen naar "business"
     * - alleen "private" en "business" toestaan
     */
    if ( 'company' === $account_type ) {
        $account_type = 'business';
    }
    if ( ! in_array( $account_type, array( 'private', 'business' ), true ) ) {
        $account_type = '';
    }


    // Ruwe (mogelijk geformatteerde) input voor investering
    $investment_raw = $_POST['ggr_investment'] ?? '';

    // Wachtwoord + herhaling
    $password  = (string) ( $_POST['ggr_password'] ?? '' );
    $password2 = (string) ( $_POST['ggr_password2'] ?? '' );

    // Acceptatie
    $terms     = ! empty( $_POST['ggr_terms'] );
    $marketing = ! empty( $_POST['ggr_marketing'] ) ? 1 : 0;

    /**
     * Validatie verplichte velden (op basis van ruwe input)
     */
    if (
        empty( $account_type ) ||
        empty( $first_name ) ||
        empty( $last_name ) ||
        empty( $email ) ||
        empty( $phone ) ||
        empty( $nationality ) ||
        $investment_raw === '' || trim( $investment_raw ) === '' ||
        empty( $password )
    ) {
        return new WP_Error( 'missing_fields', 'Vul alle verplichte velden in.' );
    }

    if ( ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Het opgegeven e-mailadres is ongeldig.' );
    }

    if ( email_exists( $email ) ) {
        return new WP_Error( 'email_exists', 'Er bestaat al een account met dit e-mailadres.' );
    }

    if ( ! $terms ) {
        return new WP_Error( 'no_terms', 'Je moet akkoord gaan met de voorwaarden.' );
    }

    // Wachtwoorden vergelijken
    if ( $password !== $password2 ) {
        return new WP_Error( 'password_mismatch', 'De wachtwoorden komen niet overeen.' );
    }

    // Sterke wachtwoordvereisten
    if (
        strlen( $password ) < 8 ||
        strlen( $password ) > 128 ||
        ! preg_match( '/[A-Z]/', $password ) ||
        ! preg_match( '/[a-z]/', $password ) ||
        ! preg_match( '/[0-9]/', $password ) ||
        ! preg_match( '/[\W_]/', $password )
    ) {
        return new WP_Error(
            'weak_password',
            'Het wachtwoord voldoet niet aan de vereisten.'
   %2
