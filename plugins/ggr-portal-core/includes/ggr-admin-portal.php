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
	

	// Medewerkers (employee rol) krijgen altijd de portal-shell.
	if ( in_array( 'employee', (array) $current_user->roles, true ) ) {
	}

	if ( '0' === $shell_param ) {
		return false;
	}
	
	// Administrator krijgt standaard de portal-shell.
	if ( current_user_can( 'manage_options' ) ) {
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
 * Redirect admins/medewerkers van wp-admin dashboard naar het portal dashboard.
 */
add_action( 'admin_init', function() {
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( ! ggr_admin_shell_user_can_access() ) {
		return;
	}
	
	if ( ! ggr_admin_shell_is_allowed() ) {
		return;
	}


	global $pagenow;
	$page_param = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 'index.php' === $pagenow && '' === $page_param ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ggr-portal-dashboard' ) );
		exit;
	}
} );

/**
 * Render dashboard pagina (basis).
 */
function ggr_admin_render_dashboard() {
	if ( ! ggr_admin_shell_user_can_access() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	$participant_count = 0;
	$participant_query = new WP_User_Query( [
		'role'         => 'participant',
		'fields'       => 'ID',
		'number'       => 1,
		'count_total'  => true,
	] );
	if ( $participant_query->get_total() !== null ) {
		$participant_count = (int) $participant_query->get_total();
	}

	global $wpdb;
	$stock_table = $wpdb->prefix . 'ggr_stock_prices';
	$latest_stock = $wpdb->get_row(
		"SELECT price_date, price_value, fund_total, total_participations, updated_at
		 FROM {$stock_table}
		 ORDER BY price_date DESC
		 LIMIT 1",
		ARRAY_A
	);

	$latest_nav_value = null;
	$latest_nav_date  = '';
	$total_value      = null;
	$total_parts      = null;

	if ( $latest_stock ) {
		$latest_nav_value = (float) $latest_stock['price_value'];
		$latest_nav_date  = $latest_stock['price_date'];
		$total_parts      = isset( $latest_stock['total_participations'] ) ? (float) $latest_stock['total_participations'] : null;
		$total_value      = isset( $latest_stock['fund_total'] ) ? (float) $latest_stock['fund_total'] : null;
	}

	if ( $total_parts === null && function_exists( 'ggr_portal_get_total_participations_all_users' ) ) {
		$total_parts = ggr_portal_get_total_participations_all_users( $latest_nav_date ?: null );
	}

	if ( $total_value === null && $latest_nav_value !== null && $total_parts !== null ) {
		$total_value = $latest_nav_value * $total_parts;
	}

	$average_value = ( $participant_count > 0 && $total_value !== null )
		? ( $total_value / $participant_count )
		: null;

	$dividend_table = $wpdb->prefix . 'ggr_dividend_accruals';
	$dividend_rows  = $wpdb->get_results(
		"SELECT accrual_total
		 FROM {$dividend_table}
		 ORDER BY accrual_date DESC
		 LIMIT 12",
		ARRAY_A
	);

	$average_dividend = null;
	if ( ! empty( $dividend_rows ) ) {
		$dividend_sum = 0.0;
		foreach ( $dividend_rows as $row ) {
			$dividend_sum += isset( $row['accrual_total'] ) ? (float) $row['accrual_total'] : 0.0;
		}
		$average_dividend = $dividend_sum / count( $dividend_rows );
	}

	$mutaties = get_posts( [
		'post_type'      => 'ggr_mutatie',
		'posts_per_page' => 5,
		'post_status'    => [ 'publish', 'draft' ],
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	$mutatie_types    = function_exists( 'ggr_mutaties_get_types' ) ? ggr_mutaties_get_types() : [];
	$mutatie_statuses = function_exists( 'ggr_mutaties_get_statuses' ) ? ggr_mutaties_get_statuses() : [];

	$meldingen = get_posts( [
		'post_type'      => 'ggr_melding',
		'posts_per_page' => 5,
		'post_status'    => [ 'publish', 'draft' ],
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	$melding_statuses = function_exists( 'ggr_meldingen_get_statuses' ) ? ggr_meldingen_get_statuses() : [];

	$format_money = function( $value ) {
		if ( $value === null ) {
			return '—';
		}
		return '€ ' . number_format( (float) $value, 2, ',', '.' );
	};
	$format_nav = function( $value ) {
		if ( $value === null ) {
			return '—';
		}
		return '€ ' . number_format( (float) $value, 4, ',', '.' );
	};
	$last_updated_label = 'Nog niet bijgewerkt';
	if ( $latest_stock && ! empty( $latest_stock['updated_at'] ) ) {
		$last_updated_label = wp_date( 'd-m-Y H:i', strtotime( $latest_stock['updated_at'] ) );
	} elseif ( $latest_nav_date ) {
		$last_updated_label = wp_date( 'd-m-Y', strtotime( $latest_nav_date ) );
	}

	echo '<div class="wrap ggr-admin-dashboard">';
	echo '<h1>Dashboard</h1>';
	echo '<p class="ggrp-fe-subtitle">Overzicht van de belangrijkste kerncijfers en recente updates.</p>';
	echo '<p class="ggr-admin-dashboard-updated">Laatst bijgewerkt: ' . esc_html( $last_updated_label ) . '</p>';

	echo '<div class="ggrp-fe-kpi-row ggr-admin-dashboard-kpis">';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Aantal participanten</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( number_format_i18n( $participant_count ) ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Actieve deelnemers in het portal.</div>';
	echo '</article>';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Totale participaties</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( $total_parts !== null ? number_format( (float) $total_parts, 4, ',', '.' ) : '—' ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Totaal uitgegeven participaties.</div>';
	echo '</article>';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Huidige NAV koers</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( $format_nav( $latest_nav_value ) ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Laatste koersdatum: ' . esc_html( $latest_nav_date ? date_i18n( 'd-m-Y', strtotime( $latest_nav_date ) ) : '—' ) . '</div>';
	echo '</article>';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Totale positiewaarde</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( $format_money( $total_value ) ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Op basis van de meest recente NAV.</div>';
	echo '</article>';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Gem. positiewaarde p.p.</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( $format_money( $average_value ) ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Totaal gedeeld door deelnemers.</div>';
	echo '</article>';

	echo '<article class="ggrp-fe-card">';
	echo '<h2 class="ggrp-fe-card-title">Gem. dividend per maand</h2>';
	echo '<div class="ggrp-fe-card-value">' . esc_html( $format_money( $average_dividend ) ) . '</div>';
	echo '<div class="ggrp-fe-card-meta">Gemiddelde over de laatste 12 maanden.</div>';
	echo '</article>';

	echo '</div>';

	echo '<div class="ggr-admin-dashboard-grid">';
	echo '<section class="ggrp-fe-panel ggr-admin-dashboard-panel">';
	echo '<div class="ggrp-fe-panel-header">';
	echo '<h2>Laatste mutaties</h2>';
	echo '<a class="ggr-admin-dashboard-link" href="' . esc_url( admin_url( 'admin.php?page=ggr-mutaties' ) ) . '">Bekijk alle mutaties</a>';
	echo '</div>';
	echo '<div class="ggrp-fe-panel-body ggr-admin-dashboard-panel-body">';

	if ( empty( $mutaties ) ) {
		echo '<p class="ggrp-fe-empty-chart">Nog geen mutaties gevonden.</p>';
	} else {
		echo '<table class="widefat striped ggr-admin-dashboard-table ggr-admin-dashboard-table--mutaties">';
		echo '<thead><tr>';
		echo '<th>Datum</th>';
		echo '<th>Type</th>';
		echo '<th>Deelnemer</th>';
		echo '<th>Bedrag</th>';
		echo '<th>Status</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		foreach ( $mutaties as $mutatie ) {
			$type_key   = get_post_meta( $mutatie->ID, 'ggr_mutatie_type', true );
			$status_key = get_post_meta( $mutatie->ID, 'ggr_mutatie_status', true );
			$amount     = get_post_meta( $mutatie->ID, 'ggr_mutatie_amount', true );
			$user_id    = (int) get_post_meta( $mutatie->ID, 'ggr_mutatie_user_id', true );
			$scope      = get_post_meta( $mutatie->ID, 'ggr_mutatie_scope', true );
			$planned    = get_post_meta( $mutatie->ID, 'ggr_mutatie_planned_date', true );

			$user_label = 'Alle participanten';
			if ( 'user' === $scope && $user_id ) {
				$user = get_user_by( 'id', $user_id );
				$user_label = $user ? $user->display_name : 'Onbekend';
			}

			$type_label   = isset( $mutatie_types[ $type_key ] ) ? $mutatie_types[ $type_key ] : $type_key;
			$status_label = isset( $mutatie_statuses[ $status_key ] ) ? $mutatie_statuses[ $status_key ] : $status_key;
			$date_label   = $planned ? date_i18n( 'd-m-Y', strtotime( $planned ) ) : get_the_date( 'd-m-Y', $mutatie );

			echo '<tr>';
			echo '<td>' . esc_html( $date_label ) . '</td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . esc_html( $format_money( $amount ) ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
	echo '</section>';

	echo '<section class="ggrp-fe-panel ggr-admin-dashboard-panel">';
	echo '<div class="ggrp-fe-panel-header">';
	echo '<h2>Laatste meldingen</h2>';
	echo '<a class="ggr-admin-dashboard-link ggr-admin-dashboard-link--blue" href="' . esc_url( admin_url( 'admin.php?page=ggr-meldingen' ) ) . '">Bekijk alle meldingen</a>';
	echo '</div>';
	echo '<div class="ggrp-fe-panel-body ggr-admin-dashboard-panel-body">';

	if ( empty( $meldingen ) ) {
		echo '<p class="ggrp-fe-empty-chart">Nog geen meldingen gevonden.</p>';
	} else {
		echo '<table class="widefat striped ggr-admin-dashboard-table ggr-admin-dashboard-table--meldingen">';
		echo '<thead><tr>';
		echo '<th>Datum</th>';
		echo '<th>Melding</th>';
		echo '<th>Status</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		foreach ( $meldingen as $melding ) {
			$status_key   = get_post_meta( $melding->ID, 'ggr_melding_status', true );
			$status_label = isset( $melding_statuses[ $status_key ] ) ? $melding_statuses[ $status_key ] : $status_key;
			$date_label   = get_the_date( 'd-m-Y H:i', $melding );

			echo '<tr>';
			echo '<td>' . esc_html( $date_label ) . '</td>';
			echo '<td>' . esc_html( $melding->post_title ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	echo '</div>';
	echo '</section>';
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
		'ggr-track-delivery',		
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

	if ( in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php' ), true ) && in_array( $post_type, array( 'ggr_email_template', 'ggr_mutatie', 'ggr_bericht' ), true ) ) {
		$allowed = true;
	}

	if ( 'post.php' === $pagenow && ! $post_type && isset( $_GET['post'] ) ) {
		$post_id = (int) $_GET['post'];
		$post    = get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'ggr_email_template', 'ggr_mutatie', 'ggr_bericht' ), true ) ) {
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
	$has_pending_mutaties = false;
	$has_pending_meldingen = false;

	$pending_mutaties = get_posts( array(
		'post_type'      => 'ggr_mutatie',
		'posts_per_page' => 1,
		'post_status'    => array( 'publish', 'draft' ),
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => 'ggr_mutatie_status',
				'value' => 'nieuw',
			),
		),
	) );
	if ( ! empty( $pending_mutaties ) ) {
		$has_pending_mutaties = true;
	}

	$pending_meldingen = get_posts( array(
		'post_type'      => 'ggr_melding',
		'posts_per_page' => 1,
		'post_status'    => array( 'publish' ),
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => 'ggr_melding_status',
				'value' => 'nieuw',
			),
		),
	) );
	if ( ! empty( $pending_meldingen ) ) {
		$has_pending_meldingen = true;
	}

	$has_mutatie_alert = $has_pending_mutaties || $has_pending_meldingen;	

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
			'slug'  => 'ggr_bericht',
			'label' => 'Berichten',
			'icon'  => 'ri-message-3-line',
			'url'   => admin_url( 'edit.php?post_type=ggr_bericht' ),
		],			
		[
			'slug'  => 'ggr-mutaties',
			'label' => 'Mutaties',
			'icon'  => 'ri-increase-decrease-line',
			'url'   => admin_url( 'admin.php?page=ggr-mutaties' ),
			'hasAlert' => $has_mutatie_alert,			
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
		[
			'slug'  => 'ggr-management-fee',
			'label' => 'Management Fee',
			'icon'  => 'ri-money-euro-box-line',
			'url'   => admin_url( 'admin.php?page=ggr-management-fee' ),
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
			'slug'  => 'ggr-track-delivery',
			'label' => 'Track delivery',
			'icon'  => 'ri-mail-send-line',
			'url'   => admin_url( 'admin.php?page=ggr-track-delivery' ),
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
		if (item.slug === 'ggr_bericht' && currentPostType === 'ggr_bericht') {
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
			if (item.hasAlert) {
				a.classList.add('has-alert');
			}			
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
