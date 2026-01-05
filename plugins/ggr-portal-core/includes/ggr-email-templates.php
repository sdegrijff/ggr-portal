<?php
/**
 * E-mailtemplates voor GGR Portal
 *
 * Regelt:
 * - CPT voor e-mailtemplates
 * - Metaboxen (key, subject, actief)
 * - Test-e-mail versturen vanuit de editor
 * - Ophalen / renderen / versturen op basis van key + placeholders
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Haal de greeting name op met fallback.
 *
 * Ondersteunt:
 * - WP_User object
 * - user array (zoals in email_change_email hook)
 * - user ID
 */
function ggr_portal_get_greeting_name( $user, $fallback = true ) {
    // Normaliseer naar WP_User
    if ( $user instanceof WP_User ) {
        $wp_user = $user;
    } elseif ( is_array( $user ) && isset( $user['ID'] ) ) {
        $wp_user = get_user_by( 'id', (int) $user['ID'] );
        if ( ! $wp_user ) {
            return '';
        }
    } else {
        // Aanname: numeric ID
        $wp_user = get_user_by( 'id', (int) $user );
        if ( ! $wp_user ) {
            return '';
        }
    }

    $greeting_name = trim( get_user_meta( $wp_user->ID, 'ggr_greeting_name', true ) );
    if ( '' !== $greeting_name ) {
        return $greeting_name;
    }

    if ( ! $fallback ) {
        return '';
    }

    $first_name = trim( get_user_meta( $wp_user->ID, 'first_name', true ) );
    if ( '' !== $first_name ) {
        return $first_name;
    }

    return ! empty( $wp_user->display_name ) ? $wp_user->display_name : $wp_user->user_login;
}

/**
 * Mooie weergavenaam ophalen met fallbacks:
 * 1. Greeting name (user_meta 'ggr_greeting_name')
 * 2. Voor- en achternaam
 * 3. display_name
 * 4. user_login
 *
 * Ondersteunt:
 * - WP_User object
 * - user array (zoals in email_change_email hook)
 * - user ID
 */
function ggr_portal_get_nice_user_name( $user ) {
    // Normaliseer naar WP_User
    if ( $user instanceof WP_User ) {
        $wp_user = $user;
    } elseif ( is_array( $user ) && isset( $user['ID'] ) ) {
        $wp_user = get_user_by( 'id', (int) $user['ID'] );
        if ( ! $wp_user ) {
            return '';
        }
    } else {
        // Aanname: numeric ID
        $wp_user = get_user_by( 'id', (int) $user );
        if ( ! $wp_user ) {
            return '';
        }
    }

    $greeting_name = ggr_portal_get_greeting_name( $wp_user, false );
    if ( '' !== $greeting_name ) {
        return $greeting_name;
    }

    // 2. Voor- en achternaam
    $first_name = trim( get_user_meta( $wp_user->ID, 'first_name', true ) );
    $last_name  = trim( get_user_meta( $wp_user->ID, 'last_name', true ) );

    if ( '' !== $first_name || '' !== $last_name ) {
        return trim( $first_name . ' ' . $last_name );
    }

    // 3. display_name
    if ( ! empty( $wp_user->display_name ) ) {
        return $wp_user->display_name;
    }

    // 4. user_login
    return $wp_user->user_login;
}



/**
 * 1. Custom Post Type registreren
 */
add_action( 'init', 'ggr_portal_register_email_templates_cpt' );
function ggr_portal_register_email_templates_cpt() {

    $labels = [
        'name'               => 'E-mailtemplates',
        'singular_name'      => 'E-mailtemplate',
        'menu_name'          => 'E-mailtemplates',
        'add_new'            => 'Nieuwe template',
        'add_new_item'       => 'Nieuwe e-mailtemplate',
        'edit_item'          => 'E-mailtemplate bewerken',
        'new_item'           => 'Nieuwe e-mailtemplate',
        'view_item'          => 'E-mailtemplate bekijken',
        'search_items'       => 'E-mailtemplates zoeken',
        'not_found'          => 'Geen e-mailtemplates gevonden',
        'not_found_in_trash' => 'Geen e-mailtemplates in prullenbak',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        // Als je het straks onder een bestaand GGR-menu wilt hangen,
        // vervang true door de slug van jouw hoofdmenu.
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'supports'           => [ 'title', 'editor' ],
        'menu_position'      => 25,
    ];

    register_post_type( 'ggr_email_template', $args );
}

