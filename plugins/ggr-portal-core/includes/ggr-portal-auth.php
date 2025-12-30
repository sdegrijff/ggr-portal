<?php
/**
 * GGR Portal – Authenticatie & loginlogica
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 0) Admin bar verbergen voor niet-beheerders
 */
add_action( 'after_setup_theme', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        show_admin_bar( false );
    }
});

/**
 * 0.1) Sessies starten voor 2FA
 *      (nodig om tijdelijk code + status per gebruiker op te slaan)
 */
function ggr_portal_start_session() {
    if ( PHP_SESSION_ACTIVE === session_status() ) {
        return;
    }

    if ( headers_sent( $sent_file, $sent_line ) ) {
        $location = $sent_file ? sprintf( '%s:%s', $sent_file, $sent_line ?: '?' ) : 'onbekend';
        error_log( 'GGR Portal: sessie kon niet worden gestart, headers al verzonden in ' . $location );
        return;
    }

    if ( PHP_SESSION_NONE === session_status() ) {
        session_start();
    }
}
add_action( 'init', 'ggr_portal_start_session', 1 );

/**
 * Helper: moet deze user 2FA doen?
 * Nu: voor alle niet-admin gebruikers.
 */
function ggr_portal_require_2fa_for_user( $user ) {
    if ( ! $user || ! isset( $user->ID ) ) {
        return false;
    }

    // Admins (manage_options) uitsluiten van 2FA om lockout-risico te beperken
    if ( user_can( $user, 'manage_options' ) ) {
        return false;
    }

    // Iedereen anders: 2FA verplicht
    return true;
}


function ggr_portal_require_2fa_for_current_user() {
    $user = wp_get_current_user();
    if ( ! $user || 0 === $user->ID ) {
        return false;
    }
    return ggr_portal_require_2fa_for_user( $user );
}

/**
 * Helper: 2FA status in sessie
 */
function ggr_portal_is_2fa_verified() {
    return ! empty( $_SESSION['ggr_2fa_verified'] ) && true === $_SESSION['ggr_2fa_verified'];
}

function ggr_portal_mark_2fa_verified() {
    $_SESSION['ggr_2fa_verified']  = true;
    $_SESSION['ggr_2fa_code']      = null;
    $_SESSION['ggr_2fa_expires']   = null;
    $_SESSION['ggr_2fa_code_sent'] = null;
}

/**
 * 2FA code genereren
 */
function ggr_portal_generate_2fa_code() {
    return wp_rand( 100000, 999999 ); // 6 cijfers
}

/**
 * 2FA code e-mailen (via template + fallback)
 */
function ggr_portal_send_2fa_code_email( $user, $code ) {
    if ( ! $user || empty( $user->user_email ) ) {
        return;
    }

    // Eerst proberen via de e-mailtemplate "two_factor_code"
    $sent = ggr_portal_send_templated_email(
        'two_factor_code',  // MOET gelijk zijn aan de key in je CPT
        $user->ID,
        array(
            'two_factor_code'          => $code,
            'two_factor_valid_minutes' => '10',
        )
    );

    // Als er geen actieve template is of iets faalt -> eenvoudige fallback
    if ( ! $sent ) {
        $subject = __( 'Je GGR bevestigingscode', 'ggr-portal-core' );
        $name    = $user->first_name ?: $user->display_name;

        $message  = sprintf( __( 'Beste %s,', 'ggr-portal-core' ), $name ) . "\n\n";
        $message .= __( 'Je bevestigingscode voor het GGR portal is:', 'ggr-portal-core' ) . "\n\n";
        $message .= $code . "\n\n";
        $message .= __( 'Deze code is 10 minuten geldig.', 'ggr-portal-core' ) . "\n\n";
        $message .= __( 'Heb jij dit niet aangevraagd? Neem dan direct contact op met GGR.', 'ggr-portal-core' ) . "\n\n";
        $message .= __( 'Met vriendelijke groet,', 'ggr-portal-core' ) . "\n";
        $message .= __( 'GGR Income Fund', 'ggr-portal-core' );

        wp_mail( $user->user_email, $subject, $message );
    }
}

/**
 * 2FA: na succesvolle login code genereren + mailen
 */
function ggr_portal_on_login( $user_login, $user ) {
    if ( ! ggr_portal_require_2fa_for_user( $user ) ) {
        return;
    }

    $code = ggr_portal_generate_2fa_code();

    $_SESSION['ggr_2fa_user_id']   = $user->ID;
    $_SESSION['ggr_2fa_code']      = (string) $code;
    $_SESSION['ggr_2fa_expires']   = time() + ( 10 * 60 ); // 10 minuten
    $_SESSION['ggr_2fa_verified']  = false;
    $_SESSION['ggr_2fa_code_sent'] = false;

    ggr_portal_send_2fa_code_email( $user, $code );
    $_SESSION['ggr_2fa_code_sent'] = true;
}
add_action( 'wp_login', 'ggr_portal_on_login', 10, 2 );

/**
 * 1) Shortcode: [user_record_field field="..."]
 */
