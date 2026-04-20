<?php
/**
 * User profile section (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiUserProfileSection {

	private array $profile;
	private string $nonceAction;
	private string $nonceField;
	private ?array $websiteConfig;
	private bool $isConnected;

	public function __construct(
		array $profile,
		string $nonceAction,
		string $nonceField,
		?array $websiteConfig = null,
		bool $isConnected = true
	) {
		$this->profile       = $profile;
		$this->nonceAction   = $nonceAction;
		$this->nonceField    = $nonceField;
		$this->websiteConfig = $websiteConfig;
		$this->isConnected   = $isConnected;
	}

	public function render(): void {
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-superhero-alt"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Content AI License', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc">
							<?php
							echo esc_html(
								$this->isConnected
									? __( 'Your license is active and connected to Content AI.', '1platform-content-ai' )
									: __( 'Your license is active but the connection could not be verified.', '1platform-content-ai' )
							);
							?>
						</p>
					</div>
				</div>
			</div>

			<div class="contai-panel-body">
				<?php $this->renderLicenseStatus(); ?>
				<?php $this->renderUserInfo(); ?>
			</div>

			<?php $this->renderActions(); ?>
		</div>
		<?php
	}

	private function renderLicenseStatus(): void {
		$isActive = ( $this->profile['status'] ?? '' ) === 'active';

		?>
		<div class="contai-stat-grid" style="grid-template-columns: repeat(2, 1fr);">
			<div class="contai-stat">
				<div class="contai-stat-head">
					<span class="contai-stat-label"><?php esc_html_e( 'License Status', '1platform-content-ai' ); ?></span>
					<span class="contai-stat-icon" aria-hidden="true">
						<span class="dashicons dashicons-<?php echo esc_attr( $isActive ? 'yes-alt' : 'warning' ); ?>"></span>
					</span>
				</div>
				<div class="contai-stat-value">
					<span class="contai-badge <?php echo esc_attr( $isActive ? 'contai-badge-success' : 'contai-badge-danger' ); ?>">
						<?php echo esc_html( $isActive ? __( 'Active', '1platform-content-ai' ) : __( 'Inactive', '1platform-content-ai' ) ); ?>
					</span>
				</div>
			</div>
			<div class="contai-stat">
				<div class="contai-stat-head">
					<span class="contai-stat-label"><?php esc_html_e( 'API Connection', '1platform-content-ai' ); ?></span>
					<span class="contai-stat-icon" aria-hidden="true">
						<span class="dashicons dashicons-<?php echo esc_attr( $this->isConnected ? 'yes-alt' : 'warning' ); ?>"></span>
					</span>
				</div>
				<div class="contai-stat-value">
					<span class="contai-badge <?php echo esc_attr( $this->isConnected ? 'contai-badge-success' : 'contai-badge-warning' ); ?>">
						<?php echo esc_html( $this->isConnected ? __( 'Connected', '1platform-content-ai' ) : __( 'Disconnected', '1platform-content-ai' ) ); ?>
					</span>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderUserInfo(): void {
		?>
		<h3 class="contai-panel-title" style="font-size: 14px; margin: 24px 0 12px;">
			<?php esc_html_e( 'Account Information', '1platform-content-ai' ); ?>
		</h3>
		<div class="contai-form-grid">
			<div class="contai-field">
				<div class="contai-field-head">
					<span class="contai-label">
						<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'User ID', '1platform-content-ai' ); ?>
					</span>
				</div>
				<code class="contai-input contai-mono" style="background: var(--bg-2);">
					<?php echo esc_html( $this->profile['userId'] ?? '-' ); ?>
				</code>
			</div>
			<div class="contai-field">
				<div class="contai-field-head">
					<span class="contai-label">
						<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
						<?php esc_html_e( 'Username', '1platform-content-ai' ); ?>
					</span>
				</div>
				<p class="contai-input" style="margin: 0;">
					<?php echo esc_html( $this->profile['username'] ?? '-' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	private function renderActions(): void {
		$hasWebsite = $this->websiteConfig && ! empty( $this->websiteConfig['websiteId'] );
		?>
		<div class="contai-panel-foot">
			<form method="post" style="display: contents;">
				<?php wp_nonce_field( $this->nonceAction, $this->nonceField ); ?>
				<span class="contai-panel-foot-meta">
					<?php esc_html_e( 'Refresh profile or tokens if the connection is stale.', '1platform-content-ai' ); ?>
				</span>
				<div class="contai-panel-foot-actions">
					<button type="submit" name="contai_refresh_profile" class="contai-btn contai-btn-secondary">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'Refresh Profile', '1platform-content-ai' ); ?>
					</button>
					<button type="submit" name="contai_refresh_tokens" class="contai-btn contai-btn-secondary">
						<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
						<?php esc_html_e( 'Refresh Tokens', '1platform-content-ai' ); ?>
					</button>
				</div>
			</form>
		</div>

		<div class="contai-panel-body" style="border-top: 1px solid var(--border-1); background: var(--contai-error-bg);">
			<h4 class="contai-panel-title" style="font-size: 14px; color: var(--contai-error-text); margin: 0 0 8px; display: flex; align-items: center; gap: 6px;">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'Danger Zone', '1platform-content-ai' ); ?>
			</h4>
			<form method="post">
				<?php wp_nonce_field( $this->nonceAction, $this->nonceField ); ?>
				<p class="contai-field-help" style="margin-bottom: 12px;">
					<?php esc_html_e( 'Deactivating your license will disconnect this site from Content AI services. Your website data will be preserved on the server.', '1platform-content-ai' ); ?>
				</p>
				<button type="submit" name="contai_deactivate_license" class="contai-btn contai-btn-danger"
					onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate your license? This will disconnect your site from Content AI services.', '1platform-content-ai' ) ); ?>');">
					<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
					<?php esc_html_e( 'Deactivate License', '1platform-content-ai' ); ?>
				</button>

				<?php if ( $hasWebsite ) : ?>
					<hr style="margin: 16px 0; border: none; border-top: 1px solid var(--contai-error-border);">
					<p class="contai-field-help" style="margin-bottom: 12px;">
						<?php esc_html_e( 'Permanently delete this website from Content AI servers. This action cannot be undone.', '1platform-content-ai' ); ?>
					</p>
					<button type="submit" name="contai_delete_website" class="contai-btn contai-btn-danger"
						onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete this website? This action cannot be undone and will remove all website data from Content AI servers.', '1platform-content-ai' ) ); ?>');">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Delete Website', '1platform-content-ai' ); ?>
					</button>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}