add_filter( 'manage_edit-ggr_email_template_columns', 'ggr_email_template_admin_columns' );
add_action( 'manage_ggr_email_template_posts_custom_column', 'ggr_email_template_admin_column_render', 10, 2 );

function ggr_email_template_admin_columns( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $label ) {
        $new_columns[ $key ] = $label;
        if ( 'title' === $key ) {
            $new_columns['ggr_email_template_recipient'] = 'Ontvanger';
        }
    }

    if ( ! isset( $new_columns['ggr_email_template_recipient'] ) ) {
        $new_columns['ggr_email_template_recipient'] = 'Ontvanger';
    }

    return $new_columns;
}

function ggr_email_template_admin_column_render( $column, $post_id ) {
    if ( 'ggr_email_template_recipient' !== $column ) {
        return;
    }

    $role = get_post_meta( $post_id, '_ggr_email_recipient_role', true );
    $recipient_label = 'Participant';

    if ( in_array( $role, array( 'administrator', 'admin' ), true ) ) {
        $recipient_label = 'Admin';
    } elseif ( 'lead' === $role ) {
        $recipient_label = 'Lead';
    }

    echo esc_html( $recipient_label );
}

/**
 * 2. Meta-boxen voor key, subject en status
 */
add_action( 'add_meta_boxes', 'ggr_portal_add_email_template_metaboxes' );
function ggr_portal_add_email_template_metaboxes() {
    add_meta_box(
        'ggr_email_template_settings',
        'E-mail instellingen',
        'ggr_portal_render_email_template_metabox',
        'ggr_email_template',
        'normal',
        'high'
    );
}