if ( ! function_exists( 'my_user_record_field_shortcode' ) ) {
    function my_user_record_field_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'field'     => '',
                'post_type' => 'user_record',
                'meta_key'  => 'user_email',
            ),
            $atts,
            'user_record_field'
        );

        if ( empty( $atts['field'] ) ) {
            return '';
        }
        
        $atts['field']     = sanitize_text_field( $atts['field'] );
        $atts['post_type'] = sanitize_key( $atts['post_type'] );
        $atts['meta_key']  = sanitize_key( $atts['meta_key'] );
        
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $current_user = wp_get_current_user();
        $user_email   = $current_user->user_email;

        $args = array(
            'post_type'      => $atts['post_type'],
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'   => $atts['meta_key'],
                    'value' => $user_email,
                ),
            ),
        );

        $records = get_posts( $args );

        if ( ! is_array( $records ) || empty( $records ) ) {
            return '';
        }

        $record_id = $records[0]->ID;

        $value = null;

        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $atts['field'], $record_id );
        }

        if ( $value === null || $value === '' ) {
            $value = get_post_meta( $record_id, $atts['field'], true );
        }

        if ( $value === '' || $value === null ) {
            return '';
        }

        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( 'strval', $value ) );
        }

        return esc_html( $value );
    }
    add_shortcode( 'user_record_field', 'my_user_record_field_shortcode' );
}

/**
 * 2) Shortcode: [ggr_logout]
 */
function ggr_logout_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'label' => 'Uitloggen',
        ),
        $atts
    );

    $logout_url = wp_logout_url( home_url( '/login/' ) );

    return '<a href="' . esc_url( $logout_url ) . '" class="ggr-logout-link">' . esc_html( $atts['label'] ) . '</a>';
}
add_shortcode( 'ggr_logout', 'ggr_logout_shortcode' );

/**
 * 3) PORTAL TOEGANG
 */

/**
 * 3.1) Redirect na login (voor alle niet-admin gebruikers)
 */
function portal_login_redirect_participant( $redirect_to, $request, $user ) {
    // Als login fout gegaan is of geen user-object: doe niks
    if ( ! $user || is_wp_error( $user ) ) {
        return $redirect_to;
    }

    // Admins/medewerkers: altijd naar het admin dashboard in portal shell
    $roles = (array) $user->roles;
    if ( user_can( $user, 'manage_options' ) || in_array( 'employee', $roles, true ) ) {
        return admin_url( 'admin.php?page=ggr-portal-dashboard' );
    }

    // Voor alle andere users: eerst 2FA, daarna dashboard
    if ( ggr_portal_require_2fa_for_user( $user ) && ! ggr_portal_is_2fa_verified() ) {
        return home_url( '/2fa/' );
    }

    return home_url( '/dashboard/' );
}
add_filter( 'login_redirect', 'portal_login_redirect_participant', 10, 3 );

/**
 * 3.1.1) Blokkeer wp-admin voor leads/participants (redirect naar home).
 */
function ggr_redirect_lead_participant_from_wp_admin() {
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $is_wp_admin = is_admin() || ( $request_uri && strpos( $request_uri, '/wp-admin' ) !== false );

    if ( ! $is_wp_admin ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( current_user_can( 'manage_options' ) ) {
        return;
    }

    $user  = wp_get_current_user();
    $roles = (array) $user->roles;

    if ( in_array( 'lead', $roles, true ) || in_array( 'participant', $roles, true ) ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }
}
add_action( 'init', 'ggr_redirect_lead_participant_from_wp_admin', 1 );


/**
 * 3.2) Force login voor alle front-end pagina's
 */
function portal_force_login_except_allowed_pages() {
    // Altijd toestaan: wp-admin, AJAX, REST
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    // Admins (manage_options) nooit forceren of redirecten
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }

    // Slugs die ook voor niet-ingelogde gebruikers bereikbaar mogen zijn
    $allowed_slugs = array(
        'login',
        'wachtwoord-vergeten',
        '2fa',
        'nieuw-wachtwoord', 
        'investeerder-worden',
    );

    // Huidige slug ophalen
    $current_slug = '';
    $obj = get_queried_object();
    if ( $obj && isset( $obj->post_name ) ) {
        $current_slug = $obj->post_name;
    }

    // ----- INGELOGDE GEBRUIKER -----
    if ( is_user_logged_in() ) {

        // Alleen participants (of andere rollen waarvoor je 2FA wilt) beperken
        if ( ggr_portal_require_2fa_for_current_user() ) {

            // Ingelogde participants horen niet meer op login / wachtwoord-vergeten
            if ( in_array( $current_slug, array( 'login', 'wachtwoord-vergeten' ), true ) ) {
                wp_safe_redirect( home_url( '/dashboard/' ) );
                exit;
            }

            // 2FA afdwingen: als nog niet geverifieerd en niet op /2fa/, redirect daarheen
            if ( ! ggr_portal_is_2fa_verified() && '2fa' !== $current_slug ) {
                wp_safe_redirect( home_url( '/2fa/' ) );
                exit;
            }
        }

        // Voor alle andere ingelogde rollen (beheerders, redacteuren, etc.) geen extra regels
        return;
    }

    // ----- NIET INGELOGDE GEBRUIKER -----

    // Niet ingelogd → bepaalde slugs zijn toegestaan (login, wachtwoord-vergeten, 2fa, nieuw-wachtwoord)
    if ( in_array( $current_slug, $allowed_slugs, true ) ) {
        return;
    }

    // Alles anders: redirect naar /login/
    wp_redirect( home_url( '/login/' ) );
    exit;
}

add_action( 'template_redirect', 'portal_force_login_except_allowed_pages' );

/**
 * 3.3) Vang standaard wp-login.php af en stuur naar /login/
 */
