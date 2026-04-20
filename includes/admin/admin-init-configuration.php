<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/init-configuration/site-configuration-form.php';
require_once __DIR__ . '/../providers/WebsiteProvider.php';

/**
 * Handle "Save Site Configuration" via admin-post.php.
 *
 * Saves all form fields to WP options and sends category_id + lang
 * to the external API via PATCH /users/websites/{website_id}.
 */
function contai_handle_save_site_configuration() {
	check_admin_referer( 'contai_save_site_configuration', 'contai_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ) );
	}

	$site_topic      = sanitize_text_field( wp_unslash( $_POST['contai_site_topic'] ?? '' ) );
	$site_language   = sanitize_key( wp_unslash( $_POST['contai_site_language'] ?? '' ) );
	$site_category   = sanitize_text_field( wp_unslash( $_POST['contai_site_category'] ?? '' ) );
	$wordpress_theme = sanitize_text_field( wp_unslash( $_POST['contai_wordpress_theme'] ?? '' ) );

	$allowed_languages = array( 'english', 'spanish' );
	if ( ! in_array( $site_language, $allowed_languages, true ) ) {
		$site_language = 'spanish';
	}

	if ( empty( $wordpress_theme ) ) {
		$wordpress_theme = 'astra';
	}

	if ( ! empty( $site_topic ) ) {
		update_option( 'contai_site_theme', $site_topic );
	}
	update_option( 'contai_site_language', $site_language );
	if ( ! empty( $site_category ) ) {
		update_option( 'contai_site_category', $site_category );
	}
	update_option( 'contai_wordpress_theme', $wordpress_theme );

	if ( empty( $site_category ) ) {
		set_transient(
			'contai_site_config_notice',
			array(
				'type'    => 'warning',
				'message' => __( 'Settings saved locally. Please select a category to sync with the API.', '1platform-content-ai' ),
			),
			30
		);

		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	$language_map = array(
		'english' => 'en',
		'spanish' => 'es',
	);
	$lang_code = $language_map[ $site_language ] ?? 'en';

	$website_provider = new ContaiWebsiteProvider();
	$api_response     = $website_provider->updateWebsite(
		array(
			'category_id' => $site_category,
			'lang'        => $lang_code,
		)
	);

	if ( $api_response->isSuccess() ) {
		$api_message = $api_response->getMessage();
		set_transient(
			'contai_site_config_notice',
			array(
				'type'    => 'success',
				'message' => ! empty( $api_message )
					? $api_message
					: __( 'Site configuration saved successfully!', '1platform-content-ai' ),
			),
			30
		);
	} else {
		$api_message = $api_response->getMessage();
		set_transient(
			'contai_site_config_notice',
			array(
				'type'    => 'error',
				'message' => ! empty( $api_message )
					? sprintf( /* translators: %s: API error message */ __( 'Settings saved locally, but API sync failed: %s', '1platform-content-ai' ), $api_message )
					: __( 'Settings saved locally, but API sync failed.', '1platform-content-ai' ),
			),
			30
		);
	}

	wp_safe_redirect( wp_get_referer() );
	exit;
}
add_action( 'admin_post_contai_save_site_configuration', 'contai_handle_save_site_configuration' );

function contai_display_site_config_notice() {
	$notice = get_transient( 'contai_site_config_notice' );

	if ( empty( $notice ) || ! is_array( $notice ) ) {
		return;
	}

	delete_transient( 'contai_site_config_notice' );

	$type    = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
	$message = $notice['message'] ?? '';

	if ( empty( $message ) ) {
		return;
	}

	$icons = array(
		'success' => 'dashicons-yes-alt',
		'error'   => 'dashicons-dismiss',
		'warning' => 'dashicons-warning',
		'info'    => 'dashicons-info',
	);
	$icon = $icons[ $type ] ?? 'dashicons-info';
	?>
	<div class="contai-notice contai-notice-<?php echo esc_attr( $type ); ?>" role="status">
		<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
		<p><?php echo esc_html( $message ); ?></p>
		<div class="contai-notice-actions"></div>
	</div>
	<?php
}

function contai_website_settings_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}
	?>
	<div class="wrap contai-app contai-page">
		<div class="contai-page-header">
			<div class="contai-page-header-row">
				<div>
					<h1 class="contai-page-title">
						<span class="contai-tile" aria-hidden="true">
							<span class="dashicons dashicons-admin-settings"></span>
						</span>
						<?php esc_html_e( 'Settings', '1platform-content-ai' ); ?>
					</h1>
					<p class="contai-page-subtitle">
						<?php esc_html_e( 'Configure your website settings, language, and theme.', '1platform-content-ai' ); ?>
					</p>
				</div>
			</div>
		</div>

		<?php contai_display_site_config_notice(); ?>
		<?php contai_render_site_configuration_form(); ?>
	</div>
	<?php
}
