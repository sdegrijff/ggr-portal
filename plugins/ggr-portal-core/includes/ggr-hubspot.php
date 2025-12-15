<?php
/**
 * HubSpot integratie voor GGR Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ophalen van de HubSpot private app token
 */
function ggr_hubspot_get_private_token() {
    if ( defined( 'GGR_HUBSPOT_PRIVATE_APP_TOKEN' ) && GGR_HUBSPOT_PRIVATE_APP_TOKEN ) {
        return trim( GGR_HUBSPOT_PRIVATE_APP_TOKEN );
    }

    $token = get_option( 'ggr_hubspot_private_app_token' );

    return is_string( $token ) ? trim( $token ) : '';
}

/**
 * Ophalen van de onboarding pipeline ID
 */
function ggr_hubspot_get_pipeline_id() {
    if ( defined( 'GGR_HUBSPOT_PIPELINE_ID' ) && GGR_HUBSPOT_PIPELINE_ID ) {
        return trim( GGR_HUBSPOT_PIPELINE_ID );
    }

    $pipeline = get_option( 'ggr_hubspot_pipeline_id' );

    return is_string( $pipeline ) ? trim( $pipeline ) : '';
}

/**
 * Mapping van onboarding statuses naar HubSpot dealstages
 */
function ggr_hubspot_get_stage_mapping() {
    $mapping = array(
        'register'           => defined( 'GGR_HUBSPOT_STAGE_REGISTER' ) ? GGR_HUBSPOT_STAGE_REGISTER : '',
        'confirmed'          => defined( 'GGR_HUBSPOT_STAGE_CONFIRMED' ) ? GGR_HUBSPOT_STAGE_CONFIRMED : '',
        'collecting'         => defined( 'GGR_HUBSPOT_STAGE_COLLECTING' ) ? GGR_HUBSPOT_STAGE_COLLECTING : '',
        'validating'         => defined( 'GGR_HUBSPOT_STAGE_VALIDATING' ) ? GGR_HUBSPOT_STAGE_VALIDATING : '',
        'sign_contract'      => defined( 'GGR_HUBSPOT_STAGE_SIGN_CONTRACT' ) ? GGR_HUBSPOT_STAGE_SIGN_CONTRACT : '',
        'transfer_funds' => defined( 'GGR_HUBSPOT_STAGE_TRANSFER_FUNDS' ) ? GGR_HUBSPOT_STAGE_TRANSFER_FUNDS : '',
        'active_participant' => defined( 'GGR_HUBSPOT_STAGE_ACTIVE_PARTICIPANT' ) ? GGR_HUBSPOT_STAGE_ACTIVE_PARTICIPANT : '',
    );

    $option_mapping = get_option( 'ggr_hubspot_stage_mapping' );

    if ( is_array( $option_mapping ) ) {
        $mapping = array_merge( $mapping, $option_mapping );
    }

    return apply_filters( 'ggr_hubspot_stage_mapping', $mapping );
}

/**
 * Ophalen van optionele webhook secret
 */
function ggr_hubspot_get_webhook_secret() {
    if ( defined( 'GGR_HUBSPOT_WEBHOOK_SECRET' ) && GGR_HUBSPOT_WEBHOOK_SECRET ) {
        return trim( GGR_HUBSPOT_WEBHOOK_SECRET );
    }

    $secret = get_option( 'ggr_hubspot_webhook_secret' );

    return is_string( $secret ) ? trim( $secret ) : '';
}

/**
 * Algemene helper om HubSpot requests te doen
 */
function ggr_hubspot_request( $method, $endpoint, $body = array() ) {
    $token = ggr_hubspot_get_private_token();

    if ( ! $token ) {
        return new WP_Error( 'hubspot_missing_token', 'HubSpot token ontbreekt.' );
    }

    $url  = 'https://api.hubapi.com' . $endpoint;
    $args = array(
        'method'  => $method,
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
    );

    if ( ! empty( $body ) ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 ) {
        $raw_body = wp_remote_retrieve_body( $response );
    
        return new WP_Error(
            'hubspot_http_error',
            'HubSpot request mislukte.',
            array(
                'status'   => $code,
                'endpoint' => $endpoint,
                'method'   => $method,
                'body'     => $data ? $data : $raw_body,
            )
        );
    }


    return $data ? $data : array();
}

