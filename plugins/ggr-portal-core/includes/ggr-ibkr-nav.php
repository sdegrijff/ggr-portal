<?php
/**
 * IBKR Flex NAV importeren en opslaan als GGR stock price.
 *
 * Gebruik:
 * - Zet je Flex Web Service token en Query ID als constants (bijv. in wp-config.php):
 *   define( 'GGR_IBKR_FLEX_TOKEN', '...' );
 *   define( 'GGR_IBKR_FLEX_QUERY_ID', '...' );
 *   (of sla ze op in de options ggr_ibkr_flex_token en ggr_ibkr_flex_query_id)
 * - Deze module plant een dagelijkse cron en slaat de NAV op via ggr_upsert_stock_price.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ophalen Flex Web Service token.
 */
function ggr_ibkr_nav_get_token() {
    if ( defined( 'GGR_IBKR_FLEX_TOKEN' ) && GGR_IBKR_FLEX_TOKEN ) {
        return trim( GGR_IBKR_FLEX_TOKEN );
    }

    $token = get_option( 'ggr_ibkr_flex_token' );

    return is_string( $token ) ? trim( $token ) : '';
}

/**
 * Ophalen Flex Query ID (NAV rapport).
 */
function ggr_ibkr_nav_get_query_id() {
    if ( defined( 'GGR_IBKR_FLEX_QUERY_ID' ) && GGR_IBKR_FLEX_QUERY_ID ) {
        return trim( GGR_IBKR_FLEX_QUERY_ID );
    }

    $query_id = get_option( 'ggr_ibkr_flex_query_id' );

    return is_string( $query_id ) ? trim( $query_id ) : '';
}

/**
 * Flex base URL.
 */
function ggr_ibkr_nav_get_base_url() {
    if ( defined( 'GGR_IBKR_FLEX_BASE_URL' ) && GGR_IBKR_FLEX_BASE_URL ) {
        return untrailingslashit( GGR_IBKR_FLEX_BASE_URL );
    }

    return 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';
}

/**
 * Zijn de minimale credentials aanwezig?
 */
function ggr_ibkr_nav_has_credentials() {
    return ggr_ibkr_nav_get_token() && ggr_ibkr_nav_get_query_id();
}

/**
 * Cron uitschakelen als er geen geldige credentials zijn.
 */
function ggr_ibkr_nav_clear_cron() {
    while ( ( $timestamp = wp_next_scheduled( 'ggr_ibkr_nav_fetch_event' ) ) !== false ) {
        wp_unschedule_event( $timestamp, 'ggr_ibkr_nav_fetch_event' );
    }
}

/**
 * Cron plannen (dagelijks).
 */
function ggr_ibkr_nav_schedule_cron() {
    if ( ! ggr_ibkr_nav_has_credentials() ) {
        ggr_ibkr_nav_clear_cron();
        return;
    }

    if ( ! wp_next_scheduled( 'ggr_ibkr_nav_fetch_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ggr_ibkr_nav_fetch_event' );
    }
}
add_action( 'init', 'ggr_ibkr_nav_schedule_cron' );

/**
 * Laatste succesvolle run opslaan (voor statusweergave en meldingen).
 *
 * @param string $date
 * @param float  $nav
 * @param float|null $fund_total
 * @param float|null $total_participations
 */
function ggr_ibkr_nav_set_last_run( $date, $nav, $fund_total = null, $total_participations = null, $statement_url = '' ) {
    $payload = array(
        'date'                 => $date,
        'nav'                  => (float) $nav,
        'fund_total'           => ( null !== $fund_total ) ? (float) $fund_total : null,
        'total_participations' => ( null !== $total_participations ) ? (float) $total_participations : null,
        'timestamp'            => current_time( 'timestamp' ),
        'statement_url'        => $statement_url ? esc_url_raw( $statement_url ) : '',
    );

    update_option( 'ggr_ibkr_nav_last_run', $payload, false );
}

/**
 * Statusinformatie over de IBKR cron en laatste succesvolle run.
 *
 * @return array{
 *     has_credentials: bool,
 *     next_run: int|false,
 *     last_run: array|null
 * }
 */
function ggr_ibkr_nav_get_status() {
    return array(
        'has_credentials' => ggr_ibkr_nav_has_credentials(),
        'next_run'        => wp_next_scheduled( 'ggr_ibkr_nav_fetch_event' ),
        'last_run'        => get_option( 'ggr_ibkr_nav_last_run' ),
        'last_error'      => get_option( 'ggr_ibkr_nav_last_error' ),
    );
}