function ggr_portal_render_email_template_metabox( $post ) {
    wp_nonce_field( 'ggr_email_template_save', 'ggr_email_template_nonce' );

    $key            = get_post_meta( $post->ID, '_ggr_email_key', true );
    $subject        = get_post_meta( $post->ID, '_ggr_email_subject', true );
    $active         = get_post_meta( $post->ID, '_ggr_email_active', true );
    $recipient_role = get_post_meta( $post->ID, '_ggr_email_recipient_role', true );
    if ( ! $recipient_role ) {
        $recipient_role = 'participant';
    }
    $available_template_keys = array(
        'account_welcome'              => 'Account welkom',
        'account_activated'            => 'Account geactiveerd',
        'two_factor_code'              => '2FA code',
        'password_reset'               => 'Wachtwoord reset',
        'password_changed'             => 'Wachtwoord gewijzigd',
        'email_changed'                => 'E-mailadres gewijzigd',
        'new_portal_message'           => 'Nieuw portalbericht',
        'help_request_confirmation'    => 'Helpvraag bevestiging',
        'feedback_thanks'              => 'Bedankt voor je feedback',        
        'documents_approved'           => 'Documenten goedgekeurd',
        'documents_rejected'           => 'Documenten afgekeurd',
        'onboarding_email_verification'=> 'Onboarding e-mailverificatie',
        'referral_invite'              => 'Verwijs-vriend uitnodiging',
        'admin_new_melding'            => 'Admin: nieuwe melding',
        'admin_ibkr_nav_success'       => 'Admin: IBKR NAV opgeslagen',
        'admin_dividend_import'        => 'Admin: dividendtransacties opgehaald',        
    );
    $available_placeholders = array(
        'user_display_name'        => 'Naam van de gebruiker',
        'account_email'            => 'Account e-mailadres',
        'portal_link'              => 'Link naar het portaal',
        'login_link'               => 'Login-link',
        'two_factor_code'          => '2FA code',
        'two_factor_valid_minutes' => 'Geldigheid 2FA-code (minuten)',
        'reset_link'               => 'Wachtwoord reset-link',
        'reset_valid_minutes'      => 'Geldigheid reset-link (minuten)',
        'message_title'            => 'Titel van het bericht',
        'message_date'             => 'Datum van het bericht',
        'message_url'              => 'Deeplink naar het bericht',
        'help_message'             => 'Helpvraag van de gebruiker',
        'feedback_message'         => 'Feedback van de gebruiker',
        'melding_title'            => 'Titel van de melding',
        'melding_url'              => 'Link naar de melding',
        'melding_type'             => 'Type melding',
        'melding_status'           => 'Status melding',
        'melding_author'           => 'Auteur van de melding',
        'referrer_name'            => 'Naam van de verwijzer',
        'referrer_email'           => 'E-mailadres van de verwijzer',
        'referral_link'            => 'Verwijzingslink',
        'old_email'                => 'Oud e-mailadres',
        'new_email'                => 'Nieuw e-mailadres',
        'site_name'                => 'Naam van de site',
        'site_url'                 => 'URL van de site',
        'admin_email'              => 'Admin e-mailadres',
        'contract_link'            => 'Link naar contract in onboarding',
        'rejection_feedback'       => 'Feedback bij afkeuring',
        'verification_link'        => 'Verificatielink',
        'ibkr_run_timestamp'       => 'Datum/tijd van IBKR Flex run',
        'ibkr_report_date'         => 'Rapportdatum (IBKR NAV)',
        'ibkr_nav_per_participation' => 'NAV per participatie',
        'ibkr_total'               => 'Totaal uit IBKR',
        'ibkr_participations'      => 'Aantal participaties',
        'dividend_imported_count'  => 'Aantal opgeslagen dividendtransacties',
        'dividend_report_date'     => 'Laatste rapportdatum dividendtransacties',
        'dividend_total_count'     => 'Aantal transacties in Flex statement',
        'dividend_duplicate_count' => 'Aantal duplicates in Flex statement',
        'dividend_statement_url'   => 'Link naar Flex statement',        
    );
    ?>
    <p>
        <label for="ggr_email_key"><strong>Template key (uniek, technisch):</strong></label><br>
        <input type="text" id="ggr_email_key" name="ggr_email_key"
               value="<?php echo esc_attr( $key ); ?>"
               style="width: 100%; max-width: 400px;">
        <small>Bijv: <code>new_portal_message</code>, <code>password_reset</code>, <code>two_factor_code</code></small>
    </p>

    <p>
        <label for="ggr_email_subject"><strong>Onderwerp:</strong></label><br>
        <input type="text" id="ggr_email_subject" name="ggr_email_subject"
               value="<?php echo esc_attr( $subject ); ?>"
               style="width: 100%;">
    </p>

    <p>
        <label>
            <input type="checkbox" name="ggr_email_active" value="1" <?php checked( $active, '1' ); ?>>
            Template actief
        </label>
    </p>

    <p><strong>Doelgroep</strong></p>
    <p class="description">Kies de ontvanger voor deze e-mailtemplate.</p>
    <p>
        <label for="ggr_email_recipient_role"><strong>Ontvanger</strong></label><br/>
        <select name="ggr_email_recipient_role" id="ggr_email_recipient_role">
            <option value="participant" <?php selected( $recipient_role, 'participant' ); ?>>Participant</option>
            <option value="lead" <?php selected( $recipient_role, 'lead' ); ?>>Lead</option>
            <option value="administrator" <?php selected( $recipient_role, 'administrator' ); ?>>Admin</option>
        </select>
    </p>

    <hr>

    <p><strong>Shortcodes naar de mails zelf (template keys):</strong></p>
    <ul style="list-style: disc; margin-left: 20px;">
        <?php foreach ( $available_template_keys as $template_key => $label ) : ?>
            <li><code><?php echo esc_html( $template_key ); ?></code> — <?php echo esc_html( $label ); ?></li>
        <?php endforeach; ?>
    </ul>
    <p>Gebruik deze sleutel in de “Template key” hierboven om de juiste mail te koppelen.</p>

    <p><strong>Shortcodes die je in de mail kunt gebruiken (placeholders):</strong></p>
    <ul style="list-style: disc; margin-left: 20px;">
        <?php foreach ( $available_placeholders as $placeholder => $label ) : ?>
            <li><code><?php echo esc_html( '{{' . $placeholder . '}}' ); ?></code> — <?php echo esc_html( $label ); ?></li>
        <?php endforeach; ?>
    </ul>
    <p>Gebruik deze in de editor hierboven in de tekst van de e-mail.</p>

    <hr>

    <h4>Test e-mail versturen</h4>
    <p>Vul een e-mailadres in en klik op “Test e-mail versturen”. De template wordt met voorbeelddata naar dat adres gestuurd.</p>

    <p>
        <label for="ggr_test_email"><strong>Test e-mailadres:</strong></label><br>
        <input type="email"
               id="ggr_test_email"
               name="ggr_test_email"
               placeholder="jij@voorbeeld.nl"
               style="width:100%; max-width:400px;">
    </p>

    <p>
        <button type="submit"
                class="button button-secondary"
                name="ggr_send_test_email"
                value="1">
            Test e-mail versturen
        </button>
    </p>
    <?php
}