/**
 * Haal bestaande HubSpot properties op voor een object type en cache ze tijdelijk.
 */
function ggr_hubspot_get_known_properties( $object_type ) {
    $cache_key = 'ggr_hubspot_known_properties_' . $object_type;
    $cached    = get_transient( $cache_key );

    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    $response = ggr_hubspot_request( 'GET', '/crm/v3/properties/' . $object_type );

    if ( is_wp_error( $response ) || empty( $response['results'] ) ) {
        return array();
    }

    $properties = wp_list_pluck( $response['results'], 'name' );

    set_transient( $cache_key, $properties, HOUR_IN_SECONDS );

    return $properties;
}

/**
 * Filter properties die niet bestaan in HubSpot om 400 fouten te voorkomen.
 */
function ggr_hubspot_filter_known_properties( $properties, $object_type ) {
    if ( empty( $properties ) || ! is_array( $properties ) ) {
        return $properties;
    }

    $known_properties = ggr_hubspot_get_known_properties( $object_type );

    if ( empty( $known_properties ) ) {
        return $properties;
    }

    $missing = array_diff( array_keys( $properties ), $known_properties );

    if ( ! empty( $missing ) ) {
        foreach ( $missing as $property_name ) {
            unset( $properties[ $property_name ] );
        }

        error_log( 'HubSpot ' . $object_type . ' properties overgeslagen (niet gevonden): ' . implode( ', ', $missing ) );
    }

    return $properties;
}

/**
 * Haal de juiste HubSpot property key op, met ondersteuning voor overrides via constants en filters.
 */
function ggr_hubspot_property_key( $object_type, $property_key ) {
    $defaults = array(
        'contacts' => array(
            'last_login_at' => 'ggr_last_login_at',
            'geboortedatum' => 'kyc_birth_date',
        ),
        'deals'    => array(
            'account_type' => 'account_type',
        ),
    );

    $mapping = array(
        'contacts' => array(
            'ggr_last_login_at' => defined( 'GGR_HUBSPOT_CONTACT_LAST_LOGIN_PROPERTY' ) ? GGR_HUBSPOT_CONTACT_LAST_LOGIN_PROPERTY : $defaults['contacts']['last_login_at'],
            'kyc_birth_date' => defined( 'GGR_HUBSPOT_CONTACT_BIRTH_DATE_PROPERTY' ) ? GGR_HUBSPOT_CONTACT_BIRTH_DATE_PROPERTY : $defaults['contacts']['geboortedatum'],
        ),
        'deals'    => array(
            'account_type' => defined( 'GGR_HUBSPOT_DEAL_ACCOUNT_TYPE_PROPERTY' ) ? GGR_HUBSPOT_DEAL_ACCOUNT_TYPE_PROPERTY : $defaults['deals']['account_type'],
        ),
    );

    $mapping = apply_filters( 'ggr_hubspot_property_mapping', $mapping, $object_type, $property_key );

    if ( isset( $mapping[ $object_type ][ $property_key ] ) && $mapping[ $object_type ][ $property_key ] ) {
        return $mapping[ $object_type ][ $property_key ];
    }

    return $property_key;
}

/**
 * Contact ID opzoeken via e-mailadres
 */
