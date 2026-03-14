<?php
/**
 * Security helper for nonce verification and capability checks.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify nonce and user capability for form submissions.
 *
 * Calls wp_die() if verification fails. Use in admin_init or wp_ajax handlers.
 *
 * @param string $nonce_action  The nonce action name.
 * @param string $nonce_field   The nonce field name in $_POST. Default '_wpnonce'.
 * @param string $capability    Required user capability. Default 'manage_options'.
 * @return void
 */
function contai_verify_request( $nonce_action, $nonce_field = '_wpnonce', $capability = 'manage_options' ) {
	if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ $nonce_field ] ), $nonce_action ) ) {
		wp_die(
			esc_html__( 'Security check failed.', '1platform-content-ai' ),
			esc_html__( 'Forbidden', '1platform-content-ai' ),
			array( 'response' => 403 )
		);
	}

	if ( ! current_user_can( $capability ) ) {
		wp_die(
			esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ),
			esc_html__( 'Unauthorized', '1platform-content-ai' ),
			array( 'response' => 403 )
		);
	}
}
