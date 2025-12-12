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
            <div class="ggr-onboarding-header">
                <div class="ggr-onboarding-logo">
                    <img
                        src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20MIF%20full%20logo%20-%20Blue%20-%20Black.png"
                        alt="GGR Monthly Income Fund"
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
                            <p>Ondergetekende (de "Participant") wil Participaties aankopen in het GGR Monthly Income Fund (het "Fonds") voor een bedrag van:</p>
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
                                    <p>Door het formulier te verzenden verklaar je akkoord te zijn met de voorwaarden van het GGR Monthly Income Fund en bevestig je dat de gegevens juist zijn.</p>
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

    // Flags voor de nieuwe stappen in de collecting-fase.
    $collecting_request_done     = get_user_meta( $user_id, 'ggr_collecting_request_done', true );
    $collecting_type_done        = get_user_meta( $user_id, 'ggr_collecting_type_done', true );
    $collecting_personal_done    = get_user_meta( $user_id, 'ggr_collecting_personal_done', true );
    $collecting_origin_done      = get_user_meta( $user_id, 'ggr_collecting_origin_done', true );
    $collecting_files_done       = get_user_meta( $user_id, 'ggr_collecting_files_done', true );

    // Bepaal actieve stap in collecting-fase en maak wisselen mogelijk via query-parameter.
    $available_collecting_steps = array( 'request', 'type', 'personal', 'origin', 'files' );
    $requested_collecting_step  = isset( $_GET['collecting_step'] )
        ? sanitize_key( wp_unslash( $_GET['collecting_step'] ) )
        : '';
        
    $step_order = array(
        'request'  => $collecting_request_done,
        'type'     => $collecting_type_done,
        'personal' => $collecting_personal_done,
        'origin'   => $collecting_origin_done,
        'files'    => $collecting_files_done,
    );

    $default_collecting_step = 'request';
    foreach ( $step_order as $step_key => $is_done ) {
        if ( empty( $is_done ) ) {
            $default_collecting_step = $step_key;
            break;
        }
    }

    $current_collecting_step = in_array( $requested_collecting_step, $available_collecting_steps, true )
        ? $requested_collecting_step
        : $default_collecting_step;
        
    /**
     * Collecting-fase: POST-afhandeling
     */
    if ( 'collecting' === $status ) {

        // Stap 1: verzoek om uitgifte participaties.
        if ( isset( $_POST['ggr_collecting_request_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_request( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][]      = $result->get_error_message();
                $current_collecting_step  = 'request';
            } else {
                $messages['success']      = 'Je verzoek om uitgifte van participaties is opgeslagen.';
                $collecting_request_done  = 1;
                update_user_meta( $user_id, 'ggr_collecting_request_done', 1 );
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $current_collecting_step  = 'type';
            }
        }

        // Stap 2: zakelijk of privé.
        if ( isset( $_POST['ggr_collecting_type_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_participation_type( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][]     = $result->get_error_message();
                $current_collecting_step = 'type';
            } else {
                $messages['success']     = 'Je keuze voor zakelijke of privé-participatie is opgeslagen.';
                $collecting_type_done    = 1;
                update_user_meta( $user_id, 'ggr_collecting_type_done', 1 );
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $current_collecting_step = 'personal';
            }
        }

        // Stap 3: persoonlijke gegevens.
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
                $current_collecting_step = 'origin';
            }
        }

        // Stap 4: herkomst van het geld.
        if ( isset( $_POST['ggr_collecting_origin_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_origin( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][]     = $result->get_error_message();
                $current_collecting_step = 'origin';
            } else {
                $messages['success']    = 'Je toelichting op de herkomst van het in te leggen bedrag is opgeslagen.';
                $collecting_origin_done = 1;
                update_user_meta( $user_id, 'ggr_collecting_origin_done', 1 );
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $current_collecting_step = 'files';      
                }
        }

        // Stap 5: documenten uploaden.
        if ( isset( $_POST['ggr_collecting_files_submit'] ) ) {
            $result = ggr_onboarding_handle_collecting_files( $user_id );

            if ( is_wp_error( $result ) ) {
                $messages['error'][]     = $result->get_error_message();
                $current_collecting_step = 'files';
            } else {
                $messages['success'] = 'Je documenten zijn ontvangen. Wij gaan hiermee aan de slag.';
                update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
                $collecting_files_done = 1;
                update_user_meta( $user_id, 'ggr_collecting_files_done', 1 );
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
                                    'request'  => '1. Verzoek om uitgifte Participaties',
                                    'type'     => '2. Wordt er vanaf zakelijk of privé geparticipeerd',
                                    'personal' => '3. Persoonlijke gegevens',
                                    'origin'   => '4. Herkomst van het in het Fonds te beleggen geld',
                                    'files'    => '5. Mee te sturen documenten',
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

                            <?php if ( 'request' === $current_collecting_step ) : ?>
                                <!-- STAP 1: VERZOEK -->
                                <h3>Stap 1: Verzoek om uitgifte Participaties</h3>
                                <p>Geef aan voor welk bedrag je participaties wilt aankopen in het GGR Monthly Income Fund.</p>

                                <form method="post" class="ggr-onboarding-form">
                                    <?php wp_nonce_field( 'ggr_collecting_request', 'ggr_collecting_request_nonce' ); ?>
                                    <?php
                                    $requested_amount = get_user_meta( $user_id, 'ggr_investment_amount', true );
                                    if ( ! $requested_amount ) {
                                        $requested_amount = get_user_meta( $user_id, 'ggr_participation_amount', true );
                                    }
                                    ?>
                                    <p class="ggr-field">
                                        <label for="ggr_participation_amount">Bedrag (minimaal € 5.000) *</label>
                                        <input type="number" id="ggr_participation_amount" name="ggr_participation_amount" min="5000" step="500"
                                               value="<?php echo esc_attr( $requested_amount ); ?>" required>
                                    </p>

                                    <div class="ggr-login-actions">
                                        <button type="submit" name="ggr_collecting_request_submit" value="1" class="ggr-login-submit">
                                            Opslaan en verder
                                        </button>
                                    </div>
                                </form>

                            <?php elseif ( 'type' === $current_collecting_step ) : ?>
                                <!-- STAP 2: TYPE -->
                                <h3>Stap 2: Wordt er vanaf zakelijk of privé geparticipeerd</h3>
                                <p>Geef aan of de participaties vanuit een privé- of zakelijke entiteit worden gehouden.</p>

                                <form method="post" class="ggr-onboarding-form">
                                    <?php wp_nonce_field( 'ggr_collecting_type', 'ggr_collecting_type_nonce' ); ?>

                                    <?php $participation_profile = get_user_meta( $user_id, 'ggr_participation_profile', true ); ?>
                                    <p class="ggr-field">
                                        <label><input type="radio" name="ggr_participation_profile" value="prive" <?php checked( $participation_profile, 'prive' ); ?> required> Privé</label>
                                        <label style="margin-left:12px;"><input type="radio" name="ggr_participation_profile" value="zakelijk" <?php checked( $participation_profile, 'zakelijk' ); ?>> Zakelijk (vul persoonlijke gegevens in als contactpersoon van het bedrijf)</label>
                                    </p>

                                    <div class="ggr-login-actions">
                                        <button type="submit" name="ggr_collecting_type_submit" value="1" class="ggr-login-submit">
                                            Opslaan en verder
                                        </button>
                                    </div>
                                </form>

                            <?php elseif ( 'personal' === $current_collecting_step ) : ?>
                                <!-- STAP 3: PERSOONLIJKE GEGEVENS -->
                                <h3>Stap 3: Persoonlijke gegevens</h3>
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
                                            <label for="ggr_kyc_city_country">Plaats *</label>
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
                            <?php elseif ( 'origin' === $current_collecting_step ) : ?>

                                <!-- STAP 4: HERKOMST GELD -->
                                <h3>Stap 4: Herkomst van het in het Fonds te beleggen geld</h3>
                                <p>Geef aan waar het te beleggen bedrag vandaan komt en licht kort toe. Kruis alles aan wat van toepassing is.</p>

                                <form method="post" class="ggr-onboarding-form">
                                    <?php wp_nonce_field( 'ggr_collecting_origin', 'ggr_collecting_origin_nonce' ); ?>

                                    <p class="ggr-field">
                                        <label for="ggr_origin_country">Land van herkomst van de middelen *</label>
                                        <?php
                                        $countries   = ggr_get_countries_nl();
                                        $selected    = get_user_meta( $user_id, 'ggr_origin_country', true );
                                        $placeholder = $selected ? '' : '<option value="">Selecteer een land</option>';
                                        ?>
                                        <select id="ggr_origin_country" name="ggr_origin_country" required>
                                            <?php echo wp_kses_post( $placeholder ); ?>
                                            <?php foreach ( $countries as $country ) : ?>
                                                <option value="<?php echo esc_attr( $country ); ?>" <?php selected( $selected, $country ); ?>>
                                                    <?php echo esc_html( $country ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_origin_salary">Ik ben in loondienst</label>
                                            <textarea id="ggr_origin_salary" name="ggr_origin_salary" rows="2" placeholder="Vermeld werkgever en hoogte/jaarinkomen."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_salary', true ) ); ?></textarea>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_origin_business">Ondernemingsactiviteiten</label>
                                            <textarea id="ggr_origin_business" name="ggr_origin_business" rows="2" placeholder="Omschrijf de activiteit en ontvangen bedragen."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_business', true ) ); ?></textarea>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_origin_rental_dividend">Opbrengsten rente/dividend/huur</label>
                                            <textarea id="ggr_origin_rental_dividend" name="ggr_origin_rental_dividend" rows="2" placeholder="Bijv. huurinkomsten uit vastgoed, dividend, rente."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_rental_dividend', true ) ); ?></textarea>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_origin_savings">Vermogen, erfenis of pensioen/ontslagvergoeding</label>
                                            <textarea id="ggr_origin_savings" name="ggr_origin_savings" rows="2" placeholder="Specificeer vermogen of ontvangen erfenis/uitkering."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_savings', true ) ); ?></textarea>
                                        </p>
                                    </div>

                                    <div class="ggr-two-cols">
                                        <p class="ggr-field">
                                            <label for="ggr_origin_sale">Opbrengst verkoop (bijv. vastgoed/aandelen)</label>
                                            <textarea id="ggr_origin_sale" name="ggr_origin_sale" rows="2" placeholder="Noem het object en het verkoopbedrag."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_sale', true ) ); ?></textarea>
                                        </p>
                                        <p class="ggr-field">
                                            <label for="ggr_origin_loan">Ontvangen lening</label>
                                            <textarea id="ggr_origin_loan" name="ggr_origin_loan" rows="2" placeholder="Geef de verstrekkende partij en voorwaarden aan."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_loan', true ) ); ?></textarea>
                                        </p>
                                    </div>

                                    <p class="ggr-field">
                                        <label for="ggr_origin_other">Overige herkomst / toelichting</label>
                                        <textarea id="ggr_origin_other" name="ggr_origin_other" rows="3" placeholder="Vul in indien de herkomst anders is dan hierboven beschreven."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_other', true ) ); ?></textarea>
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_origin_transaction_details">Rekeningnummers / transactiekenmerken</label>
                                        <textarea id="ggr_origin_transaction_details" name="ggr_origin_transaction_details" rows="3" placeholder="Geef bankrekening, transactiedatum of referentie voor de storting."><?php echo esc_textarea( get_user_meta( $user_id, 'ggr_origin_transaction_details', true ) ); ?></textarea>
                                    </p>

                                    <div class="ggr-login-actions">
                                        <button type="submit" name="ggr_collecting_origin_submit" value="1" class="ggr-login-submit">
                                            Opslaan en verder
                                        </button>
                                    </div>
                                </form>
                                
                            <?php else : ?>

                                <!-- STAP 5: BESTANDEN UPLOADEN -->
                                <h3>Stap 5: Mee te sturen documenten</h3>
                                <p>Upload de gevraagde documenten zodat we je onboarding kunnen afronden. De lijst past zich aan op basis van zakelijk of privé.</p>

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

                                    <hr>

                                    <p><strong>Specifiek voor zakelijke participanten</strong></p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_registration">Recent uittreksel Kamer van Koophandel *</label>
                                        <input type="file" id="ggr_doc_registration" name="ggr_doc_registration" accept=".pdf,.jpg,.jpeg,.png">
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_ubo">Uittreksel UBO-register / aandeelhouderslijst *</label>
                                        <input type="file" id="ggr_doc_ubo" name="ggr_doc_ubo" accept=".pdf,.jpg,.jpeg,.png">
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_share_register">Aandeelhoudersregister of overeenkomst (optioneel)</label>
                                        <input type="file" id="ggr_doc_share_register" name="ggr_doc_share_register" accept=".pdf,.jpg,.jpeg,.png">
                                    </p>

                                    <p class="ggr-field">
                                        <label for="ggr_doc_other">Overige documenten</label>
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
 * Stap 1 collecting: verzoek om uitgifte participaties
 */
function ggr_onboarding_handle_collecting_request( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_request_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_request_nonce'], 'ggr_collecting_request' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt deze gegevens niet voor een andere gebruiker wijzigen.' );
    }

    $amount = isset( $_POST['ggr_participation_amount'] ) ? floatval( $_POST['ggr_participation_amount'] ) : 0;

    if ( $amount < 5000 ) {
        return new WP_Error( 'invalid_amount', 'Het minimale bedrag voor een inschrijving is € 5.000.' );
    }

    update_user_meta( $user_id, 'ggr_participation_amount', $amount );
    update_user_meta( $user_id, 'ggr_investment_amount', $amount );

    return true;
}


/**
 * Stap 2 collecting: zakelijk of privé
 */
function ggr_onboarding_handle_collecting_participation_type( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_type_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_type_nonce'], 'ggr_collecting_type' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt deze gegevens niet voor een andere gebruiker wijzigen.' );
    }

    $profile = isset( $_POST['ggr_participation_profile'] ) ? sanitize_key( wp_unslash( $_POST['ggr_participation_profile'] ) ) : '';

    if ( ! in_array( $profile, array( 'prive', 'zakelijk' ), true ) ) {
        return new WP_Error( 'missing_type', 'Geef aan of je privé of zakelijk participeert.' );
    }

    update_user_meta( $user_id, 'ggr_participation_profile', $profile );

    return true;
}


/**
 * Stap 3 collecting: persoonlijke gegevens opslaan
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
        'ggr_kyc_birth_country',
        'ggr_kyc_birth_place',
        'ggr_kyc_nationality',
        'ggr_kyc_phone',
        'ggr_kyc_id_expiry',
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
        'ggr_kyc_birth_country',
        'ggr_kyc_birth_place',
        'ggr_kyc_nationality',
        'ggr_kyc_phone',
        'ggr_kyc_id_expiry',
        'ggr_kyc_bsn',
        'ggr_kyc_iban_name',
        'ggr_kyc_iban',
        'ggr_kyc_company',
        'ggr_kyc_kvk',
        'ggr_kyc_relation',
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
 * Stap 4 collecting: herkomst van het geld
 */
function ggr_onboarding_handle_collecting_origin( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_origin_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_origin_nonce'], 'ggr_collecting_origin' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt deze gegevens niet voor een andere gebruiker wijzigen.' );
    }

    $origin_country = isset( $_POST['ggr_origin_country'] ) ? sanitize_text_field( wp_unslash( $_POST['ggr_origin_country'] ) ) : '';

    $origin_fields = array(
        'ggr_origin_salary',
        'ggr_origin_business',
        'ggr_origin_rental_dividend',
        'ggr_origin_savings',
        'ggr_origin_sale',
        'ggr_origin_loan',
        'ggr_origin_other',
    );

    $has_origin_detail = false;
    foreach ( $origin_fields as $origin_key ) {
        if ( ! empty( $_POST[ $origin_key ] ) ) {
            $has_origin_detail = true;
            break;
        }
    }

    if ( ! $origin_country || ! $has_origin_detail ) {
        return new WP_Error( 'missing_origin', 'Vul minimaal één herkomstoptie in en geef het land van herkomst op.' );
    }

    update_user_meta( $user_id, 'ggr_origin_country', $origin_country );

    foreach ( $origin_fields as $origin_key ) {
        if ( isset( $_POST[ $origin_key ] ) ) {
            $value = wp_kses_post( wp_unslash( $_POST[ $origin_key ] ) );
            update_user_meta( $user_id, $origin_key, $value );
        }
    }

    if ( isset( $_POST['ggr_origin_transaction_details'] ) ) {
        update_user_meta( $user_id, 'ggr_origin_transaction_details', wp_kses_post( wp_unslash( $_POST['ggr_origin_transaction_details'] ) ) );
    }

    return true;
}

