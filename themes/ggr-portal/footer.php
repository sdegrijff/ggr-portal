<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<?php
// Zelfde logica als in header, zodat we weten of shell open staat
$show_portal_shell = true;

if ( function_exists( 'ggr_portal_is_login_like_page' ) && ggr_portal_is_login_like_page() ) {
    $show_portal_shell = false;
}

if ( function_exists( 'ggr_portal_is_onboarding_page' ) && ggr_portal_is_onboarding_page() ) {
    $show_portal_shell = false;
}
?>

<?php if ( $show_portal_shell ) : ?>

        </main><!-- /.ggr-shell-main -->

        <!-- Mobiele bottom-nav (portal only) -->
        <footer class="ggr-mobile-footer">
            <nav class="ggr-mobile-footer-nav">
                <?php
                // URLs
                $dash_url       = home_url( '/dashboard/' );
                $tx_url         = home_url( '/transacties/' );
                $investeren_url = home_url( '/investeren/' );

                // Active states – gebruikt de helper uit header.php als die bestaat
                $dash_active = function_exists( 'ggr_is_page_slug' ) ? ggr_is_page_slug( 'dashboard' ) : is_page( 'dashboard' );
                $tx_active   = function_exists( 'ggr_is_page_slug' ) ? ggr_is_page_slug( 'transacties' ) : is_page( 'transacties' );
                ?>
                <a href="<?php echo esc_url( $dash_url ); ?>"
                   class="ggr-mobile-footer-item <?php echo $dash_active ? 'is-active' : ''; ?>">
                    <i class="ri-dashboard-line"></i>
                    <span>Dashboard</span>
                </a>

                <a href="<?php echo esc_url( $investeren_url ); ?>"
                   class="ggr-mobile-footer-cta">
                    <span class="ggr-mobile-footer-cta-icon">
                        <i class="ri-add-line"></i>
                    </span>
                </a>

                <a href="<?php echo esc_url( $tx_url ); ?>"
                   class="ggr-mobile-footer-item <?php echo $tx_active ? 'is-active' : ''; ?>">
                    <i class="ri-bank-card-line"></i>
                    <span>Transacties</span>
                </a>
            </nav>
        </footer>

    </div><!-- /.ggr-shell -->

<?php else : ?>

        </main><!-- /.ggr-page-main -->

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
