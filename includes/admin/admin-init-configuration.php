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

		// The beta toggle lives on this screen and needs v3 styles to render
		// correctly regardless of the current flag state (otherwise turning
		// it ON the first time would look unstyled).
		$ver  = defined( 'CONTAI_VERSION' ) ? CONTAI_VERSION : '1.0.0';
		$base = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'contai-tokens', $base . 'css/contai-tokens.css', array(), $ver );
		wp_enqueue_style( 'contai-components', $base . 'css/contai-components.css', array( 'contai-tokens' ), $ver );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_script( 'contai-ui', $base . 'js/contai-ui.js', array(), $ver, true );
	}
}
add_action( 'admin_enqueue_scripts', 'contai_enqueue_website_settings_styles', 20 );

/**
 * Handle "Save UI preferences" (UI v3 beta toggle) via admin-post.php.
 *
 * Persists per-user opt-in for UI v3 as user meta `contai_ui_v3`
 * with values `on` or `off`.
 */
function contai_handle_save_ui_preferences() {
	check_admin_referer( 'contai_save_ui_preferences', 'contai_ui_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', '1platform-content-ai' ) );
	}

	$user_id = get_current_user_id();
	if ( $user_id ) {
		$enabled = isset( $_POST['contai_ui_v3'] ) && '1' === $_POST['contai_ui_v3'];
		update_user_meta( $user_id, 'contai_ui_v3', $enabled ? 'on' : 'off' );

		set_transient(
			'contai_site_config_notice',
			array(
				'type'    => 'success',
				'message' => $enabled
					? __( 'UI v3 (beta) is now enabled for your account.', '1platform-content-ai' )
					: __( 'UI v3 (beta) is now disabled for your account.', '1platform-content-ai' ),
			),
			30
		);
	}

	wp_safe_redirect( wp_get_referer() );
	exit;
}
add_action( 'admin_post_contai_save_ui_preferences', 'contai_handle_save_ui_preferences' );

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

	$v3_on      = contai_ui_v3_enabled();
	$wrap_class = 'wrap contai-settings-wrap';
	if ( $v3_on ) {
		$wrap_class .= ' contai-app';
	}
	?>
	<div class="<?php echo esc_attr( $wrap_class ); ?>">
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

		<?php contai_render_ui_v3_beta_panel( $v3_on ); ?>
	</div>
	<?php
}

/**
 * Render the UI v3 beta opt-in toggle.
 *
 * Always wrapped in `.contai-app` so the toggle uses v3 styles regardless
 * of the current flag state — the user needs to find and flip it on the
 * very first time without v3 CSS being loaded for the rest of the screen.
 *
 * @param bool $is_enabled Whether UI v3 is currently active for the user.
 */
function contai_render_ui_v3_beta_panel( $is_enabled ) {
	?>
	<div class="contai-app" style="margin-top: 24px;">
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile">
						<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'UI v3 (Beta)', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc">
							<?php esc_html_e( 'Opt into the redesigned 1Platform admin UI. The flag is saved per-user and only affects your account.', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>
				<?php if ( $is_enabled ) : ?>
					<span class="contai-badge contai-badge-success">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Enabled', '1platform-content-ai' ); ?>
					</span>
				<?php else : ?>
					<span class="contai-badge contai-badge-neutral">
						<?php esc_html_e( 'Off', '1platform-content-ai' ); ?>
					</span>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="contai_save_ui_preferences">
				<?php wp_nonce_field( 'contai_save_ui_preferences', 'contai_ui_nonce' ); ?>

				<div class="contai-panel-body">
					<div class="contai-field">
						<div class="contai-field-head">
							<label class="contai-label" for="contai_ui_v3_toggle">
								<?php esc_html_e( 'Enable UI v3 for my account', '1platform-content-ai' ); ?>
							</label>
							<span class="contai-field-state"><?php esc_html_e( 'beta', '1platform-content-ai' ); ?></span>
						</div>
						<label class="contai-toggle <?php echo $is_enabled ? 'is-on' : ''; ?>">
							<input type="checkbox" id="contai_ui_v3_toggle" name="contai_ui_v3" value="1" <?php checked( $is_enabled ); ?>>
							<span class="contai-switch" aria-hidden="true"></span>
							<span><?php echo $is_enabled ? esc_html__( 'On', '1platform-content-ai' ) : esc_html__( 'Off', '1platform-content-ai' ); ?></span>
						</label>
						<p class="contai-field-help">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'Migrated screens will use the new design. Any screen not yet migrated continues to render with the current UI.', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>

				<div class="contai-panel-foot">
					<div class="contai-panel-foot-meta">
						<?php esc_html_e( 'Preference scope: current user', '1platform-content-ai' ); ?>
					</div>
					<div class="contai-panel-foot-actions">
						<button type="submit" class="contai-btn contai-btn-primary">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Save preference', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
			</form>
		</div>
	</div>
	<?php
}
