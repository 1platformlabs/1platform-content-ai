<?php

if (!defined('ABSPATH')) exit;

/**
 * Publisuites "Not Connected" state section.
 *
 * Renders the initial connection screen with benefits grid,
 * website information card, and connect CTA.
 *
 * Receives a fully-resolved $view_data array from ContaiPublisuitesPanel — no
 * decision logic lives here; it only echoes escaped values.
 */
class ContaiPublisuitesConnectSection
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
                    <?php esc_html_e('Connect your website to Publisuites to unlock sponsored content opportunities and start earning revenue from your posts.', '1platform-content-ai'); ?>
                </p>
            </div>

            <?php $this->renderBenefitsGrid(); ?>
            <?php $this->renderWebsiteCard(); ?>
            <?php $this->renderConnectForm(); ?>

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

    private function renderBenefitsGrid(): void
    {
        ?>
        <div class="contai-ps-benefits">
            <h3 class="contai-ps-section-title">
                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                <?php esc_html_e('Why Connect to Publisuites?', '1platform-content-ai'); ?>
            </h3>
            <div class="contai-ps-benefits__grid" role="list">
                <?php foreach ($this->view_data['benefits'] as $benefit) : ?>
                    <article class="contai-ps-card contai-ps-card--benefit" role="listitem">
                        <div class="contai-ps-card__icon" aria-hidden="true">
                            <span class="dashicons <?php echo esc_attr($benefit['icon']); ?>"></span>
                        </div>
                        <h4 class="contai-ps-card__title"><?php echo esc_html($benefit['title']); ?></h4>
                        <p class="contai-ps-card__desc"><?php echo esc_html($benefit['description']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function renderWebsiteCard(): void
    {
        ?>
        <div class="contai-ps-card contai-ps-card--info">
            <h3 class="contai-ps-card__header">
                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                <?php esc_html_e('Website Information', '1platform-content-ai'); ?>
            </h3>
            <dl class="contai-ps-info-list">
                <div class="contai-ps-info-list__row">
                    <dt><?php esc_html_e('Site URL', '1platform-content-ai'); ?></dt>
                    <dd class="contai-ps-mono"><?php echo esc_html($this->view_data['site_url']); ?></dd>
                </div>
            </dl>
        </div>
        <?php
    }

    private function renderConnectForm(): void
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
                class="button button-primary button-hero contai-ps-cta"
                aria-label="<?php esc_attr_e('Connect your website to Publisuites', '1platform-content-ai'); ?>"
            >
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <?php echo esc_html($this->view_data['primary_cta_label']); ?>
            </button>
        </form>
        <?php
    }
}