/**
 * 3. Meta opslaan + (optioneel) test-e-mail versturen
 */
add_action( 'save_post_ggr_email_template', 'ggr_portal_save_email_template_meta' );
function ggr_portal_save_email_template_meta( $post_id ) {
    if ( ! isset( $_POST['ggr_email_template_nonce'] )
        || ! wp_verify_nonce( $_POST['ggr_email_template_nonce'], 'ggr_email_template_save' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Meta opslaan
    $key     = isset( $_POST['ggr_email_key'] ) ? sanitize_key( $_POST['ggr_email_key'] ) : '';
    $subject = isset( $_POST['ggr_email_subject'] ) ? sanitize_text_field( $_POST['ggr_email_subject'] ) : '';
    $active  = isset( $_POST['ggr_email_active'] ) ? '1' : '0';
    $recipient_role = isset( $_POST['ggr_email_recipient_role'] ) ? sanitize_key( $_POST['ggr_email_recipient_role'] ) : 'participant';
    if ( ! in_array( $recipient_role, array( 'participant', 'lead', 'administrator', 'admin' ), true ) ) {
        $recipient_role = 'participant';
    }
    
    update_post_meta( $post_id, '_ggr_email_key', $key );
    update_post_meta( $post_id, '_ggr_email_subject', $subject );
    update_post_meta( $post_id, '_ggr_email_active', $active );
    update_post_meta( $post_id, '_ggr_email_recipient_role', $recipient_role );

    /**
     * Test-e-mail versturen als de button is gebruikt
     * Let op: dit gebeurt tegelijk met het opslaan van de post.
     */
    if ( isset( $_POST['ggr_send_test_email'] ) ) {
        $test_email = isset( $_POST['ggr_test_email'] ) ? sanitize_email( wp_unslash( $_POST['ggr_test_email'] ) ) : '';

        // We zetten de uitkomst in een transient per gebruiker, die we in admin_notices tonen
        $transient_key = 'ggr_email_test_' . get_current_user_id();
        $status        = '0';

        if ( $test_email && is_email( $test_email ) ) {
            $post = get_post( $post_id );
            if ( $post && $post->post_type === 'ggr_email_template' ) {

                $subject_raw = get_post_meta( $post_id, '_ggr_email_subject', true );
                $body_raw    = apply_filters( 'the_content', $post->post_content );

                if ( ! $subject_raw ) {
                    $subject_raw = '(Geen onderwerp ingesteld)';
                }

                // Dummy placeholders voor test
                $placeholders = [
                    'user_display_name'        => 'Test gebruiker',
                    'account_email'            => 'test@example.com',
                    'portal_link'              => home_url( '/' ),
                    'two_factor_code'          => '123456',
                    'two_factor_valid_minutes' => '10',
                    'reset_link'               => home_url( '/wachtwoord-reset-test/' ),
                    'message_title'            => 'Voorbeeldbericht',
                    'message_date'             => date_i18n( 'd-m-Y' ),
                    'login_link'               => home_url( '/login/' ),
                    'help_message'             => 'Voorbeeld helpbericht.',
                    'feedback_message'         => 'Voorbeeld feedbackbericht.',
                    'melding_title'            => 'Nieuwe melding',
                    'melding_url'              => admin_url( 'admin.php?page=ggr-meldingen' ),
                    'melding_type'             => 'help',
                    'melding_status'           => 'nieuw',
                    'melding_author'           => 'Test deelnemer',                
                    'referrer_name'            => 'Test verwijzer',
                    'referrer_email'           => 'verwijzer@example.com',
                    'referral_link'            => home_url( '/investeerder-worden/' ),   
                    'ibkr_run_timestamp'       => date_i18n( 'Y-m-d H:i:s' ),
                    'ibkr_report_date'         => date_i18n( 'Y-m-d' ),
                    'ibkr_nav_per_participation' => '96,705867',
                    'ibkr_total'               => '34.999,83',
                    'ibkr_participations'      => '361,9060',
                    'dividend_imported_count'  => '12',
                    'dividend_report_date'     => date_i18n( 'Y-m-d' ),
                    'dividend_total_count'     => '15',
                    'dividend_duplicate_count' => '3',
                    'dividend_statement_url'   => home_url( '/flex-statement-test/' ),                    
                ];

                $replacements = [];
                foreach ( $placeholders as $k => $v ) {
                    $replacements[ '{{' . $k . '}}' ] = $v;
                }

                $subject_rendered = strtr( $subject_raw, $replacements );
                $body_rendered    = strtr( $body_raw, $replacements );

                $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

                $sent = wp_mail( $test_email, $subject_rendered, $body_rendered, $headers );

                if ( $sent ) {
                    $status = '1';
                }
            }
        }

        set_transient( $transient_key, $status, 60 );
    }
}

/**
 * 4. Template ophalen op basis van key (alleen actieve, gepubliceerde templates)
 */
function ggr_portal_get_email_template( $key ) {
    if ( empty( $key ) ) {
        return null;
    }

    $args = [
        'post_type'      => 'ggr_email_template',
        'post_status'    => 'publish',
        'meta_key'       => '_ggr_email_key',
        'meta_value'     => $key,
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ];

    $query = new WP_Query( $args );

    if ( ! $query->have_posts() ) {
        return null;
    }

    $post = $query->posts[0];

    $active = get_post_meta( $post->ID, '_ggr_email_active', true );
    if ( $active !== '1' ) {
        return null;
    }

    $subject = get_post_meta( $post->ID, '_ggr_email_subject', true );
    $body    = apply_filters( 'the_content', $post->post_content );

    return [
        'id'      => $post->ID,
        'subject' => $subject,
        'body'    => $body,
    ];
}

/**
 * 5. Renderen met placeholders
 */
function ggr_portal_render_email( $key, $placeholders = [] ) {
    $tpl = ggr_portal_get_email_template( $key );
    if ( ! $tpl ) {
        return null;
    }

    $replacements = [];
    foreach ( $placeholders as $p_key => $value ) {
        // Verwacht placeholders in de vorm {{key}}
        $replacements[ '{{' . $p_key . '}}' ] = $value;
    }

    $subject = strtr( $tpl['subject'], $replacements );
    $body    = strtr( $tpl['body'], $replacements );

    return [
        'subject' => $subject,
        'body'    => $body,
    ];
}

/**
 * 6. Helper om direct een mail te sturen (productiegebruik)
 */
function ggr_portal_send_templated_email( $template_key, $user_id, $extra_placeholders = [] ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return false;
    }

        $default_placeholders = [
            'user_display_name' => ggr_portal_get_nice_user_name( $user ),
            'account_email'     => $user->user_email,
            'portal_link'       => home_url( '/' ),
            'login_link'        => wp_login_url(),
        ];


    $placeholders = array_merge( $default_placeholders, $extra_placeholders );

    $rendered = ggr_portal_render_email( $template_key, $placeholders );
    if ( ! $rendered ) {
        return false;
    }

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    return wp_mail( $user->user_email, $rendered['subject'], $rendered['body'], $headers );
}

/**
 * Helper om een template te sturen naar het admin adres.
 */
function ggr_portal_send_admin_templated_email( $template_key, $extra_placeholders = [], $admin_email = '' ) {
    $email = $admin_email ? $admin_email : get_option( 'admin_email' );
    if ( ! $email || ! is_email( $email ) ) {
        return false;
    }

    $placeholders = array_merge(
        [
            'portal_link' => home_url( '/' ),
            'login_link'  => wp_login_url(),
        ],
        $extra_placeholders
    );

    $rendered = ggr_portal_render_email( $template_key, $placeholders );
    if ( ! $rendered ) {
        return false;
    }

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    return wp_mail( $email, $rendered['subject'], $rendered['body'], $headers );
}

/**
 * Notificatie wanneer een account de rol participant krijgt.
 */
add_action( 'set_user_role', 'ggr_portal_send_participant_activation_email', 10, 3 );
function ggr_portal_send_participant_activation_email( $user_id, $role, $old_roles ) {
    if ( 'participant' !== $role ) {
        return;
    }

    if ( in_array( 'participant', (array) $old_roles, true ) ) {
        return;
    }

    $dashboard_url = home_url( '/dashboard/' );
    $payload       = array(
        'portal_link' => $dashboard_url,
        'login_link'  => wp_login_url( $dashboard_url ),
    );

    $sent = ggr_portal_send_templated_email( 'account_welcome', $user_id, $payload );

    if ( ! $sent ) {
        ggr_portal_send_templated_email( 'account_activated', $user_id, $payload );
    }
}

/**
 * Welkomstmail bij het handmatig aanmaken van accounts (admin).
 */
add_action( 'user_register', 'ggr_portal_send_account_created_email', 10, 1 );
function ggr_portal_send_account_created_email( $user_id ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return;
    }

    if ( in_array( 'lead', (array) $user->roles, true ) ) {
        return;
    }

    $dashboard_url = home_url( '/dashboard/' );
    $payload       = array(
        'portal_link' => $dashboard_url,
        'login_link'  => wp_login_url( $dashboard_url ),
    );

    $sent = ggr_portal_send_templated_email( 'account_welcome', $user_id, $payload );

    if ( ! $sent ) {
        ggr_portal_send_templated_email( 'account_activated', $user_id, $payload );
    }
}

