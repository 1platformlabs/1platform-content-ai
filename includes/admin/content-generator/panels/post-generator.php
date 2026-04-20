<?php

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/../../../services/jobs/QueueManager.php';
require_once __DIR__ . '/../../../services/billing/CreditGuard.php';

class ContaiPostGeneratorPanel {

	private $queueManager;

	public function __construct() {
		$this->queueManager = new ContaiQueueManager();
	}

	public function render(): void {
		$this->renderFlashNotices();

		$creditGuard = new ContaiCreditGuard();
		$creditCheck = $creditGuard->validateCredits();

		if ( ! $creditCheck['has_credits'] ) :
			?>
			<div class="contai-notice contai-notice-warning" role="alert">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<p>
					<strong><?php esc_html_e( 'Insufficient Balance', '1platform-content-ai' ); ?></strong>
					<?php
					printf(
						/* translators: %1$s: balance amount, %2$s: currency code */
						esc_html__( 'Your balance is %1$s %2$s. Add credits to generate content.', '1platform-content-ai' ),
						esc_html( number_format( $creditCheck['balance'], 2 ) ),
						esc_html( $creditCheck['currency'] )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>">
						<?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
					</a>
				</p>
				<div class="contai-notice-actions"></div>
			</div>
			<?php
		endif;

		$status = $this->queueManager->getQueueStatus();
		?>

		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-edit"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Post Generator', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Generate AI-powered blog posts with images, videos, and SEO metadata.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>

			<form method="post">
				<div class="contai-panel-body">
					<div class="contai-form-grid">
						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_content_lang" class="contai-label">
									<span class="dashicons dashicons-translation" aria-hidden="true"></span>
									<?php esc_html_e( 'Target Language', '1platform-content-ai' ); ?>
								</label>
							</div>
							<select id="contai_content_lang" name="content_lang" required class="contai-select">
								<option value="en"><?php esc_html_e( 'English', '1platform-content-ai' ); ?></option>
								<option value="es"><?php esc_html_e( 'Spanish', '1platform-content-ai' ); ?></option>
							</select>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Language code for content generation (e.g., en, es, fr).', '1platform-content-ai' ); ?>
							</p>
						</div>

						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_content_country" class="contai-label">
									<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
									<?php esc_html_e( 'Target Country', '1platform-content-ai' ); ?>
								</label>
							</div>
							<select id="contai_content_country" name="content_country" required class="contai-select">
								<option value="us"><?php esc_html_e( 'United States', '1platform-content-ai' ); ?></option>
								<option value="es"><?php esc_html_e( 'Spain', '1platform-content-ai' ); ?></option>
							</select>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Country code for content generation (e.g., us, es, fr).', '1platform-content-ai' ); ?>
							</p>
						</div>

						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_image_provider" class="contai-label">
									<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
									<?php esc_html_e( 'Image Provider', '1platform-content-ai' ); ?>
								</label>
							</div>
							<select id="contai_image_provider" name="image_provider" class="contai-select" required>
								<option value="pexels" <?php selected( get_option( 'contai_image_provider', 'pexels' ), 'pexels' ); ?>><?php esc_html_e( 'Stock Photos (Free)', '1platform-content-ai' ); ?></option>
								<option value="pixabay" <?php selected( get_option( 'contai_image_provider', 'pexels' ), 'pixabay' ); ?>><?php esc_html_e( 'Stock Images (Free)', '1platform-content-ai' ); ?></option>
							</select>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Select the image provider for content generation.', '1platform-content-ai' ); ?>
							</p>
						</div>

						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_post_count" class="contai-label">
									<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
									<?php esc_html_e( 'Number of Posts', '1platform-content-ai' ); ?>
								</label>
							</div>
							<input type="number" id="contai_post_count" name="post_count" value="1" min="1" max="100" class="contai-input" required>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Enter how many posts you want to add to the queue (1–100).', '1platform-content-ai' ); ?>
							</p>
						</div>
					</div>

					<?php wp_nonce_field( 'contai_post_generator_nonce', 'contai_post_generator_nonce' ); ?>
				</div>

				<div class="contai-panel-foot">
					<div class="contai-panel-foot-meta"></div>
					<div class="contai-panel-foot-actions">
						<button type="submit" name="contai_clear_queue" class="contai-btn contai-btn-secondary"
								onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all pending and processing jobs from the queue?', '1platform-content-ai' ); ?>');">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span>
							<?php esc_html_e( 'Clear Queue', '1platform-content-ai' ); ?>
						</button>
						<button type="submit" name="contai_enqueue_posts" class="contai-btn contai-btn-primary" <?php echo ! $creditCheck['has_credits'] ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
							<?php esc_html_e( 'Add to Queue', '1platform-content-ai' ); ?>
						</button>
					</div>
				</div>
			</form>
		</div>

		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-update"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Queue Status', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Current status of the post generation queue. Refresh the page to see updates.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body">
				<div class="contai-stat-grid">
					<div class="contai-stat">
						<div class="contai-stat-head">
							<div class="contai-stat-label"><?php esc_html_e( 'Pending Jobs', '1platform-content-ai' ); ?></div>
							<div class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-clock"></span>
							</div>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( (string) $status['pending'] ); ?></div>
						<div class="contai-stat-foot">
							<span class="contai-stat-hint"><?php esc_html_e( 'Waiting in queue', '1platform-content-ai' ); ?></span>
						</div>
					</div>

					<div class="contai-stat">
						<div class="contai-stat-head">
							<div class="contai-stat-label"><?php esc_html_e( 'Processing Now', '1platform-content-ai' ); ?></div>
							<div class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-update-alt"></span>
							</div>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( (string) $status['processing'] ); ?></div>
						<div class="contai-stat-foot">
							<span class="contai-stat-hint"><?php esc_html_e( 'Actively generating', '1platform-content-ai' ); ?></span>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $status['processing_jobs'] ) ) : ?>
					<div style="margin-top: 16px;">
						<?php $this->renderProcessingTable( $status['processing_jobs'] ); ?>
					</div>
				<?php else : ?>
					<div class="contai-empty" style="margin-top: 16px;">
						<div class="contai-empty-icon is-neutral" aria-hidden="true">
							<span class="dashicons dashicons-editor-help"></span>
						</div>
						<h3 class="contai-empty-title"><?php esc_html_e( 'No active jobs', '1platform-content-ai' ); ?></h3>
						<p class="contai-empty-desc"><?php esc_html_e( 'Nothing is processing right now. Queued posts will appear here when they start running.', '1platform-content-ai' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function renderFlashNotices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
		if ( isset( $_GET['success'] ) && isset( $_GET['message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
			$this->renderNotice( 'success', $message );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
		if ( isset( $_GET['error'] ) && isset( $_GET['message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
			$this->renderNotice( 'error', $message );
		}
	}

	private function renderNotice( string $tone, string $message ): void {
		$icons = array(
			'success' => 'dashicons-yes-alt',
			'error'   => 'dashicons-dismiss',
			'warning' => 'dashicons-warning',
			'info'    => 'dashicons-info',
		);
		$icon = $icons[ $tone ] ?? 'dashicons-info';
		?>
		<div class="contai-notice contai-notice-<?php echo esc_attr( $tone ); ?>" role="status">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<p><?php echo esc_html( $message ); ?></p>
			<div class="contai-notice-actions"></div>
		</div>
		<?php
	}

	private function renderProcessingTable( array $jobs ): void {
		?>
		<div class="contai-table-wrap">
			<div class="contai-table-toolbar">
				<div class="contai-table-toolbar-left">
					<div class="contai-mono" style="text-transform: uppercase; font-size: 10.5px; letter-spacing: .06em; color: var(--fg-3);">
						<?php esc_html_e( 'Currently Processing', '1platform-content-ai' ); ?>
					</div>
				</div>
			</div>
			<table class="contai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Keyword', '1platform-content-ai' ); ?></th>
						<th><?php esc_html_e( 'Title', '1platform-content-ai' ); ?></th>
						<th><?php esc_html_e( 'Volume', '1platform-content-ai' ); ?></th>
						<th><?php esc_html_e( 'Processing Time', '1platform-content-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $job['keyword'] ); ?></strong></td>
							<td><?php echo esc_html( $job['title'] ); ?></td>
							<td class="contai-mono"><?php echo esc_html( number_format( $job['volume'] ) ); ?></td>
							<td class="contai-mono"><?php echo esc_html( $this->calculateElapsedTime( $job['processed_at'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function calculateElapsedTime( string $processedAt ): string {
		$now       = current_time( 'timestamp' );
		$processed = strtotime( $processedAt );
		$diff      = $now - $processed;

		$minutes = floor( $diff / 60 );
		$seconds = $diff % 60;

		if ( $minutes > 0 ) {
			return sprintf( '%dm %ds', $minutes, $seconds );
		}

		return sprintf( '%ds', $seconds );
	}
}
