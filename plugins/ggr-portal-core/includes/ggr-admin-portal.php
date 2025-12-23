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
 * Voor nu geen shell-activering; admin ziet de standaard WordPress omgeving.
 */
function ggr_admin_shell_is_allowed() {
	return false;
}

/**
 * Extra body-class om gerichte styling toe te passen.
 */
add_filter( 'admin_body_class', function( $classes ) {
	if ( ggr_admin_shell_is_allowed() ) {
		$classes .= ' ggr-admin-shell-enabled';
	}

	return $classes;
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
		'ggr-admin-shell-icons',
		'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
		[],
		'3.5.0'
	);

	// Verberg standaard WP chrome en reset layout voor shell.
	$custom_css = '
		body.ggr-admin-shell-enabled #adminmenumain,
		body.ggr-admin-shell-enabled #wpadminbar,
		body.ggr-admin-shell-enabled #screen-meta,
		body.ggr-admin-shell-enabled #screen-meta-links,
		body.ggr-admin-shell-enabled #contextual-help-link-wrap,
		body.ggr-admin-shell-enabled .update-nag,
		body.ggr-admin-shell-enabled .notice,
		body.ggr-admin-shell-enabled .wrap > h1.wp-heading-inline {
			display: none !important;
		}

		body.ggr-admin-shell-enabled #wpcontent,
		body.ggr-admin-shell-enabled #wpbody-content {
			margin-left: 0;
			padding: 0;
		}

		body.ggr-admin-shell-enabled #wpwrap {
			background: var(--ggr-main-bg, #f2f7f8);
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .wrap {
			margin: 0;
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .wrap > .notice,
		body.ggr-admin-shell-enabled .ggr-admin-shell__content .wrap > .update-nag {
			display: block !important;
			margin-bottom: 16px;
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content table.widefat,
		body.ggr-admin-shell-enabled .ggr-admin-shell__content .form-table {
			background: #ffffff;
			border: 1px solid #e5e7eb;
			border-radius: 12px;
			padding: 12px 12px 8px;
			box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .form-table th {
			padding-left: 6px;
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .form-table td {
			padding-right: 6px;
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .page-title-action {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			background: #ffffff;
			color: #111827;
			border: 1px solid #e5e7eb;
			border-radius: 8px;
			padding: 8px 12px;
			text-decoration: none;
			box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
			transition: all .15s ease;
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__content .page-title-action:hover {
			color: var(--ggr-accent, #f29e75);
			border-color: var(--ggr-accent, #f29e75);
		}

		body.ggr-admin-shell-enabled .ggr-admin-shell__breadcrumb {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 13px;
			color: #6b7280;
		}

		@media (max-width: 960px) {
			body.ggr-admin-shell-enabled .ggr-admin-shell__header {
				flex-direction: column;
			}
		}
	';

	wp_add_inline_style( 'ggr-admin-shell-portal', $custom_css );

	// Data voor shell in JS.
	$current_user = wp_get_current_user();

	$nav_primary = [
		[
			'slug'  => 'ggr-admin-portal',
			'label' => 'Admin Portal',
			'icon'  => 'ri-dashboard-line',
			'url'   => admin_url( 'admin.php?page=ggr-admin-portal' ),
		],
		[
			'slug'  => 'ggr-crm',
			'label' => 'CRM',
			'icon'  => 'ri-id-card-line',
			'url'   => admin_url( 'admin.php?page=ggr-crm' ),
		],
		[
			'slug'  => 'ggr-meldingen',
			'label' => 'Meldingen',
			'icon'  => 'ri-notification-3-line',
			'url'   => admin_url( 'admin.php?page=ggr-meldingen' ),
		],
		[
			'slug'  => 'ggr_bericht',
			'label' => 'Portal Berichten',
			'icon'  => 'ri-chat-3-line',
			'url'   => admin_url( 'edit.php?post_type=ggr_bericht' ),
		],
		[
			'slug'  => 'ggr-stock-price',
			'label' => 'Fund waardering',
			'icon'  => 'ri-stock-line',
			'url'   => admin_url( 'admin.php?page=ggr-stock-price' ),
		],
	];

	// Aanvullende acties/tabbladen voor beheer & systeem
	$nav_secondary = [
		[
			'slug'  => 'front-portal',
			'label' => 'Participant portal',
			'icon'  => 'ri-login-box-line',
			'url'   => home_url( '/dashboard/' ),
		],
		[
			'slug'  => 'ggr_email_template',
			'label' => 'E-mail templates',
			'icon'  => 'ri-mail-settings-line',
			'url'   => admin_url( 'edit.php?post_type=ggr_email_template' ),
		],
		[
			'slug'  => 'users.php',
			'label' => 'Beheerders & rollen',
			'icon'  => 'ri-user-settings-line',
			'url'   => admin_url( 'users.php' ),
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
			'logoUrl'       => 'https://145546258.fs1.hubspotusercontent-eu1.net/hubfs/145546258/GRR%20full%20logo%20-%20Blue%20-%20Black.png',
			'homeUrl'       => home_url( '/' ),
			'profileUrl'    => admin_url( 'profile.php' ),
			'logoutUrl'     => wp_logout_url(),
			'currentPage'   => $hook_suffix,
			'pageParam'     => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '',
			'postTypeParam' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '',
			'title'         => wp_get_document_title(),
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
		if (item.slug === 'ggr_bericht' && currentPostType === 'ggr_bericht') {
			return true;
		}
		if (item.slug === 'ggr_email_template' && currentPostType === 'ggr_email_template') {
			return true;
		}
		if (item.slug === 'users.php' && window.location.href.includes('users.php')) {
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

	const userBox = document.createElement('div');
	userBox.className = 'ggr-admin-portal__user';
	userBox.innerHTML = `
		<div class="ggrp-fe-meta-label">Ingelogd als</div>
		<h2>${data.user?.name || ''}</h2>
		<p>${data.user?.email || ''}</p>
		<div class="ggr-admin-portal__card-actions">
			<a class="ggr-admin-portal__button" href="${data.profileUrl || '#'}">
				<i class="ri-user-settings-line"></i> Profiel
			</a>
			<a class="ggr-admin-portal__button" href="${data.logoutUrl || '#'}">
				<i class="ri-logout-circle-line"></i> Uitloggen
			</a>
		</div>
	`;

	header.appendChild(titleBox);
	header.appendChild(userBox);

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

	// Vervang de wpwrap content door de shell.
	wpwrap.innerHTML = '';
	wpwrap.appendChild(shell);
});
JS
	);
} );
