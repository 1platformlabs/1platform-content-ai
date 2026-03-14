<?php

if (!defined('ABSPATH')) exit;

class ContaiInternalLinksQueueSection
{
    private $queueManager;

    public function __construct(ContaiInternalLinksQueueManager $queueManager)
    {
        $this->queueManager = $queueManager;
    }

    public function render(): void
    {
        $this->renderAdminNotices();
        $status = $this->queueManager->getQueueStatus();
        ?>
        <div class="contai-settings-section contai-section-separator">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e('Internal Links Processing Queue', '1platform-content-ai'); ?>
            </h2>

            <div class="contai-internal-links-queue-container">
                <?php $this->renderQueueForm(); ?>
                <?php $this->renderQueueStatus($status); ?>
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

    private function renderQueueForm(): void
    {
        ?>
        <form method="post" class="contai-queue-form">
            <div class="contai-form-group">
                <label for="contai_internal_links_limit" class="contai-label">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e('Number of Posts to Process', '1platform-content-ai'); ?>
                </label>
                <input type="number" id="contai_internal_links_limit" name="limit"
                       value="50" min="1" max="100" class="contai-input" required>
                <p class="contai-help-text">
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e('Process internal links for the most recent published posts (1-100)', '1platform-content-ai'); ?>
                </p>
            </div>

            <?php wp_nonce_field('contai_internal_links_nonce', 'nonce'); ?>

            <div class="contai-button-group">
                <button type="submit" name="contai_enqueue_internal_links" class="button button-primary contai-button-action">
                    <span class="dashicons dashicons-admin-links"></span>
                    <span class="contai-button-text"><?php esc_html_e('Process Internal Links', '1platform-content-ai'); ?></span>
                </button>
                <button type="submit" name="contai_clear_internal_links_queue" class="button contai-button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all pending and processing jobs from the queue?', '1platform-content-ai'); ?>');">
                    <span class="dashicons dashicons-trash"></span>
                    <span class="contai-button-text"><?php esc_html_e('Clear Queue', '1platform-content-ai'); ?></span>
                </button>
            </div>
        </form>
        <?php
    }

    private function renderQueueStatus(array $status): void
    {
        ?>
        <div class="contai-queue-status-panel">
            <?php $this->renderStatCards($status); ?>
            <?php $this->renderProcessingJobs($status['processing_jobs'] ?? []); ?>
            <?php $this->renderRefreshNote(); ?>
        </div>
        <?php
    }

    private function renderStatCards(array $status): void
    {
        ?>
        <div class="contai-queue-stats">
            <div class="contai-stat-card">
                <div class="contai-stat-icon contai-stat-pending">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="contai-stat-content">
                    <div class="contai-stat-value">
                        <?php echo esc_html($status['pending']); ?>
                    </div>
                    <div class="contai-stat-label"><?php esc_html_e('Pending Jobs', '1platform-content-ai'); ?></div>
                </div>
            </div>

            <div class="contai-stat-card">
                <div class="contai-stat-icon contai-stat-processing">
                    <span class="dashicons dashicons-update-alt"></span>
                </div>
                <div class="contai-stat-content">
                    <div class="contai-stat-value">
                        <?php echo esc_html($status['processing']); ?>
                    </div>
                    <div class="contai-stat-label"><?php esc_html_e('Processing Now', '1platform-content-ai'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderProcessingJobs(array $jobs): void
    {
        ?>
        <div>
            <?php if (!empty($jobs)): ?>
                <div class="contai-processing-jobs">
                    <h3 class="contai-section-subtitle">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e('Currently Processing', '1platform-content-ai'); ?>
                    </h3>
                    <?php $this->renderJobsTable($jobs); ?>
                </div>
            <?php else: ?>
                <div class="contai-empty-state">
                    <span class="dashicons dashicons-editor-help"></span>
                    <p><?php esc_html_e('No jobs currently processing', '1platform-content-ai'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderJobsTable(array $jobs): void
    {
        ?>
        <table class="contai-table contai-processing-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Post Title', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Post ID', '1platform-content-ai'); ?></th>
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

    private function renderJobRow(array $job): void
    {
        $payload = json_decode($job['payload'], true);
        $post_id = $payload['post_id'] ?? 0;
        $post_title = $job['post_title'] ?? get_the_title($post_id) ?: __('Unknown Post', '1platform-content-ai');
        $processed_at = strtotime($job['processed_at']);
        $elapsed = human_time_diff($processed_at, current_time('timestamp'));
        ?>
        <tr>
            <td><strong><?php echo esc_html($post_title); ?></strong></td>
            <td><?php echo esc_html($post_id); ?></td>
            <td><span class="contai-processing-time"><?php echo esc_html($elapsed); ?></span></td>
        </tr>
        <?php
    }

    private function renderRefreshNote(): void
    {
        ?>
        <div class="contai-info-box" style="margin-top: 20px;">
            <span class="contai-info-icon">ℹ️</span>
            <p><?php esc_html_e('Refresh the page to see updated queue status and processing jobs.', '1platform-content-ai'); ?></p>
        </div>
        <?php
    }
}
