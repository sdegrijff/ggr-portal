<?php
/**
 * Mollie integratie voor betaling van extra inleg (GGR) - COPY/PASTE versie.
 *
 * Wat dit oplost t.o.v. je huidige code:
 * - redirectUrl wordt altijd een geldige absolute HTTPS URL (via home_url()).
 * - webhookUrl wordt altijd een absolute URL + optioneel beveiligd met token.
 * - webhook handler leest payment id uit query/form 贸f JSON body.
 * - status mapping bevat ook open/pending/expired.
 * - logging (alleen als WP_DEBUG true is).
 *
 * Vereist in wp-config.php:
 *   define('GGR_MOLLIE_API_KEY', 'test_xxx...' of 'live_xxx...');
 * Optioneel:
 *   define('GGR_MOLLIE_WEBHOOK_SECRET', 'lange-random-string');
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function ggr_mollie_log( $message, $context = array() ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $line = '[GGR Mollie] ' . $message;
        if ( ! empty( $context ) ) {
            $line .= ' ' . wp_json_encode( $context );
        }
        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}

function ggr_mollie_get_api_key() {
    if ( defined( 'GGR_MOLLIE_API_KEY' ) && GGR_MOLLIE_API_KEY ) {
        $key = (string) GGR_MOLLIE_API_KEY;
    } else {
        $key = (string) get_option( 'ggr_mollie_api_key', '' );
    }

    return (string) apply_filters( 'ggr_portal_mollie_api_key', $key );
}

function ggr_mollie_get_webhook_secret() {
    if ( defined( 'GGR_MOLLIE_WEBHOOK_SECRET' ) && GGR_MOLLIE_WEBHOOK_SECRET ) {
        return (string) GGR_MOLLIE_WEBHOOK_SECRET;
    }
    return '';
}

/**
 * Zorgt dat een URL altijd absoluut + https is.
 * - Als je een relatieve path geeft (/bedankt), maakt dit er https://jouwdomein.nl/bedankt van.
 * - Als je een absolute url geeft, wordt die geforceerd naar https.
 */
function ggr_mollie_make_absolute_https_url( $url_or_path ) {
    $url_or_path = trim( (string) $url_or_path );

    if ( '' === $url_or_path ) {
        return '';
    }

    // Relatief pad?
    if ( 0 === strpos( $url_or_path, '/' ) && 0 !== strpos( $url_or_path, '//' ) ) {
        $abs = home_url( $url_or_path );
    } else {
        $abs = $url_or_path;
    }

    // Force https
    $abs = preg_replace( '#^http://#i', 'https://', $abs );

    // Normaliseer
    return esc_url_raw( $abs );
}

function ggr_mollie_get_webhook_url() {
    $url = rest_url( 'ggr/v1/mollie-webhook' );
    $url = ggr_mollie_make_absolute_https_url( $url );

    $secret = ggr_mollie_get_webhook_secret();
    if ( $secret ) {
        $url = add_query_arg( 'token', rawurlencode( $secret ), $url );
    }

    return $url;
}

/**
 * Mollie API request helper.
 */