/**
 * 7. Admin notice na test-e-mail
 */
add_action( 'admin_notices', 'ggr_portal_email_template_test_notice' );
function ggr_portal_email_template_test_notice() {
    if ( ! is_admin() ) {
        return;
    }

    $transient_key = 'ggr_email_test_' . get_current_user_id();
    $status        = get_transient( $transient_key );

    if ( $status === false ) {
        return;
    }

    delete_transient( $transient_key );

    if ( $status === '1' ) {
        echo '<div class="notice notice-success is-dismissible"><p>Test e-mail is verstuurd.</p></div>';
    } elseif ( $status === '0' ) {
        echo '<div class="notice notice-error is-dismissible"><p>Test e-mail kon niet worden verstuurd. Controleer het e-mailadres of je mailconfiguratie.</p></div>';
    }
}



/**
 * 9. Override WordPress "email changed" notification met eigen template
 */
add_filter( 'email_change_email', 'ggr_portal_custom_email_change_email', 10, 3 );

function ggr_portal_custom_email_change_email( $email, $user, $userdata ) {
    // Veiligheid: als de templating nog niet bestaat, laat WP default gedrag doen
    if ( ! function_exists( 'ggr_portal_render_email' ) ) {
        return $email;
    }

    $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

    $placeholders = [
        // HIER de nieuwe logica
        'user_display_name' => ggr_portal_get_nice_user_name( $user ),

        'old_email'         => $user->user_email,
        'new_email'         => isset( $userdata['user_email'] ) ? $userdata['user_email'] : '',
        'site_name'         => $site_name,
        'site_url'          => home_url( '/' ),
        'portal_link'       => home_url( '/' ),
        'login_link'        => wp_login_url(),
        'admin_email'       => get_option( 'admin_email' ),
    ];

    // Render onze eigen template met key "email_changed"
    $rendered = ggr_portal_render_email( 'email_changed', $placeholders );

    if ( ! $rendered ) {
        return $email;
    }

    $email['subject'] = $rendered['subject'];
    $email['message'] = $rendered['body'];

    // Zorg dat het echt als HTML wordt verstuurd
    $headers = [];

    if ( ! empty( $email['headers'] ) ) {
        if ( is_array( $email['headers'] ) ) {
            $headers = $email['headers'];
        } else {
            $headers[] = $email['headers'];
        }
    }

    $headers[]        = 'Content-Type: text/html; charset=UTF-8';
    $email['headers'] = $headers;

    return $email;
}

