<?php
/**
 * Keyword Extractor panel (UI v3).
 *
 * @package OnePlatformContentAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/JobStatus.php';
require_once __DIR__ . '/../../../services/jobs/KeywordExtractionJob.php';
require_once __DIR__ . '/../../../services/billing/CreditGuard.php';

class ContaiKeywordExtractorPanel {

	private ContaiJobRepository $jobRepository;

	public function __construct() {
		$this->jobRepository = new ContaiJobRepository();
	}

	public function render(): void {
		$this->renderAdminNotices();

		$creditGuard = new ContaiCreditGuard();
		$creditCheck = $creditGuard->validateCredits();

		if ( ! $creditCheck['has_credits'] ) :
			?>
			<div class="contai-notice contai-notice-warning">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<div>
					<p>
						<strong><?php esc_html_e( 'Insufficient Balance', '1platform-content-ai' ); ?></strong> —
						<?php
						printf(
							/* translators: %1$s: balance amount, %2$s: currency code */
							esc_html__( 'Your balance is %1$s %2$s. Add credits to extract keywords.', '1platform-content-ai' ),
							esc_html( number_format( $creditCheck['balance'], 2 ) ),
							esc_html( $creditCheck['currency'] )
						);
						?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=contai-billing' ) ); ?>" class="contai-btn contai-btn-primary contai-btn-sm">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php esc_html_e( 'Add Credits', '1platform-content-ai' ); ?>
						</a>
					</p>
				</div>
			</div>
			<?php
		endif;

		$status = $this->getQueueStatus();
		?>
		<div class="contai-panel">
			<div class="contai-panel-head">
				<div class="contai-panel-head-main">
					<div class="contai-tile" aria-hidden="true">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div>
						<h2 class="contai-panel-title"><?php esc_html_e( 'Extract Keywords', '1platform-content-ai' ); ?></h2>
						<p class="contai-panel-desc"><?php esc_html_e( 'Discover ranking keywords for any topic, localized to your target country.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=contai-content-generator&section=keyword-extractor' ) ); ?>">
				<div class="contai-panel-body">
					<div class="contai-form-grid">
						<div class="contai-field contai-form-grid-full">
							<div class="contai-field-head">
								<label for="contai_topic" class="contai-label">
									<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
									<?php esc_html_e( 'Topic / Theme', '1platform-content-ai' ); ?>
								</label>
								<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
							</div>
							<input type="text" id="contai_topic" name="contai_topic" class="contai-input" required
								placeholder="<?php esc_attr_e( 'e.g. plantas de interior, salud medioambiental', '1platform-content-ai' ); ?>"
								minlength="3" maxlength="200"
								value="<?php
								// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission.
								echo isset( $_POST['contai_topic'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['contai_topic'] ) ) ) : ''; ?>">
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Enter a topic or theme to discover keywords from Google (2–4 words work best).', '1platform-content-ai' ); ?>
							</p>
						</div>

						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_target_language" class="contai-label">
									<span class="dashicons dashicons-translation" aria-hidden="true"></span>
									<?php esc_html_e( 'Target Language', '1platform-content-ai' ); ?>
								</label>
								<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
							</div>
							<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission.
							$selected_lang = isset( $_POST['contai_target_language'] ) ? sanitize_text_field( wp_unslash( $_POST['contai_target_language'] ) ) : '';
							?>
							<select id="contai_target_language" name="contai_target_language" class="contai-select" required>
								<option value="en" <?php selected( $selected_lang, 'en' ); ?>><?php esc_html_e( 'English', '1platform-content-ai' ); ?></option>
								<option value="es" <?php selected( $selected_lang, 'es' ); ?>><?php esc_html_e( 'Spanish', '1platform-content-ai' ); ?></option>
							</select>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Language for keyword extraction and content analysis.', '1platform-content-ai' ); ?>
							</p>
						</div>

						<div class="contai-field">
							<div class="contai-field-head">
								<label for="contai_country" class="contai-label">
									<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
									<?php esc_html_e( 'Target Country', '1platform-content-ai' ); ?>
								</label>
								<span class="contai-field-state"><?php esc_html_e( 'Required', '1platform-content-ai' ); ?></span>
							</div>
							<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission.
							$selected_country = isset( $_POST['contai_country'] ) ? sanitize_text_field( wp_unslash( $_POST['contai_country'] ) ) : '';
							?>
							<select id="contai_country" name="contai_country" class="contai-select" required>
								<option value="us" <?php selected( $selected_country, 'us' ); ?>><?php esc_html_e( 'United States', '1platform-content-ai' ); ?></option>
								<option value="es" <?php selected( $selected_country, 'es' ); ?>><?php esc_html_e( 'Spain', '1platform-content-ai' ); ?></option>
							</select>
							<p class="contai-field-help">
								<span class="dashicons dashicons-info" aria-hidden="true"></span>
								<?php esc_html_e( 'Keywords will be localized for the selected country.', '1platform-content-ai' ); ?>
							</p>
						</div>
					</div>
				</div>
				<div class="contai-panel-foot">
					<span class="contai-panel-foot-meta">
						<?php esc_html_e( 'Extraction runs in the background — you can safely leave this page.', '1platform-content-ai' ); ?>
					</span>
					<div class="contai-panel-foot-actions">
						<?php wp_nonce_field( 'contai_keyword_extractor_nonce', 'contai_keyword_extractor_nonce' ); ?>
						<button type="submit" name="contai_extract_keywords" class="contai-btn contai-btn-primary" <?php echo ! $creditCheck['has_credits'] ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<?php esc_html_e( 'Extract Keywords', '1platform-content-ai' ); ?>
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
						<p class="contai-panel-desc"><?php esc_html_e( 'Current status of the keyword extraction queue. Refresh the page to see updates.', '1platform-content-ai' ); ?></p>
					</div>
				</div>
			</div>
			<div class="contai-panel-body">
				<div class="contai-stat-grid" style="grid-template-columns: repeat(2, 1fr);">
					<div class="contai-stat">
						<div class="contai-stat-head">
							<span class="contai-stat-label"><?php esc_html_e( 'Pending jobs', '1platform-content-ai' ); ?></span>
							<span class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-clock"></span>
							</span>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( $status['pending'] ); ?></div>
					</div>
					<div class="contai-stat">
						<div class="contai-stat-head">
							<span class="contai-stat-label"><?php esc_html_e( 'Processing now', '1platform-content-ai' ); ?></span>
							<span class="contai-stat-icon" aria-hidden="true">
								<span class="dashicons dashicons-update-alt"></span>
							</span>
						</div>
						<div class="contai-stat-value"><?php echo esc_html( $status['processing'] ); ?></div>
					</div>
				</div>

				<?php if ( ! empty( $status['processing_jobs'] ) ) : ?>
					<h3 class="contai-panel-title" style="margin-top: 20px; font-size: 14px;">
						<?php esc_html_e( 'Currently Processing', '1platform-content-ai' ); ?>
					</h3>
					<div class="contai-table-wrap" style="margin-top: 8px;">
						<table class="contai-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Topic', '1platform-content-ai' ); ?></th>
									<th><?php esc_html_e( 'Country', '1platform-content-ai' ); ?></th>
									<th><?php esc_html_e( 'Language', '1platform-content-ai' ); ?></th>
									<th><?php esc_html_e( 'Processing Time', '1platform-content-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $status['processing_jobs'] as $job ) : ?>
									<?php $this->renderJobRow( $job ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<div class="contai-empty" style="margin-top: 16px;">
						<div class="contai-empty-icon is-neutral" aria-hidden="true">
							<span class="dashicons dashicons-editor-help"></span>
						</div>
						<p class="contai-empty-desc"><?php esc_html_e( 'No jobs currently processing.', '1platform-content-ai' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function renderAdminNotices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params.
		if ( isset( $_GET['success'] ) && isset( $_GET['message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
			?>
			<div class="contai-notice contai-notice-success">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params.
		if ( isset( $_GET['error'] ) && isset( $_GET['message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
			?>
			<div class="contai-notice contai-notice-error">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}

	private function getQueueStatus(): array {
		$pending = $this->jobRepository->countJobsByTypeAndStatus(
			ContaiKeywordExtractionJob::TYPE,
			ContaiJobStatus::PENDING
		);
		$processing = $this->jobRepository->countJobsByTypeAndStatus(
			ContaiKeywordExtractionJob::TYPE,
			ContaiJobStatus::PROCESSING
		);
		$processingJobs = $this->jobRepository->getProcessingJobsByType(
			ContaiKeywordExtractionJob::TYPE
		);
		return array(
			'pending'         => $pending,
			'processing'      => $processing,
			'processing_jobs' => $processingJobs,
		);
	}

	private function renderJobRow( array $job ): void {
		$payload = json_decode( $job['payload'], true ) ?? array();
		$topic   = $payload['topic'] ?? $payload['domain'] ?? __( 'Unknown', '1platform-content-ai' );
		$country = strtoupper( $payload['country'] ?? 'N/A' );
		$lang    = strtoupper( $payload['lang'] ?? 'N/A' );
		$elapsed = $this->calculateElapsedTime( $job['processed_at'] );
		?>
		<tr>
			<td><strong><?php echo esc_html( $topic ); ?></strong></td>
			<td><?php echo esc_html( $country ); ?></td>
			<td><?php echo esc_html( $lang ); ?></td>
			<td><?php echo esc_html( $elapsed ); ?></td>
		</tr>
		<?php
	}

	private function calculateElapsedTime( ?string $processedAt ): string {
		if ( empty( $processedAt ) ) {
			return __( 'Just started', '1platform-content-ai' );
		}
		$processed = strtotime( $processedAt );
		if ( $processed === false ) {
			return __( 'Unknown', '1platform-content-ai' );
		}
		$diff    = max( 0, time() - $processed );
		$minutes = floor( $diff / 60 );
		$seconds = $diff % 60;
		if ( $minutes > 0 ) {
			return sprintf( '%dm %ds', $minutes, $seconds );
		}
		return sprintf( '%ds', $seconds );
	}
}