/**
 * Stap 5 collecting: bestanden uploaden
 */
function ggr_onboarding_handle_collecting_files( $user_id ) {

    if ( ! isset( $_POST['ggr_collecting_files_nonce'] ) ||
         ! wp_verify_nonce( $_POST['ggr_collecting_files_nonce'], 'ggr_collecting_files' ) ) {
        return new WP_Error( 'invalid_nonce', 'Ongeldige sessie. Probeer het opnieuw.' );
    }

    if ( get_current_user_id() !== (int) $user_id ) {
        return new WP_Error( 'invalid_user', 'Je kunt geen documenten voor een andere gebruiker uploaden.' );
    }
    
    $participation_profile = get_user_meta( $user_id, 'ggr_participation_profile', true );

    // Minimaal: identiteitsbewijs en herkomst middelen.
    $missing_required = array();
    if ( empty( $_FILES['ggr_doc_id']['name'] ) ) {
        $missing_required[] = 'identiteitsbewijs';
    }
    if ( empty( $_FILES['ggr_doc_funds']['name'] ) ) {
        $missing_required[] = 'bewijs van herkomst middelen';
    }

    if ( 'zakelijk' === $participation_profile ) {
        if ( empty( $_FILES['ggr_doc_registration']['name'] ) ) {
            $missing_required[] = 'uittreksel Kamer van Koophandel';
        }
        if ( empty( $_FILES['ggr_doc_ubo']['name'] ) ) {
            $missing_required[] = 'UBO-registratie of aandeelhouderslijst';
        }
    }

    if ( ! empty( $missing_required ) ) {
        return new WP_Error( 'missing_files', 'Ontbrekende documenten: ' . implode( ', ', $missing_required ) . '.' );
    }

    // Gebruik je bestaande upload helper.
    $upload_fields = array(
        'ggr_doc_id',
        'ggr_doc_funds',
        'ggr_doc_registration',
        'ggr_doc_ubo',
        'ggr_doc_share_register',
        'ggr_doc_other',
    );

    foreach ( $upload_fields as $upload_field ) {
        ggr_onboarding_handle_file_upload( $upload_field, $user_id );
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
                $content['title']       = 'Doorloop alle stappen in Collecting';
                $content['description'] = 'We vragen je om je investeringsverzoek, type deelname, persoonlijke gegevens, herkomst van het geld en de benodigde documenten in te vullen.';
                $content['bullets']     = array(
                    'Stap 1: geef je gewenste participatiebedrag op.',
                    'Stap 2: kies of je privé of zakelijk participeert.',
                    'Stap 3: vul je persoonlijke gegevens in.',
                    'Stap 4: licht de herkomst van het te beleggen geld toe.',
                    'Stap 5: upload de juiste documenten (privé of zakelijk).',
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
