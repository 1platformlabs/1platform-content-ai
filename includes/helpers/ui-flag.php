<?php
/**
 * UI v3 feature flag + foundation enqueue helper.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'contai_ui_v3_enabled' ) ) {
	/**
	 * Whether the current admin screen should render UI v3.
	 *
	 * Order of precedence: user meta → site option → false (legacy default).
	 *
	 * @return bool
	 */
	function contai_ui_v3_enabled() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$user_pref = get_user_meta( $user_id, 'contai_ui_v3', true );
			if ( 'on' === $user_pref ) {
				return true;
			}
			if ( 'off' === $user_pref ) {
				return false;
			}
		}
		return (bool) get_option( 'contai_ui_v3', false );
	}
}

if ( ! function_exists( 'contai_enqueue_ui_v3' ) ) {
	/**
	 * Enqueue v3 foundation (tokens + components + ui.js) once per admin page.
	 *
	 * Safe to call from any admin-*.php enqueue hook; no-op when the flag is off.
	 * Uses the plugin version constant for cache busting, falls back to 1.0.0.
	 *
	 * @return void
	 */
	function contai_enqueue_ui_v3() {
		if ( ! contai_ui_v3_enabled() ) {
			return;
		}

		$ver  = defined( 'CONTAI_VERSION' ) ? CONTAI_VERSION : '1.0.0';
		$base = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/assets/';

		wp_enqueue_style( 'contai-tokens', $base . 'css/contai-tokens.css', array(), $ver );
		wp_enqueue_style( 'contai-components', $base . 'css/contai-components.css', array( 'contai-tokens' ), $ver );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'contai-ui', $base . 'js/contai-ui.js', array(), $ver, true );
	}
}