/**
 * Cron hook → ophalen en opslaan.
 */
add_action( 'ggr_ibkr_nav_fetch_event', 'ggr_ibkr_nav_fetch_and_store' );

/**
 * NAV ophalen en parsen zonder opslag.
 *
 * @param string|null $token
 * @param string|null $query_id
 * @return array|WP_Error
 */
function ggr_ibkr_nav_fetch( $token = null, $query_id = null ) {
    $token    = $token ?: ggr_ibkr_nav_get_token();
    $query_id = $query_id ?: ggr_ibkr_nav_get_query_id();

    if ( ! $token || ! $query_id ) {
        return new WP_Error( 'ggr_ibkr_missing_credentials', 'Flex token of Query ID ontbreekt.' );
    }

    $reference_code = ggr_ibkr_nav_request_reference_code( $token, $query_id );

    if ( is_wp_error( $reference_code ) ) {
        ggr_ibkr_nav_log_error( 'IBKR SendRequest mislukt.', $reference_code );
        return $reference_code;
    }

    $statement_url = ggr_ibkr_nav_get_statement_url( $token, $reference_code );
    $statement_body = ggr_ibkr_nav_request_statement( $token, $reference_code );

    if ( is_wp_error( $statement_body ) ) {
        $statement_body->add_data( array( 'statement_url' => $statement_url ) );
        ggr_ibkr_nav_log_error( 'IBKR GetStatement mislukt.', $statement_body );
        return $statement_body;
    }

    $parsed = ggr_ibkr_nav_parse_statement( $statement_body );

    if ( is_wp_error( $parsed ) ) {
        $parsed->add_data( array( 'statement_url' => $statement_url ) );
        ggr_ibkr_nav_log_error( 'IBKR NAV parse mislukt.', $parsed );
        return $parsed;
    }

    return array_merge(
        $parsed,
        array(
            'statement'      => $statement_body,
            'reference_code' => $reference_code,
            'statement_url'  => $statement_url,
        )
    );
}

/**
 * Handmatige helper voor directe run (bijv. vanuit WP-CLI of een admin-actie).
 *
 * @param string|null $token
 * @param string|null $query_id
 * @return array|WP_Error
 */
function ggr_ibkr_nav_fetch_and_store( $token = null, $query_id = null ) {
    if ( ! function_exists( 'ggr_upsert_stock_price' ) || ! function_exists( 'ggr_portal_get_total_participations_all_users' ) ) {
        $error = new WP_Error( 'ggr_ibkr_missing_helpers', 'Benodigde helpers niet beschikbaar.' );
        ggr_ibkr_nav_set_last_error( $error );
        return $error;
    }

    $result = ggr_ibkr_nav_fetch( $token, $query_id );

    if ( is_wp_error( $result ) ) {
        ggr_ibkr_nav_set_last_error( $result );
        return $result;
    }

    $total_parts = ggr_portal_get_total_participations_all_users( $result['date'] );

    if ( $total_parts <= 0 ) {
        $error = new WP_Error( 'ggr_ibkr_nav_missing_participations', 'Geen participaties gevonden om NAV te berekenen.' );
        ggr_ibkr_nav_set_last_error( $error );
        return $error;
    }

    $gross_per_participation = round( $result['total'] / $total_parts, 6 );
    $fee_percent             = function_exists( 'ggr_stock_price_get_default_management_fee_percent' )
        ? ggr_stock_price_get_default_management_fee_percent()
        : 0.0;
    $nav_per_participation = function_exists( 'ggr_stock_price_calculate_net_from_gross' )
        ? ggr_stock_price_calculate_net_from_gross( $gross_per_participation, $fee_percent )
        : $gross_per_participation;

    if ( null === $nav_per_participation ) {
        $nav_per_participation = $gross_per_participation;
    }
    $statement_url = isset( $result['statement_url'] ) ? $result['statement_url'] : '';    

    $stored = ggr_upsert_stock_price(
        $result['date'],
        $nav_per_participation,
        array(
            'gross_price_value'      => $gross_per_participation,
            'management_fee_percent' => $fee_percent,
            'fund_total'           => $result['total'],
            'total_participations' => $total_parts,
            'statement_url'        => $statement_url,            
        )
    );

    if ( ! $stored ) {
        $error = new WP_Error( 'ggr_ibkr_nav_store_failed', 'Opslaan in ggr_stock_prices is mislukt.' );
        ggr_ibkr_nav_set_last_error( $error );
        return $error;
    }

    $result['nav']                  = $nav_per_participation;
    $result['value']                = $nav_per_participation; // backwards compat: value = NAV per participatie
    $result['total_participations'] = $total_parts;

    ggr_ibkr_nav_set_last_run( $result['date'], $nav_per_participation, $result['total'], $total_parts, $statement_url );
    ggr_ibkr_nav_clear_last_error();
    
    do_action(
        'ggr_ibkr_nav_stored',
        $result['date'],
        $nav_per_participation,
        $result['statement'],
        $result['total'],
        $total_parts
    );
    
    return $result;
}

