<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiInternalLinksSettingsHandler {

	private const OPTIONS = array(
		'contai_internal_links_enabled' => 'enabled',
		'contai_internal_links_max_per_post' => 'max_links_per_post',
		'contai_internal_links_max_per_keyword' => 'max_links_per_keyword',
		'contai_internal_links_max_per_target' => 'max_links_per_target',
		'contai_internal_links_batch_size' => 'batch_size',
		'contai_internal_links_excluded_tags' => 'excluded_tags',
		'contai_internal_links_case_insensitive' => 'case_insensitive',
		'contai_internal_links_word_boundaries' => 'word_boundaries',
		'contai_internal_links_same_category' => 'same_category_only',
		'contai_internal_links_min_keyword_length' => 'min_keyword_length',
		'contai_internal_links_distribute' => 'distribute_links',
	);

	public function handleRequest(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
		if ( ! isset( $_POST['internal_links_settings_nonce'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'contai_internal_links_settings_save', 'internal_links_settings_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed', '1platform-content-ai' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', '1platform-content-ai' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer().
		if ( isset( $_POST['reset_settings'] ) ) {
			$this->resetToDefaults();
			$this->showNotice( __( 'Settings reset to defaults', '1platform-content-ai' ) );
			return;
		}

		$this->saveSettings();
		$this->showNotice( __( 'Settings saved successfully', '1platform-content-ai' ) );
	}

	private function saveSettings(): void {
		$settings = $this->parsePostData();

		foreach ( self::OPTIONS as $optionName => $settingKey ) {
			update_option( $optionName, $settings[ $settingKey ] );
		}
	}

	private function parsePostData(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via check_admin_referer().
		$excluded_tags = array();
		if ( ! empty( $_POST['excluded_tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['excluded_tags'] ) ) ) );
			$excluded_tags = array_filter( array_map( 'sanitize_html_class', $tags ) );
		}

		return array(
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'max_links_per_post' => $this->sanitizeInt( 'max_links_per_post', 10, 1, 50 ),
			'max_links_per_keyword' => $this->sanitizeInt( 'max_links_per_keyword', 3, 1, 20 ),
			'max_links_per_target' => $this->sanitizeInt( 'max_links_per_target', 5, 1, 20 ),
			'batch_size' => $this->sanitizeInt( 'batch_size', 10, 1, 50 ),
			'case_insensitive' => isset( $_POST['case_insensitive'] ) ? 1 : 0,
			'word_boundaries' => isset( $_POST['word_boundaries'] ) ? 1 : 0,
			'same_category_only' => isset( $_POST['same_category_only'] ) ? 1 : 0,
			'min_keyword_length' => $this->sanitizeInt( 'min_keyword_length', 3, 1, 10 ),
			'distribute_links' => isset( $_POST['distribute_links'] ) ? 1 : 0,
			'excluded_tags' => $excluded_tags,
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function sanitizeInt( string $key, int $default, int $min, int $max ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleRequest() via check_admin_referer().
		$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $default;
		return max( $min, min( $max, $value ) );
	}

	private function resetToDefaults(): void {
		foreach ( array_keys( self::OPTIONS ) as $optionName ) {
			delete_option( $optionName );
		}
	}

	private function showNotice( string $message ): void {
		add_action(
			'admin_notices',
			function () use ( $message ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $message )
				);
			}
		);
	}
}