/**
 * Admin notificatie "Password changed for user: username" uitschakelen
 *
 * Dit is een pluggable functie in WordPress. Door hem hier leeg te definieren,
 * wordt de core-versie niet meer gebruikt en krijgt de admin geen mail meer.
 */
if ( ! function_exists( 'wp_password_change_notification' ) ) {
    function wp_password_change_notification( $user ) {
        // bewust leeg: we willen geen admin e-mail bij wachtwoordwijziging
        return;
    }
}

/**
 * 10. Override WordPress "password changed" e-mail naar gebruiker
 *
 * WordPress bouwt de bevestigingsmail voor de user via password_change_email.
 * Hier hangen we onze eigen template aan (key: password_changed).
 */
add_filter( 'password_change_email', 'ggr_portal_custom_password_change_email', 10, 3 );

function ggr_portal_custom_password_change_email( $pass_change_email, $user, $userdata ) {
    // Als templating niet beschikbaar is, gebruik standaard WP gedrag
    if ( ! function_exists( 'ggr_portal_render_email' ) ) {
        return $pass_change_email;
    }

    $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

    $placeholders = [
        'user_display_name' => ggr_portal_get_nice_user_name( $user ),
        'site_name'         => $site_name,
        'site_url'          => home_url( '/' ),
        'portal_link'       => home_url( '/' ),
        'login_link'        => wp_login_url(),
        'admin_email'       => get_option( 'admin_email' ),
    ];

    // Render onze eigen template met key "password_changed"
    $rendered = ggr_portal_render_email( 'password_changed', $placeholders );
    if ( ! $rendered ) {
        // Als template niet actief/niet gevonden is: fallback naar default e-mail
        return $pass_change_email;
    }

    // Zorg dat de mail naar de user zelf gaat
    if ( isset( $user['user_email'] ) && is_email( $user['user_email'] ) ) {
        $pass_change_email['to'] = $user['user_email'];
    }

    $pass_change_email['subject'] = $rendered['subject'];
    $pass_change_email['message'] = $rendered['body'];

    // Forceer HTML headers (WordPress verwacht hier een string, geen array)
    $headers = isset( $pass_change_email['headers'] ) ? $pass_change_email['headers'] : '';
    if ( ! is_string( $headers ) ) {
        $headers = '';
    }

    if ( stripos( $headers, 'content-type:' ) === false ) {
        $headers .= "\r\nContent-Type: text/html; charset=UTF-8";
    }

    $pass_change_email['headers'] = trim( $headers );

    return $pass_change_email;
}