/**
 * Request 1: SendRequest → ReferenceCode ophalen.
 *
 * @param string $token
 * @param string $query_id
 * @return string|WP_Error
 */
function ggr_ibkr_nav_request_reference_code( $token, $query_id ) {
    $url      = ggr_ibkr_nav_get_base_url() . '.SendRequest?t=' . rawurlencode( $token ) . '&q=' . rawurlencode( $query_id ) . '&v=3';
    $response = ggr_ibkr_nav_http_get( $url );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $xml = simplexml_load_string( $response );

    if ( false === $xml ) {
        return new WP_Error( 'ggr_ibkr_invalid_xml', 'Ongeldige XML in SendRequest response.' );
    }

    $code = isset( $xml->ReferenceCode ) ? trim( (string) $xml->ReferenceCode ) : '';

    if ( ! $code ) {
        return new WP_Error( 'ggr_ibkr_missing_reference_code', 'ReferenceCode ontbreekt in SendRequest response.' );
    }

    return $code;
}

/**
 * Request 2: GetStatement → daadwerkelijke data.
 *
 * @param string $token
 * @param string $reference_code
 * @return string|WP_Error
 */
function ggr_ibkr_nav_request_statement( $token, $reference_code ) {
    $url      = ggr_ibkr_nav_get_base_url() . '.GetStatement?t=' . rawurlencode( $token ) . '&q=' . rawurlencode( $reference_code ) . '&v=3';
    $response = ggr_ibkr_nav_http_get( $url );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return $response;
}

function ggr_ibkr_nav_get_statement_url( $token, $reference_code ) {
    return ggr_ibkr_nav_get_base_url() . '.GetStatement?t=' . rawurlencode( $token ) . '&q=' . rawurlencode( $reference_code ) . '&v=3';
}

/**
 * Basis HTTP GET helper.
 *
 * @param string $url
 * @return string|WP_Error
 */
function ggr_ibkr_nav_http_get( $url ) {
    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 30,
            'headers' => array(
                'Accept'     => 'application/xml',
                'User-Agent' => 'ggr-portal/ibkr-nav',
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error(
            'ggr_ibkr_http_error',
            'IBKR Flex API gaf een foutstatus terug.',
            array(
                'status' => $code,
                'body'   => wp_remote_retrieve_body( $response ),
            )
        );
    }

    return $body;
}

/**
 * Parseer het statement en haal datum + NAV.
 *
 * @param string $body
 * @return array|WP_Error
 */
