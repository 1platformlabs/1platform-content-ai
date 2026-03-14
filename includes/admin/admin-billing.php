<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/billing/components/BillingLayout.php';
require_once __DIR__ . '/billing/panels/OverviewPanel.php';
require_once __DIR__ . '/billing/panels/BillingHistoryPanel.php';
require_once __DIR__ . '/billing/handlers/TopUpHandler.php';

function contai_handle_billing_topup_submission() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check; nonce verified in ContaiTopUpHandler.
	if ( ! isset( $_GET['page'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'contai-billing' ) {
		return;
	}

	if ( ! isset( $_GET['section'] ) || sanitize_key( wp_unslash( $_GET['section'] ) ) !== 'overview' ) {
		return;
	}
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

	$handler = new ContaiTopUpHandler();
	$handler->handleRequest();
}
add_action( 'admin_init', 'contai_handle_billing_topup_submission' );

function contai_enqueue_billing_styles() {
	$screen = get_current_screen();

	if ( ! $screen || strpos( $screen->id, 'contai-billing' ) === false ) {
		return;
	}

	$content_gen_base_url = plugin_dir_url( __FILE__ ) . 'content-generator/assets/css/base.css';
	contai_enqueue_style_with_version(
		'contai-content-generator-base',
		$content_gen_base_url,
		array()
	);

	$css_base_url = plugin_dir_url( __FILE__ ) . 'billing/assets/css/';
	contai_enqueue_style_with_version(
		'contai-billing-base',
		$css_base_url . 'base.css',
		array( 'contai-content-generator-base' )
	);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
	$section = sanitize_key( wp_unslash( $_GET['section'] ?? 'overview' ) );
	$section_css_map = array(
		'overview' => 'overview.css',
		'billing-history' => 'billing-history.css',
	);

	if ( isset( $section_css_map[ $section ] ) ) {
		contai_enqueue_style_with_version(
			"contai-billing-{$section}",
			$css_base_url . $section_css_map[ $section ],
			array( 'contai-billing-base' )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_billing_styles', 20 );

function contai_billing_page() {
	if ( !contai_render_connection_required_notice() ) {
		

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only section navigation parameter.
	$section = sanitize_key( wp_unslash( $_GET['section'] ?? 'overview' ) );
	$valid_sections = array( 'overview', 'billing-history' );

	if ( ! in_array( $section, $valid_sections, true ) ) {
		$section = 'overview';
	}

	$service = new ContaiBillingService();
	$layout = new ContaiBillingLayout( $section );
	$layout->render_header();

	switch ( $section ) {
		case 'billing-history':
			$panel = new ContaiBillingHistoryPanel( $service );
			$layout->render_page_title(
				__( 'Billing History', '1platform-content-ai' ),
				__( 'View your transaction history and payment details', '1platform-content-ai' ),
				'dashicons-list-view'
			);
			$panel->render();
			break;
		case 'overview':
		default:
			$panel = new ContaiBillingOverviewPanel( $service );
			$layout->render_page_title(
				__( 'Billing Overview', '1platform-content-ai' ),
				__( 'Manage your credit balance and subscription', '1platform-content-ai' ),
				'dashicons-chart-area'
			);
			$panel->render();
	}

	$layout->render_footer();
	}

}
