<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/JobStatus.php';
require_once __DIR__ . '/../../../services/jobs/KeywordExtractionJob.php';

class ContaiKeywordExtractorPanel {

    private $jobRepository;

    public function __construct() {
        $this->jobRepository = new ContaiJobRepository();
    }

    public function render(): void {
        $this->renderAdminNotices();
        $status = $this->getQueueStatus();
        ?>
        <div class="contai-settings-panel contai-panel-keyword-extractor">
            <div class="contai-panel-body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=contai-content-generator&section=keyword-extractor' ) ); ?>" class="contai-keyword-form">
                    <div class="contai-form-grid contai-grid-2">
                        <div class="contai-form-group">
                            <label for="contai_source_url" class="contai-label">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                <?php esc_html_e('Source Website', '1platform-content-ai'); ?>
                            </label>
                            <input type="url" id="contai_source_url" name="contai_source_url" required
                                   class="contai-input"
                                   placeholder="https://example.com"
                                   value="<?php
                                   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission; value is sanitized and escaped.
                                   echo isset($_POST['contai_source_url']) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['contai_source_url'] ) ) ) : ''; ?>">
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Enter the competitor website URL to analyze', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_target_language" class="contai-label">
                                <span class="dashicons dashicons-translation"></span>
                                <?php esc_html_e('Target Language', '1platform-content-ai'); ?>
                            </label>
                            <select id="contai_target_language" name="contai_target_language" required class="contai-select">
                                <?php
                                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission.
                                $selected_lang = isset($_POST['contai_target_language']) ? sanitize_text_field( wp_unslash( $_POST['contai_target_language'] ) ) : '';
                                ?>
                                <option value="en" <?php selected($selected_lang, 'en'); ?>><?php esc_html_e('English', '1platform-content-ai'); ?></option>
                                <option value="es" <?php selected($selected_lang, 'es'); ?>><?php esc_html_e('Spanish', '1platform-content-ai'); ?></option>
                            </select>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Language for keyword extraction and content analysis', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_country" class="contai-label">
                                <span class="dashicons dashicons-location-alt"></span>
                                <?php esc_html_e('Target Country', '1platform-content-ai'); ?>
                            </label>
                            <select id="contai_country" name="contai_country" required class="contai-select">
                                <?php
                                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Repopulating form field after submission.
                                $selected_country = isset($_POST['contai_country']) ? sanitize_text_field( wp_unslash( $_POST['contai_country'] ) ) : '';
                                ?>
                                <option value="us" <?php selected($selected_country, 'us'); ?>><?php esc_html_e('United States', '1platform-content-ai'); ?></option>
                                <option value="es" <?php selected($selected_country, 'es'); ?>><?php esc_html_e('Spain', '1platform-content-ai'); ?></option>
                            </select>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Keywords will be localized for the selected country', '1platform-content-ai'); ?>
                            </p>
                        </div>
                    </div>

                    <?php wp_nonce_field('contai_keyword_extractor_nonce', 'contai_keyword_extractor_nonce'); ?>

                    <div class="contai-button-group">
                        <button type="submit" name="contai_extract_keywords" class="button button-primary contai-button-action">
                            <span class="dashicons dashicons-search"></span>
                            <span class="contai-button-text"><?php esc_html_e('Extract Keywords', '1platform-content-ai'); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="contai-settings-panel contai-panel-queue-status">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Queue Status', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Current status of the keyword extraction queue. Refresh the page to see updates.', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <div class="contai-queue-stats">
                    <div class="contai-stat-card">
                        <div class="contai-stat-icon contai-stat-pending">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="contai-stat-content">
                            <div class="contai-stat-value"><?php echo esc_html($status['pending']); ?></div>
                            <div class="contai-stat-label"><?php esc_html_e('Pending Jobs', '1platform-content-ai'); ?></div>
                        </div>
                    </div>

                    <div class="contai-stat-card">
                        <div class="contai-stat-icon contai-stat-processing">
                            <span class="dashicons dashicons-update-alt"></span>
                        </div>
                        <div class="contai-stat-content">
                            <div class="contai-stat-value"><?php echo esc_html($status['processing']); ?></div>
                            <div class="contai-stat-label"><?php esc_html_e('Processing Now', '1platform-content-ai'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($status['processing_jobs'])): ?>
                    <div class="contai-processing-jobs">
                        <h3 class="contai-section-title">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php esc_html_e('Currently Processing', '1platform-content-ai'); ?>
                        </h3>
                        <div>
                            <?php $this->renderProcessingTable($status['processing_jobs']); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="contai-empty-state">
                        <span class="dashicons dashicons-editor-help"></span>
                        <p><?php esc_html_e('No jobs currently processing', '1platform-content-ai'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderAdminNotices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
        if (isset($_GET['success']) && isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only read of GET params after redirect.
        if (isset($_GET['error']) && isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = sanitize_text_field( wp_unslash( $_GET['message'] ) );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($message); ?></p>
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

        return [
            'pending' => $pending,
            'processing' => $processing,
            'processing_jobs' => $processingJobs
        ];
    }

    private function renderProcessingTable(array $jobs): void {
        ?>
        <table class="contai-table contai-processing-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Domain', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Country', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Language', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Processing Time', '1platform-content-ai'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <?php $this->renderJobRow($job); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderJobRow(array $job): void {
        $payload = json_decode($job['payload'], true);
        $domain = $payload['domain'] ?? __('Unknown', '1platform-content-ai');
        $country = strtoupper($payload['country'] ?? 'N/A');
        $lang = strtoupper($payload['lang'] ?? 'N/A');
        $elapsed = $this->calculateElapsedTime($job['processed_at']);
        ?>
        <tr>
            <td><strong><?php echo esc_html($domain); ?></strong></td>
            <td><?php echo esc_html($country); ?></td>
            <td><?php echo esc_html($lang); ?></td>
            <td>
                <span class="contai-processing-time">
                    <?php echo esc_html($elapsed); ?>
                </span>
            </td>
        </tr>
        <?php
    }

    private function calculateElapsedTime(string $processedAt): string {
        $now = current_time('timestamp');
        $processed = strtotime($processedAt);
        $diff = $now - $processed;

        $minutes = floor($diff / 60);
        $seconds = $diff % 60;

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }
}
