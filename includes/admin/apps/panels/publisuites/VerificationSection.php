<?php

if (!defined('ABSPATH')) exit;

/**
 * Publisuites "Pending Verification" state section.
 *
 * Renders the two-step verification flow: create verification file,
 * then verify the website.
 *
 * Receives a fully-resolved $view_data array — no decision logic here.
 */
class ContaiPublisuitesVerificationSection
{
    private array $view_data;

    public function __construct(array $view_data)
    {
        $this->view_data = $view_data;
    }

    public function render(): void
    {
        ?>
        <div class="contai-ps" data-status="<?php echo esc_attr($this->view_data['status_key']); ?>">

            <?php $this->renderStatusBadge(); ?>

            <div class="contai-ps-hero">
                <p class="contai-ps-hero__text">
                    <?php esc_html_e('Your website needs to be verified. Follow the steps below to complete the verification process.', '1platform-content-ai'); ?>
                </p>
            </div>

            <?php $this->renderSteps(); ?>
            <?php $this->renderVerifyForm(); ?>
            <?php $this->renderDangerZone(); ?>

        </div>
        <?php
    }

    private function renderStatusBadge(): void
    {
        ?>
        <div class="contai-ps-status <?php echo esc_attr($this->view_data['status_class']); ?>" role="status">
            <span class="contai-ps-status__dot" aria-hidden="true"></span>
            <span class="contai-ps-status__label"><?php echo esc_html($this->view_data['status_label']); ?></span>
        </div>
        <?php
    }

    private function renderSteps(): void
    {
        $config = $this->view_data['config'];
        ?>
        <div class="contai-ps-steps">
            <h3 class="contai-ps-section-title">
                <span class="dashicons dashicons-editor-ol" aria-hidden="true"></span>
                <?php esc_html_e('Verification Steps', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-ps-steps__list">
                <?php $this->renderStep1($config); ?>
                <?php $this->renderStep2(); ?>
            </div>
        </div>
        <?php
    }

    private function renderStep1(array $config): void
    {
        ?>
        <div class="contai-ps-card contai-ps-card--step">
            <div class="contai-ps-step-number" aria-hidden="true">1</div>
            <div class="contai-ps-step-body">
                <h4 class="contai-ps-card__title"><?php esc_html_e('Create the verification file', '1platform-content-ai'); ?></h4>
                <p class="contai-ps-card__desc">
                    <?php
                    printf(
                        /* translators: %s: verification file name */
                        esc_html__('Create a file named %s in your website root directory.', '1platform-content-ai'),
                        '<code class="contai-ps-mono">' . esc_html($config['verificationFileName'] ?? '') . '</code>'
                    );
                    ?>
                </p>

                <?php if (!empty($config['verificationFileContent'])) : ?>
                    <div class="contai-ps-code-block">
                        <code><?php echo esc_html($config['verificationFileContent']); ?></code>
                        <button
                            type="button"
                            class="contai-ps-copy-btn"
                            data-copy="<?php echo esc_attr($config['verificationFileContent']); ?>"
                            aria-label="<?php esc_attr_e('Copy verification file content to clipboard', '1platform-content-ai'); ?>"
                        >
                            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                        </button>
                    </div>
                <?php else : ?>
                    <p class="contai-ps-help"><?php esc_html_e('The verification file should be empty.', '1platform-content-ai'); ?></p>
                <?php endif; ?>

                <?php $this->renderFileStatus(); ?>

                <form method="post" class="contai-ps-form contai-ps-form--inline">
                    <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                    <button
                        type="submit"
                        name="contai_create_verification_file"
                        class="button button-secondary"
                        aria-label="<?php esc_attr_e('Automatically create verification file', '1platform-content-ai'); ?>"
                    >
                        <span class="dashicons dashicons-media-code" aria-hidden="true"></span>
                        <?php esc_html_e('Create File Automatically', '1platform-content-ai'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    private function renderStep2(): void
    {
        ?>
        <div class="contai-ps-card contai-ps-card--step">
            <div class="contai-ps-step-number" aria-hidden="true">2</div>
            <div class="contai-ps-step-body">
                <h4 class="contai-ps-card__title"><?php esc_html_e('Verify your website', '1platform-content-ai'); ?></h4>
                <p class="contai-ps-card__desc">
                    <?php esc_html_e('Once the file is in place, click the button below to verify your website.', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderFileStatus(): void
    {
        $exists      = $this->view_data['verification_file_exists'];
        $statusClass = $exists ? 'contai-ps-file-status--ok' : 'contai-ps-file-status--missing';
        $icon        = $exists ? 'dashicons-yes-alt' : 'dashicons-warning';
        $text        = $exists
            ? __('Verification file exists', '1platform-content-ai')
            : __('Verification file not found', '1platform-content-ai');
        ?>
        <div class="contai-ps-file-status <?php echo esc_attr($statusClass); ?>" role="status" aria-live="polite">
            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <span><?php echo esc_html($text); ?></span>
        </div>
        <?php
    }

    private function renderVerifyForm(): void
    {
        if (empty($this->view_data['primary_cta_action'])) {
            return;
        }
        ?>
        <form method="post" class="contai-ps-form contai-ps-form--primary">
            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
            <button
                type="submit"
                name="<?php echo esc_attr($this->view_data['primary_cta_action']); ?>"
                class="button button-primary contai-ps-cta"
                aria-label="<?php esc_attr_e('Verify your website ownership', '1platform-content-ai'); ?>"
            >
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                <?php echo esc_html($this->view_data['primary_cta_label']); ?>
            </button>
        </form>
        <?php
    }

    private function renderDangerZone(): void
    {
        if (empty($this->view_data['secondary_cta_action'])) {
            return;
        }

        $confirm_message = esc_js(
            __('Are you sure you want to disconnect from the marketplace? You will need to reconnect later.', '1platform-content-ai')
        );
        ?>
        <div class="contai-ps-danger">
            <h4 class="contai-ps-danger__title">
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php esc_html_e('Danger Zone', '1platform-content-ai'); ?>
            </h4>
            <form method="post" class="contai-ps-form" data-confirm="<?php echo esc_attr($confirm_message); ?>">
                <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                <div class="contai-ps-danger__row">
                    <p class="contai-ps-danger__desc">
                        <?php esc_html_e('Disconnect from the marketplace. This will only remove the local connection data.', '1platform-content-ai'); ?>
                    </p>
                    <button
                        type="submit"
                        name="<?php echo esc_attr($this->view_data['secondary_cta_action']); ?>"
                        class="button button-secondary contai-ps-btn--danger"
                        aria-label="<?php esc_attr_e('Disconnect from the marketplace', '1platform-content-ai'); ?>"
                    >
                        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                        <?php echo esc_html($this->view_data['secondary_cta_label']); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