/**
 * 11. E-mail notificaties bij nieuwe berichten
 *
 * - Stuurt alleen mails wanneer een ggr_bericht van niet-publish -> publish gaat
 * - Bepaalt doelgroep o.b.v. meta (audience/role/user) + ggr_portal_user_can_read_message()
 */
add_action( 'transition_post_status', 'ggr_portal_on_message_published', 10, 3 );

function ggr_portal_on_message_published( $new_status, $old_status, $post ) {
    // Alleen ons eigen CPT
    if ( ! $post instanceof WP_Post || $post->post_type !== 'ggr_bericht' ) {
        return;
    }

    // Alleen op het moment dat een bericht gepubliceerd wordt
    if ( $new_status !== 'publish' || $old_status === 'publish' ) {
        return;
    }

    // Ontvangers bepalen
    $recipient_ids = ggr_portal_get_message_recipient_user_ids( $post );
    if ( empty( $recipient_ids ) ) {
        return;
    }

    foreach ( $recipient_ids as $user_id ) {
        ggr_portal_send_new_message_notification( $user_id, $post );
    }
}

/**
 * Bepaal lijst user IDs die een notificatie moeten krijgen voor dit bericht.
 *
 * Let op:
 * - We nemen een "ruime" doelgroep (alle relevante users)
 * - Daarna filteren we met ggr_portal_user_can_read_message() zodat alleen
 *   users die het bericht daadwerkelijk kunnen zien overblijven.
 */
