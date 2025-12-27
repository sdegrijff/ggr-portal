<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
</head>
<body <?php body_class(); ?>>

<?php
/**
 * Bepalen of de portal-shell (sidebar, mobile header/footer) getoond moet worden
 * - NIET tonen op:
 *   - login-achtige pagina's
 *   - onboarding-pagina
 */
$show_portal_shell = true;

if ( function_exists( 'ggr_portal_is_login_like_page' ) && ggr_portal_is_login_like_page() ) {
    $show_portal_shell = false;
}

if ( function_exists( 'ggr_portal_is_onboarding_page' ) && ggr_portal_is_onboarding_page() ) {
    $show_portal_shell = false;
}
?>

<?php if ( $show_portal_shell ) : ?>

    <div class="ggr-shell">
        <aside class="ggr-shell-sidebar">
            <div class="ggr-shell-logo">
                <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>">
                    <img
                        src="https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png"
                        alt="GGR Income Fund"
                        class="ggr-logo-img"
                    />
                </a>
            </div>

            <?php
            // helper voor active state
            if ( ! function_exists( 'ggr_is_page_slug' ) ) {
                function ggr_is_page_slug( $slug ) {
                    return is_page( $slug );
                }
            }
            ?>

            <nav class="ggr-shell-nav ggr-shell-nav--primary">
                <?php
                $investeren_url    = home_url( '/wijziging/' );
                $investeren_active = ggr_is_page_slug( 'wijziging' ) || ggr_is_page_slug( 'investeren' );
                ?>
                <a href="<?php echo esc_url( $investeren_url ); ?>"
                   class="ggr-shell-nav-item ggr-shell-nav-item--cta <?php echo $investeren_active ? 'is-active' : ''; ?>"
                   <?php if ( $investeren_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-funds-box-line"></i>
                    </span>
                    <span>Wijziging</span>
                </a>

                <?php
                $dash_url    = home_url( '/dashboard/' );
                $dash_active = ggr_is_page_slug( 'dashboard' );
                ?>
                <a href="<?php echo esc_url( $dash_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $dash_active ? 'is-active' : ''; ?>"
                   <?php if ( $dash_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-dashboard-line"></i>
                    </span>
                    <span>Mijn Dashboard</span>
                </a>

                <?php
                $tx_url    = home_url( '/transacties/' );
                $tx_active = ggr_is_page_slug( 'transacties' );
                ?>
                <a href="<?php echo esc_url( $tx_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $tx_active ? 'is-active' : ''; ?>"
                   <?php if ( $tx_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-bank-card-line"></i>
                    </span>
                    <span>Transacties</span>
                </a>

                <?php
                $msg_url    = home_url( '/berichten/' );
                $msg_active = ggr_is_page_slug( 'berichten' );
                ?>
                <a href="<?php echo esc_url( $msg_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $msg_active ? 'is-active' : ''; ?>"
                   <?php if ( $msg_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-notification-3-line"></i>
                    </span>
                    <span>Berichten</span>
                </a>

                <?php
                $referral_url    = home_url( '/verwijs-een-vriend/' );
                $referral_active = ggr_is_page_slug( 'verwijs-een-vriend' );
                ?>
                <a href="<?php echo esc_url( $referral_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $referral_active ? 'is-active' : ''; ?>"
                   <?php if ( $referral_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-user-add-line"></i>
                    </span>
                    <span>Verwijs een vriend</span>
                </a>                
            </nav>

            <div class="ggr-shell-divider"></div>
            <div class="ggr-shell-divider"></div>

            <nav class="ggr-shell-nav ggr-shell-nav--secondary">
                <?php
                // Mijn Account
                $acc_url    = home_url( '/mijn-account/' );
                $acc_active = ggr_is_page_slug( 'mijn-account' );
                ?>
                <a href="<?php echo esc_url( $acc_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $acc_active ? 'is-active' : ''; ?>"
                   <?php if ( $acc_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-user-3-line"></i>
                    </span>
                    <span>Mijn Account</span>
                </a>

                <?php
                // Help & Vragen
                $help_url    = home_url( '/help-vragen/' );
                $help_active = ggr_is_page_slug( 'help-vragen' );
                ?>
                <a href="<?php echo esc_url( $help_url ); ?>"
                   class="ggr-shell-nav-item <?php echo $help_active ? 'is-active' : ''; ?>"
                   <?php if ( $help_active ) : ?>aria-current="page"<?php endif; ?>>
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-question-mark"></i>
                    </span>
                    <span>Help &amp; Vragen</span>
                </a>

                <!-- Uitloggen onder Help & Vragen -->
                <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                   class="ggr-shell-nav-item ggr-shell-nav-item--logout">
                    <span class="ggr-shell-nav-icon">
                        <i class="ri-logout-box-line"></i>
                    </span>
                    <span>Uitloggen</span>
                </a>
            </nav>

        </aside>

        <!-- Mobiele header (alleen in portal-shell) -->
        <header class="ggr-mobile-header">
            <div class="ggr-mobile-header-top">

                <div class="ggr-mobile-title">
                    <?php
                    if ( is_page( 'dashboard' ) ) {
                        echo 'Dashboard';
                    } elseif ( is_page( 'transacties' ) ) {
                        echo 'Transacties';
                    } elseif ( is_page( 'berichten' ) ) {
                        echo 'Berichten';
                    } elseif ( is_page( 'mijn-account' ) ) {
                        echo 'Mijn account';
                    } elseif ( is_page( 'verwijs-een-vriend' ) ) {
                        echo 'Verwijs een vriend';                        
                    } else {
                        the_title();
                    }
                    ?>
                </div>

                <div class="ggr-mobile-actions">
                    <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"
                       class="ggr-mobile-icon-btn <?php echo is_page( 'mijn-account' ) ? 'is-active' : ''; ?>">
                        <i class="ri-logout-box-line"></i>
                    </a>
                    
                    <a href="<?php echo home_url( '/berichten/' ); ?>"
                       class="ggr-mobile-icon-btn <?php echo is_page( 'berichten' ) ? 'is-active' : ''; ?>">
                        <i class="ri-notification-3-line"></i>
                    </a>

                    <a href="<?php echo home_url( '/mijn-account/' ); ?>"
                       class="ggr-mobile-icon-btn <?php echo is_page( 'mijn-account' ) ? 'is-active' : ''; ?>">
                        <i class="ri-user-3-line"></i>
                    </a>
                </div>
            </div>
        </header>

        <main class="ggr-shell-main">
<?php else : ?>

    <!-- Geen portal-shell (bijv. login / onboarding): simpele main wrapper -->
    <main class="ggr-page-main">

<?php endif; ?>
