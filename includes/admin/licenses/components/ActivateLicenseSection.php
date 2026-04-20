<?php
/**
 * Activate license section (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiActivateLicenseSection {

	private string $nonceAction;
	private string $nonceField;

	public function __construct( string $nonceAction, string $nonceField ) {
		$this->nonceAction = $nonceAction;
		$this->nonceField  = $nonceField;
	}

	public function render(): void {
		?>
		<div class="contai-panel contai-activate-license">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-superhero-alt"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Content AI License', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Activate your Content AI license to unlock premium features.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<form method="post">
				<?php wp_nonce_field( $this->nonceAction, $this->nonceField ); ?>

				<div class="contai-panel-body">
					<div class="contai-notice contai-notice-info">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<p>
							<?php esc_html_e( 'Enter your Content AI API key to activate premium features including Search Console integration, advanced content generation, and more.', '1platform-content-ai' ); ?>
						</p>
					</div>

					<div class="contai-field" style="margin-top: 16px;">
						<div class="contai-field-head">
							<label for="contai-api-key" class="contai-label">
								<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
								<?php esc_html_e( 'API Key', '1platform-content-ai' ); ?>
							</label>
							<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
						</div>
						<input type="password"
							id="contai-api-key"
							name="contai_api_key"
							class="contai-input"
							placeholder="<?php esc_attr_e( 'Enter your Content AI API key', '1platform-content-ai' ); ?>"
							required>
					</div>
				</div>

				<div class="contai-panel-foot">
					<span class="contai-panel-foot-meta">
						<?php esc_html_e( 'You can find your API key in your Content AI dashboard.', '1platform-content-ai' ); ?>
					</span>
					<div class="contai-panel-foot-actions">
						<button type="submit" name="contai_activate_license" class="contai-btn contai-btn-primary">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Activate License', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}
}