function ggr_portal_get_message_recipient_user_ids( $post ) {
    if ( ! $post instanceof WP_Post ) {
        $post = get_post( $post );
    }
    if ( ! $post || $post->post_type !== 'ggr_bericht' ) {
        return array();
    }

    $audience  = get_post_meta( $post->ID, '_ggr_message_audience', true );
    $target_id = absint( get_post_meta( $post->ID, '_ggr_message_user_id', true ) );
    $role      = get_post_meta( $post->ID, '_ggr_message_role', true );

    $user_ids = array();

    switch ( $audience ) {
        case 'user':
            if ( $target_id ) {
                $user_ids[] = $target_id;
            }
            break;

        case 'role':
            if ( $role ) {
                $users = get_users( array(
                    'role'   => $role,
                    'fields' => 'ID',
                ) );
                $user_ids = $users ? $users : array();
            }
            break;

        case 'all':
        default:
            // In je UI staat "Iedereen (alle participants)" → dus niet ALLE WP-users.
            // Als je later meer rollen wilt meenemen, gebruik dan de filter hieronder.
            $roles = apply_filters(
                'ggr_portal_message_audience_all_roles',
                array( 'participant' )
            );

            $users = get_users( array(
                'role__in' => $roles,
                'fields'   => 'ID',
            ) );
            $user_ids = $users ? $users : array();
            break;
    }

    if ( empty( $user_ids ) ) {
        return array();
    }

    // Nu filteren we op wie het bericht daadwerkelijk mag zien
    $allowed_ids = array();
    foreach ( $user_ids as $uid ) {
        if ( ggr_portal_user_can_read_message( $post, $uid ) ) {
            $allowed_ids[] = (int) $uid;
        }
    }

    // Dubbele eruit
    $allowed_ids = array_values( array_unique( $allowed_ids ) );

    return $allowed_ids;
}

/**
 * 12. Notificatie voor nieuw portal-bericht via eigen e-mailtemplate
 *
 * Template key: new_portal_message
 * Zorg dat je in de CPT "E-mailtemplates" een template met deze key aanmaakt.
 *
 * Beschikbare placeholders in deze template:
 * - {{user_display_name}}  → al standaard uit ggr_portal_send_templated_email()
 * - {{portal_link}}        → link naar portaal
 * - {{message_title}}      → titel van het bericht
 * - {{message_date}}       → datum van het bericht
 * - {{message_url}}        → directe deeplink naar het bericht
 * - {{login_link}}         → loginpagina (optioneel met redirect)
 */
function ggr_portal_send_new_message_notification( $user_id, WP_Post $post ) {
    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) {
        return;
    }

    // Datum van het bericht voor in de e-mail
    $raw_date  = get_post_meta( $post->ID, '_ggr_message_date', true );
    if ( ! $raw_date ) {
        $raw_date = get_the_date( 'Y-m-d', $post );
    }
    $timestamp   = $raw_date ? strtotime( $raw_date . ' 00:00:00' ) : current_time( 'timestamp' );
    $message_date = date_i18n( 'd-m-Y', $timestamp );

    // URL naar jouw berichtenpagina (waar de shortcode [ggr_portal_berichten] staat)
    // Pas de default hier aan naar jouw echte slug.
    $messages_overview_url = apply_filters(
        'ggr_portal_messages_overview_url',
        home_url( '/berichten/' )
    );

    // Directe deeplink naar dit bericht binnen de portal
    $message_url = add_query_arg(
        'bericht',
        $post->ID,
        $messages_overview_url
    );

    // Optioneel: login-link die na inloggen direct naar het bericht terugleidt
    $login_link = wp_login_url( $message_url );

    $extra_placeholders = array(
        'portal_link'   => $messages_overview_url,
        'message_title' => get_the_title( $post ),
        'message_date'  => $message_date,
        'message_url'   => $message_url,
        'login_link'    => $login_link,
    );

    // Gebruik jouw generieke templated mail helper
    // Template key: new_portal_message (moet in de CPT bestaan en actief zijn)
    ggr_portal_send_templated_email(
        'new_portal_message',
        $user_id,
        $extra_placeholders
    );
}