function ggr_mollie_api_request( $method, $endpoint, $body = null ) {
    $api_key = ggr_mollie_get_api_key();
    if ( ! $api_key ) {
        return new WP_Error( 'ggr_mollie_missing_key', 'Mollie API-sleutel ontbreekt.' );
    }

    $url  = 'https://api.mollie.com' . $endpoint;
    $args = array(
        'method'  => $method,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 20,
    );

    if ( null !== $body ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    $raw    = (string) wp_remote_retrieve_body( $response );
    $data   = json_decode( $raw, true );

    if ( $status < 200 || $status >= 300 ) {
        $message = ( is_array( $data ) && isset( $data['detail'] ) ) ? (string) $data['detail'] : 'Onbekende Mollie-fout.';
        return new WP_Error(
            'ggr_mollie_http_error',
            $message,
            array(
                'status' => $status,
                'body'   => $data,
                'raw'    => $raw,
            )
        );
    }

    return is_array( $data ) ? $data : array();
}

/* -------------------------------------------------------------------------- */
/* Public API: create / refresh                                                */
/* -------------------------------------------------------------------------- */

/**
 * Maak een betaling aan voor een mutatie.
 *
 * $redirect_url mag zijn:
 * - absolute url (https://...)
 * - relatieve path (/portal/storten/bedankt/)
 * - of leeg => dan gebruiken we een default
 *
 * @return array|WP_Error {payment_id, checkout_url, status}
 */
function ggr_mollie_create_payment_for_mutatie( $mutatie_id, $amount, $description, $redirect_url = '' ) {
    $mutatie_id = (int) $mutatie_id;

    // 1) Amount format
    $amount_value = number_format( (float) $amount, 2, '.', '' );

    // 2) RedirectUrl: altijd absoluut + https en nooit leeg
    //    Pas dit pad aan naar jouw "bedankt" pagina.
    $default_redirect_path = '/portal/storten/bedankt/';
    $redirect_url          = $redirect_url ? $redirect_url : $default_redirect_path;
    $redirect_url          = ggr_mollie_make_absolute_https_url( $redirect_url );

    if ( ! $redirect_url ) {
        return new WP_Error( 'ggr_mollie_invalid_redirect', 'Redirect URL ontbreekt of is ongeldig.' );
    }

    // 3) WebhookUrl
    $webhook_url = ggr_mollie_get_webhook_url();

    // 4) Request body
    $body = array(
        'amount'      => array(
            'currency' => 'EUR',
            'value'    => $amount_value,
        ),
        'description' => (string) $description,
        'redirectUrl' => $redirect_url,
        'webhookUrl'  => $webhook_url,
        'metadata'    => array(
            'mutatie_id' => $mutatie_id,
        ),
    );

    $response = ggr_mollie_api_request( 'POST', '/v2/payments', $body );
    if ( is_wp_error( $response ) ) {
        ggr_mollie_log(
            'Create payment failed',
            array(
                'mutatie_id'  => $mutatie_id,
                'redirectUrl' => $redirect_url,
                'webhookUrl'  => $webhook_url,
                'error'       => $response->get_error_message(),
            )
        );
        return $response;
    }

    $payment_id   = $response['id'] ?? '';
    $checkout_url = $response['_links']['checkout']['href'] ?? '';
    $status       = $response['status'] ?? 'open';

    if ( ! $payment_id || ! $checkout_url ) {
        return new WP_Error( 'ggr_mollie_invalid_response', 'Mollie antwoord bevat geen payment-link.' );
    }

    // Opslaan
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', sanitize_text_field( $payment_id ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_url', esc_url_raw( $checkout_url ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_status', sanitize_key( $status ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_created_at', sanitize_text_field( $response['createdAt'] ?? current_time( 'mysql' ) ) );

    ggr_mollie_log(
        'Payment created',
        array(
            'mutatie_id'   => $mutatie_id,
            'payment_id'   => $payment_id,
            'status'       => $status,
            'redirectUrl'  => $redirect_url,
            'webhookUrl'   => $webhook_url,
            'checkout_url' => $checkout_url,
        )
    );

    return array(
        'payment_id'   => $payment_id,
        'checkout_url' => $checkout_url,
        'status'       => $status,
    );
}

/**
 * Handig op redirect-pagina: status ophalen en verwerken.
 *
 * @return array|WP_Error Mollie payment object
 */
function ggr_mollie_refresh_payment_status( $mutatie_id ) {
    $mutatie_id = (int) $mutatie_id;

    $payment_id = (string) get_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', true );
    if ( ! $payment_id ) {
        return new WP_Error( 'ggr_mollie_missing_payment', 'Geen Mollie-payment gevonden.' );
    }

    $payment = ggr_mollie_api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
    if ( is_wp_error( $payment ) ) {
        return $payment;
    }

    // update status
    ggr_mollie_handle_payment_status_update( $mutatie_id, $payment );

    // (optioneel) checkout url updaten
    if ( isset( $payment['_links']['checkout']['href'] ) ) {
        update_post_meta( $mutatie_id, 'ggr_mutatie_payment_url', esc_url_raw( $payment['_links']['checkout']['href'] ) );
    }

    return $payment;
}

/* -------------------------------------------------------------------------- */
/* Status mapping                                                              */
/* -------------------------------------------------------------------------- */

function ggr_mollie_handle_payment_status_update( $mutatie_id, array $payment_data ) {
    $mutatie_id = (int) $mutatie_id;
    $status     = sanitize_key( $payment_data['status'] ?? '' );

    if ( ! $status ) {
        return;
    }

    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_status', $status );

    // Map naar jouw domeinstatus
    switch ( $status ) {
        case 'paid':
        case 'authorized':
            update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'betaald' );
            break;

        case 'open':
        case 'pending':
            update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'in_behandeling' );
            break;

        case 'canceled':
            update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'geannuleerd' );
            break;

        case 'failed':
        case 'expired':
            update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'mislukt' );
            break;

        default:
            // onbekend: alleen payment status opslaan
            break;
    }

    ggr_mollie_log(
        'Status updated',
        array(
            'mutatie_id' => $mutatie_id,
            'status'     => $status,
        )
    );
}

/* -------------------------------------------------------------------------- */
/* Webhook                                                                     */
/* -------------------------------------------------------------------------- */