function ggr_ibkr_nav_parse_statement( $body ) {
    $xml = simplexml_load_string( $body );

    if ( false === $xml ) {
        return new WP_Error( 'ggr_ibkr_invalid_xml', 'Ongeldige XML in GetStatement response.' );
    }

    $date        = null;
    $total_value = null;

    // Probeer eerst dezelfde parser als de handmatige import te gebruiken, zodat API en manual import identiek werken.
    if ( function_exists( 'ggr_parse_ibkr_flex_equity_summary' ) ) {
        $parsed_summary = ggr_parse_ibkr_flex_equity_summary( $body );

        if ( is_array( $parsed_summary ) && isset( $parsed_summary['report_date'], $parsed_summary['total'] ) ) {
            $date        = $parsed_summary['report_date'];
            $total_value = (float) $parsed_summary['total'];
        } elseif ( is_wp_error( $parsed_summary ) ) {
            // Laat oude fallback lopen, maar log wel de fout voor debug-doeleinden.
            ggr_ibkr_nav_log_error( 'IBKR Flex manual parser gaf een fout, val terug op generieke parser.', $parsed_summary );
        }
    }

    // Fallback op generieke extractie als de manual parser niets opleverde.
    if ( ! $date ) {
        $date = ggr_ibkr_nav_extract_date_from_xml( $xml );
    }

    if ( null === $total_value ) {
        $total_value = ggr_ibkr_nav_extract_total_from_xml( $xml );
    }

    $date        = apply_filters( 'ggr_ibkr_nav_extracted_date', $date, $xml );
    $total_value = apply_filters( 'ggr_ibkr_nav_extracted_total', $total_value, $xml );
    $total_value = apply_filters( 'ggr_ibkr_nav_extracted_value', $total_value, $xml ); // backward compat: voorheen nav/value filter
    if ( null !== $total_value ) {
        if ( function_exists( 'ggr_stock_price_adjust_fund_total' ) ) {
            $total_value = ggr_stock_price_adjust_fund_total( $total_value );
        } else {
            $total_value = (float) $total_value + 10;
        }
    }
    if ( ! $date ) {
        return new WP_Error( 'ggr_ibkr_missing_date', 'Geen datum gevonden in Flex statement.' );
    }

    if ( null === $total_value ) {
        return new WP_Error( 'ggr_ibkr_missing_value', 'Geen total waarde gevonden in Flex statement.' );
    }

    return array(
        'date'   => $date,
        'total'  => $total_value,
        'value'  => $total_value, // legacy voor bestaande hooks; NAV wordt elders berekend
    );
}

/**
 * Zoek een datum in het XML (fromDate/toDate/reportDate) en normaliseer naar Y-m-d.
 *
 * @param SimpleXMLElement $xml
 * @return string|null
 */
function ggr_ibkr_nav_extract_date_from_xml( SimpleXMLElement $xml ) {
    $candidates = array(
        ggr_ibkr_nav_get_attribute( $xml, array( 'fromDate', 'toDate', 'reportDate' ) ),
    );

    // Eerste FlexStatement child proberen (vaak zit daar de reportDate).
    $first_statement = $xml->xpath( '//FlexStatement' );
    if ( ! empty( $first_statement ) && $first_statement[0] instanceof SimpleXMLElement ) {
        $candidates[] = ggr_ibkr_nav_get_attribute( $first_statement[0], array( 'fromDate', 'toDate', 'reportDate' ) );
    }

    $candidates = array_filter( array_map( 'trim', $candidates ) );

    foreach ( $candidates as $raw_date ) {
        $normalized = ggr_ibkr_nav_normalize_date( $raw_date );

        if ( $normalized ) {
            return $normalized;
        }
    }

    // Fallback naar vandaag.
    return current_time( 'Y-m-d' );
}

/**
 * Zoek de total-waarde in het XML.
 *
 * @param SimpleXMLElement $xml
 * @return float|null
 */
function ggr_ibkr_nav_extract_total_from_xml( SimpleXMLElement $xml ) {
    $attribute_candidates = array(
        ggr_ibkr_nav_get_attribute( $xml, array( 'total', 'Total' ) ),
    );

    $nodes = $xml->xpath( '//@total | //@Total' );

    if ( $nodes && is_array( $nodes ) ) {
        foreach ( $nodes as $node ) {
            $attribute_candidates[] = trim( (string) $node );
        }
    }

    $attribute_candidates = array_filter( array_map( 'trim', $attribute_candidates ) );

    foreach ( $attribute_candidates as $candidate ) {
        $value = ggr_ibkr_nav_normalize_decimal( $candidate );

        if ( null !== $value ) {
            return $value;
        }
    }

    return null;
}

/**
 * Haal het eerste aanwezige attribuut uit een set namen.
 *
 * @param SimpleXMLElement $element
 * @param array            $attribute_names
 * @return string
 */
function ggr_ibkr_nav_get_attribute( SimpleXMLElement $element, array $attribute_names ) {
    foreach ( $attribute_names as $name ) {
        if ( isset( $element[ $name ] ) ) {
            return (string) $element[ $name ];
        }
    }

    return '';
}

/**
 * Datum normaliseren naar Y-m-d.
 *
 * @param string $raw_date
 * @return string|null
 */
