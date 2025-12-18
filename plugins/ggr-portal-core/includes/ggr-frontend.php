<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Include account-module from core plugin (for [ggr_portal_account]).
 */
if ( defined( 'GGR_PORTAL_CORE_PATH' ) && file_exists( GGR_PORTAL_CORE_PATH . 'includes/ggr-account.php' ) ) {
    require_once GGR_PORTAL_CORE_PATH . 'includes/ggr-account.php';
}

/**
 * Formatting helpers
 */
function ggrp_fe_format_money( $value ) {
    if ( $value === null ) {
        return '-';
    }
    $value = (float) $value;
    return '&euro;&nbsp;' . number_format( $value, 2, ',', '.' );
}

function ggrp_fe_format_signed_money( $value ) {
    if ( $value === null ) {
        return '-';
    }
    $value = (float) $value;
    $sign  = $value > 0 ? '+' : ( $value < 0 ? '-' : '' );
    $abs   = abs( $value );
    return $sign . '&euro;&nbsp;' . number_format( $abs, 2, ',', '.' );
}

function ggrp_fe_format_signed_number( $value, $decimals = 4 ) {
    if ( $value === null ) {
        return '-';
    }
    $value = (float) $value;
    $sign  = $value > 0 ? '+' : ( $value < 0 ? '-' : '' );
    $abs   = abs( $value );
    return $sign . number_format( $abs, $decimals, ',', '.' );
}

function ggrp_fe_format_signed_percent( $value ) {
    if ( $value === null ) {
        return '-';
    }
    $value = (float) $value;
    $sign  = $value > 0 ? '+' : ( $value < 0 ? '-' : '' );
    $abs   = abs( $value );
    return $sign . number_format( $abs, 2, ',', '.' ) . '%';
}

/**
 * Zorg dat Chart.js slechts één keer wordt ingeladen.
 */
function ggrp_fe_ensure_chartjs() {
    if ( defined( 'GGR_FE_CHARTJS_LOADED' ) ) {
        return;
    }

    define( 'GGR_FE_CHARTJS_LOADED', true );
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php
}

/**
 * Nederlandse datum-helpers
 */
function ggr_portal_format_date_nl( $value ) {
    if ( empty( $value ) ) {
        return '';
    }

    $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( 'd-m-Y', $timestamp );
}

function ggr_portal_format_datetime_nl( $value ) {
    if ( empty( $value ) ) {
        return '';
    }

    $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( 'd-m-Y H:i', $timestamp );
}

/**
 * Redirect alle frontend 404's naar het portal/dashboard.
 *
 * - Alleen op de frontend (niet in admin, niet bij AJAX, niet bij REST API)
 * - Alleen als het echt een 404 is
 * - Ingelogd  → naar dashboard
 * - Niet ingelogd → naar loginpagina
 */
add_action( 'template_redirect', 'ggr_portal_redirect_404_to_dashboard' );

