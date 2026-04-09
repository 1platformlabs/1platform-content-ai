<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Publisuites "Connected" state — compact collapsible settings section.
 *
 * Renders a <details> block with connection info and disconnect action.
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
        $config = $this->view_data['config'];
        $confirm_message = esc_js(
            __('Are you sure you want to disconnect from the marketplace? You will need to reconnect later.', '1platform-content-ai')
        );
        ?>
        <details class="contai-ps-settings" style="margin-top: 24px;">
            <summary class="contai-ps-settings__summary">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <?php esc_html_e('Connection Settings', '1platform-content-ai'); ?>
                <span class="contai-ps-inline-badge contai-ps-inline-badge--active" style="margin-left: 8px; font-size: 11px;">
                    <?php esc_html_e('Active', '1platform-content-ai'); ?>
                </span>
            </summary>
            <div class="contai-ps-settings__body">
                <dl class="contai-ps-info-list" style="margin-bottom: 16px;">
                    <div class="contai-ps-info-list__row">
                        <dt><?php esc_html_e('Marketplace ID', '1platform-content-ai'); ?></dt>
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
                        <dt><?php esc_html_e('Marketplace Status', '1platform-content-ai'); ?></dt>
                        <dd>
                            <span class="contai-badge contai-badge--success">
                                &#10003; <?php esc_html_e('Approved', '1platform-content-ai'); ?>
                            </span>
                        </dd>
                    </div>
                    <div class="contai-ps-info-list__row">
                        <dt><?php esc_html_e('Site URL', '1platform-content-ai'); ?></dt>
                        <dd class="contai-ps-mono"><?php echo esc_html($this->view_data['site_url']); ?></dd>
                    </div>
                </dl>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php if (!empty($this->view_data['secondary_cta_action'])) : ?>
                        <form method="post" class="contai-ps-form" data-confirm="<?php echo esc_attr($confirm_message); ?>">
                            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                            <button
                                type="submit"
                                name="<?php echo esc_attr($this->view_data['secondary_cta_action']); ?>"
                                class="button button-secondary contai-ps-btn--danger"
                                aria-label="<?php esc_attr_e('Disconnect from the marketplace', '1platform-content-ai'); ?>"
                            >
                                <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                                <?php echo esc_html($this->view_data['secondary_cta_label']); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="contai-ps-form">
                        <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                        <button
                            type="submit"
                            name="contai_delete_from_marketplace"
                            class="button button-secondary contai-ps-btn--danger"
                            onclick="return confirm('<?php echo esc_js(__('Are you sure? This will remove the website from the marketplace and delete all synced orders.', '1platform-content-ai')); ?>');"
                        >
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            <?php esc_html_e('Remove from Marketplace', '1platform-content-ai'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </details>
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
