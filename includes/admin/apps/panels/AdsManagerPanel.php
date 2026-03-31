<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiAdsManagerPanel {

	public function __construct() {
		$this->handleFormSubmission();
	}

	private function handleFormSubmission(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified in each handler method.
		if ( isset( $_POST['adsense_injector'] ) ) {
			$this->handleSaveForm();
		} elseif ( isset( $_POST['delete_adsense_approval'] ) ) {
			$this->handleResetApproval();
		} elseif ( isset( $_POST['delete_ads_txt_and_reset_publishers'] ) ) {
			$this->handleDeleteAdsTxt();
		}
        // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handleSaveForm(): void {
		if ( ! check_admin_referer( 'contai_adsense_injector_save', 'contai_settings_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$form_type = sanitize_text_field( wp_unslash( $_POST['form_type'] ?? '' ) );

		if ( $form_type === 'publishers' ) {
			update_option( 'contai_adsense_publishers', sanitize_textarea_field( wp_unslash( $_POST['contai_adsense_publishers'] ?? '' ) ) );
			contai_generate_adsense_ads();
			add_settings_error(
				'ads_manager',
				'ads_manager_success',
				__( 'AdSense settings saved successfully.', '1platform-content-ai' ),
				'success'
			);
		} elseif ( $form_type === 'custom_head' ) {
			$allowed_tags = array_merge(
				wp_kses_allowed_html( 'post' ),
				array(
					'meta'   => array(
						'name' => true,
						'content' => true,
						'charset' => true,
						'http-equiv' => true,
						'property' => true,
					),
					'link'   => array(
						'rel' => true,
						'href' => true,
						'type' => true,
						'media' => true,
					),
					'style'  => array(
						'type' => true,
						'media' => true,
					),
					'script' => array(
						'src' => true,
						'async' => true,
						'defer' => true,
						'type' => true,
						'crossorigin' => true,
					),
				)
			);
			$custom_head = isset( $_POST['contai_custom_head'] ) ? wp_kses( wp_unslash( $_POST['contai_custom_head'] ), $allowed_tags ) : '';
			update_option( 'contai_custom_head', $custom_head );
			add_settings_error(
				'ads_manager',
				'ads_manager_success',
				__( 'Custom header code saved successfully.', '1platform-content-ai' ),
				'success'
			);
		}
	}

	private function handleResetApproval(): void {
		if ( ! check_admin_referer( 'contai_adsense_injector_delete', 'contai_delete_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( 'contai_adsense_approved' );
		add_settings_error(
			'ads_manager',
			'ads_manager_success',
			__( 'AdSense approval status has been reset.', '1platform-content-ai' ),
			'success'
		);
	}

	private function handleDeleteAdsTxt(): void {
		if ( ! check_admin_referer( 'contai_adsense_delete_ads_txt', 'contai_ads_txt_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ads_txt_path = ABSPATH . 'ads.txt';
		$deleted = false;

		if ( file_exists( $ads_txt_path ) ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$deleted = $wp_filesystem->delete( $ads_txt_path );
		} else {
			$deleted = true;
		}

		update_option( 'contai_adsense_publishers', '' );

		if ( $deleted ) {
			add_settings_error(
				'ads_manager',
				'ads_manager_success',
				__( 'ads.txt deleted and publisher list reset.', '1platform-content-ai' ),
				'success'
			);
		} else {
			add_settings_error(
				'ads_manager',
				'ads_manager_error',
				__( 'Failed to delete ads.txt. Check file permissions.', '1platform-content-ai' ),
				'error'
			);
		}
	}

	public function render(): void {
		settings_errors( 'ads_manager' );
		?>
		<div class="contai-ads-manager">
			<!-- Tab Navigation -->
			<nav class="contai-ads-tabs-nav" role="tablist">
				<button type="button" class="contai-ads-tab active" data-tab="adsense-account" role="tab" aria-selected="true">
					<span class="dashicons dashicons-chart-area"></span>
					<span class="contai-ads-tab-label"><?php esc_html_e( 'AdSense Account', '1platform-content-ai' ); ?></span>
				</button>
				<button type="button" class="contai-ads-tab" data-tab="publishers" role="tab" aria-selected="false">
					<span class="dashicons dashicons-media-text"></span>
					<span class="contai-ads-tab-label"><?php esc_html_e( 'Publisher List', '1platform-content-ai' ); ?></span>
				</button>
				<button type="button" class="contai-ads-tab" data-tab="custom-head" role="tab" aria-selected="false">
					<span class="dashicons dashicons-editor-code"></span>
					<span class="contai-ads-tab-label"><?php esc_html_e( 'Custom Header', '1platform-content-ai' ); ?></span>
				</button>
				<button type="button" class="contai-ads-tab" data-tab="advanced" role="tab" aria-selected="false">
					<span class="dashicons dashicons-admin-generic"></span>
					<span class="contai-ads-tab-label"><?php esc_html_e( 'Advanced', '1platform-content-ai' ); ?></span>
				</button>
			</nav>

			<?php $this->renderAdSenseAccountTab(); ?>
			<?php $this->renderPublishersTab(); ?>
			<?php $this->renderCustomHeaderTab(); ?>
			<?php $this->renderAdvancedTab(); ?>
		</div>
		<?php
	}

	private function renderPublishersTab(): void {
		$publishers = get_option( 'contai_adsense_publishers', '' );
		$publisher_count = 0;
		if ( ! empty( trim( $publishers ) ) ) {
			$publisher_count = count( array_filter( array_map( 'trim', explode( "\n", $publishers ) ) ) );
		}
		$ads_txt_exists = file_exists( ABSPATH . 'ads.txt' );
		?>
		<div class="contai-ads-tab-content" id="tab-publishers" role="tabpanel">
			<form method="post">
				<?php wp_nonce_field( 'contai_adsense_injector_save', 'contai_settings_nonce' ); ?>
				<input type="hidden" name="form_type" value="publishers">

				<!-- Status Bar -->
				<?php if ( $publisher_count > 0 ) : ?>
				<div class="contai-ads-status-bar">
					<div class="contai-ads-status-item">
						<span class="contai-ads-status-dot contai-ads-status-active"></span>
						<?php
						printf(
							/* translators: %d: number of configured publishers */
							esc_html( _n( '%d publisher configured', '%d publishers configured', $publisher_count, '1platform-content-ai' ) ),
							intval( $publisher_count )
						);
						?>
					</div>
					<div class="contai-ads-status-item">
						<?php if ( $ads_txt_exists ) : ?>
							<span class="contai-ads-status-dot contai-ads-status-active"></span>
							<?php esc_html_e( 'ads.txt active', '1platform-content-ai' ); ?>
						<?php else : ?>
							<span class="contai-ads-status-dot contai-ads-status-inactive"></span>
							<?php esc_html_e( 'ads.txt not found', '1platform-content-ai' ); ?>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<div class="contai-ads-form-body">
					<div class="contai-ads-field">
						<label for="contai_adsense_publishers" class="contai-ads-field-label">
							<?php esc_html_e( 'AdSense Publisher IDs', '1platform-content-ai' ); ?>
						</label>
						<textarea id="contai_adsense_publishers" name="contai_adsense_publishers"
								  class="contai-ads-textarea"
								  rows="6"
								  placeholder="pub-1234567890123456&#10;pub-9876543210987654"><?php echo esc_textarea( $publishers ); ?></textarea>
						<p class="contai-ads-field-hint">
							<?php esc_html_e( 'One publisher ID per line. Format: pub-XXXXXXXXXXXXXXX (without the ca- prefix).', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>

				<div class="contai-ads-callout contai-ads-callout-info">
					<span class="dashicons dashicons-admin-site-alt3"></span>
					<div>
						<strong><?php esc_html_e( 'Automatic ads.txt', '1platform-content-ai' ); ?></strong>
						<p><?php esc_html_e( 'Your ads.txt file will be automatically generated in the root directory of your WordPress installation when you save.', '1platform-content-ai' ); ?></p>
					</div>
				</div>

				<div class="contai-ads-form-footer">
					<button type="submit" name="adsense_injector" class="button button-primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save Publisher List', '1platform-content-ai' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	private function renderCustomHeaderTab(): void {
		?>
		<div class="contai-ads-tab-content" id="tab-custom-head" role="tabpanel">
			<form method="post">
				<?php wp_nonce_field( 'contai_adsense_injector_save', 'contai_settings_nonce' ); ?>
				<input type="hidden" name="form_type" value="custom_head">

				<div class="contai-ads-form-body">
					<div class="contai-ads-field">
						<label for="contai_custom_head" class="contai-ads-field-label">
							<?php esc_html_e( 'Header Code', '1platform-content-ai' ); ?>
						</label>
						<textarea id="contai_custom_head" name="contai_custom_head"
								  class="contai-ads-textarea contai-ads-textarea-code"
								  rows="10"
								  placeholder="<!-- Paste your verification code, analytics script, or custom meta tags here -->"><?php echo esc_textarea( get_option( 'contai_custom_head' ) ); ?></textarea>
						<p class="contai-ads-field-hint">
							<?php esc_html_e( 'This code will be injected before the closing </head> tag on every page of your site.', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>

				<div class="contai-ads-callout contai-ads-callout-warning">
					<span class="dashicons dashicons-shield"></span>
					<div>
						<strong><?php esc_html_e( 'Use with caution', '1platform-content-ai' ); ?></strong>
						<p><?php esc_html_e( 'This injects raw HTML/JavaScript into your site. Malformed code could break your pages. Common uses: site verification codes, analytics scripts, and custom meta tags.', '1platform-content-ai' ); ?></p>
					</div>
				</div>

				<div class="contai-ads-form-footer">
					<button type="submit" name="adsense_injector" class="button button-primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save Header Code', '1platform-content-ai' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	private function renderAdvancedTab(): void {
		$is_approved = get_option( 'contai_adsense_approved', null );
		$ads_txt_exists = file_exists( ABSPATH . 'ads.txt' );
		?>
		<div class="contai-ads-tab-content" id="tab-advanced" role="tabpanel">
			<div class="contai-ads-advanced-grid">
				<!-- Reset Approval -->
				<div class="contai-ads-action-card">
					<div class="contai-ads-action-icon">
						<span class="dashicons dashicons-update"></span>
					</div>
					<div class="contai-ads-action-body">
						<h3><?php esc_html_e( 'Reset Approval Status', '1platform-content-ai' ); ?></h3>
						<p><?php esc_html_e( 'Clear the AdSense approval detection status to re-run the approval detection process.', '1platform-content-ai' ); ?></p>
						<div class="contai-ads-action-meta">
							<?php if ( $is_approved !== null ) : ?>
								<span class="contai-ads-status-dot contai-ads-status-active"></span>
								<?php esc_html_e( 'Currently approved', '1platform-content-ai' ); ?>
							<?php else : ?>
								<span class="contai-ads-status-dot contai-ads-status-inactive"></span>
								<?php esc_html_e( 'Not yet detected', '1platform-content-ai' ); ?>
							<?php endif; ?>
						</div>
					</div>
					<div class="contai-ads-action-footer">
						<form method="post">
							<?php wp_nonce_field( 'contai_adsense_injector_delete', 'contai_delete_nonce' ); ?>
							<button type="submit" name="delete_adsense_approval" class="button button-secondary">
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Reset Status', '1platform-content-ai' ); ?>
							</button>
						</form>
					</div>
				</div>

				<!-- Delete ads.txt -->
				<div class="contai-ads-action-card contai-ads-action-card-danger">
					<div class="contai-ads-action-icon contai-ads-action-icon-danger">
						<span class="dashicons dashicons-trash"></span>
					</div>
					<div class="contai-ads-action-body">
						<h3><?php esc_html_e( 'Delete ads.txt & Reset', '1platform-content-ai' ); ?></h3>
						<p><?php esc_html_e( 'Permanently remove the ads.txt file and clear all publisher IDs. This cannot be undone.', '1platform-content-ai' ); ?></p>
						<div class="contai-ads-action-meta">
							<?php if ( $ads_txt_exists ) : ?>
								<span class="contai-ads-status-dot contai-ads-status-active"></span>
								<?php esc_html_e( 'ads.txt exists', '1platform-content-ai' ); ?>
							<?php else : ?>
								<span class="contai-ads-status-dot contai-ads-status-inactive"></span>
								<?php esc_html_e( 'No ads.txt file', '1platform-content-ai' ); ?>
							<?php endif; ?>
						</div>
					</div>
					<div class="contai-ads-action-footer">
						<form method="post">
							<?php wp_nonce_field( 'contai_adsense_delete_ads_txt', 'contai_ads_txt_nonce' ); ?>
							<button type="submit" name="delete_ads_txt_and_reset_publishers"
									class="button button-danger"
									onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This will permanently delete ads.txt and reset all publisher IDs.', '1platform-content-ai' ) ); ?>')">
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Delete & Reset', '1platform-content-ai' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderAdSenseAccountTab(): void {
		$is_connected = get_option( 'contai_adsense_connected', false );
		?>
		<div class="contai-ads-tab-content active" id="tab-adsense-account" role="tabpanel">
			<div id="contai-adsense-account-root">
				<?php if ( ! $is_connected ) : ?>
				<div class="contai-adsense-empty">
					<div class="contai-adsense-empty__icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<h3><?php esc_html_e( 'Connect Your AdSense Account', '1platform-content-ai' ); ?></h3>
					<p><?php esc_html_e( 'Connect via OAuth to see earnings, site approval status, and policy alerts directly in your WordPress dashboard.', '1platform-content-ai' ); ?></p>

					<div class="contai-adsense-steps">
						<div class="contai-adsense-step active">
							<span class="contai-adsense-step__num">1</span>
							<div>
								<h4><?php esc_html_e( 'Authorize with Google', '1platform-content-ai' ); ?></h4>
								<p><?php esc_html_e( 'Sign in with your Google account that has access to AdSense', '1platform-content-ai' ); ?></p>
							</div>
						</div>
						<div class="contai-adsense-step">
							<span class="contai-adsense-step__num">2</span>
							<div>
								<h4><?php esc_html_e( 'Select AdSense Account', '1platform-content-ai' ); ?></h4>
								<p><?php esc_html_e( 'Choose which AdSense account to connect to this site', '1platform-content-ai' ); ?></p>
							</div>
						</div>
						<div class="contai-adsense-step">
							<span class="contai-adsense-step__num">3</span>
							<div>
								<h4><?php esc_html_e( 'Auto-Configure', '1platform-content-ai' ); ?></h4>
								<p><?php esc_html_e( 'Earnings, approval status, and policy alerts set up automatically', '1platform-content-ai' ); ?></p>
							</div>
						</div>
					</div>

					<button type="button" id="contai-adsense-connect-btn" class="contai-btn-oauth">
						<span class="dashicons dashicons-admin-links"></span>
						<?php esc_html_e( 'Connect AdSense', '1platform-content-ai' ); ?>
					</button>
				</div>
				<?php else : ?>
				<div class="contai-adsense-connected">
					<div class="contai-adsense-header">
						<div class="contai-adsense-header__left">
							<span class="contai-adsense-badge">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', '1platform-content-ai' ); ?>
							</span>
							<span class="contai-adsense-mid" id="contai-adsense-publisher-id">&mdash;</span>
						</div>
					</div>
					<div id="contai-adsense-status-loading" class="contai-adsense-loading">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Loading AdSense data...', '1platform-content-ai' ); ?>
					</div>
					<div id="contai-adsense-status-data" style="display:none;">
						<div class="contai-adsense-features">
							<div class="contai-adsense-feature">
								<div class="contai-adsense-feature__icon contai-adsense-feature__icon--blue"><span class="dashicons dashicons-businessperson"></span></div>
								<div>
									<h4><?php esc_html_e( 'Account', '1platform-content-ai' ); ?></h4>
									<p id="contai-adsense-account-name">&mdash;</p>
								</div>
							</div>
							<div class="contai-adsense-feature">
								<div class="contai-adsense-feature__icon contai-adsense-feature__icon--green"><span class="dashicons dashicons-admin-site-alt3"></span></div>
								<div>
									<h4><?php esc_html_e( 'Site State', '1platform-content-ai' ); ?></h4>
									<p id="contai-adsense-site-state">&mdash;</p>
								</div>
							</div>
							<div class="contai-adsense-feature">
								<div class="contai-adsense-feature__icon contai-adsense-feature__icon--amber"><span class="dashicons dashicons-flag"></span></div>
								<div>
									<h4><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></h4>
									<p id="contai-adsense-connection-status">&mdash;</p>
								</div>
							</div>
						</div>
						<div class="contai-adsense-earnings-summary" id="contai-adsense-earnings" style="display:none;">
							<h4><?php esc_html_e( 'Earnings (Last 7 Days)', '1platform-content-ai' ); ?></h4>
							<div class="contai-adsense-features">
								<div class="contai-adsense-feature">
									<div class="contai-adsense-feature__icon contai-adsense-feature__icon--green"><span class="dashicons dashicons-money-alt"></span></div>
									<div>
										<h4><?php esc_html_e( 'Earnings', '1platform-content-ai' ); ?></h4>
										<p class="contai-adsense-earnings-val" id="contai-adsense-est-earnings">$0.00</p>
									</div>
								</div>
								<div class="contai-adsense-feature">
									<div class="contai-adsense-feature__icon contai-adsense-feature__icon--blue"><span class="dashicons dashicons-pressthis"></span></div>
									<div>
										<h4><?php esc_html_e( 'Clicks', '1platform-content-ai' ); ?></h4>
										<p id="contai-adsense-clicks">0</p>
									</div>
								</div>
								<div class="contai-adsense-feature">
									<div class="contai-adsense-feature__icon contai-adsense-feature__icon--purple"><span class="dashicons dashicons-visibility"></span></div>
									<div>
										<h4><?php esc_html_e( 'Impressions', '1platform-content-ai' ); ?></h4>
										<p id="contai-adsense-impressions">0</p>
									</div>
								</div>
								<div class="contai-adsense-feature">
									<div class="contai-adsense-feature__icon contai-adsense-feature__icon--amber"><span class="dashicons dashicons-chart-line"></span></div>
									<div>
										<h4><?php esc_html_e( 'RPM', '1platform-content-ai' ); ?></h4>
										<p id="contai-adsense-rpm">$0.00</p>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="contai-adsense-disconnect">
						<button type="button" id="contai-adsense-refresh-btn" class="button">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Refresh', '1platform-content-ai' ); ?>
						</button>
						<button type="button" id="contai-adsense-disconnect-btn" class="contai-btn-disconnect">
							<?php esc_html_e( 'Disconnect AdSense', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
