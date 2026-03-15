<?php

if (!defined('ABSPATH')) exit;

class ContaiSearchConsoleVerifiedSection
{
    private const NONCE_ACTION = 'contai_search_console_action';
    private const NONCE_FIELD = 'contai_search_console_nonce';

    private array $websiteConfig;

    public function __construct(array $websiteConfig)
    {
        $this->websiteConfig = $websiteConfig;
    }

    public function render(): void
    {
        ?>
        <div class="contai-settings-section contai-search-console-verified">
            <h3 class="contai-section-title">
                <span class="dashicons dashicons-cloud"></span>
                <?php esc_html_e('Search Console Integration', '1platform-content-ai'); ?>
            </h3>

            <?php $this->renderVerificationStatus(); ?>
            <?php $this->renderWebsiteInfo(); ?>
            <?php $this->renderSitemapsStatus(); ?>
            <?php $this->renderDeleteSection(); ?>
        </div>
        <?php
    }

    private function renderVerificationStatus(): void
    {
        ?>
        <div class="contai-verification-badge contai-status-verified">
            <span class="dashicons dashicons-yes-alt"></span>
            <div class="contai-badge-content">
                <span class="contai-badge-title"><?php esc_html_e('Website Verified', '1platform-content-ai'); ?></span>
                <span class="contai-badge-description"><?php esc_html_e('Your website is connected to Google Search Console', '1platform-content-ai'); ?></span>
            </div>
        </div>
        <?php
    }

    private function renderWebsiteInfo(): void
    {
        ?>
        <div class="contai-website-info">
            <h4 class="contai-section-subtitle">
                <span class="dashicons dashicons-admin-site-alt3"></span>
                <?php esc_html_e('Website Information', '1platform-content-ai'); ?>
            </h4>

            <div class="contai-info-grid">
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('URL', '1platform-content-ai'); ?></span>
                    <span class="contai-info-value"><?php echo esc_html($this->websiteConfig['url'] ?? ''); ?></span>
                </div>
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('Status', '1platform-content-ai'); ?></span>
                    <span class="contai-status-badge contai-status-active"><?php esc_html_e('Active', '1platform-content-ai'); ?></span>
                </div>
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('Website ID', '1platform-content-ai'); ?></span>
                    <span class="contai-info-value contai-text-mono"><?php echo esc_html($this->websiteConfig['websiteId'] ?? ''); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderSitemapsStatus(): void
    {
        $sitemaps = $this->websiteConfig['sitemaps'] ?? [];

        if (empty($sitemaps)) {
            return;
        }

        ?>
        <div class="contai-sitemaps-info">
            <h4 class="contai-section-subtitle">
                <span class="dashicons dashicons-networking"></span>
                <?php esc_html_e('Submitted Sitemaps', '1platform-content-ai'); ?>
            </h4>

            <table class="contai-table contai-sitemaps-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Sitemap URL', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Submitted At', '1platform-content-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sitemaps as $sitemap): ?>
                        <?php $this->renderSitemapRow($sitemap); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderSitemapRow(array $sitemap): void
    {
        $isSubmitted = $sitemap['submitted'] ?? false;
        $statusClass = $isSubmitted ? 'contai-status-active' : 'contai-status-inactive';
        $statusText = $isSubmitted ? __('Submitted', '1platform-content-ai') : __('Pending', '1platform-content-ai');
        $submittedAt = $sitemap['submitted_at'] ?? null;
        ?>
        <tr>
            <td>
                <span class="contai-sitemap-url"><?php echo esc_html($sitemap['url'] ?? ''); ?></span>
            </td>
            <td>
                <span class="contai-status-badge <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusText); ?>
                </span>
            </td>
            <td>
                <?php if ($submittedAt): ?>
                    <span class="contai-date"><?php echo esc_html($this->formatDate($submittedAt)); ?></span>
                <?php else: ?>
                    <span class="contai-text-muted">-</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function renderDeleteSection(): void
    {
        ?>
        <div class="contai-danger-zone">
            <h4 class="contai-section-subtitle contai-danger-title">
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e('Danger Zone', '1platform-content-ai'); ?>
            </h4>

            <!-- Disconnect Website -->
            <form method="post" class="contai-search-console-form" style="margin-bottom: 20px;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to disconnect this website? You will need to reconnect it later.', '1platform-content-ai')); ?>');">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <div class="contai-danger-content">
                    <div class="contai-danger-info">
                        <p class="contai-danger-description">
                            <?php esc_html_e('Disconnect this website from the plugin. This will remove the website from 1Platform and clear local connection data.', '1platform-content-ai'); ?>
                        </p>
                    </div>
                    <button type="submit" name="contai_disconnect_website" class="button button-secondary">
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php esc_html_e('Disconnect Website', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>

            <!-- Delete Website -->
            <form method="post" class="contai-search-console-form" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to delete this website from Content AI? This action cannot be undone.', '1platform-content-ai')); ?>');">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <div class="contai-danger-content">
                    <div class="contai-danger-info">
                        <p class="contai-danger-description">
                            <?php esc_html_e('Remove this website from Content AI network. This action cannot be undone.', '1platform-content-ai'); ?>
                        </p>
                    </div>
                    <button type="submit" name="contai_delete_website" class="button button-danger">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Delete Website', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    private function formatDate(string $dateString): string
    {
        $date = new DateTime($dateString);
        return $date->format(get_option('date_format') . ' ' . get_option('time_format'));
    }
}