function ggr_mollie_find_mutatie_by_payment_id( $payment_id ) {
    $posts = get_posts(
        array(
            'post_type'   => 'ggr_mutatie',
            'post_status' => 'any',
            'numberposts' => 1,
            'meta_query'  => array(
                array(
                    'key'   => 'ggr_mutatie_payment_id',
                    'value' => $payment_id,
                ),
            ),
        )
    );

    if ( empty( $posts ) ) {
        return 0;
    }

    return (int) $posts[0]->ID;
}

function ggr_mollie_register_webhook_route() {
    register_rest_route(
        'ggr/v1',
        '/mollie-webhook',
        array(
            'methods'             => 'POST',
            'callback'            => 'ggr_mollie_handle_webhook',
            'permission_callback' => '__return_true',
        )
    );
}
add_action( 'rest_api_init', 'ggr_mollie_register_webhook_route' );

function ggr_mollie_handle_webhook( WP_REST_Request $request ) {

    // Optional token beveiliging
    $secret = ggr_mollie_get_webhook_secret();
    if ( $secret ) {
        $token = (string) $request->get_param( 'token' );
        if ( ! $token || ! hash_equals( $secret, $token ) ) {
            ggr_mollie_log( 'Webhook blocked: invalid token' );
            return new WP_REST_Response( array( 'message' => 'Invalid token.' ), 403 );
        }
    }

    // Payment id kan komen als form param (klassiek) of JSON body
    $payment_id = (string) $request->get_param( 'id' );

    if ( ! $payment_id ) {
        $raw = (string) $request->get_body();
        if ( $raw ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                if ( ! empty( $json['id'] ) ) {
                    $payment_id = (string) $json['id'];
                } elseif ( ! empty( $json['resource']['id'] ) ) {
                    $payment_id = (string) $json['resource']['id'];
                }
            }
        }
    }

    $payment_id = $payment_id ? sanitize_text_field( $payment_id ) : '';
    if ( ! $payment_id ) {
        ggr_mollie_log( 'Webhook error: missing payment id', array( 'params' => $request->get_params() ) );
        return new WP_REST_Response( array( 'message' => 'Geen payment id.' ), 400 );
    }

    // Payment ophalen bij Mollie (bron van waarheid)
    $payment = ggr_mollie_api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
    if ( is_wp_error( $payment ) ) {
        ggr_mollie_log( 'Webhook error: Mollie fetch failed', array( 'payment_id' => $payment_id, 'error' => $payment->get_error_message() ) );
        return new WP_REST_Response( array( 'message' => $payment->get_error_message() ), 400 );
    }

    // Mutatie bepalen via metadata, fallback op meta search
    $mutatie_id = 0;
    if ( isset( $payment['metadata']['mutatie_id'] ) ) {
        $mutatie_id = (int) $payment['metadata']['mutatie_id'];
    }
    if ( ! $mutatie_id ) {
        $mutatie_id = ggr_mollie_find_mutatie_by_payment_id( $payment_id );
    }

    if ( ! $mutatie_id ) {
        // 200 zodat Mollie niet blijft retryen als wij hem niet kunnen linken
        ggr_mollie_log( 'Webhook: mutatie not found', array( 'payment_id' => $payment_id ) );
        return new WP_REST_Response( array( 'message' => 'Mutatie niet gevonden.' ), 200 );
    }

    // Guard: voorkom overschrijven met andere payment id
    $existing_payment_id = (string) get_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', true );
    if ( $existing_payment_id && $existing_payment_id !== $payment_id ) {
        ggr_mollie_log(
            'Webhook blocked: payment mismatch',
            array(
                'mutatie_id'          => $mutatie_id,
                'existing_payment_id' => $existing_payment_id,
                'incoming_payment_id' => $payment_id,
            )
        );
        return new WP_REST_Response( array( 'message' => 'Payment mismatch.' ), 409 );
    }

    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', $payment_id );

    // Status verwerken
    ggr_mollie_handle_payment_status_update( $mutatie_id, $payment );

    ggr_mollie_log(
        'Webhook OK',
        array(
            'mutatie_id' => $mutatie_id,
            'payment_id' => $payment_id,
            'status'     => $payment['status'] ?? '',
        )
    );

    return new WP_REST_Response( array( 'message' => 'OK' ), 200 );
}

/* -------------------------------------------------------------------------- */
/* Terminal tests (optioneel): zie instructies                                 */
/* -------------------------------------------------------------------------- */

/**
 * Let op: Je hoeft niets in Mollie Dashboard "Webhook aanmaken" te doen voor deze flow.
 * Mollie gebruikt de webhookUrl die je meegeeft bij het aanmaken van de payment.
 */
