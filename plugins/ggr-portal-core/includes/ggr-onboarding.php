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
        'register'           => 'Register (eerste aanmelding)',
        'confirmed'          => 'Confirmed (e-mail gevalideerd)',
        'collecting'         => 'Collecting (documentatie aanleveren)',
        'validating'         => 'Validating (documentatie controleren)',
        'sign_contract'      => 'Sign contract (Overeenkomst tekenen)',
        'transfer_completed' => 'Transfer completed (Geld overmaken)',
        'active_participant' => 'Active participant (Achterover leunen)',
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
}

/**
 * Admin-menu: Onboarding pagina
 */
add_action( 'admin_menu', 'ggr_onboarding_register_admin_page' );

function ggr_onboarding_register_admin_page() {
    add_users_page(
        'Onboarding',
        'Onboarding',
        'list_users',
        'ggr-onboarding',
        'ggr_onboarding_render_admin_page'
    );
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
    if ( ! current_user_can( 'list_users' ) ) {
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
            ?>
            <tr>
                <td><?php echo esc_html( $uid ); ?></td>
                <td>
                    <a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>">
                        <?php echo esc_html( $user->display_name ); ?>
                    </a>
                </td>
                <td>
                    <a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
                        <?php echo esc_html( $user->user_email ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $status_label ); ?></td>
                <td><?php echo $updated ? esc_html( $updated ) : '–'; ?></td>
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
                        $uid     = $user->ID;
                        $updated = get_user_meta( $uid, 'ggr_onboarding_updated_at', true );
                        ?>
                        <li class="ggr-onboard-card"
                            data-user-id="<?php echo esc_attr( $uid ); ?>">
                            <div class="ggr-onboard-card-header">
                                <a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>" target="_blank">
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
                                        <?php echo $updated ? esc_html( $updated ) : '–'; ?>
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

    wp_send_json_success(
        array(
            'updated_at' => $updated ? $updated : '',
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

    $messages = array(
        'error'   => array(),
        'success' => '',
    );

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
            <img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png" alt="GGR Logo">
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
        );
    }

    /**
     * Investeringsbedrag uit geformatteerde string parsen
     * Voorbeelden die hiermee werken:
     * - "100000"
     * - "100.000"
     * - "100.000,00"
     * - "€ 100.000,00"
     */
    $investment_clean = preg_replace( '/[^\d,\.]/', '', (string) $investment_raw );

    if ( $investment_clean === '' ) {
        $investment = 0;
    } else {
        if ( strpos( $investment_clean, ',' ) !== false && strpos( $investment_clean, '.' ) !== false ) {
            // Punt als duizendtallen, komma als decimalen
            $investment_clean = str_replace( '.', '', $investment_clean );
            $investment_clean = str_replace( ',', '.', $investment_clean );
        } elseif ( strpos( $investment_clean, ',' ) !== false ) {
            // Alleen komma → decimal separator
            $investment_clean = str_replace( ',', '.', $investment_clean );
        }
        $investment = (float) $investment_clean;
    }

    if ( $investment <= 0 ) {
        return new WP_Error( 'invalid_investment', 'Voer een geldig investeringsbedrag in.' );
    }

    /**
     * Gebruiker aanmaken
     */
    $username_base = sanitize_user( current( explode( '@', $email ) ), true );
    if ( ! $username_base ) {
        $username_base = 'user';
    }

    $username = $username_base;
    $i        = 1;
    while ( username_exists( $username ) ) {
        $username = $username_base . $i;
        $i++;
    }

    $user_id = wp_insert_user(
        array(
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ),
            // Belangrijk: nieuwe registraties zijn LEADS, geen participants
            'role'         => 'lead',
        )
    );

    if ( is_wp_error( $user_id ) ) {
        return new WP_Error(
            'user_create_failed',
            'Het aanmaken van het account is mislukt. Probeer het later opnieuw.'
        );
    }

    /**
     * Extra meta opslaan
     */
    update_user_meta( $user_id, 'ggr_account_type', $account_type );
    update_user_meta( $user_id, 'ggr_phone', $phone );
    update_user_meta( $user_id, 'ggr_nationality', $nationality );
    update_user_meta( $user_id, 'ggr_investment_amount', $investment );
    update_user_meta( $user_id, 'ggr_marketing_optin', $marketing );

    /**
     * Onboarding status op 'register' zetten
     */
    if ( function_exists( 'ggr_onboarding_update_status' ) ) {
        ggr_onboarding_update_status( $user_id, 'register' );
    } else {
        update_user_meta( $user_id, 'ggr_onboarding_status', 'register' );
    }

    /**
     * E-mailverificatie token genereren
     */
    $token  = wp_generate_password( 32, false, false );
    $now    = current_time( 'timestamp' );
    $expiry = $now + DAY_IN_SECONDS * 2; // 48 uur geldig

    update_user_meta( $user_id, 'ggr_onboarding_email_token', $token );
    update_user_meta( $user_id, 'ggr_onboarding_email_token_expires', $expiry );

    $current_url = ggr_onboarding_get_current_url();
    $verify_url  = add_query_arg(
        array(
            'ggr_verify' => 1,
            'uid'        => $user_id,
            'token'      => $token,
        ),
        $current_url
    );

    /**
     * Verificatie e-mail versturen via templating-systeem
     */
    if ( function_exists( 'ggr_portal_send_templated_email' ) ) {

        $extra_placeholders = array(
            'verification_link' => $verify_url,
            'login_link'        => wp_login_url(),
        );

        ggr_portal_send_templated_email(
            'onboarding_email_verification',
            $user_id,
            $extra_placeholders
        );
    }

    return true;
}

