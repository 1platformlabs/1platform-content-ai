<?php
/**
 * Billing setup notice (UI v3).
 *
 * Rendered when the user hasn't connected their 1Platform account yet.
 * Uses the v3 connection-gate pattern.
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContaiBillingSetupNotice {

	public static function render(): void {
		$licenses_url = admin_url( 'admin.php?page=contai-licenses' );
		?>
		<div class="contai-gate">
			<div class="contai-gate-lock" aria-hidden="true">
				<span class="dashicons dashicons-admin-network"></span>
			</div>
			<h2 class="contai-gate-title">
				<?php esc_html_e( 'Connect Your Account', '1platform-content-ai' ); ?>
			</h2>
			<p class="contai-gate-body">
				<?php esc_html_e( 'Link your Content AI account to start managing your billing, credits, and transaction history.', '1platform-content-ai' ); ?>
			</p>
			<ul class="contai-gate-checks">
				<li class="contai-gate-check">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'View and manage your credit balance in real time', '1platform-content-ai' ); ?>
				</li>
				<li class="contai-gate-check">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Top up credits to power AI content generation', '1platform-content-ai' ); ?>
				</li>
				<li class="contai-gate-check">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'Track every payment and credit usage with full details', '1platform-content-ai' ); ?>
				</li>
			</ul>
			<div class="contai-gate-actions">
				<a href="<?php echo esc_url( $licenses_url ); ?>" class="contai-btn contai-btn-primary">
					<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
					<?php esc_html_e( 'Configure API Keys', '1platform-content-ai' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
