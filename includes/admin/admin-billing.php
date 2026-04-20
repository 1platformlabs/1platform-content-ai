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

function contai_billing_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

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