function ggr_portal_redirect_404_to_dashboard() {
    // Niet in admin-omgeving
    if ( is_admin() ) {
        return;
    }

    // Ajax & REST met rust laten
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    // Alleen bij echte 404's
    if ( ! is_404() ) {
        return;
    }

    // Bepaal doel-URL
    if ( is_user_logged_in() ) {
        // PAS DIT AAN naar jouw echte dashboard-URL
        $target_url = home_url( '/' );
    } else {
        // Login met redirect terug naar dashboard na inloggen
        $target_url = wp_login_url( home_url( '/' ) );
    }

    // Klein beetje paranoia: zorg dat we niet in een redirect-loop komen
    $current_url = ( ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
    if ( trailingslashit( $current_url ) === trailingslashit( $target_url ) ) {
        return;
    }

    // 302 is hier veiliger dan 301: je wilt niet dat browsers/zoeken
    // het voor altijd cachen, mocht de URL later wel bestaan.
    wp_safe_redirect( $target_url, 302 );
    exit;
}


/**
 * Backwards-compatible alias; sommige templates gebruiken nog de oude naam
 * ggrp_fe_format_percent_signed().
 */
if ( ! function_exists( 'ggrp_fe_format_percent_signed' ) ) {
    function ggrp_fe_format_percent_signed( $value ) {
        return ggrp_fe_format_signed_percent( $value );
    }
}

/**
 * Bepaal CSS-klasse voor een chip obv teken van de waarde.
 */
function ggrp_fe_get_chip_class( $value ) {
    if ( $value === null ) {
        return 'ggrp-fe-chip'; // neutraal, geen waarde
    }

    $v = (float) $value;

    if ( $v > 0 ) {
        return 'ggrp-fe-chip ggrp-fe-chip--up';
    }
    if ( $v < 0 ) {
        return 'ggrp-fe-chip ggrp-fe-chip--down';
    }

    return 'ggrp-fe-chip'; // 0 precies: neutraal
}

/**
 * Registreer de rendering van de forecast-grafiek in de footer (houd shortcodes schoon).
 */
function ggrp_fe_queue_forecast_script() {
    if ( has_action( 'wp_footer', 'ggrp_fe_render_forecast_script' ) ) {
        return;
    }

    add_action( 'wp_footer', 'ggrp_fe_render_forecast_script', 20 );
}

function ggrp_fe_render_forecast_script() {
    ggrp_fe_ensure_chartjs();
    ?>
    <script>
    (function() {
        if (typeof Chart === 'undefined') {
            return;
        }

        const canvases = document.querySelectorAll('.ggr-fe-forecast-canvas');
        if (!canvases.length) return;

        const monthsLong = [
            'januari','februari','maart','april','mei','juni',
            'juli','augustus','september','oktober','november','december'
        ];
        const monthsShort = [
            'Jan','Feb','Mrt','Apr','Mei','Jun',
            'Jul','Aug','Sep','Okt','Nov','Dec'
        ];

        const formatMonthShortFromYM = function(ym) {
            const parts = (ym || '').split('-');
            if (parts.length < 2) return ym || '';
            const y = Number(parts[0]);
            const m = Number(parts[1]);
            if (!y || !m) return ym;
            const monthName = monthsShort[m-1] || '';
            return monthName + "'" + String(y).slice(2);
        };

        const formatMonthLongFromYM = function(ym) {
            const parts = (ym || '').split('-');
            if (parts.length < 2) return ym || '';
            const y = Number(parts[0]);
            const m = Number(parts[1]);
            if (!y || !m) return ym;
            const monthName = monthsLong[m-1] || '';
            return monthName + ' ' + y;
        };

        const formatPercent = function(value) {
            const val = Number(value) || 0;
            return val.toLocaleString('nl-NL', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '%';
        };

        const yAxisPercent = {
            grid: {
                display: false,
                drawBorder: false
            },
            ticks: {
                callback: (value) => formatPercent(value)
            }
        };

        const baseLayout = {
            padding: { top: 8, right: 16, bottom: 8, left: 16 }
        };

        canvases.forEach(function(canvas) {
            const rawLabels     = canvas.dataset.forecastLabels ? JSON.parse(canvas.dataset.forecastLabels) : [];
            const rawActual     = canvas.dataset.forecastActual ? JSON.parse(canvas.dataset.forecastActual) : [];
            const rawProjection = canvas.dataset.forecastProjection ? JSON.parse(canvas.dataset.forecastProjection) : [];

            if (!rawLabels.length) return;

            const hasActual   = rawActual.some((val) => val !== null && !Number.isNaN(val));
            const hasForecast = rawProjection.some((val) => val !== null && !Number.isNaN(val));
            if (!hasActual && !hasForecast) return;

            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: rawLabels.map(formatMonthShortFromYM),
                    datasets: [
                        {
                            label: 'Realisatie',
                            data: rawActual,
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointHoverRadius: 4,
                            pointHitRadius: 10,
                            borderColor: '#709aa7',
                            backgroundColor: 'rgba(112,154,167,0.18)',
                            spanGaps: false,
                        },
                        {
                            label: 'Prognose',
                            data: rawProjection,
                            fill: false,
                            tension: 0.3,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointHitRadius: 10,
                            borderColor: '#111827',
                            borderDash: [6, 6],
                            spanGaps: true,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: baseLayout,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: true },
                        tooltip: {
                            displayColors: true,
                            callbacks: {
                                title: function(items) {
                                    if (!items.length) return '';
                                    const idx = items[0].dataIndex;
                                    const key = rawLabels[idx];
                                    return formatMonthLongFromYM(key);
                                },
                                label: function(context) {
                                    const value = context.parsed.y;
                                    if (value === null || Number.isNaN(value)) {
                                        return '';
                                    }
                                    return context.dataset.label + ': ' + formatPercent(value);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            offset: false,
                            ticks: { maxTicksLimit: 12 },
                            grid: {
                                display: false,
                                drawBorder: false
                            }
                        },
                        y: yAxisPercent
                    }
                }
            });
        });
    })();
    </script>
    <?php
}

/**
 * Front-end helper: zorg dat portal shortcodes alleen werken voor ingelogde gebruikers.
 * - Geeft NULL terug als alles oké is
 * - Geeft HTML terug met een melding als de user niet is ingelogd
 */
if ( ! function_exists( 'ggrp_fe_require_login' ) ) {
    function ggrp_fe_require_login() {
        if ( is_user_logged_in() ) {
            return null;
        }

        $login_url = esc_url( home_url( '/login/' ) );

        ob_start();
        ?>
        <section class="ggrp-fe">
            <h1>Dashboard</h1>
            <p>
                Je bent niet ingelogd.
                <a href="<?php echo $login_url; ?>">Log in</a> om je dashboard te bekijken.
            </p>
        </section>
        <?php
        return ob_get_clean();
    }
}

/**
 * Shortcode: [ggr_portal_dashboard]
 * Dashboard-overzicht met KPI's en 3 grafieken.
 */
function ggrp_fe_dashboard_shortcode( $atts ) {
    // 0) Login check via helper
    $maybe_error = ggrp_fe_require_login();
    if ( $maybe_error !== null ) {
        return $maybe_error;
    }

    $user    = wp_get_current_user();
    $naam    = $user && $user->display_name ? $user->display_name : 'investeerder';
    $user_id = get_current_user_id();

    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) || ! $user_id ) {
        return '<section class="ggrp-fe"><h1>Dashboard</h1><p>Historie niet beschikbaar.</p></section>';
    }

    $history_raw = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history_raw ) {
        return '<section class="ggrp-fe"><h1>Dashboard</h1><p>Nog geen historie beschikbaar.</p></section>';
    }

    // History oplopend op datum
    $history = array_values( $history_raw );
    usort(
        $history,
        function( $a, $b ) {
            return strcmp( $a->datum, $b->datum );
        }
    );

    // Laatste transactie (hoogste datum)
    $last_tx_row = end( $history );
    reset( $history );

    // 1) Timeseries + dag- en maand-snapshots opbouwen
    global $wpdb;

    $monthly         = []; // "YYYY-MM" => snapshot
    $daily_snapshots = []; // "Y-m-d"   => snapshot
    $posDates        = []; // ruwe datums Y-m-d (voor tooltips)
    $posValues       = [];

    $first_row  = reset( $history );
    $first_date = ( $first_row && ! empty( $first_row->datum ) ) ? $first_row->datum : null;

    $stock_rows  = [];
    $stock_table = $wpdb->prefix . 'ggr_stock_prices';

    if ( $first_date ) {
        $stock_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT price_date, price_value
                 FROM {$stock_table}
                 WHERE price_date >= %s
                 ORDER BY price_date ASC",
                $first_date
            )
        );
    }

    // Geschatte waardering op basis van koersreeks
    if ( ! empty( $stock_rows ) ) {
        $cumul_inleg         = 0.0;
        $cumul_opname        = 0.0;
        $cumul_distributie   = 0.0;
        $cumul_participaties = 0.0;

        $history_index = 0;
        $history_count = count( $history );

        foreach ( $stock_rows as $stock ) {
            $date_stock = $stock->price_date;
            $dt         = DateTime::createFromFormat( 'Y-m-d', $date_stock );
            if ( ! $dt ) {
                continue;
            }

            // Alle historie-regels t/m deze koersdatum verwerken
            while ( $history_index < $history_count ) {
                $h = $history[ $history_index ];

                if ( $h->datum > $date_stock ) {
                    break;
                }

                $cumul_inleg         += (float) $h->inlegbedrag;
                $cumul_opname        += (float) $h->opnamebedrag;
                $cumul_distributie   += (float) $h->distributievergoeding;
                $cumul_participaties += (float) $h->nieuwe_participaties - (float) $h->verkochte_participaties;

                $history_index++;
            }

            $netto_inleg  = $cumul_inleg - $cumul_opname;
            $units_totaal = $cumul_participaties;

            // Voor er iets ingelegd is: geen relevante waardering
            if ( $netto_inleg <= 0 && $units_totaal <= 0 ) {
                continue;
            }

            $price         = (float) $stock->price_value;
            $positiewaarde = $units_totaal * $price;

            // Investeringsrendement: alleen marktwaarde t.o.v. netto inleg (dividend niet meetellen)
            $investeringsrendement = null;
            if ( $netto_inleg > 0 && $positiewaarde > 0 ) {
                $investeringsrendement = ( $positiewaarde / $netto_inleg - 1 ) * 100;
            }

            $ym_key = $dt->format( 'Y-m' );
            $year   = (int) $dt->format( 'Y' );
            $month  = (int) $dt->format( 'm' );
            $d_key  = $dt->format( 'Y-m-d' );

            // Dag-snapshot
            $daily_snapshots[ $d_key ] = [
                'jaar'                  => $year,
                'maand'                 => $month,
                'positiewaarde'         => $positiewaarde,
                'investeringsrendement' => $investeringsrendement,
                'totaal_participaties'  => $cumul_participaties,
                'dividend_cumul'        => $cumul_distributie,
            ];

            // Per maand altijd de LAATSTE waardering bewaren
            $monthly[ $ym_key ] = $daily_snapshots[ $d_key ];

            // Voor bovenste grafiek: waardering per koersdatum
            $posDates[]  = $d_key;
            $posValues[] = round( $positiewaarde, 2 );
        }
    }

    // Fallback: als er nog geen GGR Stock Prices zijn, gebruik oude logica (alleen inleg + dividend)
    if ( empty( $monthly ) ) {
        $cumul_inleg         = 0.0;
        $cumul_opname        = 0.0;
        $cumul_distributie   = 0.0;
        $cumul_participaties = 0.0;

        $monthly         = [];
        $daily_snapshots = [];
        $posDates        = [];
        $posValues       = [];

        foreach ( $history as $row ) {
            $datum = $row->datum;
            $dt    = DateTime::createFromFormat( 'Y-m-d', $datum );
            if ( ! $dt ) {
                continue;
            }

            $ym_key = $dt->format( 'Y-m' );
            $year   = (int) $dt->format( 'Y' );
            $month  = (int) $dt->format( 'm' );
            $d_key  = $dt->format( 'Y-m-d' );

            $cumul_inleg         += (float) $row->inlegbedrag;
            $cumul_opname        += (float) $row->opnamebedrag;
            $cumul_distributie   += (float) $row->distributievergoeding;
            $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;

            $netto_inleg   = $cumul_inleg - $cumul_opname;
            $positiewaarde = $netto_inleg + $cumul_distributie;

            $investeringsrendement = null;
            if ( $netto_inleg > 0 && $positiewaarde > 0 ) {
                $investeringsrendement = ( $positiewaarde / $netto_inleg - 1 ) * 100;
            }

            $daily_snapshots[ $d_key ] = [
                'jaar'                  => $year,
                'maand'                 => $month,
                'positiewaarde'         => $positiewaarde,
                'investeringsrendement' => $investeringsrendement,
                'totaal_participaties'  => $cumul_participaties,
                'dividend_cumul'        => $cumul_distributie,
            ];

            $monthly[ $ym_key ] = $daily_snapshots[ $d_key ];

            $posDates[]  = $d_key;
            $posValues[] = round( $positiewaarde, 2 );
        }
    }

    if ( empty( $monthly ) || empty( $daily_snapshots ) ) {
        return '<section class="ggrp-fe"><h1>Dashboard</h1><p>Nog geen geldige historie beschikbaar.</p></section>';
    }

    // 2) Laatste maand bepalen voor de kaarten (laatste datum met data)
    $all_keys = array_keys( $monthly );
    sort( $all_keys ); // chrono

    $last_key       = end( $all_keys );
    $last_year      = (int) substr( $last_key, 0, 4 );
    $last_month_int = (int) substr( $last_key, 5, 2 );

    $selected_year  = $last_year;
    $selected_month = $last_month_int;
    $selected_key   = $last_key;

    // Snapshot voor de kaartjes = laatste dag in de gekozen maand (of laatste dag overall als die maand geen data heeft)
    $current_card_snapshot = null;
    foreach ( $daily_snapshots as $date_key => $snap ) {
        if ( substr( $date_key, 0, 7 ) === $selected_key ) {
            $current_card_snapshot = $snap;
        }
    }
    if ( ! $current_card_snapshot ) {
        $last_date_key         = array_key_last( $daily_snapshots );
        $current_card_snapshot = $daily_snapshots[ $last_date_key ];
    }

    // Snapshot rond de laatste transactie: eerste koersdag >= tx-datum
    $last_tx_snapshot      = null;
    $last_tx_snapshot_date = null;
    if ( $last_tx_row ) {
        $tx_date = $last_tx_row->datum;
        foreach ( $daily_snapshots as $date_key => $snap ) {
            if ( $date_key >= $tx_date ) {
                if ( $last_tx_snapshot_date === null || $date_key < $last_tx_snapshot_date ) {
                    $last_tx_snapshot      = $snap;
                    $last_tx_snapshot_date = $date_key;
                }
            }
        }
        // Als er geen koers NA de tx is, pak laatste snapshot
        if ( ! $last_tx_snapshot ) {
            $last_date_key         = array_key_last( $daily_snapshots );
            $last_tx_snapshot      = $daily_snapshots[ $last_date_key ];
            $last_tx_snapshot_date = $last_date_key;
        }
    }

    // 5) KPI's (absolute waarden)
        // 5) KPI's (absolute waarden)
    $positie_total = $current_card_snapshot['positiewaarde'];
    $inv_total     = $current_card_snapshot['investeringsrendement'];
    $parts_total   = $current_card_snapshot['totaal_participaties'];
    $div_total     = $current_card_snapshot['dividend_cumul'];

    /**
     * Chips: t.o.v. VORIGE transactie voor positie + rendement
     *
     * - We vergelijken alleen als er minstens 2 transacties zijn
     * - Vergelijking is t.o.v. de snapshot na de voorlaatste transactie
     * - Bij 0 of 1 transactie: geen vergelijking (waarde blijft null => '-')
     */
    $positie_vs_last_tx_pct = null;
    $inv_vs_last_tx_delta   = null;

    $history_count = is_array( $history ) ? count( $history ) : 0;

    if ( $history_count >= 2 ) {
        // Voorlaatste (vorige) transactie
        $prev_tx_row  = $history[ $history_count - 2 ];
        $prev_tx_date = $prev_tx_row->datum;

        // Zoek eerste snapshot op of ná datum vorige transactie
        $prev_tx_snapshot = null;

        foreach ( $daily_snapshots as $date_key => $snap ) {
            if ( $date_key >= $prev_tx_date ) {
                $prev_tx_snapshot = $snap;
                break;
            }
        }

        // Fallback: als er om wat voor reden dan ook geen snapshot na die datum is,
        // pak de laatste snapshot die we hebben.
        if ( ! $prev_tx_snapshot && ! empty( $daily_snapshots ) ) {
            $last_date_key    = array_key_last( $daily_snapshots );
            $prev_tx_snapshot = $daily_snapshots[ $last_date_key ];
        }

        if ( $prev_tx_snapshot ) {
            if ( $prev_tx_snapshot['positiewaarde'] > 0 && $positie_total > 0 ) {
                $positie_vs_last_tx_pct = ( $positie_total / $prev_tx_snapshot['positiewaarde'] - 1 ) * 100;
            }

            if ( $prev_tx_snapshot['investeringsrendement'] !== null && $inv_total !== null ) {
                $inv_vs_last_tx_delta = $inv_total - $prev_tx_snapshot['investeringsrendement'];
            }
        }
    }



    // Chips: mutatie in de laatste transactie zelf voor participaties + dividend
    $parts_delta_last_tx = null;
    $div_delta_last_tx   = null;
    if ( $last_tx_row ) {
        $parts_delta_last_tx = (float) $last_tx_row->nieuwe_participaties - (float) $last_tx_row->verkochte_participaties;
        $div_delta_last_tx   = (float) $last_tx_row->distributievergoeding;
    }

    // Chip-classes
    $chip_pos_class   = ggrp_fe_get_chip_class( $positie_vs_last_tx_pct );
    $chip_inv_class   = ggrp_fe_get_chip_class( $inv_vs_last_tx_delta );
    $chip_part_class  = ggrp_fe_get_chip_class( $parts_delta_last_tx );
    $chip_div_class   = ggrp_fe_get_chip_class( $div_delta_last_tx );

            // 6) Dividend per maand-arrays voor 2 onderste grafieken
    $month_keys          = array_keys( $monthly );
    sort( $month_keys );
    $divMonthKeys        = []; // YYYY-MM
    $divCumulValues      = [];
    $divPerMonthValues   = [];
    $prev_cumul_dividend = 0.0;

    foreach ( $month_keys as $mk ) {
        $snap  = $monthly[ $mk ];
        $cumul = (float) $snap['dividend_cumul'];

        $divMonthKeys[]      = $mk;
        $divCumulValues[]    = round( $cumul, 2 );
        $divPerMonthValues[] = round( $cumul - $prev_cumul_dividend, 2 );

        $prev_cumul_dividend = $cumul;
    }

    // Prognosegrafiek: laatste 6 maanden realisatie + prognose tot 12 maanden
    $forecast_month_labels    = array();
    $forecast_actual_series   = array();
    $forecast_projection_full = array();

    if ( ! empty( $month_keys ) ) {
        $forecast_actual_keys    = array_slice( $month_keys, -6 );
        $forecast_actual_values  = array();
        $forecast_display_labels = array();

        $dividend_per_month_map = array();
        foreach ( $divMonthKeys as $index => $mk ) {
            $dividend_per_month_map[ $mk ] = $divPerMonthValues[ $index ];
        }

        foreach ( $forecast_actual_keys as $mk ) {
            $dividend_maand = isset( $dividend_per_month_map[ $mk ] ) ? (float) $dividend_per_month_map[ $mk ] : 0.0;
            $positie_maand  = isset( $monthly[ $mk ]['positiewaarde'] ) ? (float) $monthly[ $mk ]['positiewaarde'] : null;

            $shifted_label = $mk;
            $mk_date       = DateTime::createFromFormat( 'Y-m', $mk );
            if ( $mk_date instanceof DateTime ) {
                $mk_date->modify( '-1 month' );
                $shifted_label = $mk_date->format( 'Y-m' );
            }

            $forecast_display_labels[] = $shifted_label;

            if ( $positie_maand && $positie_maand > 0 ) {
                $rendement_pct           = ( $dividend_maand / $positie_maand ) * 100;
                $forecast_actual_values[] = round( $rendement_pct, 4 );
            } else {
                $forecast_actual_values[] = null;
            }
        }

        $valid_points = array();
        foreach ( $forecast_actual_values as $index => $value ) {
            if ( $value !== null ) {
                $valid_points[] = array(
                    'x' => $index,
                    'y' => $value,
                );
            }
        }

        $slope      = 0.0;
        $intercept  = 0.0;
        $point_count = count( $valid_points );

        if ( $point_count >= 2 ) {
            $sum_x  = 0.0;
            $sum_y  = 0.0;
            $sum_xy = 0.0;
            $sum_x2 = 0.0;

            foreach ( $valid_points as $point ) {
                $sum_x  += $point['x'];
                $sum_y  += $point['y'];
                $sum_xy += $point['x'] * $point['y'];
                $sum_x2 += $point['x'] * $point['x'];
            }

            $denominator = ( $point_count * $sum_x2 ) - ( $sum_x * $sum_x );
            if ( $denominator !== 0.0 ) {
                $slope = ( ( $point_count * $sum_xy ) - ( $sum_x * $sum_y ) ) / $denominator;
            }

            $intercept = ( $sum_y - ( $slope * $sum_x ) ) / $point_count;
        } elseif ( $point_count === 1 ) {
            $intercept = $valid_points[0]['y'];
        }

        $last_known_value = null;
        for ( $i = count( $forecast_actual_values ) - 1; $i >= 0; $i-- ) {
            if ( $forecast_actual_values[ $i ] !== null ) {
                $last_known_value = $forecast_actual_values[ $i ];
                break;
            }
        }

        if ( $last_known_value === null ) {
            $last_known_value = 0.0;
        }

        $forecast_months_to_add = max( 0, 12 - count( $forecast_actual_keys ) );
        $forecast_months_to_add = min( 6, $forecast_months_to_add );

        $future_keys   = array();
        $future_values = array();
        $last_month_key = end( $forecast_actual_keys );
        if ( $last_month_key ) {
            $last_month_date = DateTime::createFromFormat( 'Y-m', $last_month_key );
            if ( $last_month_date instanceof DateTime ) {
                for ( $i = 1; $i <= $forecast_months_to_add; $i++ ) {
                    $future = clone $last_month_date;
                    $future->modify( '+' . $i . ' month' );
                    $future_keys[] = $future->format( 'Y-m' );

                    $x_value        = ( count( $forecast_actual_values ) - 1 ) + $i;
                    $predicted      = $intercept + ( $slope * $x_value );
                    $future_values[] = round( $predicted, 4 );
                }
            }
        }

        $forecast_month_labels = array_merge( $forecast_actual_keys, $future_keys );

        if ( ! empty( $forecast_month_labels ) ) {
            $forecast_length        = count( $forecast_month_labels );
            $forecast_actual_series = array_merge(
                $forecast_actual_values,
                array_fill( 0, $forecast_length - count( $forecast_actual_values ), null )
            );
            $forecast_projection_full = array_fill( 0, $forecast_length, null );

            if ( ! empty( $forecast_actual_values ) ) {
                $actual_count = count( $forecast_actual_values );
                $forecast_projection_full[ $actual_count - 1 ] = $last_known_value;

                foreach ( $future_values as $index => $value ) {
                    $forecast_projection_full[ $actual_count + $index ] = $value;
                }
            }
        }
    }


    /**
     * Laatst bijgewerkte GGR stock price ophalen
     * uit tabel {prefix}ggr_stock_prices
     */
    global $wpdb;
    $stock_table = $wpdb->prefix . 'ggr_stock_prices';

    // laatste snapshot op basis van price_date (meest recente koersdatum)
    $latest_stock_row = $wpdb->get_row(
        "SELECT price_date, updated_at
         FROM {$stock_table}
         ORDER BY price_date DESC
         LIMIT 1",
        ARRAY_A
    );

    // Volgende handelsmoment: standaard eerste maandag van de maand.
    $next_trade_day_label = '';
    try {
        $tz     = wp_timezone();
        $today  = new DateTime( 'today', $tz );
        $first  = new DateTime( $today->format( 'Y-m-01' ), $tz );
        $cursor = clone $first;
        while ( (int) $cursor->format( 'N' ) !== 1 ) {
            $cursor->modify( '+1 day' );
        }
        if ( $cursor < $today ) {
            $first_next = new DateTime( $today->format( 'Y-m-01' ), $tz );
            $first_next->modify( 'first day of next month' );
            $cursor = clone $first_next;
            while ( (int) $cursor->format( 'N' ) !== 1 ) {
                $cursor->modify( '+1 day' );
            }
        }
        $next_trade_day_label = wp_date( 'l j F Y', $cursor->getTimestamp() );
    } catch ( Exception $e ) {
        $next_trade_day_label = '';
    }

    if ( $latest_stock_row ) {
        // Datum van de laatste koers
        $laatste_datum_display = date_i18n( 'd-m-Y', strtotime( $latest_stock_row['price_date'] ) );

        // Tijd van laatste update voor die koers (fallback: leeg)
        $laatste_tijd_display = ! empty( $latest_stock_row['updated_at'] )
            ? date_i18n( 'H:i', strtotime( $latest_stock_row['updated_at'] ) )
            : '';
    } else {
        $laatste_datum_display = '-';
        $laatste_tijd_display  = '';
    }

    $canvas_pos_id         = 'ggr_fe_chart_pos_' . uniqid();
    $canvas_div_cum_id     = 'ggr_fe_chart_divcum_' . uniqid();
    $canvas_div_month_id   = 'ggr_fe_chart_divmonth_' . uniqid();
    $canvas_forecast_id    = 'ggr_fe_chart_forecast_' . uniqid();
    $has_forecast_chart    = ! empty( $forecast_month_labels ) && ! empty( $forecast_actual_series );

    if ( $has_forecast_chart ) {
        ggrp_fe_queue_forecast_script();
    }

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--dashboard">
        <header class="ggrp-fe-header">
            <div>
                <h1>Hallo <?php echo esc_html( $naam ); ?>,</h1>
                <p class="ggrp-fe-subtitle">
                    Laatst geüpdatet op <?php echo esc_html( $laatste_datum_display ); ?>
                    <?php if ( $laatste_tijd_display ) : ?>
                        om <?php echo esc_html( $laatste_tijd_display ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="ggrp-fe-header-meta">
                <span class="ggrp-fe-meta-label">Volgende handelsdag</span>
                <span class="ggrp-fe-trade-chip">
                    <strong><?php echo esc_html( $next_trade_day_label ? $next_trade_day_label : 'Nog niet bekend' ); ?></strong>
                </span>
            </div>            
        </header>

        <div class="ggrp-fe-kpi-row">
            <!-- Positiewaarde -->
            <article class="ggrp-fe-card">
                <h2 class="ggrp-fe-card-title">Positiewaarde</h2>
                <div class="ggrp-fe-card-value">
                    <?php echo esc_html( ggrp_fe_format_money( $positie_total ) ); ?>
                </div>
                <div class="ggrp-fe-card-meta">
                    <span class="<?php echo esc_attr( $chip_pos_class ); ?>">
                        <?php echo esc_html( ggrp_fe_format_percent_signed( $positie_vs_last_tx_pct ) ); ?>
                    </span>
                    <span class="ggrp-fe-card-meta-text">t.o.v. vorige maand</span>
                </div>
            </article>

            <!-- Investeringsrendement -->
            <article class="ggrp-fe-card">
                <h2 class="ggrp-fe-card-title">Investeringsrendement</h2>
                <div class="ggrp-fe-card-value">
                    <?php echo $inv_total !== null
                        ? esc_html( number_format( $inv_total, 2, ',', '.' ) . '%' )
                        : '-'; ?>
                </div>
                <div class="ggrp-fe-card-meta">
                    <span class="<?php echo esc_attr( $chip_inv_class ); ?>">
                        <?php echo esc_html( ggrp_fe_format_percent_signed( $inv_vs_last_tx_delta ) ); ?>
                    </span>
                    <span class="ggrp-fe-card-meta-text">t.o.v. vorige maand</span>
                </div>
            </article>

            <!-- Participaties -->
            <article class="ggrp-fe-card">
                <h2 class="ggrp-fe-card-title">Participaties</h2>
                <div class="ggrp-fe-card-value">
                    <?php echo esc_html(
                        number_format( (float) $parts_total, 3, ',', '.' )
                    ); ?>
                </div>
                <div class="ggrp-fe-card-meta">
                    <span class="<?php echo esc_attr( $chip_part_class ); ?>">
                        <?php echo esc_html( ggrp_fe_format_signed_number( $parts_delta_last_tx, 3 ) ); ?>
                    </span>
                    <span class="ggrp-fe-card-meta-text">participaties deze maand</span>
                </div>
            </article>

            <!-- Dividend uitgekeerd -->
            <article class="ggrp-fe-card">
                <h2 class="ggrp-fe-card-title">Dividend uitgekeerd</h2>
                <div class="ggrp-fe-card-value">
                    <?php echo esc_html( ggrp_fe_format_money( $div_total ) ); ?>
                </div>
                <div class="ggrp-fe-card-meta">
                    <span class="<?php echo esc_attr( $chip_div_class ); ?>">
                        <?php echo esc_html( ggrp_fe_format_signed_money( $div_delta_last_tx ) ); ?>
                    </span>
                    <span class="ggrp-fe-card-meta-text">dividend deze maand</span>
                </div>
            </article>
        </div>

        <!-- Positie grafiek -->
        <section class="ggrp-fe-panel">
            <div class="ggrp-fe-panel-header">
                <h2>Positiewaarde</h2>
                <div class="ggrp-fe-range-buttons" aria-label="Filter grafiekperiode">
                    <button type="button" class="ggrp-fe-range-button is-active" data-range="all">Alles</button>
                </div>
            </div>
            <div class="ggrp-fe-panel-body ggrp-fe-panel-body--chart">
                <?php if ( ! empty( $posDates ) && ! empty( $posValues ) ) : ?>
                    <div class="ggr-positie-grafiek-wrapper">
                        <canvas id="<?php echo esc_attr( $canvas_pos_id ); ?>"></canvas>
                    </div>
                <?php else : ?>
                    <p class="ggrp-fe-empty-chart">Nog geen historie beschikbaar.</p>
                <?php endif; ?>
            </div>
        </section>
        
        <!-- Dividend grafieken: cumulatief + per maand -->
        <div class="ggrp-fe-chart-row">
            <!-- Cumulatief -->
            <section class="ggrp-fe-panel">
                <div class="ggrp-fe-panel-header">
                    <h2>Dividend Totaal</h2>
                </div>
                <div class="ggrp-fe-panel-body ggrp-fe-panel-body--chart-small">
                    <?php if ( ! empty( $divMonthKeys ) ) : ?>
                        <div class="ggr-positie-grafiek-wrapper">
                            <canvas id="<?php echo esc_attr( $canvas_div_cum_id ); ?>"></canvas>
                        </div>
                    <?php else : ?>
                        <p class="ggrp-fe-empty-chart">Nog geen dividendgegevens beschikbaar.</p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Per maand -->
            <section class="ggrp-fe-panel">
                <div class="ggrp-fe-panel-header">
                    <h2>Dividend per maand </h2>
                </div>
                <div class="ggrp-fe-panel-body ggrp-fe-panel-body--chart-small">
                    <?php if ( ! empty( $divMonthKeys ) ) : ?>
                        <div class="ggr-positie-grafiek-wrapper">
                            <canvas id="<?php echo esc_attr( $canvas_div_month_id ); ?>"></canvas>
                        </div>
                    <?php else : ?>
                        <p class="ggrp-fe-empty-chart">Nog geen dividendgegevens beschikbaar.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </section>

        <!-- Prognose grafiek -->
        <section class="ggrp-fe-panel">
            <div class="ggrp-fe-panel-header">
                <h2>Dividend rendement (%) per maand + Prognose voor 6 maanden</h2>
            </div>
            <div class="ggrp-fe-panel-body ggrp-fe-panel-body--chart">
                <?php if ( ! empty( $forecast_month_labels ) && ! empty( $forecast_actual_series ) ) : ?>
                    <div class="ggr-positie-grafiek-wrapper">
                        <canvas
                            id="<?php echo esc_attr( $canvas_forecast_id ); ?>"
                            class="ggr-fe-forecast-canvas"
                            data-forecast-labels='<?php echo wp_json_encode( $forecast_month_labels ); ?>'
                            data-forecast-actual='<?php echo wp_json_encode( $forecast_actual_series ); ?>'
                            data-forecast-projection='<?php echo wp_json_encode( $forecast_projection_full ); ?>'
                        ></canvas>
                    </div>
                <?php else : ?>
                    <p class="ggrp-fe-empty-chart">Onvoldoende data voor een prognose.</p>
                <?php endif; ?>
            </div>
        </section>

    <?php


    // Chart.js + 3 grafieken
    if ( ! empty( $posDates ) && ! empty( $posValues ) ) : ?>
        <?php ggrp_fe_ensure_chartjs(); ?>
        <script>
        (function() {
            if (typeof Chart === 'undefined') {
                return;
            }

            const kleurLijn   = '#709aa7';
            const kleurFill   = 'rgba(112,154,167,0.18)';

            const posDates       = <?php echo wp_json_encode( $posDates ); ?>;      // Y-m-d
            const posValues      = <?php echo wp_json_encode( $posValues ); ?>;
            const divMonthKeys   = <?php echo wp_json_encode( $divMonthKeys ); ?>;  // Y-m
            const divCumulVals   = <?php echo wp_json_encode( $divCumulValues ); ?>;
            const divMonthVals   = <?php echo wp_json_encode( $divPerMonthValues ); ?>;

            const monthsLong = [
                'januari','februari','maart','april','mei','juni',
                'juli','augustus','september','oktober','november','december'
            ];
            const monthsShort = [
                'Jan','Feb','Mrt','Apr','Mei','Jun',
                'Jul','Aug','Sep','Okt','Nov','Dec'
            ];

            function formatMonthShortFromYMD(ymd) {
                const parts = (ymd || '').split('-');
                if (parts.length < 2) return ymd || '';
                const y = Number(parts[0]);
                const m = Number(parts[1]);
                if (!y || !m) return ymd;
                const label = monthsShort[m-1] || '';
                return label + "'" + String(y).slice(2);
            }
            function formatDateLongFromYMD(ymd) {
                const parts = (ymd || '').split('-');
                if (parts.length < 3) return ymd || '';
                const y = Number(parts[0]);
                const m = Number(parts[1]);
                const d = Number(parts[2]);
                if (!y || !m || !d) return ymd;
                const monthName = monthsLong[m-1] || '';
                return d + ' ' + monthName + ' ' + y;
            }
            function formatMonthShortFromYM(ym) {
                return formatMonthShortFromYMD(ym + '-01');
            }
            function formatMonthLongFromYM(ym) {
                const parts = (ym || '').split('-');
                if (parts.length < 2) return ym || '';
                const y = Number(parts[0]);
                const m = Number(parts[1]);
                if (!y || !m) return ym;
                const monthName = monthsLong[m-1] || '';
                return monthName + ' ' + y;
            }

            function formatMoney(value) {
                const val = Number(value) || 0;
                return '€ ' + val.toLocaleString('nl-NL', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            // Labels
            const posLabels  = posDates.map(formatMonthShortFromYMD);
            const divLabels  = divMonthKeys.map(formatMonthShortFromYM);

            // Helpers voor range-filter op de positiesgrafiek
            const basePosDates  = posDates.slice();
            const basePosValues = posValues.slice();
            let posChart        = null;

            function getFilteredPosData(rangeKey) {
                if (!basePosDates.length) {
                    return { dates: [], values: [], labels: [], tickLimit: 6 };
                }

                if (rangeKey === 'all') {
                    return {
                        dates: basePosDates,
                        values: basePosValues,
                        labels: basePosDates.map(formatMonthShortFromYMD),
                        tickLimit: 10,
                    };
                }

                const days = parseInt(rangeKey, 10);
                if (Number.isNaN(days) || days <= 0) {
                    return {
                        dates: basePosDates,
                        values: basePosValues,
                        labels: basePosDates.map(formatMonthShortFromYMD),
                        tickLimit: 10,
                    };
                }

                const lastDateString = basePosDates[basePosDates.length - 1];
                const lastDate = new Date(lastDateString);
                if (Number.isNaN(lastDate.getTime())) {
                    return {
                        dates: basePosDates,
                        values: basePosValues,
                        labels: basePosDates.map(formatMonthShortFromYMD),
                        tickLimit: 10,
                    };
                }

                const cutoff = new Date(lastDate);
                cutoff.setDate(cutoff.getDate() - (days - 1));

                const filteredDates = [];
                const filteredValues = [];
                basePosDates.forEach((dateStr, idx) => {
                    const parsed = new Date(dateStr);
                    if (!Number.isNaN(parsed.getTime()) && parsed >= cutoff) {
                        filteredDates.push(dateStr);
                        filteredValues.push(basePosValues[idx]);
                    }
                });

                // Zorg dat we altijd minstens 2 punten tonen; anders fallback naar alles
                if (filteredDates.length < 2) {
                    return {
                        dates: basePosDates,
                        values: basePosValues,
                        labels: basePosDates.map(formatMonthShortFromYMD),
                        tickLimit: 10,
                    };
                }

                let tickLimit = 10;
                switch (days) {
                    case 1:
                        tickLimit = 3;
                        break;
                    case 7:
                        tickLimit = 7;
                        break;
                    case 30:
                        tickLimit = 8;
                        break;
                    case 365:
                        tickLimit = 12;
                        break;
                    default:
                        tickLimit = 10;
                }

                return {
                    dates: filteredDates,
                    values: filteredValues,
                    labels: filteredDates.map(formatMonthShortFromYMD),
                    tickLimit,
                };
            }

            function updatePosChart(rangeKey) {
                if (!posChart) return;
                const filtered = getFilteredPosData(rangeKey);
                posChart.data.labels = filtered.labels;
                posChart.data.datasets[0].data = filtered.values;
                posChart.options.scales.x.ticks.maxTicksLimit = filtered.tickLimit;
                posChart.update();
            }

            // Gedeelde y-as: geen grid/rand, wel euro-format
            const yAxisEuro = {
                grid: {
                    display: false,
                    drawBorder: false
                },
                ticks: {
                    callback: function(value) {
                        return '€ ' + Number(value).toLocaleString('nl-NL', {
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0
                        });
                    }
                }
            };

            // Gemeenschappelijke layout: wat lucht binnen de card
            const baseLayout = {
                padding: { top: 8, right: 16, bottom: 8, left: 16 }
            };

            // ----- 1) Positie grafiek (line) -----
            const ctxPos = document.getElementById('<?php echo esc_js( $canvas_pos_id ); ?>').getContext('2d');
            posChart = new Chart(ctxPos, {
                type: 'line',
                data: {
                    labels: posLabels,
                    datasets: [{
                        label: 'Positiewaarde',
                        data: posValues,
                        fill: true,
                        cubicInterpolationMode: 'monotone',
                        tension: 0.7,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 0,
                        pointHitRadius: 12,
                        borderColor: kleurLijn,
                        backgroundColor: kleurFill
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: baseLayout,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: true,
                            callbacks: {
                                title: function(items) {
                                    if (!items.length) return '';
                                    const idx = items[0].dataIndex;
                                    const rawDate = posDates[idx];
                                    return formatDateLongFromYMD(rawDate);
                                },
                                label: function(context) {
                                    const value = context.parsed.y || 0;
                                    return 'Waarde: ' + formatMoney(value);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            offset: false,
                            ticks: { maxTicksLimit: 10 },
                            grid: {
                                display: false,
                                drawBorder: false
                            }
                        },
                        y: yAxisEuro
                    }
                }
            });

            const rangeButtons = document.querySelectorAll('.ggrp-fe-range-button');
            rangeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    rangeButtons.forEach((b) => b.classList.remove('is-active'));
                    btn.classList.add('is-active');
                    updatePosChart(btn.dataset.range || 'all');
                });
            });

            updatePosChart('all');
            
            // ----- 2) Dividend cumulatief (line) -----
            const ctxDivCum = document.getElementById('<?php echo esc_js( $canvas_div_cum_id ); ?>').getContext('2d');
            new Chart(ctxDivCum, {
                type: 'line',
                data: {
                    labels: divLabels,
                    datasets: [{
                        label: 'Cumulatief dividend',
                        data: divCumulVals,
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHitRadius: 10,
                        borderColor: kleurLijn,
                        backgroundColor: kleurFill
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: baseLayout,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: true,
                            callbacks: {
                                title: function(items) {
                                    if (!items.length) return '';
                                    const idx = items[0].dataIndex;
                                    const key = divMonthKeys[idx];
                                    return formatMonthLongFromYM(key);
                                },
                                label: function(context) {
                                    const value = context.parsed.y || 0;
                                    return 'Dividend totaal: ' + formatMoney(value);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            offset: false,
                            ticks: { maxTicksLimit: 6 },
                            grid: {
                                display: false,
                                drawBorder: false
                            }
                        },
                        y: yAxisEuro
                    }
                }
            });

            // ----- 3) Dividend per maand (bar) -----
            const ctxDivMonth  = document.getElementById('<?php echo esc_js( $canvas_div_month_id ); ?>').getContext('2d');
            new Chart(ctxDivMonth, {
                type: 'bar',
                data: {
                    labels: divLabels,
                    datasets: [{
                        label: 'Dividend per maand',
                        data: divMonthVals,
                        borderWidth: 0,
                        backgroundColor: kleurLijn,
                        categoryPercentage: 0.9,
                        barPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: baseLayout,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: true,
                            callbacks: {
                                title: function(items) {
                                    if (!items.length) return '';
                                    const idx = items[0].dataIndex;
                                    const key = divMonthKeys[idx];
                                    return formatMonthLongFromYM(key);
                                },
                                label: function(context) {
                                    const value = context.parsed.y || 0;
                                    return 'Dividend: ' + formatMoney(value);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            offset: true, // voorkomt afgesneden 1e/laatste staaf
                            ticks: { maxTicksLimit: 6 },
                            grid: {
                                display: false,
                                drawBorder: false
                            }
                        },
                        y: yAxisEuro
                    }
                }
            });
        })();
        </script>
    <?php
    endif;

    return ob_get_clean();
}
add_shortcode( 'ggr_portal_dashboard', 'ggrp_fe_dashboard_shortcode' );

/**
 * TRANSACTIES
 * Shortcode: [ggr_portal_transacties]
 */
function ggrp_fe_transacties_shortcode( $atts ) {
    $maybe_error = ggrp_fe_require_login();
    if ( $maybe_error !== null ) {
        return $maybe_error;
    }

    $user_id = get_current_user_id();

    if ( ! function_exists( 'ggr_portal_get_history_for_user' ) ) {
        return '<section class="ggrp-fe"><h1>Transacties</h1><p>Transactie-historie is niet beschikbaar (core plugin niet actief?).</p></section>';
    }

    $history = ggr_portal_get_history_for_user( $user_id );
    if ( ! $history ) {
        return '<section class="ggrp-fe"><h1>Transacties</h1><p>Nog geen transacties gevonden.</p></section>';
    }

    /**
     * 1) Chronologisch: positiewaarde + participaties voor/na per transactie uitrekenen.
     *    Boekhoudkundige logica: netto inleg + cumulatief dividend.
     */
    $history_asc = $history;
    usort( $history_asc, function( $a, $b ) {
        return strcmp( $a->datum, $b->datum );
    } );

    $cumul_inleg         = 0.0;
    $cumul_opname        = 0.0;
    $cumul_distributie   = 0.0;
    $cumul_participaties = 0.0;
    $current_pos         = 0.0;

    foreach ( $history_asc as $row ) {
        $old_pos   = $current_pos;
        $old_parts = $cumul_participaties;

        $cumul_inleg         += (float) $row->inlegbedrag;
        $cumul_opname        += (float) $row->opnamebedrag;
        $cumul_distributie   += (float) $row->distributievergoeding;
        $cumul_participaties += (float) $row->nieuwe_participaties - (float) $row->verkochte_participaties;

        $netto_inleg = $cumul_inleg - $cumul_opname;
        $current_pos = $netto_inleg + $cumul_distributie;

        $row->old_positiewaarde = $old_pos;
        $row->new_positiewaarde = $current_pos;
        $row->old_participaties = $old_parts;
        $row->new_participaties = $cumul_participaties;
    }

    /**
     * 2) UI: sorteer weer nieuw -> oud
     */
    usort( $history, function( $a, $b ) {
        return strcmp( $b->datum, $a->datum );
    });

    // Beschikbare jaren bepalen
    $years = [];
    foreach ( $history as $row ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $row->datum );
        if ( ! $d ) {
            continue;
        }
        $y = (int) $d->format( 'Y' );
        if ( ! in_array( $y, $years, true ) ) {
            $years[] = $y;
        }
    }
    if ( empty( $years ) ) {
        return '<section class="ggrp-fe"><h1>Transacties</h1><p>Nog geen geldige transacties gevonden.</p></section>';
    }
    rsort( $years );
    $selected_year = isset( $_GET['t_year'] ) ? (int) $_GET['t_year'] : $years[0];
    if ( ! in_array( $selected_year, $years, true ) ) {
        $selected_year = $years[0];
    }

    // Filter transacties op jaar
    $filtered = array_filter( $history, function( $row ) use ( $selected_year ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $row->datum );
        if ( ! $d ) {
            return false;
        }
        return (int) $d->format( 'Y' ) === $selected_year;
    });

    $laatste_datum = do_shortcode( '[ggr_latest_datum]' );

    ob_start();
    ?>
    <section class="ggrp-fe ggrp-fe--transacties">
        <header class="ggrp-fe-header">
            <div>
                <h1>Transacties</h1>
                <p class="ggrp-fe-subtitle">
             
                </p>
            </div>

            <div class="ggrp-fe-header-year">
                <form method="get" class="ggrp-fe-year-filter-form">
                    <?php
                    foreach ( $_GET as $key => $val ) {
                        if ( 't_year' === $key || is_array( $val ) ) {
                            continue;
                        }
                        echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
                    }
                    ?>
                    <div class="ggrp-fe-year-select-wrapper">
                        <select name="t_year" class="ggrp-fe-year-select" onchange="this.form.submit()">
                            <?php foreach ( $years as $year ) : ?>
                                <option value="<?php echo (int) $year; ?>" <?php selected( $year, $selected_year ); ?>>
                                    <?php echo (int) $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="ggrp-fe-year-select-caret">▾</span>
                    </div>
                </form>
            </div>
        </header>

        <section class="ggrp-fe-panel ggrp-fe-panel--transacties">
            <div class="ggrp-fe-trans-head">
                <span>Type transactie</span>
                <span>BIJ/AF</span>
                <span>Bedrag</span>
                <span>Status</span>
                <span>Datum</span>
            </div>

            <div class="ggrp-fe-panel-body ggrp-fe-panel-body--transacties">
                <?php if ( empty( $filtered ) ) : ?>
                    <p class="ggrp-fe-empty-transactions">
                        Geen transacties gevonden voor <?php echo esc_html( $selected_year ); ?>.
                    </p>
                <?php else : ?>
                    <?php foreach ( $filtered as $row ) : ?>
                        <?php
                        $inleg   = (float) $row->inlegbedrag;
                        $opname  = (float) $row->opnamebedrag;
                        $div     = (float) $row->distributievergoeding;

                        $has_inleg  = $inleg  > 0;
                        $has_opname = $opname > 0;
                        $has_div    = $div    > 0;

                        // Netto geldmutatie voor BIJ/AF kolom
                        $netto = $inleg + $div - $opname;
                        if ( $netto > 0 ) {
                            $bijaf  = 'BIJ';
                        } elseif ( $netto < 0 ) {
                            $bijaf  = 'AF';
                        } else {
                            $bijaf  = '-';
                        }
                        $bedrag = abs( $netto );

                        // "Hoofdtype" – puur voor titel
                        $type = 'Onbekend';
                        if ( $has_inleg && ! $has_opname && ! $has_div ) {
                            $type = 'Inleg';
                        } elseif ( $has_opname && ! $has_inleg && ! $has_div ) {
                            $type = 'Opname';
                        } elseif ( $has_div && ! $has_inleg && ! $has_opname ) {
                            $type = 'Dividend';
                        } elseif ( $has_opname && $has_div ) {
                            $type = 'Opname + dividend';
                        } elseif ( $has_inleg && $has_div ) {
                            $type = 'Inleg + dividend';
                        } else {
                            $type = 'Samengesteld';
                        }

                        $d = DateTime::createFromFormat( 'Y-m-d', $row->datum );
                        $datum_label = $d ? $d->format( 'd M Y' ) : $row->datum;

                        $bedrag_fmt = '€ ' . number_format( $bedrag, 2, ',', '.' );

                        // Status
                        $status_raw = isset( $row->status ) ? trim( (string) $row->status ) : '';
                        if ( $status_raw === '' ) {
                            $status_raw = 'Voltooid';
                        }
                        $status_slug = strtolower( preg_replace( '/\s+/', '-', $status_raw ) );

                        // PDF optioneel
                        $pdf_url  = isset( $row->pdf_url ) ? trim( (string) $row->pdf_url ) : '';

                        $old_pos = isset( $row->old_positiewaarde ) ? (float) $row->old_positiewaarde : null;
                        $new_pos = isset( $row->new_positiewaarde ) ? (float) $row->new_positiewaarde : null;

                        $old_parts   = isset( $row->old_participaties ) ? (float) $row->old_participaties : null;
                        $new_parts   = isset( $row->new_participaties ) ? (float) $row->new_participaties : null;
                        $delta_parts = ( $old_parts !== null && $new_parts !== null )
                            ? ( $new_parts - $old_parts )
                            : null;
                        ?>
                        <article class="ggrp-fe-trans-row">
                            <!-- GESLOTEN REGEL (header) -->
                            <div class="ggrp-fe-trans-row-main" onclick="ggrTransRowToggle(event, this)">
                                <div class="ggrp-fe-trans-col ggrp-fe-trans-col--type">
                                    <strong><?php echo esc_html( $type ); ?></strong>
                                </div>
                                <div class="ggrp-fe-trans-col">
                                    <?php echo esc_html( $bijaf ); ?>
                                </div>
                                <div class="ggrp-fe-trans-col">
                                    <?php echo esc_html( $bedrag_fmt ); ?>
                                </div>
                                <div class="ggrp-fe-trans-col ggrp-fe-trans-col--status">
                                    <span class="ggrp-fe-status-badge ggrp-fe-status-badge--<?php echo esc_attr( $status_slug ); ?>">
                                        <?php echo esc_html( $status_raw ); ?>
                                    </span>
                                </div>
                                <div class="ggrp-fe-trans-col">
                                    <?php echo esc_html( $datum_label ); ?>
                                </div>
                            </div>

                            <!-- OPEN GEDEELTE -->
                            <div class="ggrp-fe-trans-extra">
                                <!-- Mutaties (geldstromen) -->
                                <div class="ggrp-fe-trans-extra-block">
                                    <div class="ggrp-fe-trans-extra-label">Mutaties</div>
                                    <div class="ggrp-fe-trans-extra-value">
                                        <?php if ( $has_inleg || $has_opname || $has_div ) : ?>
                                            <?php if ( $has_inleg ) : ?>
                                                <div>Inleg (BIJ): <?php echo esc_html( ggrp_fe_format_money( $inleg ) ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $has_opname ) : ?>
                                                <div>Opname (AF): <?php echo esc_html( ggrp_fe_format_money( $opname ) ); ?></div>
                                            <?php endif; ?>
                                            <?php if ( $has_div ) : ?>
                                                <div>Dividend (BIJ): <?php echo esc_html( ggrp_fe_format_money( $div ) ); ?></div>
                                            <?php endif; ?>
                                            <div class="ggrp-fe-trans-netto">
                                                Netto geldmutatie: <?php echo esc_html( ggrp_fe_format_signed_money( $netto ) ); ?>
                                            </div>
                                        <?php else : ?>
                                            Geen geldmutatie geregistreerd.
                                        <?php endif; ?>
                                    </div>
                                </div>

                             

                                <!-- Participaties -->
                                <div class="ggrp-fe-trans-extra-block">
                                    <div class="ggrp-fe-trans-extra-label">Participaties</div>
                                    <div class="ggrp-fe-trans-extra-value">
                                        <?php if ( $old_parts !== null && $new_parts !== null ) : ?>
                                            <div>Oorspronkelijk:
                                                <?php echo esc_html( number_format( $old_parts, 3, ',', '.' ) ); ?>
                                            </div>
                                            <div>Na transactie:
                                                <?php echo esc_html( number_format( $new_parts, 3, ',', '.' ) ); ?>
                                            </div>
                                            <?php if ( $delta_parts !== 0.0 ) : ?>
                                                <div style="margin-top:0.3rem;font-size:0.8rem;color:#9ca3af;">
                                                    Mutatie:
                                                    <?php echo esc_html( ggrp_fe_format_signed_number( $delta_parts, 3 ) ); ?>
                                                    participaties
                                                </div>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            Niet beschikbaar
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- FOOTER: Transactie ID links, PDF rechts -->
                                <div class="ggrp-fe-trans-extra-footer">
                                    <div class="ggrp-fe-trans-id">
                                        Transactie ID: <?php echo esc_html( $row->transactie_code ); ?>
                                    </div>

                                    <?php
                                    $pdf_href = $pdf_url ? esc_url( $pdf_url ) : '#';
                                    ?>
                                                                  </div>

                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <script>
        // Toggle functie voor open/dicht klappen van een transactiekaart
        function ggrTransRowToggle(evt, el) {
            try {
                if (evt.target.closest && evt.target.closest('a,button')) {
                    return;
                }
                var card = el.closest ? el.closest('.ggrp-fe-trans-row') : null;
                if (!card) {
                    return;
                }
                card.classList.toggle('is-open');
            } catch (e) {
                console.error('ggrTransRowToggle error:', e);
            }
        }
        </script>

    </section>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_portal_transacties', 'ggrp_fe_transacties_shortcode' );
