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
                <?php esc_html_e('Connect to Search Console', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-info-box contai-info-box-info">
                <span class="dashicons dashicons-info-outline"></span>
                <div>
                    <p><?php esc_html_e('Your website has not been added to Google Search Console yet.', '1platform-content-ai'); ?></p>
                    <p><?php esc_html_e('Click the button below to automatically register, verify, and submit your sitemaps in one step.', '1platform-content-ai'); ?></p>
                </div>
            </div>

            <div class="contai-website-preview">
                <div class="contai-preview-item">
                    <span class="contai-preview-label"><?php esc_html_e('Website URL:', '1platform-content-ai'); ?></span>
                    <span class="contai-preview-value"><?php echo esc_html($this->siteUrl); ?></span>
                </div>
                <?php if (!empty($this->sitemaps)): ?>
                <div class="contai-preview-item">
                    <span class="contai-preview-label"><?php esc_html_e('Sitemaps to submit:', '1platform-content-ai'); ?></span>
                    <ul class="contai-sitemaps-list">
                        <?php foreach ($this->sitemaps as $sitemap): ?>
                            <li><?php echo esc_html($sitemap); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <div class="contai-setup-steps">
                <p class="contai-setup-steps-title"><?php esc_html_e('This will automatically:', '1platform-content-ai'); ?></p>
                <ol>
                    <li><?php esc_html_e('Register your site with Google Search Console', '1platform-content-ai'); ?></li>
                    <li><?php esc_html_e('Create the verification file', '1platform-content-ai'); ?></li>
                    <li><?php esc_html_e('Verify your website ownership', '1platform-content-ai'); ?></li>
                    <li><?php esc_html_e('Submit your sitemaps', '1platform-content-ai'); ?></li>
                </ol>
            </div>

            <form method="post" class="contai-search-console-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <div class="contai-form-actions">
                    <button type="submit" name="contai_setup_search_console" class="button button-primary button-large">
                        <span class="dashicons dashicons-cloud-saved"></span>
                        <?php esc_html_e('Connect to Search Console', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