function portal_redirect_wp_login_to_custom() {
    global $pagenow;

    // Alleen ingrijpen op wp-login.php
    if ( 'wp-login.php' !== $pagenow ) {
        return;
    }

    // POST altijd met rust laten (verwerken van login / lostpassword)
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
        return;
    }

    // --- BELANGRIJK: "checkemail=confirm" → doorsturen naar front-end login met reset=sent ---
    if ( isset( $_GET['checkemail'] ) && $_GET['checkemail'] === 'confirm' ) {
        $target = add_query_arg(
            'reset',
            'sent',
            home_url( '/login/' )
        );
        wp_safe_redirect( $target );
        exit;
    }

    // Als je admins nooit wilt forceren:
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';

    // Acties die we wél via wp-login zelf toestaan
    $allowed_actions = array( 'lostpassword', 'rp', 'resetpass', 'logout' );
    if ( in_array( $action, $allowed_actions, true ) ) {
        return;
    }

    // Als iemand via redirect_to naar wp-admin komt, laat het dan door WP afhandelen
    $redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';
    if ( strpos( $redirect_to, 'wp-admin' ) !== false ) {
        return;
    }

    // Alles anders: naar de front-end loginpagina
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}
add_action( 'login_init', 'portal_redirect_wp_login_to_custom' );


/**
 * 4) SHORTCODE: [ggr_login_form]
 */
