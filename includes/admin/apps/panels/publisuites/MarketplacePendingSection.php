<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Publisuites "Marketplace Pending" state — website verified but awaiting marketplace approval.
 *
 * Shows status info, Sync Now button (to check approval), and Remove from Marketplace button.
 */
class ContaiPublisuitesMarketplacePendingSection
{
    private array $view_data;

    public function __construct(array $view_data)
    {
        $this->view_data = $view_data;
    }

    public function render(): void
    {
        $config = $this->view_data['config'];
        $publisuites_id = esc_html($config['publisuitesId'] ?? '—');
        $site_url = esc_html($this->view_data['site_url']);
        $verified_at = !empty($config['verifiedAt']) ? esc_html($this->formatDate($config['verifiedAt'])) : '—';
        $confirm_remove = esc_js(
            __('Are you sure? This will remove the website from the marketplace and delete all synced orders.', '1platform-content-ai')
        );
        ?>
        <div class="contai-ps-marketplace-pending" style="margin-top: 16px;">
            <div class="contai-ps-notice contai-ps-notice--warning" style="margin-bottom: 20px; padding: 16px; border-left: 4px solid #dba617; background: #fff8e1; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; font-weight: 600;">
                    &#9203; <?php esc_html_e('Marketplace Status: Pending Approval', '1platform-content-ai'); ?>
                </p>
                <p style="margin: 0; color: #555;">
                    <?php esc_html_e('Your website has been submitted and is under review by the marketplace administrators. This usually takes 24-48 hours.', '1platform-content-ai'); ?>
                </p>
            </div>

            <details class="contai-ps-settings" open>
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
                            <dd class="contai-ps-mono"><?php echo $publisuites_id; ?></dd>
                        </div>
                        <div class="contai-ps-info-list__row">
                            <dt><?php esc_html_e('Site URL', '1platform-content-ai'); ?></dt>
                            <dd class="contai-ps-mono"><?php echo $site_url; ?></dd>
                        </div>
                        <div class="contai-ps-info-list__row">
                            <dt><?php esc_html_e('Status', '1platform-content-ai'); ?></dt>
                            <dd>
                                <span class="contai-badge contai-badge--warning">
                                    &#9203; <?php esc_html_e('Pending Approval', '1platform-content-ai'); ?>
                                </span>
                            </dd>
                        </div>
                        <div class="contai-ps-info-list__row">
                            <dt><?php esc_html_e('Submitted', '1platform-content-ai'); ?></dt>
                            <dd><?php echo $verified_at; ?></dd>
                        </div>
                    </dl>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <form method="post" class="contai-ps-form">
                            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                            <button
                                type="submit"
                                name="contai_sync_publisuites"
                                class="button button-primary"
                            >
                                <span class="dashicons dashicons-update" aria-hidden="true" style="margin-top: 3px;"></span>
                                <?php esc_html_e('Sync Now', '1platform-content-ai'); ?>
                            </button>
                        </form>
                        <form method="post" class="contai-ps-form">
                            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                            <button
                                type="submit"
                                name="contai_delete_from_marketplace"
                                class="button button-secondary contai-ps-btn--danger"
                                onclick="return confirm('<?php echo $confirm_remove; ?>');"
                            >
                                <span class="dashicons dashicons-trash" aria-hidden="true" style="margin-top: 3px;"></span>
                                <?php esc_html_e('Remove from Marketplace', '1platform-content-ai'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </details>

            <p class="description" style="margin-top: 12px; color: #666;">
                <?php esc_html_e('Once approved, your website will start receiving sponsored post orders from the marketplace.', '1platform-content-ai'); ?>
            </p>
        </div>
        <?php
    }

    private function formatDate(string $dateString): string
    {
        try {
            $date = new DateTime($dateString);
            return $date->format(get_option('date_format'));
        } catch (Exception $e) {
            return $dateString;
        }
    }
}
