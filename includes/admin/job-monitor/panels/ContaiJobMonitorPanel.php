<?php
/**
 * Jobs admin panel — dispatcher.
 *
 * The v3 renderer lives in ContaiJobMonitorPanel.v3.php (retained under its
 * current filename until the ui/dashboard branch inlines it). There is no
 * legacy fallback and no feature flag: every request renders v3.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ContaiJobMonitorPanel' ) ) {
	class ContaiJobMonitorPanel {

		public static function render(): void {
			require_once __DIR__ . '/ContaiJobMonitorPanel.v3.php';
			( new ContaiAdminJobMonitorV3() )->render();
		}

		public static function wrapperClass(): string {
			return 'wrap contai-app contai-page contai-job-monitor';
		}
	}
}
