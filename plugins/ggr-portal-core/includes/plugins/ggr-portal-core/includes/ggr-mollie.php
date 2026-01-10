<?php
/**
 * Mollie integratie voor betaling van extra inleg.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ggr_mollie_get_api_key() {
    $key = get_option( 'ggr_mollie_api_key', '' );

    return apply_filters( 'ggr_portal_mollie_api_key', $key );
}

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

    $status = wp_remote_retrieve_response_code( $response );
    $data   = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $status < 200 || $status >= 300 ) {
        $message = isset( $data['detail'] ) ? $data['detail'] : 'Onbekende Mollie-fout.';
        return new WP_Error( 'ggr_mollie_http_error', $message, array( 'status' => $status, 'body' => $data ) );
    }

    return $data;
}

function ggr_mollie_create_payment_for_mutatie( $mutatie_id, $amount, $description, $redirect_url ) {
    $amount_value = number_format( (float) $amount, 2, '.', '' );
    $body         = array(
        'amount'       => array(
            'currency' => 'EUR',
            'value'    => $amount_value,
        ),
        'description'  => $description,
        'redirectUrl'  => $redirect_url,
        'webhookUrl'   => rest_url( 'ggr/v1/mollie-webhook' ),
        'metadata'     => array(
            'mutatie_id' => (int) $mutatie_id,
        ),
    );

    $response = ggr_mollie_api_request( 'POST', '/v2/payments', $body );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $payment_id  = $response['id'] ?? '';
    $checkout_url = $response['_links']['checkout']['href'] ?? '';

    if ( ! $payment_id || ! $checkout_url ) {
        return new WP_Error( 'ggr_mollie_invalid_response', 'Mollie antwoord bevat geen payment-link.' );
    }

    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', sanitize_text_field( $payment_id ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_url', esc_url_raw( $checkout_url ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_status', sanitize_key( $response['status'] ?? 'open' ) );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_created_at', sanitize_text_field( $response['createdAt'] ?? current_time( 'mysql' ) ) );

    return array(
        'payment_id'  => $payment_id,
        'checkout_url' => $checkout_url,
        'status'      => $response['status'] ?? 'open',
    );
}

function ggr_mollie_refresh_payment_status( $mutatie_id ) {
    $payment_id = get_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', true );
    if ( ! $payment_id ) {
        return new WP_Error( 'ggr_mollie_missing_payment', 'Geen Mollie-payment gevonden.' );
    }

    $response = ggr_mollie_api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_status', sanitize_key( $response['status'] ?? 'open' ) );

    if ( isset( $response['_links']['checkout']['href'] ) ) {
        update_post_meta( $mutatie_id, 'ggr_mutatie_payment_url', esc_url_raw( $response['_links']['checkout']['href'] ) );
    }

    if ( isset( $response['status'] ) ) {
        ggr_mollie_handle_payment_status_update( $mutatie_id, $response );
    }

    return $response;
}

function ggr_mollie_handle_payment_status_update( $mutatie_id, array $payment_data ) {
    $status = sanitize_key( $payment_data['status'] ?? '' );
    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_status', $status );

    if ( in_array( $status, array( 'paid', 'authorized' ), true ) ) {
        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'betaald' );
    } elseif ( 'failed' === $status ) {
        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'in_behandeling' );
    } elseif ( 'canceled' === $status ) {
        update_post_meta( $mutatie_id, 'ggr_mutatie_status', 'afgewezen' );
    }
}

function ggr_mollie_find_mutatie_by_payment_id( $payment_id ) {
    $posts = get_posts( array(
        'post_type'   => 'ggr_mutatie',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_query'  => array(
            array(
                'key'   => 'ggr_mutatie_payment_id',
                'value' => $payment_id,
            ),
        ),
    ) );

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
    $payment_id = $request->get_param( 'id' );
    $payment_id = $payment_id ? sanitize_text_field( $payment_id ) : '';

    if ( ! $payment_id ) {
        return new WP_REST_Response( array( 'message' => 'Geen payment id.' ), 400 );
    }

    $payment_data = ggr_mollie_api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
    if ( is_wp_error( $payment_data ) ) {
        return new WP_REST_Response( array( 'message' => $payment_data->get_error_message() ), 400 );
    }

    $mutatie_id = 0;
    if ( isset( $payment_data['metadata']['mutatie_id'] ) ) {
        $mutatie_id = (int) $payment_data['metadata']['mutatie_id'];
    }

    if ( ! $mutatie_id ) {
        $mutatie_id = ggr_mollie_find_mutatie_by_payment_id( $payment_id );
    }

    if ( ! $mutatie_id ) {
        return new WP_REST_Response( array( 'message' => 'Mutatie niet gevonden.' ), 200 );
    }

    update_post_meta( $mutatie_id, 'ggr_mutatie_payment_id', $payment_id );
    ggr_mollie_handle_payment_status_update( $mutatie_id, $payment_data );

    return new WP_REST_Response( array( 'message' => 'OK' ), 200 );
}
