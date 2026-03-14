<?php

if (!defined('ABSPATH')) exit;

class ContaiSearchConsoleAddWebsiteSection
{
    private const NONCE_ACTION = 'contai_search_console_action';
    private const NONCE_FIELD = 'contai_search_console_nonce';

    private string $siteUrl;
    private array $sitemaps;

    public function __construct(string $siteUrl, array $sitemaps)
    {
        $this->siteUrl = $siteUrl;
        $this->sitemaps = $sitemaps;
    }

    public function render(): void
    {
        ?>
        <div class="contai-settings-section contai-search-console-add">
            <h3 class="contai-section-title">
                <span class="dashicons dashicons-admin-site-alt3"></span>
                <?php esc_html_e('Add Website to Search Console', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-info-box contai-info-box-info">
                <span class="dashicons dashicons-info-outline"></span>
                <div>
                    <p><?php esc_html_e('Your website has not been added to Google Search Console yet.', '1platform-content-ai'); ?></p>
                    <p><?php esc_html_e('Click the button below to add your site to Search Console and start the verification process.', '1platform-content-ai'); ?></p>
                </div>
            </div>

            <div class="contai-website-preview">
                <div class="contai-preview-item">
                    <span class="contai-preview-label"><?php esc_html_e('Website URL:', '1platform-content-ai'); ?></span>
                    <span class="contai-preview-value"><?php echo esc_html($this->siteUrl); ?></span>
                </div>
                <div class="contai-preview-item">
                    <span class="contai-preview-label"><?php esc_html_e('Sitemaps to submit:', '1platform-content-ai'); ?></span>
                    <ul class="contai-sitemaps-list">
                        <?php foreach ($this->sitemaps as $sitemap): ?>
                            <li><?php echo esc_html($sitemap); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <form method="post" class="contai-search-console-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <div class="contai-form-actions">
                    <button type="submit" name="contai_add_website" class="button button-primary button-large">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Add to Search Console', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