function ggr_login_form_shortcode() {

    if ( is_user_logged_in() ) {
        return '';
    }

    $state      = isset( $_GET['login'] ) ? sanitize_text_field( $_GET['login'] ) : '';
    $is_failed  = ( $state === 'failed' );

    $args = array(
        'echo'           => false,
        'redirect'       => home_url( '/dashboard/' ),
        'form_id'        => 'ggr-loginform',
        'label_username' => 'E-mailadres',
        'label_password' => 'Wachtwoord',
        'label_log_in'   => 'Inloggen',
        'remember'       => false,
        'value_username' => '',
        'value_remember' => false,
    );

    $form        = wp_login_form( $args );
    $lost        = home_url( '/wachtwoord-vergeten/' );
    $invest_link = home_url( '/investeerder-worden/' );

    ob_start();
    ?>
    
    <div class="ggr-login-header">
    <div class="ggr-login-header-left">
        <a href="/">
            <img src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png"
                 alt="GGR Income Fund"
                 class="ggr-login-logo">
                 <style>
.ggr-login-header {
    width: 100%;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
}

.ggr-login-header-left {
    display: flex;
    align-items: center;
}

.ggr-login-logo {
    height: 75px;
    width: auto;
    display: block;
    object-fit: contain;
}

.ggr-login-header-right {
    display: flex;
    align-items: center;
    gap: 6px;
}

.ggr-login-header-right span {
    font-size: 15px;
    font-weight: 400;
    color: #333333;
}

.ggr-login-header-cta {
    font-size: 15px;
    font-weight: 600;
    color: #c57a54;
    text-decoration: underline;
}

.ggr-login-header-cta:hover {
    color: #a96845;
}
</style>

        </a>
    </div>

    <div class="ggr-login-header-right">
        <span>Investeerder worden?</span>
        <a href="/investeerder-worden" class="ggr-login-header-cta">
            Meld je hier aan
        </a>
    </div>
</div>


    <div class="ggr-login-wrapper">
        <div class="ggr-login-card">
            <h1 class="ggr-login-title">Welkom bij het GGR Portaal!</h1>
            <p class="ggr-login-subtitle">Inloggen</p>

            <div class="ggr-login-fields">
                <?php echo $form; ?>
            </div>

            <a class="ggr-login-forgot" style="font-size:15px; color:#709aa7;" href="<?php echo esc_url( $lost ); ?>">Wachtwoord vergeten?</a>

            <div class="ggr-login-actions">
                <button type="button" class="ggr-login-submit" data-ggr-login-submit>
                    Inloggen
                </button>

                <a href="<?php echo esc_url( $invest_link ); ?>" class="ggr-login-invest-button">
                    Investeerder worden
                </a>
            </div>

            <?php if ( $is_failed ) : ?>
                <div class="ggr-login-toast ggr-login-toast--error" data-ggr-login-toast>
                    <div class="ggr-login-toast__icon">!</div>
                    <div class="ggr-login-toast__content">
                        <div class="ggr-login-toast__title">Oeps...</div>
                        <div class="ggr-login-toast__message">
                            Uw gebruikersnaam en/of wachtwoord zijn niet juist. Probeer het opnieuw.
                        </div>
                    </div>
                    <button type="button" class="ggr-login-toast__close" data-ggr-toast-close aria-label="Sluiten">
                        ×
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    var params      = new URLSearchParams(window.location.search);
    var loginState  = params.get('login'); // 'failed', 'empty', etc.
    var resetState  = params.get('reset'); // 'sent' (mail verstuurd) of 'done' (wachtwoord gewijzigd)

    var form        = document.getElementById('ggr-loginform');
    var loginCard   = document.querySelector('.ggr-login-card');
    var userField   = document.getElementById('user_login');
    var passField   = document.getElementById('user_pass');
    var submitBtn   = document.querySelector('[data-ggr-login-submit]');
    var toast       = document.querySelector('[data-ggr-login-toast]');
    var toastClose  = toast ? toast.querySelector('[data-ggr-toast-close]') : null;

    // Als er geen login card of form is (bijv. op /wachtwoord-vergeten/) -> stop.
    if (!loginCard || !form) {
        return;
    }

    // HTML5/browser-validatie uitschakelen; we gebruiken eigen JS-validatie
    form.setAttribute('novalidate', 'novalidate');

    // Core WP error-box ("Invalid username or password.") verstoppen
    var wpError = document.getElementById('login_error');
    if (wpError) {
        wpError.style.display = 'none';
    }
    // Extra: alle WP .error/.message binnen de login-card weg
    document.querySelectorAll('.ggr-login-card .error, .ggr-login-card .message').forEach(function(el) {
        el.style.display = 'none';
    });

    // Grijze placeholders standaard + iconen/verbeteringen
    if (userField && !userField.placeholder) userField.placeholder = 'E-mailadres';
    if (passField && !passField.placeholder) passField.placeholder = 'Wachtwoord';

    function enhanceInput(el, options) {
        if (!el) return;

        if (el.parentElement && el.parentElement.classList.contains('ggr-input-wrapper')) {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'ggr-input-wrapper';
        el.parentNode.insertBefore(wrapper, el);
        wrapper.appendChild(el);

        if (options && options.iconClass) {
            var icon = document.createElement('span');
            icon.className = 'ggr-input-icon';
            icon.innerHTML = '<i class="' + options.iconClass + '"></i>';
            wrapper.appendChild(icon);
        }

        if (options && options.addToggle) {
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'ggr-password-toggle';
            toggle.setAttribute('aria-label', 'Wachtwoord tonen');
            toggle.innerHTML = '<i class="ri-eye-line"></i>';

            toggle.addEventListener('click', function() {
                var isHidden = el.getAttribute('type') === 'password';
                el.setAttribute('type', isHidden ? 'text' : 'password');
                toggle.innerHTML = isHidden ? '<i class="ri-eye-off-line"></i>' : '<i class="ri-eye-line"></i>';
                toggle.setAttribute('aria-label', isHidden ? 'Wachtwoord verbergen' : 'Wachtwoord tonen');
            });

            wrapper.appendChild(toggle);
        }
    }

    enhanceInput(userField, { iconClass: 'ri-mail-line' });
    enhanceInput(passField, { iconClass: 'ri-lock-line', addToggle: true });
    
    function markEmptyField(el) {
        if (!el) return;
        el.classList.add('ggr-input-error');
        el.value = ''; // placeholder goed zichtbaar
        el.placeholder = 'Dit veld is verplicht';

        var wrapper = el.closest('p');
        if (wrapper && !wrapper.querySelector('.ggr-field-error')) {
            var span = document.createElement('span');
            span.className = 'ggr-field-error';
            span.textContent = 'Dit veld is verplicht';
            wrapper.appendChild(span);
        }
    }

    function clearFieldError(el) {
        if (!el) return;
        el.classList.remove('ggr-input-error');
        var wrapper = el.closest('p');
        if (wrapper) {
            var msg = wrapper.querySelector('.ggr-field-error');
            if (msg) msg.remove();
        }
    }

    // Front-end validatie vóór submit
    if (submitBtn) {
        submitBtn.addEventListener('click', function (e) {
            var hasError = false;

            [userField, passField].forEach(function (el) {
                if (!el) return;
                if (!el.value.trim()) {
                    hasError = true;
                    markEmptyField(el);
                } else {
                    clearFieldError(el);
                }
            });

            if (hasError) {
                // voorkom POST als er lege velden zijn
                e.preventDefault();
                return;
            }

            form.submit();
        });
    }

    // Server-side "empty" state (fallback, bv. bij redirect)
    if (loginState === 'empty') {
        [userField, passField].forEach(function (el) {
            if (!el) return;
            markEmptyField(el);
        });
    }

    // Mislukte login -> toon error-toast
    if (loginState === 'failed' && toast) {
        toast.classList.add('is-visible');

        if (toastClose) {
            toastClose.addEventListener('click', function () {
                toast.classList.remove('is-visible');
            });
        }

        setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 15000);
    }

    // Helper om een succes-toast te tonen (mail verstuurd / wachtwoord gewijzigd)
    function showSuccessToast(title, message) {
        var successToast = document.createElement('div');
        successToast.className = 'ggr-login-toast ggr-login-toast--success is-visible';
        successToast.innerHTML = '' +
            '<div class="ggr-login-toast__icon">✓</div>' +
            '<div class="ggr-login-toast__content">' +
            '  <div class="ggr-login-toast__title">' + title + '</div>' +
            '  <div class="ggr-login-toast__message">' + message + '</div>' +
            '</div>' +
            '<button type="button" class="ggr-login-toast__close" aria-label="Sluiten">×</button>';

        loginCard.appendChild(successToast);

        var successClose = successToast.querySelector('.ggr-login-toast__close');
        if (successClose) {
            successClose.addEventListener('click', function () {
                successToast.classList.remove('is-visible');
            });
        }

        setTimeout(function () {
            successToast.classList.remove('is-visible');
        }, 8000);
    }

    // Succes: resetmail verstuurd -> /login/?reset=sent
    if (resetState === 'sent') {
        showSuccessToast(
            'E-mail verstuurd',
            'We hebben je een e-mail gestuurd met een link om je wachtwoord te resetten.'
        );
    }

    // Succes: wachtwoord gewijzigd -> /login/?reset=done
    if (resetState === 'done') {
        showSuccessToast(
            'Wachtwoord gewijzigd',
            'Je wachtwoord is succesvol aangepast. Je kunt nu inloggen met je nieuwe wachtwoord.'
        );
    }
});
</script>

    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_login_form', 'ggr_login_form_shortcode' );


/**
 * 4.1) Placeholders voor de standaard WP velden (fallback)
 */
