<?php
/**
 * Billing overview panel (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../services/billing/BillingService.php';
require_once __DIR__ . '/../handlers/TopUpHandler.php';
require_once __DIR__ . '/../components/BillingSetupNotice.php';

class ContaiBillingOverviewPanel {

	private ContaiBillingService $service;

	public function __construct( ContaiBillingService $service ) {
		$this->service = $service;
	}

	public function render(): void {
		$this->enqueueAssets();
		$this->renderMessage();

		$userProfile = $this->service->getUserProfile();

		if ( ! $userProfile ) {
			$this->renderUserNotConfigured();
			return;
		}

		$response = $this->service->getBilling();

		if ( ! $response->isSuccess() ) {
			$this->renderError(
				ContaiNoticeHelper::buildErrorNotice(
					'Load billing',
					$response,
					__( 'Failed to load billing information.', '1platform-content-ai' )
				)
			);
			return;
		}

		$data           = $response->getData();
		$subscriptionId = $data['subscription']['id'] ?? '—';
		$balance        = $data['billing']['balance'] ?? 0;
		$currency       = $data['billing']['currency'] ?? 'USD';

		$this->renderBalanceCard( (float) $balance, $currency );
		$this->renderDetailsPanel( (string) $subscriptionId, $currency );
		$this->renderTopUpModal( $currency );
	}

	private function renderBalanceCard( float $balance, string $currency ): void {
		$history_url = admin_url( 'admin.php?page=contai-billing&section=billing-history' );
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-money-alt"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Available Balance', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Credits ready to use across your plugin features.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body">
				<div class="contai-stat-grid" style="grid-template-columns: 1fr;">
					<div class="contai-stat">
						<div class="contai-stat-head">
							<span class="contai-stat-label"><?php esc_html_e( 'Balance', '1platform-content-ai' ); ?></span>
							<span class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-money-alt"></span>
							</span>
						</div>
						<div class="contai-stat-value">
							<?php echo esc_html( number_format( $balance, 2 ) ); ?>
							<span class="contai-stat-unit"><?php echo esc_html( $currency ); ?></span>
						</div>
						<div class="contai-stat-foot">
							<?php esc_html_e( 'Use credits to generate content, keywords, images and more.', '1platform-content-ai' ); ?>
						</div>
					</div>
				</div>
			</div>
			<div class="contai-panel-foot">
				<span class="contai-panel-foot-meta">
					<?php esc_html_e( 'Credits never expire.', '1platform-content-ai' ); ?>
				</span>
				<div class="contai-panel-foot-actions">
					<a href="<?php echo esc_url( $history_url ); ?>" class="contai-btn contai-btn-secondary">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'View history', '1platform-content-ai' ); ?>
					</a>
					<button type="button" class="contai-btn contai-btn-primary" id="contai-open-topup-modal">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add credit to balance', '1platform-content-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderDetailsPanel( string $subscriptionId, string $currency ): void {
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-id-alt"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Account Details', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Your subscription and billing currency.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body">
				<div class="contai-form-grid">
					<div class="contai-field">
						<div class="contai-field-head">
							<span class="contai-label">
								<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Subscription ID', '1platform-content-ai' ); ?>
							</span>
						</div>
						<code class="contai-input" style="font-family: var(--contai-font-mono);">
							<?php echo esc_html( $subscriptionId ); ?>
						</code>
					</div>
					<div class="contai-field">
						<div class="contai-field-head">
							<span class="contai-label">
								<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Currency', '1platform-content-ai' ); ?>
							</span>
						</div>
						<p class="contai-input" style="margin: 0;">
							<?php echo esc_html( $currency ); ?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderTopUpModal( string $currency ): void {
		$form_url = admin_url( 'admin.php?page=contai-billing&section=overview' );
		?>
		<div class="contai-modal-backdrop" id="contai-topup-modal" style="display: none;" role="dialog" aria-modal="true">
			<div class="contai-modal" role="document">
				<div class="contai-modal-head">
					<div class="contai-modal-icon is-primary" aria-hidden="true">
						<span class="dashicons dashicons-plus-alt2"></span>
					</div>
					<div>
						<h3><?php esc_html_e( 'Add Credit to Balance', '1platform-content-ai' ); ?></h3>
						<p><?php esc_html_e( 'Select the amount you want to top up.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( $form_url ); ?>">
					<?php wp_nonce_field( ContaiTopUpHandler::NONCE_ACTION, ContaiTopUpHandler::NONCE_FIELD ); ?>
					<input type="hidden" name="contai_billing_topup" value="1">
					<input type="hidden" name="contai_topup_currency" value="<?php echo esc_attr( $currency ); ?>">

					<div class="contai-modal-body">
						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_topup_amount" class="contai-label">
									<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
									<?php esc_html_e( 'Amount', '1platform-content-ai' ); ?>
								</label>
								<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
							</div>
							<input
								type="number"
								id="contai_topup_amount"
								name="contai_topup_amount"
								class="contai-input"
								min="5"
								max="200"
								step="0.01"
								required
								placeholder="<?php esc_attr_e( 'Enter amount', '1platform-content-ai' ); ?>">
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php
								printf(
									/* translators: %1$d: minimum amount, %2$d: maximum amount, %3$s: currency code */
									esc_html__( 'Minimum %1$d and maximum %2$d (%3$s)', '1platform-content-ai' ),
									5,
									200,
									esc_html( $currency )
								);
								?>
							</p>
						</div>
					</div>

					<div class="contai-modal-foot">
						<button type="button" class="contai-btn contai-btn-secondary" id="contai-close-topup-modal">
							<?php esc_html_e( 'Cancel', '1platform-content-ai' ); ?>
						</button>
						<button type="submit" class="contai-btn contai-btn-primary">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Continue', '1platform-content-ai' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function renderMessage(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only, no data modification.
		if ( ! isset( $_GET['contai_bl_message'] ) || ! isset( $_GET['contai_bl_type'] ) ) {
			return;
		}

		$message  = urldecode( sanitize_text_field( wp_unslash( $_GET['contai_bl_message'] ) ) );
		$type     = sanitize_key( wp_unslash( $_GET['contai_bl_type'] ) );
		$trace_id = isset( $_GET['contai_bl_trace_id'] )
			? sanitize_text_field( wp_unslash( urldecode( $_GET['contai_bl_trace_id'] ) ) )
			: null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$valid_types = array( 'success', 'error', 'warning', 'info' );
		$notice_type = in_array( $type, $valid_types, true ) ? $type : 'info';
		$icon        = $notice_type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning';
		?>
		<div class="contai-notice contai-notice-<?php echo esc_attr( $notice_type ); ?>">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<p>
				<?php echo esc_html( $message ); ?>
				<?php if ( ! empty( $trace_id ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-logs&trace_id=' . urlencode( $trace_id ) ) ); ?>">
						[<?php esc_html_e( 'Ref', '1platform-content-ai' ); ?>: <?php echo esc_html( $trace_id ); ?>]
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private function renderUserNotConfigured(): void {
		ContaiBillingSetupNotice::render();
	}

	private function renderError( string $message ): void {
		?>
		<div class="contai-notice contai-notice-error">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div>
				<p><strong><?php esc_html_e( 'Error', '1platform-content-ai' ); ?></strong></p>
				<p><?php echo wp_kses( $message, array( 'a' => array( 'href' => array(), 'class' => array() ) ) ); ?></p>
			</div>
		</div>
		<?php
	}

	private function enqueueAssets(): void {
		$jsFile = dirname( __DIR__ ) . '/assets/js/billing.js';
		$jsUrl  = plugin_dir_url( __FILE__ ) . '../assets/js/billing.js';

		wp_enqueue_script(
			'contai-billing',
			$jsUrl,
			array( 'jquery' ),
			file_exists( $jsFile ) ? filemtime( $jsFile ) : '1.0.0',
			true
		);
	}
}
