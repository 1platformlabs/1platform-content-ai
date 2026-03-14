<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/billing/BillingService.php';
require_once __DIR__ . '/../handlers/TopUpHandler.php';
require_once __DIR__ . '/../components/BillingSetupNotice.php';

class ContaiBillingOverviewPanel
{
    private ContaiBillingService $service;

    public function __construct(ContaiBillingService $service)
    {
        $this->service = $service;
    }

    public function render(): void
    {
        $this->enqueueAssets();
        $this->renderMessage();

        $userProfile = $this->service->getUserProfile();

        if (!$userProfile) {
            $this->renderUserNotConfigured();
            return;
        }

        $response = $this->service->getBilling();

        if (!$response->isSuccess()) {
            $this->renderError($response->getMessage() ?? __('Failed to load billing information.', '1platform-content-ai'));
            return;
        }

        $data = $response->getData();
        $subscriptionId = $data['subscription']['id'] ?? '—';
        $balance = $data['billing']['balance'] ?? 0;
        $currency = $data['billing']['currency'] ?? 'USD';

        ?>
        <div class="contai-settings-panel contai-panel-billing-overview">
            <?php $this->renderBillingCards($subscriptionId, $balance, $currency); ?>
            <?php $this->renderTopUpModal($currency); ?>
        </div>
        <?php
    }

    private function renderBillingCards(string $subscriptionId, $balance, string $currency): void
    {
        $history_url = admin_url('admin.php?page=contai-billing&section=billing-history');
        ?>
        <div class="contai-billing-overview">
            <div class="contai-billing-hero">
                <div class="contai-billing-hero-content">
                    <div class="contai-billing-hero-icon">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="contai-billing-hero-info">
                        <p class="contai-billing-hero-label"><?php esc_html_e('Available Balance', '1platform-content-ai'); ?></p>
                        <p class="contai-billing-hero-amount">
                            <span class="contai-billing-hero-number"><?php echo esc_html(number_format((float) $balance, 2)); ?></span>
                            <span class="contai-billing-hero-currency"><?php echo esc_html($currency); ?></span>
                        </p>
                    </div>
                </div>
                <div class="contai-billing-hero-actions">
                    <button type="button" class="button button-primary contai-billing-topup-btn" id="contai-open-topup-modal">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Add credit to balance', '1platform-content-ai'); ?>
                    </button>
                    <a href="<?php echo esc_url($history_url); ?>" class="button contai-billing-history-link">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e('View history', '1platform-content-ai'); ?>
                    </a>
                </div>
            </div>

            <div class="contai-billing-details">
                <div class="contai-billing-detail-row">
                    <div class="contai-billing-detail-icon">
                        <span class="dashicons dashicons-id-alt"></span>
                    </div>
                    <div class="contai-billing-detail-info">
                        <span class="contai-billing-detail-label"><?php esc_html_e('Subscription ID', '1platform-content-ai'); ?></span>
                        <code class="contai-billing-detail-value"><?php echo esc_html($subscriptionId); ?></code>
                    </div>
                </div>
                <div class="contai-billing-detail-row">
                    <div class="contai-billing-detail-icon">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="contai-billing-detail-info">
                        <span class="contai-billing-detail-label"><?php esc_html_e('Currency', '1platform-content-ai'); ?></span>
                        <span class="contai-billing-detail-value"><?php echo esc_html($currency); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderTopUpModal(string $currency): void
    {
        $form_url = admin_url('admin.php?page=contai-billing&section=overview');
        ?>
        <div class="contai-billing-modal-overlay" id="contai-topup-modal" style="display: none;">
            <div class="contai-billing-modal">
                <div class="contai-billing-modal-header">
                    <h3>
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Add Credit to Balance', '1platform-content-ai'); ?>
                    </h3>
                </div>

                <form method="post" action="<?php echo esc_url($form_url); ?>">
                    <?php wp_nonce_field(ContaiTopUpHandler::NONCE_ACTION, ContaiTopUpHandler::NONCE_FIELD); ?>
                    <input type="hidden" name="contai_billing_topup" value="1">
                    <input type="hidden" name="contai_topup_currency" value="<?php echo esc_attr($currency); ?>">

                    <div class="contai-billing-modal-body">
                        <div class="contai-form-group">
                            <label for="contai_topup_amount" class="contai-label">
                                <span class="dashicons dashicons-money-alt"></span>
                                <?php esc_html_e('Amount', '1platform-content-ai'); ?>
                            </label>
                            <input
                                type="number"
                                id="contai_topup_amount"
                                name="contai_topup_amount"
                                class="contai-input"
                                min="5"
                                max="200"
                                step="0.01"
                                required
                                placeholder="<?php esc_attr_e('Enter amount', '1platform-content-ai'); ?>"
                            >
                            <p class="contai-help-text">
                                <span class="dashicons dashicons-info"></span>
                                <?php
                                printf(
                                    /* translators: %1$d: minimum amount, %2$d: maximum amount, %3$s: currency code */
                                    esc_html__('Minimum %1$d and maximum %2$d (%3$s)', '1platform-content-ai'),
                                    5,
                                    200,
                                    esc_html($currency)
                                );
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="contai-billing-modal-footer">
                        <button type="button" class="button contai-billing-modal-cancel" id="contai-close-topup-modal">
                            <?php esc_html_e('Cancel', '1platform-content-ai'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-yes"></span>
                            <?php esc_html_e('Continue', '1platform-content-ai'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function renderMessage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only, no data modification.
        if (!isset($_GET['contai_bl_message']) || !isset($_GET['contai_bl_type'])) {
            return;
        }

        $message = urldecode(sanitize_text_field(wp_unslash($_GET['contai_bl_message'])));
        $type = sanitize_key(wp_unslash($_GET['contai_bl_type']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $class = $type === 'success' ? 'notice-success' : 'notice-error';

        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    private function renderUserNotConfigured(): void
    {
        ContaiBillingSetupNotice::render();
    }

    private function renderError(string $message): void
    {
        ?>
        <div class="contai-settings-panel contai-panel-billing-overview">
            <div class="contai-settings-section">
                <div class="contai-info-box contai-info-box-error">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <p><strong><?php esc_html_e('Error', '1platform-content-ai'); ?></strong></p>
                        <p><?php echo esc_html($message); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function enqueueAssets(): void
    {
        $jsFile = dirname(__DIR__) . '/assets/js/billing.js';
        $jsUrl = plugin_dir_url(__FILE__) . '../assets/js/billing.js';

        wp_enqueue_script(
            'contai-billing',
            $jsUrl,
            ['jquery'],
            file_exists($jsFile) ? filemtime($jsFile) : '1.0.0',
            true
        );
    }
}