/**
 * Front-end onboarding dashboard voor ingelogde leads/deelnemers
 *
 * Shortcode: [ggr_onboarding_dashboard]
 */
add_shortcode( 'ggr_onboarding_dashboard', 'ggr_onboarding_dashboard_shortcode' );

function ggr_onboarding_dashboard_shortcode() {

    if ( ! is_user_logged_in() ) {
        return '<p>Je moet ingelogd zijn om je onboarding te bekijken.</p>';
    }

    $user    = wp_get_current_user();
    $user_id = $user->ID;

    // Onboarding status & label.
    $status       = function_exists( 'ggr_onboarding_get_status' )
        ? ggr_onboarding_get_status( $user_id )
        : 'register';

    $stages       = function_exists( 'ggr_onboarding_get_stages' )
        ? ggr_onboarding_get_stages()
        : array();
    $status_label = isset( $stages[ $status ] ) ? $stages[ $status ] : ucfirst( $status );

    $updated = get_user_meta( $user_id, 'ggr_onboarding_updated_at', true );

    // Messages voor de collecting-flow.
    $messages = array(
        'error'   => array(),
        'success' => '',
    );

    // Flag: heeft gebruiker stap 1 (persoonlijke gegevens) al afgerond?
    $collecting_personal_done = get_user_meta( $user_id, 'ggr_collecting_personal_done', true );

    // Bepaal actieve stap in collecting-fase en maak wisselen mogelijk via query-parameter.
    $available_collecting_steps = array( 'personal', 'files' );
    $requested_collecting_step  = isset( $_GET['collecting_step'] )
        ? sanitize_key( wp_unslash( $_GET['collecting_step'] ) )
        : '';
    $current_collecting_step    = in_array( $requested_collecting_step, $available_collecting_steps, true )
        ? $requested_collecting_step
        : ( $collecting_personal_done ? 'files' : 'personal' );

    /**
     * Collecting-fase: POST-afhandeling
     */
    if ( 'collecting' === $status ) {

        // Stap 1: persoonlijke gegevens.
        if ( isset( $_POST['ggr_collecting_personal_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_personal( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][] = $result->get_error_message();
                $current_collecting_step = 'personal';
            } else {
                $messages['success']            = 'Je persoonlijke gegevens zijn opgeslagen.';
                $collecting_personal_done       = 1;
                update_user_meta( $user_id, 'ggr_collecting_personal_done', 1 );
                // Optioneel: timestamp bijwerken.
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $current_collecting_step = 'files';
            }
        }

        // Stap 2: documenten uploaden.
        if ( isset( $_POST['ggr_collecting_files_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_files( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][] = $result->get_error_message();
                $current_collecting_step = 'files';
            } else {
                $messages['success'] = 'Je documenten zijn ontvangen. Wij gaan hiermee aan de slag.';
                // Hier laat je de status bewust nog op "collecting" staan,
                // zodat jij in de backoffice eerst kunt valideren.
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $current_collecting_step = 'files';
            }
        }
    }

    // Content voor blok rechts (algemene uitleg per status).
    $side_block = ggr_onboarding_get_side_block_content( $status );

    ob_start();
    ?>
    <div class="ggr-onboarding-shell">
        <div class="ggr-onboarding-card">

            <div class="ggr-onboarding-header">
            </div>

            <div class="ggr-onboarding-body">
                <div class="ggr-onboarding-steps">
                    <h2>Jouw stappen</h2>
                    <p>We begeleiden je stap voor stap. Zodra alle stappen zijn afgerond, word je een actieve participant.</p>

                    <ul class="ggr-onboarding-step-list">
                        <?php
                        // Heel simpel: mapping per status naar "done/current/upcoming".
                        $order = array(
                            'register',
                            'confirmed',
                            'collecting',
                            'validating',
                            'sign_contract',
                            'transfer_completed',
                            'active_participant',
                        );

                        $current_index = array_search( $status, $order, true );
                        if ( $current_index === false ) {
                            $current_index = 0;
                        }

                        foreach ( $order as $index => $key ) {
                            $label = isset( $stages[ $key ] ) ? $stages[ $key ] : $key;

                            if ( $index < $current_index ) {
                                $state = 'done';
                                $icon  = '✓';
                            } elseif ( $index === $current_index ) {
                                $state = 'current';
                                $icon  = '•';
                            } else {
                                $state = 'upcoming';
                                $icon  = '…';
                            }
                            ?>
                            <li class="ggr-onboarding-step">
                                <div class="ggr-onboarding-step-icon ggr-onboarding-step-icon--<?php echo esc_attr( $state ); ?>">
                                    <?php echo esc_html( $icon ); ?>
                                </div>
                                <div class="ggr-onboarding-step-content">
                                    <h3><?php echo esc_html( $label ); ?></h3>
                                    <p>
                                        <?php
                                        switch ( $key ) {
                                            case 'register':
                                                echo 'Je registratie is ontvangen. Volg de instructies in de e-mail om je adres te bevestigen.';
                                                break;
                                            case 'confirmed':
                                                echo 'Je e-mailadres is bevestigd. Wacht op verdere instructies of lever gevraagde documentatie aan.';
                                                break;
                                            case 'collecting':
                                                echo 'We vragen je om aanvullende gegevens en documentatie aan te leveren (KYC).';
                                                break;
                                            case 'validating':
                                                echo 'We controleren jouw gegevens en documentatie. Je hoeft nu niets te doen.';
                                                break;
                                            case 'sign_contract':
                                                echo 'Je ontvangt of hebt een overeenkomst/termsheet te ondertekenen.';
                                                break;
                                            case 'transfer_completed':
                                                echo 'Je storting is ontvangen en wordt verwerkt.';
                                                break;
                                            case 'active_participant':
                                                echo 'Je onboarding is afgerond. Je krijgt toegang tot het volledige GGR Portal.';
                                                break;
                                            default:
                                                echo 'Deze stap maakt onderdeel uit van je onboarding.';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </li>
                            <?php
                        }
                        ?>
                    </ul>
                </div>

                <div class="ggr-onboarding-title-block">
                    <h1>Onboarding als investeerder</h1>
                    <p>Volg hier de stappen tot je volledige toegang krijgt tot het GGR Portal.</p>

                    <div class="ggr-onboarding-status-badge">
                        <span>Huidige fase:</span>
                        <strong><?php echo esc_html( $status_label ); ?></strong>
                    </div>

                    <div class="ggr-onboarding-side">

                        <?php if ( ! empty( $messages['error'] ) || ! empty( $messages['success'] ) ) : ?>
                            <div class="ggr-onboarding-notice-wrapper">
                                <?php foreach ( $messages['error'] as $err ) : ?>
                                    <div class="ggr-login-notice ggr-login-notice--error">
                                        <p><?php echo esc_html( $err ); ?></p>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ( $messages['success'] ) : ?>
                                    <div class="ggr-login-notice ggr-login-notice--info">
                                        <p><?php echo esc_html( $messages['success'] ); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <h2><?php echo esc_html( $side_block['title'] ); ?></h2>
                        <p><?php echo esc_html( $side_block['description'] ); ?></p>

                        <?php if ( ! empty( $side_block['bullets'] ) && is_array( $side_block['bullets'] ) ) : ?>
                            <ul class="ggr-onboarding-side-list">
                                <?php foreach ( $side_block['bullets'] as $bullet ) : ?>
                                    <li><?php echo esc_html( $bullet ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ( 'collecting' === $status ) : ?>

                            <hr class="ggr-onboarding-separator" />
                            <div class="ggr-onboarding-step-switch">
                                <?php
                                $current_url = ggr_onboarding_get_current_url();
                                $step_labels = array(
                                    'personal' => 'Stap 1: persoonlijke gegevens',
                                    'files'    => 'Stap 2: documenten uploaden',
                                );
                                foreach ( $step_labels as $step_key => $step_label ) :
                                    $step_url  = add_query_arg( 'collecting_step', $step_key, $current_url );
                                    $is_active = ( $current_collecting_step === $step_key );
                                    ?>
                                    <a class="ggr-onboarding-step-tab <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $step_url ); ?>">
                                        <?php echo esc_html( $step_label ); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <?php if ( 'personal' === $current_collecting_step ) : ?>
                                <!-- STAP 1: PERSOONLIJKE GEGEVENS -->
                                <h3>Stap 1: persoonlijke gegevens</h3>
                                <p>Vul hieronder je gegevens in zoals ze ook op het inschrijfformulier staan.</p>

                                <form method="post" class="ggr-onboarding-form">
                                    <?php wp_nonce_field( 'ggr_collecting_personal', 'ggr_collecting_personal_nonce' ); ?>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_full_name">Volledige naam *</label>
                                            <input type="text" id="ggr_kyc_full_name" name="ggr_kyc_full_name"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_full_name', true ) ); ?>"
                                                   required>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_birth_date">Geboortedatum *</label>
                                            <input type="date" id="ggr_kyc_birth_date" name="ggr_kyc_birth_date"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_birth_date', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <p class="ggr-field">
                                        <label for="ggr_kyc_address">Adres *</label>
                                        <input type="text" id="ggr_kyc_address" name="ggr_kyc_address"
                                               value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_address', true ) ); ?>"
                                               required>
                                    </p>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_postcode">Postcode *</label>
                                            <input type="text" id="ggr_kyc_postcode" name="ggr_kyc_postcode"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_postcode', true ) ); ?>"
                                                   required>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_city_country">Woonplaats + land *</label>
                                            <input type="text" id="ggr_kyc_city_country" name="ggr_kyc_city_country"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_city_country', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_birth_country">Geboorteland *</label>
                                            <?php
                                            $countries   = ggr_get_countries_nl();
                                            $selected    = get_user_meta( $user_id, 'ggr_kyc_birth_country', true );
                                            $placeholder = $selected ? '' : '<option value="">Selecteer je geboorteland</option>';
                                            ?>
                                            <select id="ggr_kyc_birth_country" name="ggr_kyc_birth_country" required>
                                                <?php echo wp_kses_post( $placeholder ); ?>
                                                <?php foreach ( $countries as $country ) : ?>
                                                    <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $selected, $country ); ?>>
                                                        <?php echo esc_html( $country ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_birth_place">Geboorteplaats *</label>
                                            <input type="text" id="ggr_kyc_birth_place" name="ggr_kyc_birth_place"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_birth_place', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_nationality">Nationaliteit *</label>
                                            <?php
                                            $countries   = ggr_get_countries_nl();
                                            $selected    = get_user_meta( $user_id, 'ggr_kyc_nationality', true );
                                            $placeholder = $selected ? '' : '<option value="">Selecteer je nationaliteit</option>';
                                            ?>
                                            <select id="ggr_kyc_nationality" name="ggr_kyc_nationality" required>
                                                <?php echo wp_kses_post( $placeholder ); ?>
                                                <?php foreach ( $countries as $country ) : ?>
                                                    <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $selected, $country ); ?>>
                                                        <?php echo esc_html( $country ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_phone">Telefoonnummer *</label>
                                            <input type="tel" id="ggr_kyc_phone" name="ggr_kyc_phone"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_phone', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_id_expiry">Geldigheid identiteitsbewijs *</label>
                                            <input type="date" id="ggr_kyc_id_expiry" name="ggr_kyc_id_expiry"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_id_expiry', true ) ); ?>"
                                                   required>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_origin_funds">Herkomst van middelen *</label>
                                            <input type="text" id="ggr_kyc_origin_funds" name="ggr_kyc_origin_funds"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_origin_funds', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_origin_country">Land van herkomst middelen *</label>
                                            <input type="text" id="ggr_kyc_origin_country" name="ggr_kyc_origin_country"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_origin_country', true ) ); ?>"
                                                   required>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_relation">Relatie tot GGR (optioneel)</label>
                                            <input type="text" id="ggr_kyc_relation" name="ggr_kyc_relation"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_relation', true ) ); ?>">
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_pep_reason">Toelichting PEP (optioneel)</label>
                                            <input type="text" id="ggr_kyc_pep_reason" name="ggr_kyc_pep_reason"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_pep_reason', true ) ); ?>">
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_bsn">Burgerservicenummer *</label>
                                            <input type="text" id="ggr_kyc_bsn" name="ggr_kyc_bsn"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_bsn', true ) ); ?>"
                                                   required>
                                        </p>
                                    </div>

                                    <p class="ggr-field">
                                        <label for="ggr_kyc_iban_name">Tenaamstelling IBAN *</label>
                                        <input type="text" id="ggr_kyc_iban_name" name="ggr_kyc_iban_name"
                                               value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_iban_name', true ) ); ?>"
                                               required>
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_kyc_iban">IBAN *</label>
                                        <input type="text" id="ggr_kyc_iban" name="ggr_kyc_iban"
                                               value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_iban', true ) ); ?>"
                                               required>
                                    </p>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_company">Bedrijfsnaam (optioneel)</label>
                                            <input type="text" id="ggr_kyc_company" name="ggr_kyc_company"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_company', true ) ); ?>">
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_kyc_kvk">KVK nummer (optioneel)</label>
                                            <input type="text" id="ggr_kyc_kvk" name="ggr_kyc_kvk"
                                                   value="<?php echo esc_attr( get_user_meta( $user_id, 'ggr_kyc_kvk', true ) ); ?>">
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <span>Politiek Prominent Persoon *</span><br>
                                            <?php $pep = get_user_meta( $user_id, 'ggr_kyc_pep', true ); ?>
                                            <label><input type="radio" name="ggr_kyc_pep" value="ja" <?php checked( $pep, 'ja' ); ?> required> Ja</label>
                                            <label style="margin-left:12px;"><input type="radio" name="ggr_kyc_pep" value="nee" <?php checked( $pep, 'nee' ); ?>> Nee</label>
                                        </p>
                                        <p class="ggr-field">
                                            <span>US person *</span><br>
                                            <?php $us = get_user_meta( $user_id, 'ggr_kyc_us_person', true ); ?>
                                            <label><input type="radio" name="ggr_kyc_us_person" value="ja" <?php checked( $us, 'ja' ); ?> required> Ja</label>
                                            <label style="margin-left:12px;"><input type="radio" name="ggr_kyc_us_person" value="nee" <?php checked( $us, 'nee' ); ?>> Nee</label>
                                        </p>
                                    </div>

                                    <div class="ggr-login-actions">
                                        <button type="submit"
                                                name="ggr_collecting_personal_submit"
                                                value="1"
                                                class="ggr-login-submit">
                                            Opslaan
                                        </button>
                                    </div>
                                </form>

                            <?php else : ?>

                                <!-- STAP 2: BESTANDEN UPLOADEN -->
                                <h3>Stap 2: documenten uploaden</h3>
                                <p>Upload de gevraagde documenten zodat we je onboarding kunnen afronden.</p>

                                <form method="post" enctype="multipart/form-data" class="ggr-onboarding-form">
                                    <?php wp_nonce_field( 'ggr_collecting_files', 'ggr_collecting_files_nonce' ); ?>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_id">Identiteitsbewijs (paspoort / ID-kaart) *</label>
                                        <input type="file" id="ggr_doc_id" name="ggr_doc_id" accept=".pdf,.jpg,.jpeg,.png" required>
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_funds">Bewijs herkomst middelen (bijv. bankafschrift) *</label>
                                        <input type="file" id="ggr_doc_funds" name="ggr_doc_funds" accept=".pdf,.jpg,.jpeg,.png" required>
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_other">Overig document (optioneel)</label>
                                        <input type="file" id="ggr_doc_other" name="ggr_doc_other" accept=".pdf,.jpg,.jpeg,.png">
                                    </p>

                                    <div class="ggr-login-actions">
                                        <button type="submit"
                                                name="ggr_collecting_files_submit"
                                                value="1"
                                                class="ggr-login-submit">
                                            Documenten uploaden
                                        </button>
                                    </div>
                                </form>

                            <?php endif; // end personal/files switch ?>
                        <?php endif; // end collecting check ?>

                        <div class="ggr-onboarding-meta-block">
                            <h3>Jouw gegevens</h3>
                            <div class="ggr-onboarding-meta">
                                <div><strong>Naam:</strong> <?php echo esc_html( $user->display_name ); ?></div>
                                <div><strong>E-mail:</strong> <?php echo esc_html( $user->user_email ); ?></div>
                                <?php
                                $investment = get_user_meta( $user_id, 'ggr_investment_amount', true );
                                if ( $investment ) :
                                    ?>
                                    <div><strong>Indicatieve investering:</strong>
                                        € <?php echo esc_html( number_format_i18n( floatval( $investment ), 0 ) ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $updated ) : ?>
                                    <div><strong>Laatste update:</strong> <?php echo esc_html( $updated ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="/contact" class="ggr-onboarding-primary-btn">
                            Vragen over je onboarding?
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php

    return ob_get_clean();
}


function ggr_onboarding_handle_file_upload( $file_key, $user_id ) {
    if ( empty( $_FILES[ $file_key ]['name'] ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $uploaded = wp_handle_upload( $_FILES[ $file_key ], array( 'test_form' => false ) );

    if ( isset( $uploaded['url'] ) ) {
        update_user_meta( $user_id, $file_key, $uploaded['url'] );
    }
}


/**
 * Stap 1 collecting: persoonlijke gegevens opslaan
 */
function ggr_onboarding_handle_collecting_personal( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_personal_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_personal_nonce'], 'ggr_collecting_personal' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt deze gegevens niet voor een andere gebruiker wijzigen.' );
    }

    $required_fields = array(
        'ggr_kyc_full_name',
        'ggr_kyc_birth_date',
        'ggr_kyc_address',
        'ggr_kyc_postcode',
        'ggr_kyc_city_country',
        'ggr_kyc_bsn',
        'ggr_kyc_iban_name',
        'ggr_kyc_iban',
        'ggr_kyc_pep',
        'ggr_kyc_us_person',
    );

    foreach ( $required_fields as $key ) {
        if ( empty( $_POST[ $key ] ) ) {
            return new WP_Error( 'missing_fields', 'Vul alle verplichte velden in.' );
        }
    }

    // Basisvalidatie IBAN zou je hier nog kunnen toevoegen.

    $fields = array(
        'ggr_kyc_full_name',
        'ggr_kyc_birth_date',
        'ggr_kyc_address',
        'ggr_kyc_postcode',
        'ggr_kyc_city_country',
        'ggr_kyc_bsn',
        'ggr_kyc_iban_name',
        'ggr_kyc_iban',
        'ggr_kyc_company',
        'ggr_kyc_kvk',
        'ggr_kyc_pep',
        'ggr_kyc_us_person',
    );

    foreach ( $fields as $key ) {
        if ( isset( $_POST[ $key ] ) ) {
            $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            update_user_meta( $user_id, $key, $value );
        }
    }

    return true;
}

/**
 * Stap 2 collecting: bestanden uploaden
 */
function ggr_onboarding_handle_collecting_files( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_files_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_files_nonce'], 'ggr_collecting_files' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt geen documenten voor een andere gebruiker uploaden.' );
    }

    // Minimaal: identiteitsbewijs en herkomst middelen.
    if ( empty( $_FILES['ggr_doc_id']['name'] ) || empty( $_FILES['ggr_doc_funds']['name'] ) ) {
        return new WP_Error( 'missing_files', 'Upload minimaal je identiteitsbewijs en bewijs van herkomst middelen.' );
    }

    // Gebruik je bestaande upload helper.
    ggr_onboarding_handle_file_upload( 'ggr_doc_id', $user_id );
    ggr_onboarding_handle_file_upload( 'ggr_doc_funds', $user_id );

    if ( ! empty( $_FILES['ggr_doc_other']['name'] ) ) {
        ggr_onboarding_handle_file_upload( 'ggr_doc_other', $user_id );
    }

    return true;
}

if ( ! function_exists( 'ggr_onboarding_get_side_block_content' ) ) {
    /**
     * Content voor het blok rechts van de steps, per status
     */
    function ggr_onboarding_get_side_block_content( $status ) {
        // Default – fallback als er een onbekende status is
        $content = array(
            'title'       => 'Volgende stap in je onboarding',
            'description' => 'We begeleiden je stap voor stap. Volg de aanwijzingen in het overzicht links.',
            'bullets'     => array(),
        );

        switch ( $status ) {
            case 'register':
                $content['title']       = 'Bevestig je e-mailadres';
                $content['description'] = 'We hebben je registratie ontvangen. Om verder te gaan moet je eerst je e-mailadres bevestigen.';
                $content['bullets']     = array(
                    'Open de e-mail die we je zojuist gestuurd hebben.',
                    'Klik op de verificatielink in die e-mail.',
                    'Controleer je SPAM-map als je niets ontvangt.',
                );
                break;

            case 'confirmed':
                $content['title']       = 'Wacht op documentatieverzoek';
                $content['description'] = 'Je e-mailadres is bevestigd. De volgende stap is het aanleveren van aanvullende documentatie (KYC).';
                $content['bullets']     = array(
                    'Houd je e-mail in de gaten voor ons documentatieverzoek.',
                    'Zorg dat je een geldig identiteitsbewijs en relevante gegevens bij de hand hebt.',
                );
                break;

            case 'collecting':
                $content['title']       = 'Vul je gegevens in en upload documenten';
                $content['description'] = 'In deze stap vragen we eerst om je persoonlijke gegevens, daarna kun je je documenten uploaden.';
                $content['bullets']     = array(
                    'Stap 1: vul je persoonlijke gegevens in, zoals in het inschrijfformulier.',
                    'Stap 2: upload je identiteitsbewijs en bewijs van herkomst van middelen.',
                    'Controleer goed of alles compleet en leesbaar is.',
                );
                break;

            case 'validating':
                $content['title']       = 'We controleren je gegevens';
                $content['description'] = 'Je documentatie is ontvangen. We zijn bezig met het controleren van je gegevens.';
                $content['bullets']     = array(
                    'Je hoeft op dit moment niets te doen.',
                    'Als er iets ontbreekt of onduidelijk is, nemen we contact met je op.',
                );
                break;

            case 'sign_contract':
                $content['title']       = 'Teken je overeenkomst';
                $content['description'] = 'Je staat op het punt officieel investeerder te worden. De overeenkomst/termsheet moet nog ondertekend worden.';
                $content['bullets']     = array(
                    'Controleer de overeenkomst die je van ons hebt ontvangen.',
                    'Onderteken digitaal of volgens de meegestuurde instructies.',
                );
                break;

            case 'transfer_completed':
                $content['title']       = 'Je storting wordt verwerkt';
                $content['description'] = 'De storting is gedaan en wordt verwerkt in onze administratie.';
                $content['bullets']     = array(
                    'Bewaar je betalingsbewijs voor je eigen administratie.',
                    'Zodra alles verwerkt is, verschijnt je positie in het portal.',
                );
                break;

            case 'active_participant':
                $content['title']       = 'Je bent actief deelnemer';
                $content['description'] = 'Je onboarding is afgerond. Je hebt (of krijgt) volledige toegang tot het GGR Portal en je investeringen.';
                $content['bullets']     = array(
                    'Log in op het portal om je positie en rapportages te bekijken.',
                    'Pas je gegevens aan als er iets verandert (bijv. adres of contactgegevens).',
                );
                break;
        }

        return $content;
    }
}
