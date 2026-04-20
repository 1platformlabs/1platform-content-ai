<?php
/**
 * Billing history panel (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../services/billing/BillingService.php';
require_once __DIR__ . '/../components/BillingSetupNotice.php';

class ContaiBillingHistoryPanel {

	private const DEFAULT_LIMIT = 10;

	private ContaiBillingService $service;

	public function __construct( ContaiBillingService $service ) {
		$this->service = $service;
	}

	public function render(): void {
		$userProfile = $this->service->getUserProfile();

		if ( ! $userProfile ) {
			$this->renderUserNotConfigured();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination.
		$limit = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : self::DEFAULT_LIMIT;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination.
		$skip = isset( $_GET['skip'] ) ? absint( wp_unslash( $_GET['skip'] ) ) : 0;

		if ( $limit < 1 || $limit > 100 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$response = $this->service->getTransactions( $limit, $skip );

		if ( ! $response->isSuccess() ) {
			$this->renderError(
				ContaiNoticeHelper::buildErrorNotice(
					'Load transactions',
					$response,
					__( 'Failed to load transactions.', '1platform-content-ai' )
				)
			);
			return;
		}

		$data         = $response->getData();
		$transactions = $this->extractTransactions( $data );
		$total        = is_array( $data ) ? ( $data['total'] ?? null ) : null;

		if ( empty( $transactions ) ) {
			$this->renderEmptyState();
			return;
		}

		$this->renderTransactionPanel( $transactions, $limit, $skip, count( $transactions ), $total );
	}

	private function extractTransactions( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['transactions'] ) ) {
			return $data['transactions'];
		}
		if ( isset( $data['items'] ) ) {
			return $data['items'];
		}
		if ( isset( $data[0] ) ) {
			return $data;
		}
		return array();
	}

	private function renderTransactionPanel( array $transactions, int $limit, int $skip, int $count, ?int $total ): void {
		$showing_from = $skip + 1;
		$showing_to   = $skip + $count;
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-list-view"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Transactions', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc">
							<?php
							if ( $total !== null ) {
								printf(
									/* translators: %1$d: start item, %2$d: end item, %3$d: total */
									esc_html__( 'Showing %1$d–%2$d of %3$d transactions.', '1platform-content-ai' ),
									intval( $showing_from ),
									intval( $showing_to ),
									intval( $total )
								);
							} else {
								printf(
									/* translators: %1$d: start item, %2$d: end item */
									esc_html__( 'Showing %1$d–%2$d transactions.', '1platform-content-ai' ),
									intval( $showing_from ),
									intval( $showing_to )
								);
							}
							?>
						</p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body" style="padding: 0;">
				<div class="contai-table-wrap">
					<table class="contai-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Description', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Reference', '1platform-content-ai' ); ?></th>
								<th><?php esc_html_e( 'Status', '1platform-content-ai' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Amount', '1platform-content-ai' ); ?></th>
								<th style="text-align: center;"><?php esc_html_e( 'Payment', '1platform-content-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $transactions as $transaction ) : ?>
								<?php $this->renderRow( $transaction ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php $this->renderPagination( $limit, $skip, $count, $total ); ?>
		</div>
		<?php
	}

	private function renderRow( array $transaction ): void {
		$created_at  = $transaction['created_at'] ?? '';
		$amount      = $transaction['usd_amount'] ?? $transaction['usdAmount'] ?? $transaction['amount'] ?? 0;
		$currency    = $transaction['currency'] ?? '';
		$status      = $transaction['status'] ?? '';
		$description = $transaction['description'] ?? '';
		$reference   = $transaction['reference'] ?? '';
		$payment_url = $transaction['payment_url'] ?? '';

		$formatted_date = '';
		$formatted_time = '';
		if ( ! empty( $created_at ) ) {
			$timestamp = strtotime( $created_at );
			if ( $timestamp !== false ) {
				$formatted_date = date_i18n( get_option( 'date_format' ), $timestamp );
				$formatted_time = date_i18n( get_option( 'time_format' ), $timestamp );
			}
		}

		$badge_variant = $this->getStatusBadgeVariant( $status );
		$status_icon   = $this->getStatusIcon( $status );

		?>
		<tr>
			<td>
				<div style="display:flex;flex-direction:column;">
					<span><?php echo esc_html( $formatted_date ); ?></span>
					<span class="contai-field-state"><?php echo esc_html( $formatted_time ); ?></span>
				</div>
			</td>
			<td><?php echo esc_html( $description ); ?></td>
			<td>
				<?php if ( ! empty( $reference ) ) : ?>
					<code class="contai-mono"><?php echo esc_html( $reference ); ?></code>
				<?php else : ?>
					<span class="contai-field-state">—</span>
				<?php endif; ?>
			</td>
			<td>
				<span class="contai-badge <?php echo esc_attr( $badge_variant ); ?>">
					<span class="dashicons <?php echo esc_attr( $status_icon ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( ucfirst( $status ) ); ?>
				</span>
			</td>
			<td style="text-align: right;">
				<?php echo esc_html( number_format( (float) $amount, 2 ) ); ?>
				<span class="contai-field-state"><?php echo esc_html( $currency ); ?></span>
			</td>
			<td style="text-align: center;">
				<?php if ( ! empty( $payment_url ) ) : ?>
					<a href="<?php echo esc_url( $payment_url ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Pay now', '1platform-content-ai' ); ?>
						<span class="dashicons dashicons-external" aria-hidden="true"></span>
					</a>
				<?php else : ?>
					<span class="contai-field-state">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function getStatusIcon( string $status ): string {
		$map = array(
			'completed'  => 'dashicons-yes-alt',
			'success'    => 'dashicons-yes-alt',
			'paid'       => 'dashicons-yes-alt',
			'approved'   => 'dashicons-yes-alt',
			'pending'    => 'dashicons-clock',
			'processing' => 'dashicons-clock',
			'created'    => 'dashicons-clock',
			'failed'     => 'dashicons-dismiss',
			'cancelled'  => 'dashicons-dismiss',
			'expired'    => 'dashicons-dismiss',
		);
		return $map[ strtolower( $status ) ] ?? 'dashicons-marker';
	}

	private function getStatusBadgeVariant( string $status ): string {
		$map = array(
			'completed'  => 'contai-badge-success',
			'success'    => 'contai-badge-success',
			'paid'       => 'contai-badge-success',
			'approved'   => 'contai-badge-success',
			'pending'    => 'contai-badge-warning',
			'processing' => 'contai-badge-warning',
			'created'    => 'contai-badge-info',
			'failed'     => 'contai-badge-danger',
			'cancelled'  => 'contai-badge-danger',
			'expired'    => 'contai-badge-danger',
		);
		return $map[ strtolower( $status ) ] ?? 'contai-badge-neutral';
	}

	private function renderPagination( int $limit, int $skip, int $count, ?int $total ): void {
		$base_url  = admin_url( 'admin.php?page=contai-billing&section=billing-history' );
		$prev_skip = max( 0, $skip - $limit );
		$next_skip = $skip + $limit;
		$has_prev  = $skip > 0;
		$has_next  = $count >= $limit;

		if ( ! $has_prev && ! $has_next ) {
			return;
		}
		?>
		<div class="contai-panel-foot">
			<span class="contai-panel-foot-meta">
				<?php
				printf(
					/* translators: %1$d: page skip offset, %2$d: limit per page */
					esc_html__( 'Page offset %1$d · %2$d per page', '1platform-content-ai' ),
					intval( $skip ),
					intval( $limit )
				);
				?>
			</span>
			<div class="contai-panel-foot-actions">
				<?php if ( $has_prev ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'limit' => $limit, 'skip' => $prev_skip ), $base_url ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Previous', '1platform-content-ai' ); ?>
					</a>
				<?php else : ?>
					<span class="contai-btn contai-btn-secondary contai-btn-sm is-disabled" aria-disabled="true">
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Previous', '1platform-content-ai' ); ?>
					</span>
				<?php endif; ?>

				<?php if ( $has_next ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'limit' => $limit, 'skip' => $next_skip ), $base_url ) ); ?>" class="contai-btn contai-btn-secondary contai-btn-sm">
						<?php esc_html_e( 'Next', '1platform-content-ai' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</a>
				<?php else : ?>
					<span class="contai-btn contai-btn-secondary contai-btn-sm is-disabled" aria-disabled="true">
						<?php esc_html_e( 'Next', '1platform-content-ai' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function renderEmptyState(): void {
		$overview_url = admin_url( 'admin.php?page=contai-billing&section=overview' );
		?>
		<div class="contai-empty">
			<div class="contai-empty-icon is-primary" aria-hidden="true">
				<span class="dashicons dashicons-portfolio"></span>
			</div>
			<h3 class="contai-empty-title">
				<?php esc_html_e( 'No transactions yet', '1platform-content-ai' ); ?>
			</h3>
			<p class="contai-empty-desc">
				<?php esc_html_e( 'Your billing history will appear here once you add credit to your balance and start using the platform.', '1platform-content-ai' ); ?>
			</p>
			<div class="contai-empty-actions">
				<a href="<?php echo esc_url( $overview_url ); ?>" class="contai-btn contai-btn-primary">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add credit to balance', '1platform-content-ai' ); ?>
				</a>
			</div>
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
}
