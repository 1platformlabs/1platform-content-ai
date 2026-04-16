<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../services/category-api/CategoryAPIService.php';
require_once __DIR__ . '/../../services/billing/CreditGuard.php';

function contai_render_full_site_generator_form() {
	// Fetch categories for the select field
	$category_service = new ContaiCategoryAPIService();
	$categories = $category_service->getActiveCategories();
	$saved_category = get_option( 'contai_site_category', '' );
	$site_domain    = wp_parse_url( home_url(), PHP_URL_HOST );
	$default_email  = 'info@' . preg_replace( '/^www\./', '', $site_domain );

	// Preserve form data on validation error (inline notice = POST was rejected)
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Values are only used for re-display, not processing.
	$has_post_data = ! empty( $GLOBALS['contai_site_gen_inline_notice'] ) && isset( $_POST['contai_start_site_generation'] );
	$post = static function ( $key, $default = '' ) use ( $has_post_data ) {
		if ( ! $has_post_data || ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	};

	// Check credit balance for UI feedback
	$creditGuard = new ContaiCreditGuard();
	$creditCheck = $creditGuard->validateCredits();

	?>
	<?php if ( ! $creditCheck['has_credits'] ) : ?>
		<div class="contai-info-box contai-info-box-warning" style="margin-bottom: 20px;">
			<div class="contai-info-box-icon">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="contai-info-box-content">
				<p><strong><?php esc_html_e( 'Insufficient Balance', '1platform-content-ai' ); ?></strong></p>
				<p>
					<?php
					printf(
						/* translators: %1$s: balance amount, %2$s: currency code */
						esc_html__( 'Your current balance is %1$s %2$s. You need credits to generate content.', '1platform-content-ai' ),
						esc_html( number_format( $creditCheck['balance'], 2 ) ),
						esc_html( $creditCheck['currency'] )
					);
					?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>" class="button button-primary" style="margin-top: 8px;">
					<span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
				</a>
			</div>
		</div>
	<?php else : ?>
		<div class="contai-info-box contai-info-box-success" style="margin-bottom: 20px;">
			<div class="contai-info-box-icon">
				<span class="dashicons dashicons-money-alt"></span>
			</div>
			<div class="contai-info-box-content">
				<p>
					<?php
					printf(
						/* translators: %1$s: balance amount, %2$s: currency code */
						esc_html__( 'Available balance: %1$s %2$s', '1platform-content-ai' ),
						esc_html( number_format( $creditCheck['balance'], 2 ) ),
						esc_html( $creditCheck['currency'] )
					);
					?>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=contai-ai-site-generator' ) ); ?>" class="contai-site-generator-form">
		<?php wp_nonce_field( 'contai_site_generator_nonce', 'contai_site_generator_nonce' ); ?>
		<input type="hidden" name="contai_start_site_generation" value="1">
		<input type="hidden" id="contai_wordpress_theme" name="contai_wordpress_theme" value="astra">

		<!-- Step 1: Website Identity -->
		<div class="contai-wizard-section" data-step="1">
			<div class="contai-section-header">
				<div class="contai-step-indicator">
					<span class="contai-step-number">1</span>
					<span class="contai-step-line"></span>
				</div>
				<div class="contai-section-title-group">
					<h2 class="contai-section-title">
						<span class="dashicons dashicons-admin-site-alt3 contai-section-icon"></span>
						<?php esc_html_e( 'Website Identity', '1platform-content-ai' ); ?>
					</h2>
					<p class="contai-section-description"><?php esc_html_e( 'Define what your website is about and who it targets.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
			<div class="contai-section-body">
				<div class="contai-form-grid contai-grid-2">
					<div class="contai-form-group contai-span-full">
						<label for="contai_site_topic" class="contai-label">
							<?php esc_html_e( 'Site Topic', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-edit"></span>
							<input type="text" id="contai_site_topic" name="contai_site_topic" class="contai-input" value="<?php echo esc_attr( $post( 'contai_site_topic' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g., Indoor gardening, Personal finance, Pet care...', '1platform-content-ai' ); ?>" autocomplete="off" required>
						</div>
						<span class="contai-help-text"><?php esc_html_e( 'The main subject of your website', '1platform-content-ai' ); ?></span>
					</div>

					<div class="contai-form-group">
						<label for="contai_site_category" class="contai-label">
							<?php esc_html_e( 'Category', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<select id="contai_site_category" name="contai_site_category" class="contai-select" autocomplete="off" required data-lang-select="#contai_site_language">
							<?php
								$post_category = $post( 'contai_site_category' );
								$selected_category = $post_category ?: $saved_category;
								?>
							<?php if ( empty( $categories ) ) : ?>
								<option value=""><?php esc_html_e( 'No categories available', '1platform-content-ai' ); ?></option>
							<?php else : ?>
								<option value=""><?php esc_html_e( 'Select a category', '1platform-content-ai' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<?php
									$category_id = esc_attr( $category['id'] ?? '' );
									$title_en = esc_html( $category['title']['en'] ?? 'Unnamed Category' );
									$title_es = esc_html( $category['title']['es'] ?? $title_en );
									$category_theme = esc_attr( $category['recommended_theme'] ?? 'astra' );
									$is_selected = ( $selected_category === $category_id );
									?>
									<option value="<?php echo esc_attr( $category_id ); ?>"<?php selected( $is_selected ); ?> data-title-en="<?php echo esc_attr( $title_en ); ?>" data-title-es="<?php echo esc_attr( $title_es ); ?>" data-theme="<?php echo esc_attr( $category_theme ); ?>">
										<?php echo esc_html( $title_en ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<span class="contai-help-text"><?php esc_html_e( 'Determines the theme and content style', '1platform-content-ai' ); ?></span>
					</div>

					<div class="contai-form-group">
						<label for="contai_site_language" class="contai-label">
							<?php esc_html_e( 'Language', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<?php $post_language = $post( 'contai_site_language', 'english' ); ?>
						<select id="contai_site_language" name="contai_site_language" class="contai-select" autocomplete="language" required data-category-select="#contai_site_category">
							<option value="english" <?php selected( $post_language, 'english' ); ?>><?php esc_html_e( 'English', '1platform-content-ai' ); ?></option>
							<option value="spanish" <?php selected( $post_language, 'spanish' ); ?>><?php esc_html_e( 'Spanish', '1platform-content-ai' ); ?></option>
						</select>
					</div>

					<div class="contai-form-group">
						<label for="contai_target_country" class="contai-label">
							<?php esc_html_e( 'Target Country', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<?php $post_country = $post( 'contai_target_country', 'us' ); ?>
						<select id="contai_target_country" name="contai_target_country" class="contai-select" autocomplete="country" required>
							<option value="us" <?php selected( $post_country, 'us' ); ?>><?php esc_html_e( 'United States', '1platform-content-ai' ); ?></option>
							<option value="es" <?php selected( $post_country, 'es' ); ?>><?php esc_html_e( 'Spain', '1platform-content-ai' ); ?></option>
						</select>
					</div>

					<div class="contai-form-group">
						<label for="contai_adsense_publisher" class="contai-label">
							<?php esc_html_e( 'AdSense Publisher ID', '1platform-content-ai' ); ?>
							<span class="contai-optional">(<?php esc_html_e( 'optional', '1platform-content-ai' ); ?>)</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-money-alt"></span>
							<input type="text" id="contai_adsense_publisher" name="contai_adsense_publisher" class="contai-input" value="<?php echo esc_attr( $post( 'contai_adsense_publisher' ) ); ?>" placeholder="pub-1234567890123456" autocomplete="off" spellcheck="false" pattern="pub-\d{10,20}">
						</div>
						<span class="contai-help-text"><?php esc_html_e( 'Account > Account information in AdSense. Leave empty if you do not have an AdSense account yet.', '1platform-content-ai' ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 2: Legal Information -->
		<div class="contai-wizard-section" data-step="2">
			<div class="contai-section-header">
				<div class="contai-step-indicator">
					<span class="contai-step-number">2</span>
					<span class="contai-step-line"></span>
				</div>
				<div class="contai-section-title-group">
					<h2 class="contai-section-title">
						<span class="dashicons dashicons-shield contai-section-icon"></span>
						<?php esc_html_e( 'Legal Information', '1platform-content-ai' ); ?>
					</h2>
					<p class="contai-section-description"><?php esc_html_e( 'Used to generate privacy policy, terms of service, and cookie consent.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
			<div class="contai-section-body">
				<div class="contai-form-grid contai-grid-2">
					<div class="contai-form-group">
						<label for="contai_legal_owner" class="contai-label">
							<?php esc_html_e( 'Business Owner', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-businessperson"></span>
							<input type="text" id="contai_legal_owner" name="contai_legal_owner" class="contai-input" value="<?php echo esc_attr( $post( 'contai_legal_owner' ) ); ?>" placeholder="<?php esc_attr_e( 'John Doe', '1platform-content-ai' ); ?>" autocomplete="name" required>
						</div>
					</div>

					<div class="contai-form-group">
						<label for="contai_legal_email" class="contai-label">
							<?php esc_html_e( 'Contact Email', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-email"></span>
							<input type="email" id="contai_legal_email" name="contai_legal_email" class="contai-input" value="<?php echo esc_attr( $post( 'contai_legal_email', $default_email ) ); ?>" placeholder="<?php esc_attr_e( 'info@domain.com', '1platform-content-ai' ); ?>" autocomplete="email" spellcheck="false" required>
						</div>
					</div>

					<div class="contai-form-group">
						<label for="contai_legal_activity" class="contai-label">
							<?php esc_html_e( 'Business Activity', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-building"></span>
							<input type="text" id="contai_legal_activity" name="contai_legal_activity" class="contai-input" value="<?php echo esc_attr( $post( 'contai_legal_activity' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g., Digital publishing', '1platform-content-ai' ); ?>" autocomplete="organization-title" required>
						</div>
					</div>

					<div class="contai-form-group">
						<label for="contai_legal_address" class="contai-label">
							<?php esc_html_e( 'Business Address', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-location"></span>
							<input type="text" id="contai_legal_address" name="contai_legal_address" class="contai-input" value="<?php echo esc_attr( $post( 'contai_legal_address' ) ); ?>" placeholder="<?php esc_attr_e( '123 Main St, City, Country', '1platform-content-ai' ); ?>" autocomplete="street-address" required>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 3: Content Generation Settings -->
		<div class="contai-wizard-section" data-step="3">
			<div class="contai-section-header">
				<div class="contai-step-indicator">
					<span class="contai-step-number">3</span>
				</div>
				<div class="contai-section-title-group">
					<h2 class="contai-section-title">
						<span class="dashicons dashicons-admin-post contai-section-icon"></span>
						<?php esc_html_e( 'Content Generation', '1platform-content-ai' ); ?>
					</h2>
					<p class="contai-section-description"><?php esc_html_e( 'Configure how AI generates your website content, keywords, and images.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
			<div class="contai-section-body">
				<div class="contai-form-grid contai-grid-2">
					<div class="contai-form-group contai-span-full">
						<label for="contai_source_topic" class="contai-label">
							<?php esc_html_e( 'Keyword Topic', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<div class="contai-input-wrap contai-input-icon">
							<span class="dashicons dashicons-search"></span>
							<input type="text" id="contai_source_topic" name="contai_source_topic" class="contai-input" value="<?php echo esc_attr( $post( 'contai_source_topic' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g., indoor plants care, home workout routines...', '1platform-content-ai' ); ?>" autocomplete="off" spellcheck="false" required>
						</div>
						<span class="contai-help-text"><?php esc_html_e( 'We\'ll research this topic to extract relevant keywords for your content.', '1platform-content-ai' ); ?></span>
					</div>

					<div class="contai-form-group">
						<label for="contai_num_posts" class="contai-label">
							<?php esc_html_e( 'Number of Posts', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<input type="number" id="contai_num_posts" name="contai_num_posts" class="contai-input" value="<?php echo esc_attr( $post( 'contai_num_posts', '100' ) ); ?>" min="1" max="1000" autocomplete="off" required>
					</div>

					<div class="contai-form-group">
						<label for="contai_comments_per_post" class="contai-label">
							<?php esc_html_e( 'Comments per Post', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<input type="number" id="contai_comments_per_post" name="contai_comments_per_post" class="contai-input" value="<?php echo esc_attr( $post( 'contai_comments_per_post', '1' ) ); ?>" min="1" max="10" autocomplete="off" required>
					</div>

					<div class="contai-form-group">
						<label for="contai_image_provider" class="contai-label">
							<?php esc_html_e( 'Image Source', '1platform-content-ai' ); ?>
							<span class="contai-required">*</span>
						</label>
						<?php $post_image = $post( 'contai_image_provider', 'pexels' ); ?>
						<select id="contai_image_provider" name="contai_image_provider" class="contai-select" autocomplete="off" required>
							<option value="pexels" <?php selected( $post_image, 'pexels' ); ?>><?php esc_html_e( 'Stock Photos (Free)', '1platform-content-ai' ); ?></option>
							<option value="pixabay" <?php selected( $post_image, 'pixabay' ); ?>><?php esc_html_e( 'Stock Images (Free)', '1platform-content-ai' ); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>

		<!-- Submit Section -->
		<div class="contai-wizard-section contai-wizard-submit">
			<div class="contai-section-body">
				<div class="contai-launch-card">
					<div class="contai-launch-info">
						<div class="contai-launch-icon">
							<span class="dashicons dashicons-clock"></span>
						</div>
						<div class="contai-launch-text">
							<strong><?php esc_html_e( 'Background Process', '1platform-content-ai' ); ?></strong>
							<p><?php esc_html_e( 'Generation runs in the background. You can safely close this page and check progress later.', '1platform-content-ai' ); ?></p>
						</div>
					</div>
					<div class="contai-submit-area">
						<button type="submit" id="contai_submit_btn" class="contai-btn contai-btn-primary contai-btn-lg" <?php echo ! $creditCheck['has_credits'] ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-controls-play"></span>
							<?php esc_html_e( 'Launch Site Generation', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	</form>

	<?php
	$js_dir = plugin_dir_path( dirname( __DIR__ ) ) . 'admin/assets/js/';
	$js_url = plugin_dir_url( dirname( __DIR__ ) ) . 'admin/assets/js/';

	wp_enqueue_script(
		'tai-category-sync',
		$js_url . 'tai-category-sync.js',
		array(),
		filemtime( $js_dir . 'tai-category-sync.js' ),
		true
	);

	wp_enqueue_script(
		'tai-site-generator-form',
		$js_url . 'tai-site-generator-form.js',
		array(),
		filemtime( $js_dir . 'tai-site-generator-form.js' ),
		true
	);

	wp_localize_script(
		'tai-site-generator-form',
		'contaiSiteGenI18n',
		array(
			'unsavedWarning' => esc_html__( 'You have unsaved changes. Are you sure you want to leave?', '1platform-content-ai' ),
			'starting'       => esc_html__( 'Starting...', '1platform-content-ai' ),
		)
	);
	?>
	<?php
}
