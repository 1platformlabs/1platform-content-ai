<?php

if (!defined('ABSPATH')) exit;

class ContaiBillingSetupNotice
{
    public static function render(): void
    {
        $licenses_url = admin_url('admin.php?page=contai-licenses');
        ?>
        <div class="contai-setup-notice">
            <div class="contai-setup-notice-card">
                <div class="contai-setup-notice-icon-wrapper">
                    <div class="contai-setup-notice-icon">
                        <span class="dashicons dashicons-admin-network"></span>
                    </div>
                    <div class="contai-setup-notice-icon-pulse"></div>
                </div>

                <h2 class="contai-setup-notice-title">
                    <?php esc_html_e('Connect Your Account', '1platform-content-ai'); ?>
                </h2>
                <p class="contai-setup-notice-description">
                    <?php esc_html_e('Link your Content AI account to start managing your billing, credits, and transaction history.', '1platform-content-ai'); ?>
                </p>

                <div class="contai-setup-notice-features">
                    <div class="contai-setup-notice-feature">
                        <span class="contai-setup-notice-check">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </span>
                        <div class="contai-setup-notice-feature-content">
                            <span class="contai-setup-notice-feature-title">
                                <?php esc_html_e('Credit Balance', '1platform-content-ai'); ?>
                            </span>
                            <span class="contai-setup-notice-feature-desc">
                                <?php esc_html_e('View and manage your available credits in real time', '1platform-content-ai'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="contai-setup-notice-feature">
                        <span class="contai-setup-notice-check">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </span>
                        <div class="contai-setup-notice-feature-content">
                            <span class="contai-setup-notice-feature-title">
                                <?php esc_html_e('Top-Up Credits', '1platform-content-ai'); ?>
                            </span>
                            <span class="contai-setup-notice-feature-desc">
                                <?php esc_html_e('Add credits to power AI content generation and SEO tools', '1platform-content-ai'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="contai-setup-notice-feature">
                        <span class="contai-setup-notice-check">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </span>
                        <div class="contai-setup-notice-feature-content">
                            <span class="contai-setup-notice-feature-title">
                                <?php esc_html_e('Transaction History', '1platform-content-ai'); ?>
                            </span>
                            <span class="contai-setup-notice-feature-desc">
                                <?php esc_html_e('Track every payment and credit usage with full details', '1platform-content-ai'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <a href="<?php echo esc_url($licenses_url); ?>" class="contai-setup-notice-cta">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e('Configure API Keys', '1platform-content-ai'); ?>
                    <span class="dashicons dashicons-arrow-right-alt contai-setup-notice-cta-arrow"></span>
                </a>

                <p class="contai-setup-notice-hint">
                    <span class="dashicons dashicons-info-outline"></span>
                    <?php esc_html_e('You\'ll need your Content AI API key to get started', '1platform-content-ai'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}
