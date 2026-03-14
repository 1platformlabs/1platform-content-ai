<?php

if (!defined('ABSPATH')) exit;

class ContaiUserProfileSection
{
    private array $profile;
    private string $nonceAction;
    private string $nonceField;
    private ?array $websiteConfig;
    private bool $isConnected;

    public function __construct(
        array $profile,
        string $nonceAction,
        string $nonceField,
        ?array $websiteConfig = null,
        bool $isConnected = true
    ) {
        $this->profile = $profile;
        $this->nonceAction = $nonceAction;
        $this->nonceField = $nonceField;
        $this->websiteConfig = $websiteConfig;
        $this->isConnected = $isConnected;
    }

    public function render(): void
    {
        ?>
        <div class="contai-settings-panel contai-license-panel contai-license-active">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-superhero-alt"></span>
                        <?php esc_html_e('Content AI License', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php if ($this->isConnected) : ?>
                            <?php esc_html_e('Your license is active and connected to Content AI', '1platform-content-ai'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Your license is active but the connection could not be verified', '1platform-content-ai'); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <?php $this->renderLicenseStatus(); ?>
                <?php $this->renderUserInfo(); ?>
                <?php $this->renderActions(); ?>
            </div>
        </div>
        <?php
    }

    private function renderLicenseStatus(): void
    {
        $isActive = ($this->profile['status'] ?? '') === 'active';
        $statusClass = $isActive ? 'contai-status-active' : 'contai-status-inactive';
        $statusText = $isActive ? __('Active', '1platform-content-ai') : __('Inactive', '1platform-content-ai');

        $connectionClass = $this->isConnected ? 'contai-status-active' : 'contai-status-inactive';
        $connectionText = $this->isConnected ? __('Connected', '1platform-content-ai') : __('Disconnected', '1platform-content-ai');
        $connectionIcon = $this->isConnected ? 'yes-alt' : 'warning';
        ?>
        <div class="contai-license-status-card <?php echo esc_attr($statusClass); ?>">
            <div class="contai-status-icon">
                <span class="dashicons dashicons-<?php echo esc_attr( $isActive ? 'yes-alt' : 'warning' ); ?>"></span>
            </div>
            <div class="contai-status-content">
                <span class="contai-status-label"><?php esc_html_e('License Status', '1platform-content-ai'); ?></span>
                <span class="contai-status-value"><?php echo esc_html($statusText); ?></span>
            </div>
        </div>
        <div class="contai-license-status-card <?php echo esc_attr($connectionClass); ?>">
            <div class="contai-status-icon">
                <span class="dashicons dashicons-<?php echo esc_attr($connectionIcon); ?>"></span>
            </div>
            <div class="contai-status-content">
                <span class="contai-status-label"><?php esc_html_e('API Connection', '1platform-content-ai'); ?></span>
                <span class="contai-status-value"><?php echo esc_html($connectionText); ?></span>
            </div>
        </div>
        <?php
    }

    private function renderUserInfo(): void
    {
        ?>
        <div class="contai-user-info-section">
            <h3 class="contai-section-subtitle">
                <span class="dashicons dashicons-admin-users"></span>
                <?php esc_html_e('Account Information', '1platform-content-ai'); ?>
            </h3>

            <div class="contai-info-grid">
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('User ID', '1platform-content-ai'); ?></span>
                    <span class="contai-info-value contai-text-mono"><?php echo esc_html($this->profile['userId'] ?? '-'); ?></span>
                </div>
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('Username', '1platform-content-ai'); ?></span>
                    <span class="contai-info-value"><?php echo esc_html($this->profile['username'] ?? '-'); ?></span>
                </div>
                <div class="contai-info-item">
                    <span class="contai-info-label"><?php esc_html_e('Account Status', '1platform-content-ai'); ?></span>
                    <span class="contai-status-badge <?php echo esc_attr( ($this->profile['status'] ?? '') === 'active' ? 'contai-status-active' : 'contai-status-inactive' ); ?>">
                        <?php echo esc_html(ucfirst($this->profile['status'] ?? 'unknown')); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderActions(): void
    {
        $hasWebsite = $this->websiteConfig && !empty($this->websiteConfig['websiteId']);
        ?>
        <div class="contai-license-actions">
            <form method="post" class="contai-license-form">
                <?php wp_nonce_field($this->nonceAction, $this->nonceField); ?>

                <div class="contai-actions-row">
                    <button type="submit" name="contai_refresh_profile" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Refresh Profile', '1platform-content-ai'); ?>
                    </button>
                    <button type="submit" name="contai_refresh_tokens" class="button button-secondary">
                        <span class="dashicons dashicons-superhero"></span>
                        <?php esc_html_e('Refresh Tokens', '1platform-content-ai'); ?>
                    </button>
                </div>

                <div class="contai-danger-zone">
                    <h4 class="contai-danger-title">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Danger Zone', '1platform-content-ai'); ?>
                    </h4>

                    <div class="contai-danger-content">
                        <p class="contai-danger-description">
                            <?php esc_html_e('Deactivating your license will disconnect this site from Content AI services. Your website data will be preserved on the server.', '1platform-content-ai'); ?>
                        </p>
                        <button type="submit" name="contai_deactivate_license" class="button button-danger"
                                onclick="return confirm('<?php echo esc_js(__('Are you sure you want to deactivate your license? This will disconnect your site from Content AI services.', '1platform-content-ai')); ?>');">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e('Deactivate License', '1platform-content-ai'); ?>
                        </button>
                    </div>

                    <?php if ($hasWebsite) : ?>
                    <hr class="contai-danger-separator" />
                    <div class="contai-danger-content">
                        <p class="contai-danger-description">
                            <?php esc_html_e('Permanently delete this website from Content AI servers. This action cannot be undone.', '1platform-content-ai'); ?>
                        </p>
                        <button type="submit" name="contai_delete_website" class="button button-danger"
                                onclick="return confirm('<?php echo esc_js(__('Are you sure you want to permanently delete this website? This action cannot be undone and will remove all website data from Content AI servers.', '1platform-content-ai')); ?>');">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Delete Website', '1platform-content-ai'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
    }
}
