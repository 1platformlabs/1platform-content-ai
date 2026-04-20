<?php
/**
 * Jobs admin screen — entry point.
 *
 * The renderer lives at includes/admin/job-monitor/panels/ContaiJobMonitorPanel.php.
 * Foundation CSS/JS is enqueued globally from the main plugin file on every
 * plugin admin page, so no per-screen enqueue is needed here.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/job-monitor/panels/ContaiJobMonitorPanel.php';

function contai_render_job_monitor_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

	ContaiJobMonitorPanel::render();
}
