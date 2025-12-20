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

    $statement_body = ggr_ibkr_nav_request_statement( $token, $reference_code );

    if ( is_wp_error( $statement_body ) ) {
        ggr_ibkr_nav_log_error( 'IBKR GetStatement mislukt.', $statement_body );
        return $statement_body;
    }

    $parsed = ggr_ibkr_nav_parse_statement( $statement_body );

    if ( is_wp_error( $parsed ) ) {
        ggr_ibkr_nav_log_error( 'IBKR NAV parse mislukt.', $parsed );
        return $parsed;
    }

    return array_merge(
        $parsed,
        array(
            'statement'      => $statement_body,
            'reference_code' => $reference_code,
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
    if ( ! function_exists( 'ggr_upsert_stock_price' ) ) {
        return new WP_Error( 'ggr_ibkr_missing_helpers', 'ggr_upsert_stock_price() is niet beschikbaar.' );
    }

    $result = ggr_ibkr_nav_fetch( $token, $query_id );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    $stored = ggr_upsert_stock_price( $result['date'], $result['value'] );

    if ( ! $stored ) {
        return new WP_Error( 'ggr_ibkr_nav_store_failed', 'Opslaan in ggr_stock_prices is mislukt.' );
    }

    do_action( 'ggr_ibkr_nav_stored', $result['date'], $result['value'], $result['statement'] );

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

    $date  = ggr_ibkr_nav_extract_date_from_xml( $xml );
    $value = ggr_ibkr_nav_extract_value_from_xml( $xml );

    $date  = apply_filters( 'ggr_ibkr_nav_extracted_date', $date, $xml );
    $value = apply_filters( 'ggr_ibkr_nav_extracted_value', $value, $xml );

    if ( ! $date ) {
        return new WP_Error( 'ggr_ibkr_missing_date', 'Geen datum gevonden in Flex statement.' );
    }

    if ( null === $value ) {
        return new WP_Error( 'ggr_ibkr_missing_value', 'Geen NAV waarde gevonden in Flex statement.' );
    }

    return array(
        'date'  => $date,
        'value' => $value,
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
 * Zoek een NAV/NAV per share in het XML.
 *
 * @param SimpleXMLElement $xml
 * @return float|null
 */
function ggr_ibkr_nav_extract_value_from_xml( SimpleXMLElement $xml ) {
    $attribute_candidates = array(
        ggr_ibkr_nav_get_attribute( $xml, array( 'nav', 'NAV', 'navPrice', 'navPerShare', 'NetAssetValue' ) ),
    );

    $nodes = $xml->xpath( '//@nav | //@NAV | //@NetAssetValue | //@navPerShare | //@navPrice' );

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

    $result = ggr_ibkr_ibkr_fetch_xml_statement( $token, $query_id );

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
            // Default gedrag: zelfde als "fetch"
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

                WP_CLI::success(
                    sprintf(
                        'NAV opgehaald (niet opgeslagen) voor %s: %s (ReferenceCode: %s)',
                        $result['date'],
                        $result['value'],
                        $result['reference_code']
                    )
                );
                return;
            }

            $result = ggr_ibkr_nav_fetch_and_store( $token, $query_id );

            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }

            WP_CLI::success(
                sprintf(
                    'NAV opgeslagen voor %s: %s',
                    $result['date'],
                    $result['value']
                )
            );
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
    WP_CLI::add_command( 'ggr ibkr-nav', 'GGR_IBKR_NAV_CLI_Command', array( 'shortdesc' => 'IBKR Flex NAV ophalen en opslaan.' ) );
}