function ggr_ibkr_nav_normalize_date( $raw_date ) {
    $raw_date = trim( (string) $raw_date );

    if ( preg_match( '/^\d{8}$/', $raw_date ) ) {
        // yyyymmdd → Y-m-d
        $year  = substr( $raw_date, 0, 4 );
        $month = substr( $raw_date, 4, 2 );
        $day   = substr( $raw_date, 6, 2 );

        return $year . '-' . $month . '-' . $day;
    }

    $timestamp = strtotime( $raw_date );

    return $timestamp ? date( 'Y-m-d', $timestamp ) : null;
}

/**
 * Getal normaliseren (punt of komma als decimal).
 *
 * @param string $raw_number
 * @return float|null
 */
function ggr_ibkr_nav_normalize_decimal( $raw_number ) {
    if ( '' === $raw_number ) {
        return null;
    }

    $normalized = str_replace( array( ' ', ',' ), array( '', '.' ), $raw_number );

    if ( ! is_numeric( $normalized ) ) {
        return null;
    }

    return (float) $normalized;
}

/**
 * Eenvoudige logger voor fouten (zonder secrets).
 *
 * @param string          $message
 * @param WP_Error|string $error
 */
function ggr_ibkr_nav_log_error( $message, $error ) {
    $context = array();

    if ( $error instanceof WP_Error ) {
        $context = $error->get_error_data();
        if ( ! is_array( $context ) ) {
            $context = array();
        }

        $context['code']    = $error->get_error_code();
        $context['message'] = $error->get_error_message();
    }

    if ( empty( $context ) ) {
        error_log( '[GGR IBKR NAV] ' . $message );
        return;
    }

    error_log( '[GGR IBKR NAV] ' . $message . ' | ' . wp_json_encode( $context ) );
}

function ggr_ibkr_nav_extract_error_message( $body ) {
    if ( ! is_string( $body ) || '' === trim( $body ) ) {
        return '';
    }

    $xml = simplexml_load_string( $body );

    if ( false === $xml ) {
        return '';
    }

    $nodes = $xml->xpath( '//*[local-name()="ErrorMessage" or local-name()="ErrorMsg" or local-name()="ErrorDescription" or local-name()="Error" or local-name()="Message"]' );

    if ( empty( $nodes ) ) {
        return '';
    }

    foreach ( $nodes as $node ) {
        $value = trim( (string) $node );
        if ( '' !== $value ) {
            return $value;
        }
    }

    return '';
}

function ggr_ibkr_nav_format_error_message( WP_Error $error ) {
    $message = $error->get_error_message();
    $data    = $error->get_error_data();
    $details = array();

    if ( is_array( $data ) ) {
        if ( isset( $data['status'] ) ) {
            $details[] = 'HTTP status ' . $data['status'];
        }

        if ( isset( $data['body'] ) ) {
            $body_message = ggr_ibkr_nav_extract_error_message( $data['body'] );
            if ( $body_message ) {
                $details[] = $body_message;
            }
        }
    } elseif ( is_string( $data ) ) {
        $body_message = ggr_ibkr_nav_extract_error_message( $data );
        if ( $body_message ) {
            $details[] = $body_message;
        }
    }

    if ( ! empty( $details ) ) {
        $message .= ' (' . implode( ' - ', array_unique( $details ) ) . ')';
    }

    return $message;
}

function ggr_ibkr_nav_set_last_error( WP_Error $error ) {
    $data          = $error->get_error_data();
    $statement_url = '';

    if ( is_array( $data ) && ! empty( $data['statement_url'] ) ) {
        $statement_url = esc_url_raw( $data['statement_url'] );
    }

    update_option(
        'ggr_ibkr_nav_last_error',
        array(
            'timestamp' => time(),
            'code'      => $error->get_error_code(),
            'message'   => ggr_ibkr_nav_format_error_message( $error ),
            'statement_url' => $statement_url,
        ),
        false
    );
}

function ggr_ibkr_nav_clear_last_error() {
    delete_option( 'ggr_ibkr_nav_last_error' );
}

/**
 * Stuur de beheerder een eenvoudige bevestiging wanneer de IBKR NAV is opgeslagen.
 *
 * @param string $date
 * @param float  $nav_per_participation
 * @param string $statement
 * @param float|null $fund_total
 * @param float|null $total_participations
 */