function ggr_login_add_placeholders( $content ) {
    $content .= "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var u = document.getElementById('user_login');
        var p = document.getElementById('user_pass');
        if (u && !u.placeholder) u.placeholder = 'E-mailadres';
        if (p && !p.placeholder) p.placeholder = 'Wachtwoord';
    });
    </script>
    ";
    return $content;
}
add_filter( 'login_form_bottom', 'ggr_login_add_placeholders' );

/**
 * 4.2) Hidden flag toevoegen aan het front-end loginformulier
 *      zodat we in filters weten dat het om /login/ gaat.
 */
function ggr_login_add_custom_flag( $content ) {
    $content .= '<input type="hidden" name="ggr_frontend_login" value="1" />';
    return $content;
}
add_filter( 'login_form_middle', 'ggr_login_add_custom_flag' );

/**
 * 5) Login fouten & redirects
 */

/**
 * 5.1) Mislukte login vanaf ons front-end formulier
 *      -> /login?login=failed
 */
function ggr_login_failed_redirect( $username ) {
    if ( empty( $_POST['ggr_frontend_login'] ) ) {
        return;
    }

    $login_page = home_url( '/login/' );
    wp_safe_redirect( add_query_arg( 'login', 'failed', $login_page ) );
    exit;
}
add_action( 'wp_login_failed', 'ggr_login_failed_redirect' );

/**
 * 5.2) Lege velden -> /login?login=empty
 */
function ggr_verify_loginf_fields( $user, $username, $password ) {
    if ( empty( $_POST['ggr_frontend_login'] ) ) {
        return $user;
    }

    $login_page = home_url( '/login/' );

    if ( empty( $username ) || empty( $password ) ) {
        wp_safe_redirect( add_query_arg( 'login', 'empty', $login_page ) );
        exit;
    }

    return $user;
}
add_filter( 'authenticate', 'ggr_verify_loginf_fields', 30, 3 );

/**
 * 5.3) Uitloggen -> terug naar /login met melding
 */
function ggr_logout_redirect() {
    $login_page = home_url( '/login/' );
    wp_safe_redirect( add_query_arg( 'login', 'loggedout', $login_page ) );
    exit;
}
add_action( 'wp_logout', 'ggr_logout_redirect' );

/**
 * 6) LOST PASSWORD: shortcode [ggr_lost_password_form]
 */
