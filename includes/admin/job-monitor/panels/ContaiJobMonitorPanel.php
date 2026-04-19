<?php
/**
 * Router for the Jobs admin panel.
 *
 * Dispatches to the UI v3 implementation or the legacy one based on
 * contai_ui_v3_enabled(). Both variants are fully self-contained and
 * handle their own POST actions.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ContaiJobMonitorPanel' ) ) {
	class ContaiJobMonitorPanel {

		/**
		 * Render the correct variant for the current user/site preference.
		 *
		 * Panel files only define renderer classes (no side-effect execution
		 * on include), so this method is responsible for instantiating and
		 * invoking them. Includes are idempotent.
		 */
		public static function render(): void {
			if ( function_exists( 'contai_ui_v3_enabled' ) && contai_ui_v3_enabled() ) {
				require_once __DIR__ . '/ContaiJobMonitorPanel.v3.php';
				( new ContaiAdminJobMonitorV3() )->render();
				return;
			}

			require_once __DIR__ . '/ContaiJobMonitorPanel.legacy.php';
			( new ContaiAdminJobMonitor() )->render();
		}

		/**
		 * Wrapper class for the outermost <div class="wrap"> on the Jobs page.
		 *
		 * Exposed as a pure helper so it can be unit-tested without running the
		 * full render path.
		 */
		public static function wrapperClass(): string {
			if ( function_exists( 'contai_ui_v3_enabled' ) && contai_ui_v3_enabled() ) {
				return 'wrap contai-app contai-page contai-job-monitor';
			}
			return 'wrap contai-job-monitor';
		}
	}
}
