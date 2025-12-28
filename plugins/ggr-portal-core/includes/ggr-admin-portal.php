<?php
/**
 * GGR Admin Shell
 *
 * Restylet de volledige WordPress admin-omgeving naar de portal look & feel,
 * verbergt WP chrome, en plaatst alle bestaande beheerschermen in dezelfde
 * shell. Werkt voor alle admin-pagina's voor beheerders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basiscontrole:
 */
function ggr_admin_shell_is_allowed() {
	$current_user = wp_get_current_user();
	$shell_param  = isset( $_GET['ggr_admin_shell'] ) ? sanitize_text_field( wp_unslash( $_GET['ggr_admin_shell'] ) ) : '';
	
	if ( '0' === $shell_param ) {
		return false;	 
	}
	
	// Administrator krijgt standaard de portal-shell.
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}


	// Medewerkers (employee rol) krijgen de portal-shell.
	if ( in_array( 'employee', (array) $current_user->roles, true ) ) {
		return true;
	}

	return false;
}

/**
 * Helper: check of huidige user een employee is.
 */
function ggr_admin_shell_user_is_employee() {
	$current_user = wp_get_current_user();
	return in_array( 'employee', (array) $current_user->roles, true );
}

/**
 * Helper: toegang tot admin-shell pagina's.
 */
function ggr_admin_shell_user_can_access() {
	return current_user_can( 'manage_options' ) || ggr_admin_shell_user_is_employee();
}

/**
 * Admin menu: dashboard entry voor de shell.
 */
add_action( 'admin_menu', function() {
	add_menu_page(
		'GGR Admin Dashboard',
		'GGR Admin Dashboard',
		'read',
		'ggr-portal-dashboard',
		'ggr_admin_render_dashboard',
		'dashicons-dashboard',
		2
	);
} );

/**
 * Render dashboard pagina (basis).
 */
function ggr_admin_render_dashboard() {
	if ( ! ggr_admin_shell_user_can_access() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	echo '<div class="wrap">';
	echo '<h1>Dashboard</h1>';
	echo '<p>Welkom in het GGR admin dashboard.</p>';
	echo '</div>';
}

/**
 * Blokkeer employee toegang tot niet-toegestane wp-admin pagina’s.
 */
add_action( 'admin_init', function() {
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! ggr_admin_shell_user_is_employee() ) {
		return;
	}

	global $pagenow;
	$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

	$allowed_admin_pages = array(
		'ggr-portal-dashboard',
		'ggr-meldingen',
		'ggr-mutaties',		
		'ggr-stock-price',
		'ggr-dividend-accruals',
	);

	$allowed_users_pages = array(
		'ggr-participant-overzicht',
		'ggr-onboarding',
		'ggr-audit-log',
	);

	$allowed = false;

	if ( 'admin.php' === $pagenow && in_array( $page, $allowed_admin_pages, true ) ) {
		$allowed = true;
	}

	if ( 'users.php' === $pagenow && in_array( $page, $allowed_users_pages, true ) ) {
		$allowed = true;
	}

	if ( in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) && in_array( $post_type, array( 'ggr_email_template', 'ggr_mutatie' ), true ) ) {
		$allowed = true;
	}

	if ( 'post.php' === $pagenow && ! $post_type && isset( $_GET['post'] ) ) {
		$post_id = (int) $_GET['post'];
		$post    = get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'ggr_email_template', 'ggr_mutatie' ), true ) ) {
			$allowed = true;
		}
	}

	if ( ! $allowed ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ggr-portal-dashboard' ) );
		exit;
	}
} );


/**
 * Extra body-class om gerichte styling toe te passen.
 */
add_filter( 'admin_body_class', function( $classes ) {
	if ( ggr_admin_shell_is_allowed() ) {
		$classes .= ' ggr-admin-shell-enabled ggr-admin-shell-pending';
	}

	return $classes;
} );

/**
 * Voorkom layout verspringing door de admin-shell al te verbergen voordat styles laden.
 */
