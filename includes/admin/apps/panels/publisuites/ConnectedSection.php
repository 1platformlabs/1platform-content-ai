<?php

if (!defined('ABSPATH')) exit;

/**
 * Publisuites "Connected" (verified) state section.
 *
 * Renders the success view with verification badge, integration info,
 * and disconnect danger zone.
 *
 * Receives a fully-resolved $view_data array — no decision logic here.
 */
class ContaiPublisuitesConnectedSection
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
            <?php $this->renderSuccessCard(); ?>
            <?php $this->renderInfoCard(); ?>
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

    private function renderSuccessCard(): void
    {
        ?>
        <div class="contai-ps-card contai-ps-card--success">
            <span class="dashicons dashicons-yes-alt contai-ps-card--success__icon" aria-hidden="true"></span>
            <div>
                <h3 class="contai-ps-card__title"><?php esc_html_e('Website Verified', '1platform-content-ai'); ?></h3>
                <p class="contai-ps-card__desc">
                    <?php esc_html_e('Your website is connected to Publisuites and ready to receive sponsored content opportunities.', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderInfoCard(): void
    {
        $config = $this->view_data['config'];
        ?>
        <div class="contai-ps-card contai-ps-card--info">
            <h3 class="contai-ps-card__header">
                <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                <?php esc_html_e('Integration Details', '1platform-content-ai'); ?>
            </h3>
            <dl class="contai-ps-info-list">
                <div class="contai-ps-info-list__row">
                    <dt><?php esc_html_e('Status', '1platform-content-ai'); ?></dt>
                    <dd>
                        <span class="contai-ps-inline-badge contai-ps-inline-badge--active">
                            <?php esc_html_e('Active', '1platform-content-ai'); ?>
                        </span>
                    </dd>
                </div>
                <div class="contai-ps-info-list__row">
                    <dt><?php esc_html_e('Publisuites ID', '1platform-content-ai'); ?></dt>
                    <dd class="contai-ps-mono"><?php echo esc_html($config['publisuitesId'] ?? '—'); ?></dd>
                </div>
                <?php if (!empty($config['verifiedAt'])) : ?>
                    <div class="contai-ps-info-list__row">
                        <dt><?php esc_html_e('Verified At', '1platform-content-ai'); ?></dt>
                        <dd>
                            <time datetime="<?php echo esc_attr($config['verifiedAt']); ?>">
                                <?php echo esc_html($this->formatDate($config['verifiedAt'])); ?>
                            </time>
                        </dd>
                    </div>
                <?php endif; ?>
                <div class="contai-ps-info-list__row">
                    <dt><?php esc_html_e('Site URL', '1platform-content-ai'); ?></dt>
                    <dd class="contai-ps-mono"><?php echo esc_html($this->view_data['site_url']); ?></dd>
                </div>
            </dl>
        </div>
        <?php
    }

    private function renderDangerZone(): void
    {
        if (empty($this->view_data['secondary_cta_action'])) {
            return;
        }

        $confirm_message = esc_js(
            __('Are you sure you want to disconnect from Publisuites? You will need to reconnect later.', '1platform-content-ai')
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
                        <?php esc_html_e('Disconnect from Publisuites. This will only remove the local connection data.', '1platform-content-ai'); ?>
                    </p>
                    <button
                        type="submit"
                        name="<?php echo esc_attr($this->view_data['secondary_cta_action']); ?>"
                        class="button button-secondary contai-ps-btn--danger"
                        aria-label="<?php esc_attr_e('Disconnect from Publisuites', '1platform-content-ai'); ?>"
                    >
                        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                        <?php echo esc_html($this->view_data['secondary_cta_label']); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    private function formatDate(string $dateString): string
    {
        try {
            $date = new DateTime($dateString);
            return $date->format(get_option('date_format') . ' ' . get_option('time_format'));
        } catch (Exception $e) {
            return $dateString;
        }
    }
}
