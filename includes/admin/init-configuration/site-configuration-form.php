<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../services/category-api/CategoryAPIService.php';

function contai_render_site_configuration_form() {
	$site_topic    = esc_attr( get_option( 'contai_site_theme', 'blog' ) );
	$site_language = esc_attr( get_option( 'contai_site_language', 'spanish' ) );
	$languages     = array( 'english', 'spanish' );

	$category_service = new ContaiCategoryAPIService();
	$categories       = $category_service->getActiveCategories();
	$saved_category   = get_option( 'contai_site_category', '' );
	?>
	<div class="contai-panel" id="contai-site-config-section">
		<div class="contai-panel-head">
			<div class="contai-panel-head-main">
				<div class="contai-tile" aria-hidden="true">
					<span class="dashicons dashicons-admin-site-alt3"></span>
				</div>
				<div>
					<h2 class="contai-panel-title"><?php esc_html_e( 'Site Configuration', '1platform-content-ai' ); ?></h2>
					<p class="contai-panel-desc"><?php esc_html_e( 'Configure your website settings and preferences.', '1platform-content-ai' ); ?></p>
				</div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="contai_save_site_configuration">
			<?php wp_nonce_field( 'contai_save_site_configuration', 'contai_nonce' ); ?>

			<div class="contai-panel-body">
				<div class="contai-form-grid">
					<div class="contai-field">
						<div class="contai-field-head">
							<label for="contai_site_topic" class="contai-label">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Site Topic', '1platform-content-ai' ); ?>
							</label>
						</div>
						<input type="text" id="contai_site_topic" name="contai_site_topic"
							value="<?php echo esc_attr( $site_topic ); ?>"
							class="contai-input"
							placeholder="<?php esc_attr_e( 'e.g., technology, health, travel', '1platform-content-ai' ); ?>">
						<p class="contai-field-help">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'Main topic or niche of your website.', '1platform-content-ai' ); ?>
						</p>
					</div>

					<div class="contai-field">
						<div class="contai-field-head">
							<label for="contai_site_language" class="contai-label">
								<span class="dashicons dashicons-translation" aria-hidden="true"></span>
								<?php esc_html_e( 'Site Language', '1platform-content-ai' ); ?>
							</label>
						</div>
						<select id="contai_site_language" name="contai_site_language" class="contai-select" data-category-select="#contai_site_category">
							<?php foreach ( $languages as $lang ) : ?>
								<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $site_language, $lang ); ?>>
									<?php echo esc_html( ucfirst( $lang ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="contai-field-help">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'Language used when generating legal pages.', '1platform-content-ai' ); ?>
						</p>
					</div>

					<div class="contai-field">
						<div class="contai-field-head">
							<label for="contai_site_category" class="contai-label">
								<span class="dashicons dashicons-category" aria-hidden="true"></span>
								<?php esc_html_e( 'Category', '1platform-content-ai' ); ?>
							</label>
						</div>
						<select id="contai_site_category" name="contai_site_category" class="contai-select" data-lang-select="#contai_site_language">
							<?php if ( empty( $categories ) ) : ?>
								<option value=""><?php esc_html_e( 'No categories available', '1platform-content-ai' ); ?></option>
							<?php else : ?>
								<option value=""><?php esc_html_e( 'Select a category', '1platform-content-ai' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<?php
									$category_id    = esc_attr( $category['id'] ?? '' );
									$title_en       = esc_html( $category['title']['en'] ?? 'Unnamed Category' );
									$title_es       = esc_html( $category['title']['es'] ?? $title_en );
									$category_theme = esc_attr( $category['recommended_theme'] ?? 'astra' );
									$is_selected    = ( $saved_category === $category_id );
									?>
									<option value="<?php echo esc_attr( $category_id ); ?>"<?php selected( $is_selected ); ?> data-title-en="<?php echo esc_attr( $title_en ); ?>" data-title-es="<?php echo esc_attr( $title_es ); ?>" data-theme="<?php echo esc_attr( $category_theme ); ?>">
										<?php
										$current_lang = ContaiCategoryAPIService::normalizeLanguage( $site_language );
										echo esc_html( ContaiCategoryAPIService::getCategoryTitle( $category, $current_lang ) );
										?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="contai-field-help">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'Select the category for your website content.', '1platform-content-ai' ); ?>
						</p>
					</div>

					<div class="contai-field">
						<div class="contai-field-head">
							<label for="contai_wordpress_theme_display" class="contai-label">
								<span class="dashicons dashicons-art" aria-hidden="true"></span>
								<?php esc_html_e( 'WordPress Theme', '1platform-content-ai' ); ?>
							</label>
							<span class="contai-field-state"><?php esc_html_e( 'auto', '1platform-content-ai' ); ?></span>
						</div>
						<input type="text" id="contai_wordpress_theme_display" class="contai-input" readonly
							value="<?php echo esc_attr( ucfirst( get_option( 'contai_wordpress_theme', 'astra' ) ) ); ?>">
						<input type="hidden" id="contai_wordpress_theme" name="contai_wordpress_theme"
							value="<?php echo esc_attr( get_option( 'contai_wordpress_theme', 'astra' ) ); ?>">
						<p class="contai-field-help">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'Automatically assigned based on the selected category.', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>

				<?php
				wp_enqueue_script(
					'tai-category-sync',
					plugin_dir_url( dirname( __DIR__ ) ) . 'admin/assets/js/tai-category-sync.js',
					array(),
					filemtime( plugin_dir_path( dirname( __DIR__ ) ) . 'admin/assets/js/tai-category-sync.js' ),
					true
				);
				?>

				<div class="contai-notice contai-notice-info" role="status" style="margin-top: 16px;">
					<span class="dashicons dashicons-info" aria-hidden="true"></span>
					<p>
						<strong><?php esc_html_e( 'Important.', '1platform-content-ai' ); ?></strong>
						<?php esc_html_e( 'Make sure to enable WordPress permalinks for this plugin to work correctly.', '1platform-content-ai' ); ?>
					</p>
					<div class="contai-notice-actions"></div>
				</div>
			</div>

			<div class="contai-panel-foot">
				<div class="contai-panel-foot-meta"></div>
				<div class="contai-panel-foot-actions">
					<button type="submit" name="contai_init_site_config" class="contai-btn contai-btn-primary">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Save Site Configuration', '1platform-content-ai' ); ?>
					</button>
				</div>
			</div>
		</form>
	</div>
	<?php
}
