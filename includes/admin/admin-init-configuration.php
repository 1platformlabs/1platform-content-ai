<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/init-configuration/site-configuration-form.php';
require_once __DIR__ . '/../providers/WebsiteProvider.php';

function contai_enqueue_website_settings_styles() {
	$screen = get_current_screen();
	if ( $screen && strpos( $screen->id, 'contai-website-settings' ) !== false ) {
		contai_enqueue_style_with_version(
			'contai-content-generator-base',
			plugin_dir_url( __FILE__ ) . 'content-generator/assets/css/base.css',
			array()
		);

		contai_enqueue_style_with_version(
			'contai-admin-init-configuration',
			plugin_dir_url( __FILE__ ) . 'assets/css/admin-init-configuration.css',
			array( 'contai-content-generator-base' )
		);
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_website_settings_styles', 20 );

/**
 * Handle "Save Site Configuration" via admin-post.php.
 *
 * Saves all form fields to WP options and sends category_id + lang
 * to the external API via PATCH /users/websites/{website_id}.
 *
 * cURL examples (import to Postman):
 *
 * # PATCH website with category and language
 * curl -X PATCH http://127.0.0.1:8000/api/v1/users/websites/{website_id} \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *   -d '{"category_id": "698248ea782d9290d094c506", "lang": "en"}'
 *
 * # Error case (website not found)
 * curl -X PATCH http://127.0.0.1:8000/api/v1/users/websites/000000000000000000000000 \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
 *   -H "x-user-token: <USER_ACCESS_TOKEN>" \
 *   -d '{"category_id": "698248ea782d9290d094c506", "lang": "es"}'
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

	// Save all fields to WP options
	if ( ! empty( $site_topic ) ) {
		update_option( 'contai_site_theme', $site_topic );
	}
	update_option( 'contai_site_language', $site_language );
	if ( ! empty( $site_category ) ) {
		update_option( 'contai_site_category', $site_category );
	}
	update_option( 'contai_wordpress_theme', $wordpress_theme );

	// Validate required fields for API call
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

	// Convert form language to API code
	$language_map = array(
		'english' => 'en',
		'spanish' => 'es',
	);
	$lang_code = $language_map[ $site_language ] ?? 'en';

	// Send PATCH to external API
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
					? sprintf( __( 'Settings saved locally, but API sync failed: %s', '1platform-content-ai' ), $api_message )
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

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}

function contai_website_settings_page() {
	if ( contai_render_connection_required_notice() ) {
		return;
	}

	contai_display_site_config_notice();
	?>
	<div class="wrap contai-settings-wrap">
		<h1>
			<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'Settings', '1platform-content-ai' ); ?>
		</h1>

		<div class="contai-page-description">
			<p>
				<?php esc_html_e( 'Configure your website settings, language, and theme.', '1platform-content-ai' ); ?>
			</p>
		</div>

		<?php contai_render_site_configuration_form(); ?>
	</div>
	<?php
}