function ggr_hubspot_find_contact_id_by_email( $email ) {
    if ( ! $email ) {
        return 0;
    }

    $response = ggr_hubspot_request(
        'POST',
        '/crm/v3/objects/contacts/search',
        array(
            'filterGroups' => array(
                array(
                    'filters' => array(
                        array(
                            'propertyName' => 'email',
                            'operator'     => 'EQ',
                            'value'        => $email,
                        ),
                    ),
                ),
            ),
            'limit' => 1,
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return ! empty( $response['results'][0]['id'] ) ? (int) $response['results'][0]['id'] : 0;
}

/**
 * Contact upserten
 */
function ggr_hubspot_upsert_contact( $user_id ) {
    $user = get_user_by( 'id', $user_id );

    if ( ! $user ) {
        return new WP_Error( 'invalid_user', 'Gebruiker niet gevonden.' );
    }

    $email = $user->user_email;

    if ( ! $email ) {
        return new WP_Error( 'missing_email', 'Gebruiker heeft geen e-mailadres.' );
    }

    $contact_id = get_user_meta( $user_id, 'ggr_hubspot_contact_id', true );

    if ( ! $contact_id ) {
        $contact_id = ggr_hubspot_find_contact_id_by_email( $email );

        if ( is_wp_error( $contact_id ) ) {
            return $contact_id;
        }
    }

    $last_login   = ggr_hubspot_get_last_login_display( get_user_meta( $user_id, 'ggr_last_login_at', true ) );

    $properties = array(
        'firstname'             => $user->first_name,
        'lastname'              => $user->last_name,
        'email'                 => $email,
        'phone'                 => get_user_meta( $user_id, 'phone', true ),
        'country'               => ggr_hubspot_get_contact_country( $user_id ),
        'account_type'          => get_user_meta( $user_id, 'ggr_account_type', true ),
        'investment_amount'     => get_user_meta( $user_id, 'ggr_participation_amount', true ),
        'onboarding_status'     => function_exists( 'onboarding_get_status' ) ? onboarding_get_status( $user_id ) : '',
        ggr_hubspot_property_key( 'contacts', 'last_login_at' ) => $last_login,
        ggr_hubspot_property_key( 'contacts', 'geboortedatum' ) => ggr_hubspot_get_birth_date( $user_id ),
    );

    $properties = array_filter(
        apply_filters( 'ggr_hubspot_contact_properties', $properties, $user_id ),
        function( $value ) {
            return $value !== '' && $value !== null;
        }
    );

    $properties = ggr_hubspot_filter_known_properties( $properties, 'contacts' );

    if ( $contact_id ) {
        $response = ggr_hubspot_request(
            'PATCH',
            '/crm/v3/objects/contacts/' . $contact_id,
            array( 'properties' => $properties )
        );
    } else {
        $response = ggr_hubspot_request(
            'POST',
            '/crm/v3/objects/contacts',
            array( 'properties' => $properties )
        );

        if ( ! is_wp_error( $response ) && ! empty( $response['id'] ) ) {
            $contact_id = (int) $response['id'];
            update_user_meta( $user_id, 'ggr_hubspot_contact_id', $contact_id );
        }
    }

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return $contact_id ? $contact_id : ( ! empty( $response['id'] ) ? (int) $response['id'] : 0 );
}

/**
 * Bepaal het land voor HubSpot contact records.
 */
function ggr_hubspot_get_contact_country( $user_id ) {
    $country_fields = array(
        'address_country',
        'ggr_kyc_country',
        'ggr_kyc_birth_country',
    );

    foreach ( $country_fields as $field ) {
        $value = get_user_meta( $user_id, $field, true );
        if ( $value ) {
            return $value;
        }
    }

    return '';
}

/**
 * Ophalen en normaliseren van geboortedatum voor HubSpot contacts.
 */
function ggr_hubspot_get_birth_date( $user_id ) {
    $birth_fields = array(
        'ggr_kyc_birth_date',
        'birth_date',
    );

    foreach ( $birth_fields as $field ) {
        $value = get_user_meta( $user_id, $field, true );

        if ( ! $value ) {
            continue;
        }

        $timestamp = strtotime( $value );

        if ( $timestamp ) {
        if ( ! $timestamp ) {
            continue;
        }

        // portal data formattere naar hubspot data oftewel DD-MM-YYYY (contact value).
        return gmdate( 'd-m-Y', $timestamp );
    }

    return '';
}

/**
 * Format last login als DD-MM-YYYY HH:MM voor HubSpot contact properties.
 */
function ggr_hubspot_get_last_login_display( $value ) {
    if ( ! $value ) {
        return '';
    }

    $timestamp = is_numeric( $value ) ? (int) $value : strtotime( $value );

    if ( ! $timestamp ) {
        return '';
    }

    return gmdate( 'd-m-Y H:i', $timestamp );
}

/**
 * Deal upserten / dealstage bijwerken
 */
function ggr_hubspot_upsert_deal( $user_id, $contact_id, $status ) {
    $pipeline = ggr_hubspot_get_pipeline_id();

    if ( ! $pipeline ) {
        return new WP_Error( 'missing_pipeline', 'HubSpot pipeline ID ontbreekt.' );
    }

    $stage_mapping = ggr_hubspot_get_stage_mapping();
    $deal_stage    = isset( $stage_mapping[ $status ] ) ? $stage_mapping[ $status ] : '';

    if ( ! $deal_stage ) {
        return new WP_Error(
            'missing_stage_mapping',
            'Geen HubSpot dealstage mapping voor status: ' . $status
        );
    }

    $deal_id = get_user_meta( $user_id, 'ggr_hubspot_deal_id', true );
    $user    = get_user_by( 'id', $user_id );

    $properties = array(
        'dealname'  => $user ? $user->display_name : 'Nieuwe lead',
        'pipeline'  => $pipeline,
        'dealstage' => $deal_stage,
        'amount'    => get_user_meta( $user_id, 'ggr_participation_amount', true ),
        'account_type' => get_user_meta( $user_id, 'ggr_account_type', true ),
    );

    $properties = array_filter(
        apply_filters( 'ggr_hubspot_deal_properties', $properties, $user_id, $status )
    );
    
    $properties = ggr_hubspot_filter_known_properties( $properties, 'deals' );

    if ( $deal_id ) {

        // Update bestaande deal
        $response = ggr_hubspot_request(
            'PATCH',
            '/crm/v3/objects/deals/' . $deal_id,
            array( 'properties' => $properties )
        );

    } else {

        // Nieuwe deal + associatie met contact
        $associations = array();

        if ( $contact_id ) {
            $associations[] = array(
                'to' => array( 'id' => $contact_id ),
                'types' => array(
                    array(
                        'associationCategory' => 'HUBSPOT_DEFINED',
                        'associationTypeId'   => 3, // Deal ↔ Contact
                    ),
                ),
            );
        }

        $response = ggr_hubspot_request(
            'POST',
            '/crm/v3/objects/deals',
            array(
                'properties'   => $properties,
                'associations' => $associations,
            )
        );

        if ( ! is_wp_error( $response ) && ! empty( $response['id'] ) ) {
            update_user_meta( $user_id, 'ggr_hubspot_deal_id', (int) $response['id'] );
        }
    }

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return ! empty( $response['id'] ) ? (int) $response['id'] : (int) $deal_id;
}

/**
 * Volledige sync uitvoeren: contact + deal
 */
function ggr_hubspot_sync_user( $user_id, $status = null ) {
    if ( ! ggr_hubspot_get_private_token() ) {
        return;
    }

    $status = $status ? $status : ( function_exists( 'onboarding_get_status' ) ? onboarding_get_status( $user_id ) : '' );

    $contact_id = ggr_hubspot_upsert_contact( $user_id );

    if ( is_wp_error( $contact_id ) ) {
        error_log( 'HubSpot contact sync mislukt: ' . $contact_id->get_error_message() );
        return;
    }

    if ( $status ) {
        $deal = ggr_hubspot_upsert_deal( $user_id, $contact_id, $status );

        if ( is_wp_error( $deal ) ) {
            error_log( 'HubSpot deal sync mislukt: ' . $deal->get_error_message() );
            error_log( 'HubSpot deal sync error data: ' . print_r( $deal->get_error_data(), true ) );

        }
    }
}

/**
 * Alleen laatste login pushen (zonder stage bij te werken)
 */
function ggr_hubspot_sync_last_login( $user_id ) {
    if ( ! ggr_hubspot_get_private_token() ) {
        return;
    }

    $contact_id = get_user_meta( $user_id, 'ggr_hubspot_contact_id', true );

    if ( ! $contact_id ) {
        $contact_id = ggr_hubspot_upsert_contact( $user_id );

        if ( is_wp_error( $contact_id ) || ! $contact_id ) {
            error_log( 'HubSpot contact sync (login) mislukt.' );
            return;
        }
    }

    $last_login = get_user_meta( $user_id, 'ggr_last_login_at', true );
    $payload    = array(
        'properties' => apply_filters(
            'ggr_hubspot_last_login_properties',
            array(
                ggr_hubspot_property_key( 'contacts', 'last_login_at' ) => ggr_hubspot_get_last_login_display(
                    $last_login ? $last_login : current_time( 'timestamp', true )
                ),
            ),
            $user_id
        ),
    );

    $payload['properties'] = ggr_hubspot_filter_known_properties( $payload['properties'], 'contacts' );
    
    $response = ggr_hubspot_request( 'PATCH', '/crm/v3/objects/contacts/' . $contact_id, $payload );

    if ( is_wp_error( $response ) ) {
        error_log( 'HubSpot last login sync mislukt: ' . $response->get_error_message() );
    }
}

/**
 * REST endpoint voor healthcheck of handmatige sync
 */
add_action( 'rest_api_init', function() {
    register_rest_route(
        'ggr-portal/v1',
        '/hubspot-webhook',
        array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => function() {
                    return array( 'status' => 'ok', 'timestamp' => current_time( 'mysql' ) );
                },
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'ggr_hubspot_rest_sync',
                'permission_callback' => 'ggr_hubspot_validate_rest_request',
                'args'                => array(
                    'user_id' => array(
                        'required'          => false,
                        'validate_callback' => function( $value, $request, $param ) {
                            return is_numeric( $value );
                        },
                    ),
                    'status'  => array(
                        'required' => false,
                    ),
                ),
            ),
        )
    );
} );

function ggr_hubspot_validate_rest_request( $request ) {
    $secret = ggr_hubspot_get_webhook_secret();

    if ( ! $secret ) {
        return true;
    }

    $provided = $request->get_header( 'x-ggr-webhook-secret' );

    if ( ! $provided ) {
        $provided = $request->get_param( 'token' );
    }

    return hash_equals( $secret, (string) $provided );
}

function ggr_hubspot_rest_sync( WP_REST_Request $request ) {
    $user_id = (int) $request->get_param( 'user_id' );
    $status  = $request->get_param( 'status' );

    if ( $user_id ) {
        ggr_hubspot_sync_user( $user_id, $status );

        return array(
            'synced_user' => $user_id,
            'status'      => $status ? $status : ( function_exists( 'onboarding_get_status' ) ? onboarding_get_status( $user_id ) : '' ),
        );
    }

    return array( 'message' => 'Geen gebruiker-id opgegeven.' );
}

/**
 * Trigger HubSpot contact sync bij wijzigingen in relevante user_meta velden.
 */
add_action( 'updated_user_meta', 'ggr_hubspot_contact_meta_changed', 10, 4 );
add_action( 'added_user_meta',   'ggr_hubspot_contact_meta_changed', 10, 4 );

function ggr_hubspot_contact_meta_changed( $meta_id, $user_id, $meta_key, $meta_value ) {

    // Standaard velden + alle ggr_* meta velden moeten een sync triggeren.
    $watched = array(
        'phone',
        'address_country',
        'ggr_kyc_country',
        'ggr_kyc_birth_country',
        'ggr_nationality',
        'ggr_account_type',
        'ggr_participation_amount',
        'ggr_kyc_birth_date',
        'birth_date',
        'first_name',
        'last_name',
        'ggr_last_login_at',
    );

    $is_ggr_field = strpos( $meta_key, 'ggr_' ) === 0;

    if ( ! $is_ggr_field && ! in_array( $meta_key, $watched, true ) ) {
        return;
    }

    ggr_hubspot_trigger_contact_sync( (int) $user_id );
}

add_action( 'profile_update', 'ggr_hubspot_user_profile_updated', 10, 2 );

function ggr_hubspot_user_profile_updated( $user_id, $old_user_data ) {
    ggr_hubspot_trigger_contact_sync( (int) $user_id );
}

/**
 * Start een (gethrottelde) contact sync als HubSpot tokens aanwezig zijn.
 */
function ggr_hubspot_trigger_contact_sync( $user_id ) {
    if ( ! ggr_hubspot_get_private_token() ) {
        return;
    }

    // Throttle per user om spam/rate limits te voorkomen
    $lock_key = 'ggr_hubspot_contact_sync_lock_' . (int) $user_id;
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, 1, 30 );

    // Alleen contact updaten (geen dealstage nodig bij elke profielwijziging)
    $contact_id = ggr_hubspot_upsert_contact( (int) $user_id );

    if ( is_wp_error( $contact_id ) ) {
        error_log( 'HubSpot contact sync (meta change) mislukt: ' . $contact_id->get_error_message() );
    }
}
}
