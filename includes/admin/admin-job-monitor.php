<?php
/**
 * Jobs admin screen — entry point.
 *
 * The full renderer was split into legacy + v3 panels under
 * includes/admin/job-monitor/panels/ per design_handoff_ui_v3 Section 7.
 * This file remains as the menu callback and the enqueue hook.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/job-monitor/panels/ContaiJobMonitorPanel.php';

/**
 * Enqueue the UI v3 foundation on the Jobs screen when the flag is on.
 *
 * Legacy CSS/JS is still enqueued from within the legacy panel so the
 * off-path keeps the current visuals byte-identical.
 */
function contai_enqueue_job_monitor_v3_assets() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || strpos( (string) $screen->id, 'contai-job-monitor' ) === false ) {
		return;
	}
	if ( function_exists( 'contai_enqueue_ui_v3' ) ) {
		contai_enqueue_ui_v3();
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_job_monitor_v3_assets', 20 );

function contai_render_job_monitor_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

	ContaiJobMonitorPanel::render();
}
