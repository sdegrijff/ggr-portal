<?php
/**
 * GGR CRM Module
 * - Beheer van leads + participants
 * - CRM velden (owner, tags, notes)
 * - Onboarding status beheer
 * - Automatische rolwissel lead -> participant
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CRM ONBOARDING STATUSES
 */
function ggr_crm_get_onboarding_statuses() {
    return [
        'register'           => 'Register',
        'confirmed'          => 'Confirmed',
        'collecting'         => 'Collecting',
        'validating'         => 'Validating',
        'sign_contract'      => 'Sign contract',
        'transfer_completed' => 'Transfer completed',
        'active_participant' => 'Active participant',
    ];
}

/**
 * ADMIN MENU - CRM DASHBOARD
 */
add_action( 'admin_menu', function() {
    add_menu_page(
        'GGR CRM',
        'GGR CRM',
        'manage_options',           // Alleen admins mogen CRM zien
        'ggr-crm',
        'ggr_crm_render_overview',
        'dashicons-id',
        26
    );

    add_submenu_page(
        null,
        'CRM Dossier',
        'CRM Dossier',
        'manage_options',
        'ggr-crm-detail',
        'ggr_crm_render_detail'
    );
});


/**
 * CRM OVERVIEW
 */
function ggr_crm_render_overview() {

    $args = [
        'role__in' => ['lead', 'participant'],
        'orderby'  => 'registered',
        'order'    => 'DESC',
        'number'   => 9999
    ];

    $users = get_users($args);
    $statuses = ggr_crm_get_onboarding_statuses();

    echo '<div class="wrap"><h1>GGR CRM Overzicht</h1>';

    echo '<table class="widefat striped">';
    echo '<thead>
            <tr>
                <th>Naam</th>
                <th>E-mail</th>
                <th>Telefoon</th>
                <th>Status</th>
                <th>Owner</th>
                <th>Actie</th>
            </tr>
        </thead><tbody>';

    foreach ($users as $u) {

        $status = get_user_meta($u->ID, 'ggr_onboarding_status', true);
        $label  = $statuses[$status] ?? 'Onbekend';

        $phone = get_user_meta($u->ID, 'ggr_phone', true);
        $owner = get_user_meta($u->ID, 'ggr_crm_owner', true);
        $owner_user = $owner ? get_user_by('id', $owner) : null;

        echo '<tr>
                <td>' . esc_html($u->display_name) . '</td>
                <td>' . esc_html($u->user_email) . '</td>
                <td>' . esc_html($phone) . '</td>
                <td>' . esc_html($label) . '</td>
                <td>' . ($owner_user ? esc_html($owner_user->display_name) : '-') . '</td>
                <td><a class="button" href="' . admin_url('admin.php?page=ggr-crm-detail&user_id=' . $u->ID) . '">Open dossier</a></td>
              </tr>';
    }

    echo '</tbody></table></div>';
}


/**
 * CRM DETAIL PAGE
 */
