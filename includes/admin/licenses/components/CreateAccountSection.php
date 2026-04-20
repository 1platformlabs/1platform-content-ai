<?php
/**
 * Create Account section — self-service registration via payment (UI v3).
 *
 * Renders the registration form with email + amount selector.
 * JavaScript handles polling and auto-activation.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiCreateAccountSection {

	public static function render( ?string $pending_session_id = null, ?string $pending_payment_url = null ): void {
		?>
		<div class="contai-panel contai-create-account" id="contai-create-account">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-plus-alt2"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Create Your Account', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc">
							<?php esc_html_e( 'Create your account with an initial balance. Your API key will be generated automatically after payment.', '1platform-content-ai' ); ?>
						</p>
					</div>
				</div>
				<span class="contai-badge contai-badge-info"><?php esc_html_e( 'New', '1platform-content-ai' ); ?></span>
			</div>

			<div class="contai-panel-body">
				<?php if ( $pending_session_id ) : ?>
					<div class="contai-notice contai-notice-info" id="contai-onboarding-recovery"
						data-session-id="<?php echo esc_attr( $pending_session_id ); ?>"
						<?php if ( $pending_payment_url ) : ?>data-payment-url="<?php echo esc_url( $pending_payment_url ); ?>"<?php endif; ?>>
						<span class="contai-spinner" aria-hidden="true"></span>
						<p><?php esc_html_e( 'You have a pending registration. Checking status…', '1platform-content-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<div id="contai-create-account-form" <?php echo $pending_session_id ? 'style="display:none;"' : ''; ?>>
					<div class="contai-form-grid">
						<div class="contai-field contai-form-grid-full">
							<div class="contai-field-head">
								<label for="contai-onboarding-email" class="contai-label">
									<span class="dashicons dashicons-email" aria-hidden="true"></span>
									<?php esc_html_e( 'Email Address', '1platform-content-ai' ); ?>
								</label>
								<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
							</div>
							<input type="email"
								id="contai-onboarding-email"
								class="contai-input"
								placeholder="<?php esc_attr_e( 'your@email.com', '1platform-content-ai' ); ?>"
								required>
						</div>

						<div class="contai-field contai-form-grid-full">
							<div class="contai-field-head">
								<span class="contai-label">
									<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
									<?php esc_html_e( 'Initial Balance (USD)', '1platform-content-ai' ); ?>
								</span>
							</div>
							<div class="contai-amount-group">
								<button type="button" class="contai-amount-btn" data-amount="5">$5</button>
								<button type="button" class="contai-amount-btn active" data-amount="10">$10</button>
								<button type="button" class="contai-amount-btn" data-amount="25">$25</button>
							</div>
						</div>
					</div>

					<div class="contai-notice contai-notice-error" id="contai-onboarding-error" style="display:none; margin-top: 12px;">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p></p>
					</div>
				</div>

				<div class="contai-onboarding-status" id="contai-onboarding-status" style="display:none; text-align: center; padding: 20px;">
					<span class="contai-spinner" aria-hidden="true" style="margin: 0 auto 10px;"></span>
					<p id="contai-onboarding-status-text">
						<?php esc_html_e( 'Processing your payment…', '1platform-content-ai' ); ?>
					</p>
					<button type="button" id="contai-onboarding-cancel" class="contai-btn contai-btn-ghost contai-btn-sm" style="margin-top:12px;">
						<?php esc_html_e( 'Cancel and start over', '1platform-content-ai' ); ?>
					</button>
				</div>

				<div class="contai-notice contai-notice-success" id="contai-onboarding-success" style="display:none;">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Account created! Activating your license…', '1platform-content-ai' ); ?></p>
				</div>
			</div>

			<div class="contai-panel-foot">
				<span class="contai-panel-foot-meta">
					<?php esc_html_e( 'You can close this tab — we\'ll continue processing in the background.', '1platform-content-ai' ); ?>
				</span>
				<div class="contai-panel-foot-actions">
					<button type="button" id="contai-onboarding-submit" class="contai-btn contai-btn-primary contai-btn-lg">
						<?php esc_html_e( 'Create Account & Pay', '1platform-content-ai' ); ?>
					</button>
				</div>
			</div>
		</div>

		<p style="text-align: center; margin-top: 12px;">
			<a href="#" id="contai-toggle-existing-key" class="contai-btn contai-btn-ghost contai-btn-sm">
				<?php esc_html_e( 'Already have an API key? Click here', '1platform-content-ai' ); ?>
			</a>
		</p>
		<?php
	}
}
