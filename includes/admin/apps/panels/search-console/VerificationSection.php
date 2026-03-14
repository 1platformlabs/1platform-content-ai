<?php

if (!defined('ABSPATH')) exit;

class ContaiSearchConsoleVerificationSection
{
    private const NONCE_ACTION = 'contai_search_console_action';
    private const NONCE_FIELD = 'contai_search_console_nonce';

    private array $websiteConfig;
    private bool $verificationFileExists;

    public function __construct(array $websiteConfig, bool $verificationFileExists)
    {
        $this->websiteConfig = $websiteConfig;
        $this->verificationFileExists = $verificationFileExists;
    }

    public function render(): void
    {
        ?>
        <div class="contai-settings-section contai-search-console-verification">
            <h3 class="contai-section-title">
                <span class="dashicons dashicons-shield"></span>
                <?php esc_html_e('Website Verification Required', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-info-box contai-info-box-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <p><strong><?php esc_html_e('Your website needs to be verified with Google Search Console.', '1platform-content-ai'); ?></strong></p>
                    <p><?php esc_html_e('Follow the instructions below to complete the verification process.', '1platform-content-ai'); ?></p>
                </div>
            </div>

            <div class="contai-verification-steps">
                <h4 class="contai-section-subtitle">
                    <span class="dashicons dashicons-editor-ol"></span>
                    <?php esc_html_e('Verification Steps', '1platform-content-ai'); ?>
                </h4>

                <div class="contai-step">
                    <div class="contai-step-number">1</div>
                    <div class="contai-step-content">
                        <p class="contai-step-title"><?php esc_html_e('Create the verification file', '1platform-content-ai'); ?></p>
                        <p class="contai-step-description">
                            <?php
                            printf(
                                /* translators: %s: verification file name wrapped in code tags */
                                esc_html__('Create a file named %s in your website root directory with the following content:', '1platform-content-ai'),
                                '<code>' . esc_html($this->websiteConfig['file_name']) . '</code>'
                            );
                            ?>
                        </p>
                        <div class="contai-code-block">
                            <code><?php echo esc_html($this->websiteConfig['file_content']); ?></code>
                            <button type="button" class="contai-copy-btn" data-copy="<?php echo esc_attr($this->websiteConfig['file_content']); ?>">
                                <span class="dashicons dashicons-admin-page"></span>
                            </button>
                        </div>

                        <?php $this->renderFileStatus(); ?>

                        <form method="post" class="contai-search-console-form-inline">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                            <button type="submit" name="contai_create_verification_file" class="button button-secondary">
                                <span class="dashicons dashicons-media-code"></span>
                                <?php esc_html_e('Create File Automatically', '1platform-content-ai'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="contai-step">
                    <div class="contai-step-number">2</div>
                    <div class="contai-step-content">
                        <p class="contai-step-title"><?php esc_html_e('Verify your website', '1platform-content-ai'); ?></p>
                        <p class="contai-step-description">
                            <?php esc_html_e('Once the file is in place, click the button below to verify your website.', '1platform-content-ai'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <form method="post" class="contai-search-console-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <div class="contai-form-actions">
                    <button type="submit" name="contai_verify_website" class="button button-primary">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Verify Website', '1platform-content-ai'); ?>
                    </button>
                </div>
            </form>

            <?php $this->renderDeleteSection(); ?>
        </div>
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
                            <?php esc_html_e('Disconnect this website from the plugin. This will only remove the local connection data.', '1platform-content-ai'); ?>
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

    private function renderFileStatus(): void
    {
        $statusClass = $this->verificationFileExists ? 'contai-status-success' : 'contai-status-warning';
        $statusIcon = $this->verificationFileExists ? 'dashicons-yes-alt' : 'dashicons-warning';
        $statusText = $this->verificationFileExists
            ? __('Verification file exists', '1platform-content-ai')
            : __('Verification file not found', '1platform-content-ai');
        ?>
        <div class="contai-file-status <?php echo esc_attr($statusClass); ?>">
            <span class="dashicons <?php echo esc_attr($statusIcon); ?>"></span>
            <span><?php echo esc_html($statusText); ?></span>
        </div>
        <?php
    }
}