function ggr_crm_render_detail() {

    if ( ! current_user_can('manage_options') ) wp_die('Geen toegang');
    if ( empty($_GET['user_id']) ) wp_die('Geen gebruiker geselecteerd');

    $user_id = intval($_GET['user_id']);
    $user = get_user_by('id', $user_id);

    if ( ! $user ) wp_die('Gebruiker niet gevonden');

    $statuses = ggr_crm_get_onboarding_statuses();

    // CRM meta
    $nationality       = get_user_meta($user_id, 'ggr_nationality', true);
    $account_type      = get_user_meta($user_id, 'ggr_account_type', true);
    $investment_amount = get_user_meta($user_id, 'ggr_investment_amount', true);
    $crm_owner         = get_user_meta($user_id, 'ggr_crm_owner', true);
    $crm_tags          = get_user_meta($user_id, 'ggr_crm_tags', true);
    $crm_notes         = get_user_meta($user_id, 'ggr_crm_notes', true);
    $status            = get_user_meta($user_id, 'ggr_onboarding_status', true);

    $owners = get_users(['role__in' => ['administrator', 'editor']]);

    echo '<div class="wrap"><h1>CRM Dossier – ' . esc_html($user->display_name) . '</h1>';

    echo '<form method="post">';
    wp_nonce_field('ggr_crm_save');

    echo '<table class="form-table">';

    echo '<tr><th>Naam</th><td>' . esc_html($user->display_name) . '</td></tr>';
    echo '<tr><th>Email</th><td>' . esc_html($user->user_email) . '</td></tr>';

    echo '<tr><th>Status</th><td><select name="ggr_onboarding_status">';
    foreach ($statuses as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th>Nationaliteit</th><td><input type="text" name="ggr_nationality" value="' . esc_attr($nationality) . '" class="regular-text"></td></tr>';

    echo '<tr><th>Accounttype</th><td>
            <select name="ggr_account_type">
                <option value="">– Selecteer –</option>
                <option value="private" ' . selected($account_type, 'private', false) . '>Particulier</option>
                <option value="business" ' . selected($account_type, 'business', false) . '>Zakelijk</option>
            </select>
          </td></tr>';

    echo '<tr><th>Investeringsbedrag (EUR)</th><td><input type="number" name="ggr_investment_amount" value="' . esc_attr($investment_amount) . '"></td></tr>';

    echo '<tr><th>CRM Owner</th><td><select name="ggr_crm_owner"><option value="">– Niet toegewezen –</option>';
    foreach ($owners as $o) {
        echo '<option value="' . $o->ID . '" ' . selected($crm_owner, $o->ID, false) . '>' . $o->display_name . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th>Tags</th><td><input type="text" name="ggr_crm_tags" value="' . esc_attr($crm_tags) . '" class="regular-text"><br><small>Comma-separated</small></td></tr>';

    echo '<tr><th>Notes</th><td><textarea name="ggr_crm_notes" rows="6" class="large-text">' . esc_textarea($crm_notes) . '</textarea></td></tr>';

    echo '</table>';

    echo '<p><button type="submit" class="button button-primary">Opslaan</button></p>';
    echo '</form></div>';
}


/**
 * CRM SAVE HANDLER
 */
add_action('admin_init', function() {

    if ( ! isset($_POST['_wpnonce']) ) return;
    if ( ! wp_verify_nonce($_POST['_wpnonce'], 'ggr_crm_save') ) return;
    if ( ! current_user_can('manage_options') ) return;

    $user_id = intval($_GET['user_id']);

    // Save CRM fields
    update_user_meta($user_id, 'ggr_nationality',       sanitize_text_field($_POST['ggr_nationality']));
    update_user_meta($user_id, 'ggr_account_type',      sanitize_text_field($_POST['ggr_account_type']));
    update_user_meta($user_id, 'ggr_investment_amount', floatval($_POST['ggr_investment_amount']));
    update_user_meta($user_id, 'ggr_crm_owner',         intval($_POST['ggr_crm_owner']));
    update_user_meta($user_id, 'ggr_crm_tags',          sanitize_text_field($_POST['ggr_crm_tags']));
    update_user_meta($user_id, 'ggr_crm_notes',         sanitize_textarea_field($_POST['ggr_crm_notes']));

    // Save onboarding status
    $status = sanitize_text_field($_POST['ggr_onboarding_status']);
    if ( function_exists( 'ggr_onboarding_update_status' ) ) {
        ggr_onboarding_update_status( $user_id, $status );
    } else {
        update_user_meta( $user_id, 'ggr_onboarding_status', $status );
        update_user_meta( $user_id, 'ggr_onboarding_updated_at', current_time( 'mysql' ) );
    }

    // Automatic role switching
    if ($status === 'active_participant') {
        $user = new WP_User($user_id);
        $user->set_role('participant');
    } else {
        $user = new WP_User($user_id);
        $user->set_role('lead');
    }

    wp_redirect(admin_url('admin.php?page=ggr-crm-detail&user_id=' . $user_id . '&saved=1'));
    exit;
});