add_action( 'admin_head', function() {
	if ( ! ggr_admin_shell_is_allowed() ) {
		return;
	}

	echo '<style id="ggr-admin-shell-preload">
body.ggr-admin-shell-enabled #wpwrap{opacity:0;visibility:hidden;}
body.ggr-admin-shell-enabled.ggr-admin-shell-ready #wpwrap{opacity:1;visibility:visible;}
</style>';
} );

/**
 * Laad front-end styles + shell script op alle admin-pagina's voor beheerders.
 */
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	if ( ! ggr_admin_shell_is_allowed() ) {
		return;
	}

	$theme_uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'ggr-admin-shell-base',
		get_stylesheet_uri(),
		[],
		'1.0'
	);

	wp_enqueue_style(
		'ggr-admin-shell-shell',
		$theme_uri . '/assets/css/shell.css',
		[ 'ggr-admin-shell-base' ],
		'1.0'
	);

	wp_enqueue_style(
		'ggr-admin-shell-portal',
		$theme_uri . '/assets/css/portal.css',
		[ 'ggr-admin-shell-shell' ],
		'1.0'
	);

	wp_enqueue_style(
		'ggr-admin-shell-admin',
		$theme_uri . '/assets/css/admin-shell.css',
		[ 'ggr-admin-shell-portal' ],
		'1.0'
	);

	wp_enqueue_style(
		'ggr-admin-shell-icons',
		'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
		[],
		'3.5.0'
	);

	// Data voor shell in JS.
	$current_user = wp_get_current_user();

	$nav_primary = [
		[
			'slug'  => 'ggr-portal-dashboard',
			'label' => 'Dashboard',
			'icon'  => 'ri-dashboard-line',
			'url'   => admin_url( 'admin.php?page=ggr-portal-dashboard' ),
		],
		[
			'slug'  => 'ggr-participant-overzicht',
			'label' => 'Participanten',
			'icon'  => 'ri-group-line',
			'url'   => admin_url( 'users.php?page=ggr-participant-overzicht' ),
		],
		[
			'slug'  => 'ggr-onboarding',
			'label' => 'Onboarding',
			'icon'  => 'ri-user-add-line',
			'url'   => admin_url( 'users.php?page=ggr-onboarding' ),
		],
		[
			'slug'  => 'ggr-meldingen',
			'label' => 'Meldingen/taken',
			'icon'  => 'ri-notification-3-line',
			'url'   => admin_url( 'admin.php?page=ggr-meldingen' ),
		],
		[
			'slug'  => 'ggr-mutaties',
			'label' => 'Mutaties',
			'icon'  => 'ri-increase-decrease-line',
			'url'   => admin_url( 'admin.php?page=ggr-mutaties' ),
		],		

		[
			'slug'  => 'ggr-stock-price',
			'label' => 'NAV Koers',
			'icon'  => 'ri-stock-line',
			'url'   => admin_url( 'admin.php?page=ggr-stock-price' ),
		],
		[
			'slug'  => 'ggr-dividend-accruals',
			'label' => 'Dividend Accruals',
			'icon'  => 'ri-exchange-funds-line',
			'url'   => admin_url( 'admin.php?page=ggr-dividend-accruals' ),
		],
	];

	// Aanvullende acties/tabbladen voor beheer & systeem
	$nav_secondary = [
	    [
			'slug'  => 'ggr_email_template',
			'label' => 'E-mail templates',
			'icon'  => 'ri-mail-settings-line',
			'url'   => admin_url( 'edit.php?post_type=ggr_email_template' ),
		],
		[
			'slug'  => 'ggr-audit-log',
			'label' => 'Audit log',
			'icon'  => 'ri-shield-check-line',
			'url'   => admin_url( 'admin.php?page=ggr-audit-log' ),
		],
	];

	// Alleen admins: directe link naar klassieke WP backend (systeembeheer).
	if ( current_user_can( 'administrator' ) ) {
		$nav_secondary[] = [
			'slug'  => 'wp-admin-classic',
			'label' => 'WordPress admin',
			'icon'  => 'ri-shield-line',
			'url'   => add_query_arg( 'ggr_admin_shell', '0', admin_url( 'index.php' ) ),
		];
	}

	$ibkr_status_payload = null;
	if ( function_exists( 'ggr_ibkr_nav_get_status' ) ) {
		$ibkr_status = ggr_ibkr_nav_get_status();

		$next_run_label = '';
		if ( ! empty( $ibkr_status['next_run'] ) ) {
			$next_run_label = wp_date( 'd-m-Y H:i', (int) $ibkr_status['next_run'] );
		}

		$last_run_label = '';
		if ( ! empty( $ibkr_status['last_run'] ) && is_array( $ibkr_status['last_run'] ) ) {
			$last_run         = $ibkr_status['last_run'];
			$report_date_raw  = ! empty( $last_run['date'] ) ? $last_run['date'] : '';
			$report_date      = $report_date_raw ? wp_date( 'd-m-Y', strtotime( $report_date_raw ) ) : '';
			$nav_value        = isset( $last_run['nav'] ) ? number_format( (float) $last_run['nav'], 4, ',', '.' ) : '';
			$total_value      = isset( $last_run['fund_total'] ) ? number_format( (float) $last_run['fund_total'], 2, ',', '.' ) : '';
			$total_parts      = isset( $last_run['total_participations'] ) ? number_format( (float) $last_run['total_participations'], 4, ',', '.' ) : '';
			$detail_fragments = array();

			if ( $nav_value !== '' ) {
				$detail_fragments[] = 'NAV: € ' . $nav_value;
			}
			if ( $total_value !== '' ) {
				$detail_fragments[] = 'Totaal: € ' . $total_value;
			}

			$detail_text = $detail_fragments ? implode( ', ', $detail_fragments ) : '';
			$label_parts = array_filter( array(
				$run_timestamp,
				$report_date ? '' . $report_date : '',
			) );

			$last_run_label = $label_parts ? implode( ' · ', $label_parts ) : '';

			if ( $detail_text ) {
				$last_run_label = $last_run_label ? $last_run_label . ' · ' . $detail_text : $detail_text;
			}
		}

		$ibkr_status_payload = array(
			'hasCredentials' => ! empty( $ibkr_status['has_credentials'] ),
			'nextRun'        => $next_run_label,
			'lastRun'        => $last_run_label,
		);
	}

	wp_register_script(
		'ggr-admin-shell',
		'',
		[],
		'1.0',
		true
	);

	wp_enqueue_script( 'ggr-admin-shell' );

	wp_add_inline_script(
		'ggr-admin-shell',
		'window.ggrAdminShell = ' . wp_json_encode( [
			'user'          => [
				'name'  => $current_user->display_name,
				'email' => $current_user->user_email,
			],
			'navPrimary'    => $nav_primary,
			'navSecondary'  => $nav_secondary,
			'logoUrl'       => 'https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GGR%20Icon%20-%20Blue%20-%20Black.png',
			'homeUrl'       => home_url( '/' ),
			'profileUrl'    => admin_url( 'profile.php' ),
			'logoutUrl'     => wp_logout_url(),
			'currentPage'   => $hook_suffix,
			'pageParam'     => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '',
			'postTypeParam' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '',
			'title'         => wp_get_document_title(),
			'ibkrStatus'    => $ibkr_status_payload,
		] ) . ';',
		'before'
	);

	wp_add_inline_script(
		'ggr-admin-shell',
		<<<'JS'
document.addEventListener('DOMContentLoaded', function() {
	if (!document.body.classList.contains('ggr-admin-shell-enabled')) {
		return;
	}

	const data = window.ggrAdminShell || {};
	const wpwrap = document.getElementById('wpwrap');
	const wpbodyContent = document.getElementById('wpbody-content');

	if (!wpwrap || !wpbodyContent) {
		return;
	}

	// Bepaal current slug voor active state.
	const currentPage = data.pageParam || '';
	const currentPostType = data.postTypeParam || '';

	const isNavItemActive = (item) => {
		if (item.slug === 'ggr_email_template' && currentPostType === 'ggr_email_template') {
			return true;
		}
		if (item.slug === 'ggr-mutaties' && currentPostType === 'ggr_mutatie') {
			return true;
		}		
		if (item.slug === 'ggr-participant-overzicht' && currentPage === 'ggr-participant-overzicht') {
			return true;
		}
		if (item.slug === 'ggr-onboarding' && currentPage === 'ggr-onboarding') {
			return true;
		}
		if (item.slug === 'profile.php' && window.location.href.includes('profile.php')) {
			return true;
		}
		return item.slug === currentPage;
	};

	// Shell structuur.
	const shell = document.createElement('div');
	shell.className = 'ggr-admin-shell ggr-shell';

	// Sidebar.
	const sidebar = document.createElement('aside');
	sidebar.className = 'ggr-shell-sidebar';

	const logo = document.createElement('div');
	logo.className = 'ggr-shell-logo';
	logo.innerHTML = `
		<a href="${data.navPrimary?.[0]?.url || '#'}">
			<img src="${data.logoUrl}" alt="GGR Admin Portal" class="ggr-logo-img" />
		</a>
	`;
	sidebar.appendChild(logo);

	const buildNav = (items) => {
		const nav = document.createElement('nav');
		nav.className = 'ggr-shell-nav';
		items.forEach((item) => {
			const a = document.createElement('a');
			a.href = item.url;
			a.className = 'ggr-shell-nav-item';
			if (item.external) {
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
			}
			if (isNavItemActive(item)) {
				a.classList.add('is-active');
				a.setAttribute('aria-current', 'page');
			}
			a.innerHTML = `
				<span class="ggr-shell-nav-icon"><i class="${item.icon}"></i></span>
				<span>${item.label}</span>
			`;
			nav.appendChild(a);
		});
		return nav;
	};

	const navPrimary = buildNav(data.navPrimary || []);
	navPrimary.classList.add('ggr-shell-nav--primary');
	sidebar.appendChild(navPrimary);

	const divider = document.createElement('div');
	divider.className = 'ggr-shell-divider';
	sidebar.appendChild(divider.cloneNode());
	sidebar.appendChild(divider.cloneNode());

	const navSecondary = buildNav(data.navSecondary || []);
	navSecondary.classList.add('ggr-shell-nav--secondary');

	const collapseToggle = document.createElement('button');
	collapseToggle.type = 'button';
	collapseToggle.className = 'ggr-shell-nav-item ggr-shell-nav-item--collapse';
	collapseToggle.innerHTML = `
		<span class="ggr-shell-nav-icon"><i class="ri-layout-left-line"></i></span>
		<span>Menu invouwen</span>
	`;
	navSecondary.appendChild(collapseToggle);

	const logout = document.createElement('a');
	logout.href = data.logoutUrl || '#';
	logout.className = 'ggr-shell-nav-item ggr-shell-nav-item--logout';
	logout.innerHTML = `
		<span class="ggr-shell-nav-icon"><i class="ri-logout-box-line"></i></span>
		<span>Uitloggen</span>
	`;
	navSecondary.appendChild(logout);

	sidebar.appendChild(navSecondary);

	// Main.
	const main = document.createElement('main');
	main.className = 'ggr-shell-main';

	const content = document.createElement('div');
	content.className = 'ggr-admin-shell__content ggrp-fe';

	// Header.
	const header = document.createElement('header');
	header.className = 'ggr-admin-shell__header ggrp-fe-header';

	const titleBox = document.createElement('div');
	const pageTitle = (document.querySelector('.wrap > h1')?.innerText || data.title || 'Dashboard').trim();
	titleBox.innerHTML = `
		<h1>${pageTitle}</h1>
		<div class="ggr-admin-shell__breadcrumb">
			<i class="ri-home-4-line"></i>
			<span>Admin</span>
			<i class="ri-arrow-right-s-line"></i>
			<span>${pageTitle}</span>
		</div>
	`;

	header.appendChild(titleBox);

	// Plaats WP content in portal wrapper.
	const pageWrapper = document.createElement('section');
	pageWrapper.className = 'ggr-admin-shell__page ggrp-fe-card';

	// Verplaats bestaande body-content naar onze wrapper.
	pageWrapper.appendChild(wpbodyContent);

	content.appendChild(header);
	content.appendChild(pageWrapper);

	main.appendChild(content);

	shell.appendChild(sidebar);
	shell.appendChild(main);

	const headerToggle = document.createElement('button');
	headerToggle.className = 'ggr-admin-shell__toggle';
	headerToggle.setAttribute('aria-pressed', 'false');
	headerToggle.setAttribute('aria-label', 'Menu inklappen');
	headerToggle.innerHTML = '<i class="ri-menu-fold-line"></i>';

	if (data.pageParam === 'ggr-stock-price' && data.ibkrStatus) {
		const status = data.ibkrStatus;
		const lastRun = status.lastRun || 'Nog niet uitgevoerd';
		const nextRun = status.nextRun || (status.hasCredentials ? 'Nog niet ingepland' : 'Niet ingepland');
		const notice = document.createElement('div');
		notice.className = 'notice notice-info';
		notice.innerHTML = `
			<p><strong>Laatste IBKR Waarde:</strong> ${lastRun}</p>
			<p><strong>Volgende IBKR Waarde:</strong> ${nextRun}</p>
		`;
		pageWrapper.prepend(notice);
	}

	const isCollapsed = () => shell.classList.contains('is-collapsed');
	const setCollapsed = (collapsed) => {
		shell.classList.toggle('is-collapsed', collapsed);
		collapseToggle.innerHTML = collapsed
			? `<span class="ggr-shell-nav-icon"><i class="ri-layout-right-line"></i></span><span>Menu uitklappen</span>`
			: `<span class="ggr-shell-nav-icon"><i class="ri-layout-left-line"></i></span><span>Menu invouwen</span>`;
		headerToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
		headerToggle.setAttribute('aria-label', collapsed ? 'Menu uitklappen' : 'Menu inklappen');
		headerToggle.innerHTML = collapsed ? '<i class="ri-menu-unfold-line"></i>' : '<i class="ri-menu-fold-line"></i>';
		if (window.localStorage) {
			window.localStorage.setItem('ggrAdminShellCollapsed', collapsed ? '1' : '0');
		}
	};

	collapseToggle.addEventListener('click', () => {
		setCollapsed(!isCollapsed());
	});

	// Vervang de wpwrap content door de shell.
	wpwrap.innerHTML = '';
	wpwrap.appendChild(shell);

	headerToggle.addEventListener('click', function() {
		setCollapsed(!isCollapsed());
	});

	titleBox.prepend(headerToggle);

	const stored = window.localStorage ? window.localStorage.getItem('ggrAdminShellCollapsed') : null;
	setCollapsed(stored === '1');

	document.body.classList.remove('ggr-admin-shell-pending');
	document.body.classList.add('ggr-admin-shell-ready');
});
JS
	);
} );


/**
 * Admins: voeg schakeloptie toe om naar de admin-portal shell te gaan.
 */
add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	if ( ggr_admin_shell_is_allowed() ) {
		return;
	}

	$wp_admin_bar->add_node( [
		'id'    => 'ggr-admin-shell-switch',
		'title' => __( 'Open admin-portal', 'ggr-portal-core' ),
		'href'  => add_query_arg( 'ggr_admin_shell', '1', admin_url( 'index.php' ) ),
	] );
}, 100 );