function ggr_ibkr_nav_send_admin_notification( $date, $nav_per_participation, $statement, $fund_total = null, $total_participations = null ) {
    $formatted_date = $date;
    if ( $date ) {
        $timestamp = strtotime( $date );
        if ( $timestamp ) {
            $formatted_date = wp_date( 'j F Y', $timestamp );
        }
    }
    
    if ( function_exists( 'ggr_portal_send_admin_templated_email' ) ) {
        $placeholders = array(
            'ibkr_run_timestamp'        => wp_date( 'Y-m-d H:i:s' ),
            'ibkr_report_date'          => $formatted_date,
            'ibkr_nav_per_participation'=> number_format( (float) $nav_per_participation, 6, ',', '.' ),
            'ibkr_total'                => null !== $fund_total ? number_format( (float) $fund_total, 2, ',', '.' ) : '',
            'ibkr_participations'       => null !== $total_participations ? number_format( (float) $total_participations, 4, ',', '.' ) : '',
        );

        $sent = ggr_portal_send_admin_templated_email( 'admin_ibkr_nav_success', $placeholders );
        if ( $sent ) {
            return;
        }
    }    
    $admin_email = get_option( 'admin_email' );

    if ( ! $admin_email || ! is_email( $admin_email ) ) {
        return;
    }

    $subject = sprintf( 'IBKR NAV opgeslagen voor %s', $formatted_date );

    $lines   = array();
    $lines[] = sprintf( 'De IBKR Flex API is succesvol uitgevoerd op %s.', wp_date( 'Y-m-d H:i:s' ) );
    $lines[] = sprintf( 'Datum rapport: %s', $formatted_date );
    $lines[] = sprintf( 'NAV per participatie: € %s', number_format( (float) $nav_per_participation, 6, ',', '.' ) );

    if ( null !== $fund_total ) {
        $lines[] = sprintf( 'Totaal uit IBKR: € %s', number_format( (float) $fund_total, 2, ',', '.' ) );
    }

    if ( null !== $total_participations ) {
        $lines[] = sprintf( 'Participaties: %s', number_format( (float) $total_participations, 4, ',', '.' ) );
    }

    $lines[] = '';
    $lines[] = 'Dit is een automatische melding vanuit de GGR Portal.';

    wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
}
add_action( 'ggr_ibkr_nav_stored', 'ggr_ibkr_nav_send_admin_notification', 10, 5 );

