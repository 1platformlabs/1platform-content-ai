<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/jobs/QueueManager.php';
require_once __DIR__ . '/../../../services/billing/CreditGuard.php';

class ContaiPostGeneratorPanel {

    private $queueManager;

    public function __construct() {
        $this->queueManager = new ContaiQueueManager();
    }

    public function render(): void {
        $this->renderAdminNotices();

        $creditGuard = new ContaiCreditGuard();
        $creditCheck = $creditGuard->validateCredits();

        if ( ! $creditCheck['has_credits'] ) : ?>
            <div class="notice notice-warning" style="margin-bottom: 15px;">
                <p>
                    <strong><?php esc_html_e( 'Insufficient Balance', '1platform-content-ai' ); ?></strong> —
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
            </div>
        <?php endif;

        $status = $this->queueManager->getQueueStatus();
        ?>
        <div class="contai-post-generator-container">

            <div class="contai-settings-panel contai-panel-post-generator">
                <div class="contai-panel-body">
                    <form method="post" class="contai-post-generator-form">

                        <div class="contai-form-group">
                            <label for="contai_content_lang" class="contai-label">
                                <span class="dashicons dashicons-location-alt"></span>
                                <?php esc_html_e('Target Language', '1platform-content-ai'); ?>
                            </label>
                            <select id="contai_content_lang" name="content_lang" required class="contai-select">
                                <option value="en"><?php esc_html_e('English', '1platform-content-ai'); ?></option>
                                <option value="es"><?php esc_html_e('Spanish', '1platform-content-ai'); ?></option>
                            </select>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Language code for content generation (e.g., en, es, fr)', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_content_country" class="contai-label">
                                <span class="dashicons dashicons-location-alt"></span>
                                <?php esc_html_e('Target Country', '1platform-content-ai'); ?>
                            </label>
                            <select id="contai_content_country" name="content_country" required class="contai-select">
                                <option value="us"><?php esc_html_e('United States', '1platform-content-ai'); ?></option>
                                <option value="es"><?php esc_html_e('Spain', '1platform-content-ai'); ?></option>
                            </select>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Country code for content generation (e.g., us, es, fr)', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_image_provider" class="contai-label">
                                <span class="dashicons dashicons-format-image"></span>
                                <?php esc_html_e('Image Provider', '1platform-content-ai'); ?>
                            </label>
                            <select id="contai_image_provider" name="image_provider" class="contai-input" required>
                                <option value="pexels" <?php selected(get_option('contai_image_provider', 'pexels'), 'pexels'); ?>>Pexels</option>
                                <option value="pixabay" <?php selected(get_option('contai_image_provider', 'pexels'), 'pixabay'); ?>>Pixabay</option>
                            </select>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Select the image provider for content generation', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <div class="contai-form-group">
                            <label for="contai_post_count" class="contai-label">
                                <span class="dashicons dashicons-list-view"></span>
                                <?php esc_html_e('Number of Posts to Generate', '1platform-content-ai'); ?>
                            </label>
                            <input type="number" id="contai_post_count" name="post_count" value="1" min="1" max="100" class="contai-input" required>
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Enter how many posts you want to add to the queue (1-100)', '1platform-content-ai'); ?>
                            </p>
                        </div>

                        <?php wp_nonce_field('contai_post_generator_nonce', 'contai_post_generator_nonce'); ?>

                        <div class="contai-button-group">
                            <button type="submit" name="contai_enqueue_posts" class="button button-primary contai-button-action" <?php echo ! $creditCheck['has_credits'] ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-edit"></span>
                                <span class="contai-button-text"><?php esc_html_e('Add to Queue', '1platform-content-ai'); ?></span>
                            </button>
                            <button type="submit" name="contai_clear_queue" class="button contai-button-secondary" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all pending and processing jobs from the queue?', '1platform-content-ai'); ?>');">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="contai-button-text"><?php esc_html_e('Clear Queue', '1platform-content-ai'); ?></span>
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
                            <?php esc_html_e('Current status of the post generation queue. Refresh the page to see updates.', '1platform-content-ai'); ?>
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

    private function renderProcessingTable(array $jobs): void {
        ?>
        <table class="contai-table contai-processing-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('ContaiKeyword', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Title', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Volume', '1platform-content-ai'); ?></th>
                    <th><?php esc_html_e('Processing Time', '1platform-content-ai'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><strong><?php echo esc_html($job['keyword']); ?></strong></td>
                        <td><?php echo esc_html($job['title']); ?></td>
                        <td><?php echo esc_html(number_format($job['volume'])); ?></td>
                        <td>
                            <span class="contai-processing-time">
                                <?php echo esc_html($this->calculateElapsedTime($job['processed_at'])); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