function ggr_lost_password_form_shortcode() {

    if ( is_user_logged_in() ) {
        return '';
    }

    $logo_url   = 'https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png';
    $action_url = wp_lostpassword_url();
    $login_url  = home_url( '/login/' );

    ob_start();
    ?>
    <div class="ggr-login-wrapper">
        <div class="ggr-login-shell">

            <!-- Logo boven card -->
            <div class="ggr-logo-top"
                 style="display:flex;justify-content:center;margin-bottom:16px;">
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="GGR"
                     loading="lazy"
                     style="max-height:75px;width:auto;">
            </div>

            <!-- Card -->
            <div class="ggr-login-card" style="text-align:center;">
                <h1 class="ggr-login-title">Wachtwoord vergeten</h1>

                <!-- Uitleg boven veld -->
                <div class="ggr-login-subtitle" style="margin-bottom:20px; text-align:center;">
                    Wij sturen je een e-mail waarmee je jouw wachtwoord kunt wijzigen. Gebruik het e-mailadres waarmee je inlogt:<br>
                </div>

                <form name="lostpasswordform"
                      id="ggr-lostpasswordform"
                      action="<?php echo esc_url( $action_url ); ?>"
                      method="post">

<div class="ggr-login-fields" style="text-align:left;">
  <div class="ggr-field">
    <label for="user_login">E-mailadres</label>

    <div class="ggr-input-wrap">
      <i class="ri-mail-line" aria-hidden="true"></i>
      <input type="email"
             name="user_login"
             id="user_login"
             class="input"
             value=""
             size="20"
             placeholder="E-mailadres"
             required />
    </div>
  </div>
</div>

                    <div class="ggr-login-actions">
                        <button type="submit"
                                class="ggr-login-submit"
                                name="wp-submit">
                            Resetlink versturen
                        </button>
                    </div>
                </form>
            </div>

            <!-- Terug naar login -->
            <div style="text-align:center;margin-top:16px;">
                <a href="<?php echo esc_url( $login_url ); ?>"
                   class="ggr-login-forgot ggr-login-back-link"
                   style="font-size:15px; color:#709aa7;">
                    Terug naar inloggen
                </a>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_lost_password_form', 'ggr_lost_password_form_shortcode' );






/**
 * 6.1) Custom wachtwoord-reset e-mail via template "password_reset"
 *
 * Vereist:
 * - Een actieve e-mailtemplate met key: password_reset
 * - Placeholders in de template:
 *   {{user_display_name}}, {{reset_link}}, {{reset_valid_minutes}}
 */

$GLOBALS['ggr_portal_last_pwreset_subject'] = '';

add_filter( 'retrieve_password_message', 'ggr_portal_reset_password_message', 10, 4 );
function ggr_portal_reset_password_message( $message, $key, $user_login, $user_data ) {
    global $ggr_portal_last_pwreset_subject;

    // Als de render-functie niet bestaat -> standaard WP mail laten staan
    if ( ! function_exists( 'ggr_portal_render_email' ) ) {
        return $message;
    }

    // Reset-link opbouwen zoals WordPress dat zelf ook doet
    $reset_link = network_site_url(
        "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user_login ),
        'login'
    );

    // Hoe lang de link geldig is (moet overeenkomen met je copy in de mail)
    $valid_minutes = 10;

    // Jouw e-mailtemplate renderen
    $rendered = ggr_portal_render_email(
        'password_reset', // MOET gelijk zijn aan de key in je CPT
        array(
            'user_display_name'   => $user_data->display_name,
            'reset_link'          => $reset_link,
            'reset_valid_minutes' => $valid_minutes,
        )
    );

    // Als er geen actieve template is, val terug op de standaard WP mail
    if ( ! $rendered ) {
        return $message;
    }

    // Subject even parkeren voor de title-filter
    $ggr_portal_last_pwreset_subject = $rendered['subject'];

    // Body (HTML) teruggeven aan WordPress
    return $rendered['body'];
}

add_filter( 'retrieve_password_title', 'ggr_portal_reset_password_title', 10, 3 );
function ggr_portal_reset_password_title( $title, $user_login, $user_data ) {
    global $ggr_portal_last_pwreset_subject;

    if ( ! empty( $ggr_portal_last_pwreset_subject ) ) {
        $subject = $ggr_portal_last_pwreset_subject;
        $ggr_portal_last_pwreset_subject = '';
        return $subject;
    }

    // Fallback als er geen template-subject beschikbaar is
    return 'Nieuw wachtwoord instellen | GGR Income Fund';
}

/**
 * 6.2) Alle mails als HTML versturen
 * (past bij jouw gebruik van HTML-templates in het portal)
 */
function ggr_portal_mail_content_type( $content_type ) {
    return 'text/html; charset=UTF-8';
}
add_filter( 'wp_mail_content_type', 'ggr_portal_mail_content_type' );

/**
 * 6.3) Redirect WP reset-pagina (rp/resetpass) naar front-end pagina /nieuw-wachtwoord/
 */
function ggr_portal_redirect_password_reset_to_frontend() {

    // Alleen op wp-login.php met acties rp/resetpass
    $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
    if ( ! in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
        return;
    }

    if ( empty( $_GET['key'] ) || empty( $_GET['login'] ) ) {
        return;
    }

    $key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
    $login = sanitize_text_field( wp_unslash( $_GET['login'] ) );

    $url = add_query_arg(
        array(
            'key'   => rawurlencode( $key ),
            'login' => rawurlencode( $login ),
        ),
        home_url( '/nieuw-wachtwoord/' )
    );

    wp_safe_redirect( $url );
    exit;
}
add_action( 'login_form_rp', 'ggr_portal_redirect_password_reset_to_frontend' );
add_action( 'login_form_resetpass', 'ggr_portal_redirect_password_reset_to_frontend' );

/**
 * 6.4) Shortcode [ggr_reset_password_form] – front-end nieuw wachtwoord
 * Zelfde stijl als login/2FA + logo erboven + placeholders.
 */
function ggr_reset_password_form_shortcode() {

    // Logo
    $logo_url = 'https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png';

    // Ingelogde niet-admins horen hier niet te komen
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        $dash = esc_url( home_url( '/dashboard/' ) );
        return '<script>window.location.href = "' . $dash . '";</script>';
    }

    $key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
    $login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

    $error = '';

    if ( empty( $key ) || empty( $login ) ) {
        $error = 'De link om je wachtwoord te resetten is ongeldig. Vraag een nieuwe link aan.';
    } else {

        // Formulier gepost → wachtwoord verwerken
        if ( isset( $_POST['ggr_new_pass_nonce'] ) && wp_verify_nonce( $_POST['ggr_new_pass_nonce'], 'ggr_reset_password' ) ) {

            $pass1 = isset( $_POST['pass1'] ) ? (string) $_POST['pass1'] : '';
            $pass2 = isset( $_POST['pass2'] ) ? (string) $_POST['pass2'] : '';

            if ( empty( $pass1 ) || empty( $pass2 ) ) {
                $error = 'Vul beide wachtwoordvelden in.';
            } elseif ( $pass1 !== $pass2 ) {
                $error = 'De ingevulde wachtwoorden komen niet overeen.';
            } elseif ( strlen( $pass1 ) < 8 ) {
                $error = 'Je wachtwoord moet minimaal 8 tekens lang zijn.';
            } else {
                $user = check_password_reset_key( $key, $login );
                if ( is_wp_error( $user ) ) {
                    $error = 'De link om je wachtwoord te resetten is ongeldig of verlopen. Vraag een nieuwe link aan.';
                } else {
                    reset_password( $user, $pass1 );
                    $login_url = esc_url( home_url( '/login/?reset=done' ) );
                    return '<script>window.location.href = "' . $login_url . '";</script>';
                }
            }

        } else {
            // Alleen key alvast valideren voor nette foutmelding
            $user = check_password_reset_key( $key, $login );
            if ( is_wp_error( $user ) ) {
                $error = 'De link om je wachtwoord te resetten is ongeldig of verlopen. Vraag een nieuwe link aan.';
            }
        }
    }

    ob_start();
    ?>
    <div class="ggr-login-wrapper ggr-resetpass-wrapper">
        <div class="ggr-login-shell">
            <div class="ggr-logo-top"
                 style="display:flex;justify-content:center;margin-bottom:16px;">
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="GGR"
                     loading="lazy"
                     style="max-height:75px;width:auto;">
            </div>

            <div class="ggr-login-card">
                <h1 class="ggr-login-title">Nieuw wachtwoord instellen</h1>
                <auth class="ggr-login-subtitle"
                        style="text-align: center;!important"
                >
                    Kies hieronder een nieuw wachtwoord voor je GGR account.
                </p>

                <?php if ( $error ) : ?>
                    <div class="ggr-login-notice ggr-login-notice--error">
                        <?php echo esc_html( $error ); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="ggr-resetpass-form">
                    <div class="ggr-login-fields"
                            style="text-align:left;"    
                            >
                        <div class="ggr-field"> 
                            <label for="pass1">Nieuw wachtwoord</label>
                            <input type="password"
                                   name="pass1"
                                   id="pass1"
                                   class="input"
                                   autocomplete="new-password"
                                   placeholder="Nieuw wachtwoord"
                                   required />
                        </div>
                        <div class="ggr-field">
                            <label for="pass2">Herhaal nieuw wachtwoord</label>
                            <input type="password"
                                   name="pass2"
                                   id="pass2"
                                   class="input"
                                   autocomplete="new-password"
                                   placeholder="Herhaal nieuw wachtwoord"
                                   required />
                        </div>
                    </div>

                    <?php wp_nonce_field( 'ggr_reset_password', 'ggr_new_pass_nonce' ); ?>

                    <div class="ggr-login-actions">
                        <button type="submit" class="ggr-login-submit">
                            Wachtwoord opslaan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'ggr_reset_password_form', 'ggr_reset_password_form_shortcode' );