/**
 * WP-CLI helper: wp ggr ibkr-nav
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * CLI helpers voor IBKR NAV.
     */
    class GGR_IBKR_NAV_CLI_Command extends WP_CLI_Command {
/**
 * Haal het volledige Flex XML statement op (zonder parsing/DB).
 *
 * ## OPTIONS
 *
 * [--token=<token>]
 * : Overschrijf het Flex token.
 *
 * [--query-id=<id>]
 * : Overschrijf de Flex Query ID.
 *
 * [--file=<path>]
 * : Schrijf het XML statement naar bestand. Als weglaten: print naar stdout.
 *
 * @subcommand xml
 */
public function xml( $args, $assoc_args ) {
    $token    = isset( $assoc_args['token'] ) ? trim( (string) $assoc_args['token'] ) : '';
    $query_id = isset( $assoc_args['query-id'] ) ? trim( (string) $assoc_args['query-id'] ) : '';
    $file     = isset( $assoc_args['file'] ) ? trim( (string) $assoc_args['file'] ) : '';

    $token    = $token ?: ggr_ibkr_nav_get_token();
    $query_id = $query_id ?: ggr_ibkr_nav_get_query_id();

    if ( ! $token || ! $query_id ) {
        WP_CLI::error( 'Flex token of Query ID ontbreekt.' );
    }

    // Haal de statement op zonder te schrijven naar de database.
    $result = ggr_ibkr_nav_fetch( $token, $query_id );

    if ( is_wp_error( $result ) ) {
        WP_CLI::error( $result->get_error_message() );
    }

    $xml = $result['statement'];

    if ( $file ) {
        $written = @file_put_contents( $file, $xml );
        if ( false === $written ) {
            WP_CLI::error( 'Kon XML niet wegschrijven naar: ' . $file );
        }

        WP_CLI::success(
            sprintf(
                'XML opgeslagen naar %s (ReferenceCode: %s)',
                $file,
                $result['reference_code']
            )
        );
        return;
    }

    WP_CLI::log( $xml );
}

        /**
         * Default: NAV ophalen en opslaan.
         *
         * ## OPTIONS
         *
         * [--token=<token>]
         * : Overschrijf het Flex token (anders gebruiken we de opgeslagen waarde).
         *
         * [--query-id=<id>]
         * : Overschrijf de Flex Query ID (anders gebruiken we de opgeslagen waarde).
         *
         * [--no-store]
         * : Haal de NAV op, maar sla niet op in de database.
         *
         * ## EXAMPLES
         *
         *     wp ggr ibkr-nav
         *     wp ggr ibkr-nav --no-store
         *     wp ggr ibkr-nav --token=XXX --query-id=123 --no-store
         */
        public function __invoke( $args, $assoc_args ) {
            // Ondersteun expliciet subcommand-aliases, voor het geval WP-CLI test/xml/status niet als subcommand herkent.
            if ( isset( $args[0] ) ) {
                $subcommand = array_shift( $args );

                switch ( $subcommand ) {
                    case 'test':
                        $this->test( $args, $assoc_args );
                        return;
                    case 'xml':
                        $this->xml( $args, $assoc_args );
                        return;
                    case 'status':
                        $this->status();
                        return;
                    case 'fetch':
                        // expliciet fetch subcommand (valt normaal ook op default).
                        break;
                    default:
                        // Onbekende subcommand → laat reguliere fetch lopen zodat WP-CLI een nette foutmelding geeft.
                        $args = array_merge( array( $subcommand ), $args );
                        break;
                }
            }

            // Default gedrag: zelfde als "fetch".
            $this->fetch( $args, $assoc_args );
        }

        /**
         * NAV ophalen (en optioneel opslaan).
         *
         * ## OPTIONS
         *
         * [--token=<token>]
         * : Overschrijf het Flex token (anders gebruiken we de opgeslagen waarde).
         *
         * [--query-id=<id>]
         * : Overschrijf de Flex Query ID (anders gebruiken we de opgeslagen waarde).
         *
         * [--no-store]
         * : Haal de NAV op, maar sla niet op in de database.
         *
         * @subcommand fetch
         */
        public function fetch( $args, $assoc_args ) {
            $token    = isset( $assoc_args['token'] ) ? trim( (string) $assoc_args['token'] ) : '';
            $query_id = isset( $assoc_args['query-id'] ) ? trim( (string) $assoc_args['query-id'] ) : '';

            $token    = $token ?: ggr_ibkr_nav_get_token();
            $query_id = $query_id ?: ggr_ibkr_nav_get_query_id();

            if ( ! $token || ! $query_id ) {
                WP_CLI::error( 'Flex token of Query ID ontbreekt.' );
            }

            if ( isset( $assoc_args['no-store'] ) ) {
                $result = ggr_ibkr_nav_fetch( $token, $query_id );

                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }

                $total_parts = function_exists( 'ggr_portal_get_total_participations_all_users' )
                    ? ggr_portal_get_total_participations_all_users( $result['date'] )
                    : null;

                $nav_per_participation = ( $total_parts > 0 ) ? round( $result['total'] / $total_parts, 6 ) : null;

                $message = sprintf(
                    'IBKR total opgehaald (niet opgeslagen) voor %s: %s (ReferenceCode: %s)',
                    $result['date'],
                    $result['total'],
                    $result['reference_code']
                );

                if ( null !== $nav_per_participation ) {
                    $message .= sprintf( ' | NAV per participatie: %s (participaties: %s)', $nav_per_participation, $total_parts );
                }

                WP_CLI::success( $message );
                return;
            }

            $result = ggr_ibkr_nav_fetch_and_store( $token, $query_id );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            $message = sprintf(
                'NAV per participatie opgeslagen voor %s: %s',
                $result['date'],
                $result['value']
            );

            if ( isset( $result['total'] ) && isset( $result['total_participations'] ) ) {
                $message .= sprintf(
                    ' | Totaal: %s | Participaties: %s',
                    $result['total'],
                    $result['total_participations']
                );
            }

            WP_CLI::success( $message );
        }
        
        /**
         * Test de IBKR Flex run via SSH/WP-CLI, met optioneel opslaan en wegschrijven van de XML.
         *
         * ## OPTIONS
         *
         * [--token=<token>]
         * : Overschrijf het Flex token (anders gebruiken we de opgeslagen waarde).
         *
         * [--query-id=<id>]
         * : Overschrijf de Flex Query ID (anders gebruiken we de opgeslagen waarde).
         *
         * [--no-store]
         * : Haal de NAV op, maar sla niet op in de database.
         *
         * [--output-file=<path>]
         * : Schrijf de opgehaalde XML naar dit pad (bijv. /tmp/ibkr-test.xml).
         *
         * ## EXAMPLES
         *
         *     wp ggr ibkr-nav test
         *     wp ggr ibkr-nav test --no-store --output-file=/tmp/ibkr.xml
         *     wp ggr ibkr-nav test --token=XXX --query-id=123
         *
         * @subcommand test
         */
        public function test( $args, $assoc_args ) {
            $token       = isset( $assoc_args['token'] ) ? trim( (string) $assoc_args['token'] ) : '';
            $query_id    = isset( $assoc_args['query-id'] ) ? trim( (string) $assoc_args['query-id'] ) : '';
            $output_file = isset( $assoc_args['output-file'] ) ? trim( (string) $assoc_args['output-file'] ) : '';

            $token    = $token ?: ggr_ibkr_nav_get_token();
            $query_id = $query_id ?: ggr_ibkr_nav_get_query_id();

            if ( ! $token || ! $query_id ) {
                WP_CLI::error( 'Flex token of Query ID ontbreekt.' );
            }

            $store_result = ! isset( $assoc_args['no-store'] );

            $result = $store_result
                ? ggr_ibkr_nav_fetch_and_store( $token, $query_id )
                : ggr_ibkr_nav_fetch( $token, $query_id );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            if ( $output_file ) {
                $written = @file_put_contents( $output_file, $result['statement'] );

                if ( false === $written ) {
                    WP_CLI::warning( 'Kon XML niet wegschrijven naar: ' . $output_file );
                } else {
                    WP_CLI::log( 'XML opgeslagen naar: ' . $output_file );
                }
            }

            $message = $store_result
                ? sprintf( 'Test-run voltooid en opgeslagen voor %s: %s', $result['date'], $result['value'] )
                : sprintf( 'Test-run voltooid (niet opgeslagen) voor %s: %s (total)', $result['date'], $result['total'] );

            if ( isset( $result['nav'] ) && isset( $result['total_participations'] ) ) {
                $message .= sprintf( ' | Totaal: %s | Participaties: %s', $result['total'], $result['total_participations'] );
            }

            WP_CLI::success( $message );

            if ( isset( $result['reference_code'] ) ) {
                WP_CLI::log( 'ReferenceCode: ' . $result['reference_code'] );
            }
        }

        /**
         * Controleer de huidige IBKR Flex status en cron.
         *
         * @subcommand status
         */
        public function status() {
            $has_token    = (bool) ggr_ibkr_nav_get_token();
            $has_query_id = (bool) ggr_ibkr_nav_get_query_id();
            $next_run     = wp_next_scheduled( 'ggr_ibkr_nav_fetch_event' );

            WP_CLI::log( 'Flex token: ' . ( $has_token ? 'ingevuld' : 'ontbreekt' ) );
            WP_CLI::log( 'Flex Query ID: ' . ( $has_query_id ? 'ingevuld' : 'ontbreekt' ) );
            WP_CLI::log( 'Flex base URL: ' . ggr_ibkr_nav_get_base_url() );

            if ( $next_run ) {
                WP_CLI::log( 'Cron volgende run: ' . wp_date( 'Y-m-d H:i:s', $next_run ) );
            } else {
                WP_CLI::warning( 'Cron event ggr_ibkr_nav_fetch_event staat niet ingepland.' );
            }
        }
    }

    // Belangrijk: assoc_args = true, anders kan WP-CLI soms vreemd doen met args parsing.
    WP_CLI::add_command(
        'ggr ibkr-nav',
        'GGR_IBKR_NAV_CLI_Command',
        array(
            'shortdesc' => 'IBKR Flex NAV ophalen en opslaan.',
            // Sta een optionele positional voor subcommands (test/xml/status/fetch) en eventuele extra args toe,
            // zodat WP-CLI niet klaagt over "Too many positional arguments: test".
            'synopsis'  => array(
                array(
                    'type'        => 'positional',
                    'name'        => 'subcommand',
                    'optional'    => true,
                    'description' => 'Subcommand: test, xml, status of fetch.',
                ),
                array(
                    'type'        => 'positional',
                    'name'        => 'args',
                    'optional'    => true,
                    'repeating'   => true,
                    'description' => 'Extra argumenten voor het subcommand.',
                ),
            ),
        )
    );
}