/**
 * 7) 2FA PAGINA – shortcode [ggr_2fa_form]
 */
function ggr_2fa_form_shortcode() {

    $logo_url = 'https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png';

    // Niet ingelogd → naar login
    if ( ! is_user_logged_in() ) {
        $login = esc_url( home_url( '/login/' ) );
        return '<script>window.location.href = "' . $login . '";</script>';
    }

    $user = wp_get_current_user();

    // Als deze user geen 2FA nodig heeft (bijv. admin), nette melding
    if ( ! ggr_portal_require_2fa_for_user( $user ) ) {
        ob_start();
        ?>
        <div class="ggr-login-wrapper">
            <div class="ggr-login-shell">
                <div class="ggr-logo-top"
                     style="display:flex;justify-content:center;margin-bottom:16px;">
                    <img src="<?php echo esc_url( $logo_url ); ?>"
                         alt="GGR"
                         loading="lazy"
                         style="max-height:75px;width:auto;">
                </div>

                <div class="ggr-login-card">
                    <h1 class="ggr-login-title">2FA niet vereist</h1>
                    <auth class="ggr-login-subtitle">
                        Voor jouw account is geen tweestapsverificatie ingesteld.
                    </p>
                    <div class="ggr-login-actions">
                        <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"
                           class="ggr-login-submit">
                            Naar dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Als 2FA al is afgerond → door naar dashboard
    if ( ggr_portal_is_2fa_verified() ) {
        $dash = esc_url( home_url( '/dashboard/' ) );
        return '<script>window.location.href = "' . $dash . '";</script>';
    }

    $error  = '';
    $notice = '';

    // Code verlopen?
    if ( ! empty( $_SESSION['ggr_2fa_expires'] ) && time() > $_SESSION['ggr_2fa_expires'] ) {
        $error = 'Je code is verlopen. Vraag een nieuwe code aan.';
        $_SESSION['ggr_2fa_code']      = null;
        $_SESSION['ggr_2fa_code_sent'] = false;
    }

    // Nieuwe code versturen
    if ( isset( $_POST['ggr_2fa_resend'] ) && check_admin_referer( 'ggr_2fa_resend', 'ggr_2fa_resend_nonce' ) ) {
        $code = ggr_portal_generate_2fa_code();
        $_SESSION['ggr_2fa_code']      = (string) $code;
        $_SESSION['ggr_2fa_expires']   = time() + ( 10 * 60 );
        $_SESSION['ggr_2fa_code_sent'] = true;

        ggr_portal_send_2fa_code_email( $user, $code );
        $notice = 'We hebben een nieuwe code naar je e-mailadres gestuurd.';
    }

    // Als er (nog) geen code is én we hebben niet net opnieuw gestuurd → verse code
    if ( empty( $_SESSION['ggr_2fa_code'] ) && empty( $_POST['ggr_2fa_resend'] ) ) {
        $code = ggr_portal_generate_2fa_code();
        $_SESSION['ggr_2fa_code']      = (string) $code;
        $_SESSION['ggr_2fa_expires']   = time() + ( 10 * 60 );
        $_SESSION['ggr_2fa_code_sent'] = true;

        ggr_portal_send_2fa_code_email( $user, $code );
        $notice = 'We hebben een bevestigingscode naar je e-mailadres gestuurd.';
    }

    if ( ! $notice && ! empty( $_SESSION['ggr_2fa_code_sent'] ) ) {
        $notice = 'We hebben een bevestigingscode naar je e-mailadres gestuurd.';
    }

    // Code controleren
    if ( isset( $_POST['ggr_2fa_code'] ) && check_admin_referer( 'ggr_2fa_verify', 'ggr_2fa_verify_nonce' ) ) {
        $input_code = trim( sanitize_text_field( $_POST['ggr_2fa_code'] ) );

        if ( empty( $input_code ) ) {
            $error = 'Vul je code in.';
        } elseif ( empty( $_SESSION['ggr_2fa_code'] ) || empty( $_SESSION['ggr_2fa_expires'] ) ) {
            $error = 'Er is geen geldige code gevonden. Vraag een nieuwe code aan.';
        } elseif ( time() > $_SESSION['ggr_2fa_expires'] ) {
            $error = 'Je code is verlopen. Vraag een nieuwe code aan.';
            $_SESSION['ggr_2fa_code']      = null;
            $_SESSION['ggr_2fa_code_sent'] = false;
        } elseif ( $input_code !== $_SESSION['ggr_2fa_code'] ) {
            $error = 'De ingevulde code is onjuist.';
        } else {
            ggr_portal_mark_2fa_verified();
            $dash = esc_url( home_url( '/dashboard/' ) );
            return '<script>window.location.href = "' . $dash . '";</script>';
        }
    }

    // HTML voor de 2FA-pagina
    ob_start();
    ?>
    <div class="ggr-login-wrapper">
        <div class="ggr-login-shell">
            <div class="ggr-logo-top"
                 style="display:flex;justify-content:center;margin-bottom:16px;">
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="GGR"
                     loading="lazy"
                     style="max-height:75px;width:auto;">
            </div>

            <div class="ggr-login-card">
                <h1 class="ggr-login-title">Bevestig je login</h1>

                <?php if ( $notice ) : ?>
                    <div class="ggr-login-notice ggr-login-notice--info">
                        <?php echo esc_html( $notice ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( $error ) : ?>
                    <div class="ggr-login-notice ggr-login-notice--error">
                        <?php echo esc_html( $error ); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="ggr-2fa-form" id="ggr-2fa-form">
                    <div class="ggr-field">
                        <label for="ggr_2fa_code">Bevestigingscode</label>
                        <input type="text"
                               name="ggr_2fa_code"
                               id="ggr_2fa_code"
                               class="input"
                               pattern="[0-9]{6}"
                               maxlength="6"
                               inputmode="numeric"
                               required />
                    </div>

                    <div class="ggr-submit">
                        <?php wp_nonce_field( 'ggr_2fa_verify', 'ggr_2fa_verify_nonce' ); ?>
                        <!-- Kleine fallback-submit, verborgen -->
                        <input type="submit"
                               class="button button-primary ggr-button"
                               value="Bevestigen"
                               style="display:none;" />
                    </div>
                </form>

                <div class="ggr-login-actions">
                    <button type="button"
                            class="ggr-login-submit"
                            data-ggr-2fa-submit>
                        Bevestigen
                    </button>
                </div>

                <form method="post" class="ggr-2fa-resend">
                    <?php wp_nonce_field( 'ggr_2fa_resend', 'ggr_2fa_resend_nonce' ); ?>
                    <button type="submit"
                            name="ggr_2fa_resend"
                            class="ggr-link-button">
                        Nieuwe code sturen
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var form  = document.getElementById("ggr-2fa-form");
        var code  = document.getElementById("ggr_2fa_code");
        var btn   = document.querySelector("[data-ggr-2fa-submit]");

        if (!form || !btn) return;

        btn.addEventListener("click", function() {
            if (code && !code.value.trim()) {
                code.focus();
                return;
            }
            form.submit();
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode( 'ggr_2fa_form', 'ggr_2fa_form_shortcode' );


/**
 * Login redirect voor Leads:
 * - Leads gaan standaard naar de onboarding-omgeving
 * - Participants / admins volgen de normale flow
 */
add_filter( 'login_redirect', 'ggr_onboarding_login_redirect_for_leads', 10, 3 );

function ggr_onboarding_login_redirect_for_leads( $redirect_to, $requested_redirect_to, $user ) {
    if ( ! $user instanceof WP_User ) {
        return $redirect_to;
    }

    // Pas aan naar de slug van je onboarding-pagina
    $lead_onboarding_url = home_url( '/onboarding/' );

    $roles = (array) $user->roles;

    // Alleen voor leads
    if ( in_array( 'lead', $roles, true ) ) {
        return $lead_onboarding_url;
    }

    // Iedereen anders blijft de standaard redirect gebruiken
    return $redirect_to;
}

/**
 * Frontend guard:
 * - Leads mogen niet op portal-pagina's komen
 * - Ze worden teruggestuurd naar de onboarding-omgeving
 */
add_action( 'template_redirect', 'ggr_restrict_portal_for_leads' );

function ggr_restrict_portal_for_leads() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user  = wp_get_current_user();
    $roles = (array) $user->roles;

    if ( ! in_array( 'lead', $roles, true ) ) {
        return; // geen lead → geen restrictie hier
    }

    // HIER moet je je portal-pagina's expliciet benoemen
    // (slugs of IDs). Voorbeeld:
    $portal_pages = array(
        'dashboard',
        'mijn-portefeuille',
        'transacties',
        'profiel',
    );

    if ( is_page( $portal_pages ) ) {
        // Stuur lead terug naar onboarding
        wp_safe_redirect( home_url( '/onboarding/' ) );
        exit;
    }
}

add_action( 'wp_login', 'ggr_portal_store_last_login', 10, 2 );

function ggr_portal_store_last_login( $user_login, $user ) {
    if ( ! $user instanceof WP_User ) {
        return;
    }

    update_user_meta( $user->ID, 'ggr_last_login_at', current_time( 'timestamp' ) );
    
    if ( function_exists( 'ggr_hubspot_sync_last_login' ) ) {
        ggr_hubspot_sync_last_login( $user->ID );
    }

    if ( function_exists( 'ggr_portal_log_participant_action' ) ) {
        $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_strip_all_tags( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $details = array();

        if ( $ip_address ) {
            $details[] = sprintf( 'IP-adres: "%s"', ggr_portal_format_audit_value( $ip_address ) );
        }

        if ( $user_agent ) {
            $details[] = sprintf( 'User agent: "%s"', ggr_portal_format_audit_value( $user_agent ) );
        }

        ggr_portal_log_participant_action(
            $user->ID,
            'login',
            'Succesvolle login.',
            array(
                'changes' => $details,
            )
        );
    }
}
