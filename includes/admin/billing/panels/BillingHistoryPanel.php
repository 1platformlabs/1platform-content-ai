<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../services/billing/BillingService.php';
require_once __DIR__ . '/../components/BillingSetupNotice.php';

class ContaiBillingHistoryPanel
{
    private const DEFAULT_LIMIT = 10;

    private ContaiBillingService $service;

    public function __construct(ContaiBillingService $service)
    {
        $this->service = $service;
    }

    public function render(): void
    {
        $userProfile = $this->service->getUserProfile();

        if (!$userProfile) {
            $this->renderUserNotConfigured();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination, no data modification.
        $limit = isset($_GET['limit']) ? absint(wp_unslash($_GET['limit'])) : self::DEFAULT_LIMIT;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination, no data modification.
        $skip = isset($_GET['skip']) ? absint(wp_unslash($_GET['skip'])) : 0;

        if ($limit < 1 || $limit > 100) {
            $limit = self::DEFAULT_LIMIT;
        }

        $response = $this->service->getTransactions($limit, $skip);

        if (!$response->isSuccess()) {
            $this->renderError($response->getMessage() ?? __('Failed to load transactions.', '1platform-content-ai'));
            return;
        }

        $data = $response->getData();
        $transactions = $this->extractTransactions($data);
        $total = is_array($data) ? ($data['total'] ?? null) : null;

        ?>
        <div class="contai-settings-panel contai-panel-billing-history">
            <?php
            if (empty($transactions)) {
                $this->renderEmptyState();
            } else {
                $this->renderTransactionCard($transactions, $limit, $skip, count($transactions), $total);
            }
            ?>
        </div>
        <?php
    }

    private function extractTransactions($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['transactions'])) {
            return $data['transactions'];
        }

        if (isset($data['items'])) {
            return $data['items'];
        }

        // Direct array of transactions (numeric keys, no metadata)
        if (isset($data[0])) {
            return $data;
        }

        return [];
    }

    private function renderTransactionCard(array $transactions, int $limit, int $skip, int $count, ?int $total): void
    {
        $showing_from = $skip + 1;
        $showing_to = $skip + $count;
        ?>
        <div class="contai-billing-card">
            <div class="contai-billing-card-header">
                <div class="contai-billing-card-header-left">
                    <span class="dashicons dashicons-list-view"></span>
                    <h3><?php esc_html_e('Transactions', '1platform-content-ai'); ?></h3>
                </div>
                <span class="contai-billing-card-count">
                    <?php
                    if ($total !== null) {
                        /* translators: %1$d: start item number, %2$d: end item number, %3$d: total number of items */
                        printf(esc_html__('%1$d–%2$d of %3$d', '1platform-content-ai'), intval($showing_from), intval($showing_to), intval($total));
                    } else {
                        /* translators: %1$d: start item number, %2$d: end item number */
                        printf(esc_html__('%1$d–%2$d', '1platform-content-ai'), intval($showing_from), intval($showing_to));
                    }
                    ?>
                </span>
            </div>

            <div class="contai-billing-table-wrapper">
                <table class="contai-billing-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', '1platform-content-ai'); ?></th>
                            <th><?php esc_html_e('Description', '1platform-content-ai'); ?></th>
                            <th><?php esc_html_e('Reference', '1platform-content-ai'); ?></th>
                            <th><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                            <th class="contai-col-right"><?php esc_html_e('Amount', '1platform-content-ai'); ?></th>
                            <th class="contai-col-center"><?php esc_html_e('Payment', '1platform-content-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <?php $this->renderRow($transaction); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $this->renderPagination($limit, $skip, $count, $total); ?>
        </div>
        <?php
    }

    private function renderRow(array $transaction): void
    {
        $created_at = $transaction['created_at'] ?? '';

        // Priorizar usd_amount si existe
        if (isset($transaction['usd_amount']) || isset($transaction['usdAmount'])) {
            $amount = $transaction['usd_amount'] ?? $transaction['usdAmount'] ?? 0;
            $currency = 'USD';
        } else {
            $amount = $transaction['amount'] ?? 0;
            $currency = $transaction['currency'] ?? '';
        }

        $status = $transaction['status'] ?? '';
        $description = $transaction['description'] ?? '';
        $reference = $transaction['reference'] ?? '';
        $payment_url = $transaction['payment_url'] ?? '';

        $formatted_date = '';
        $formatted_time = '';
        if (!empty($created_at)) {
            $timestamp = strtotime($created_at);
            if ($timestamp !== false) {
                $formatted_date = date_i18n(get_option('date_format'), $timestamp);
                $formatted_time = date_i18n(get_option('time_format'), $timestamp);
            }
        }

        $status_class = $this->getStatusClass($status);
        $status_icon = $this->getStatusIcon($status);

        ?>
        <tr>
            <td>
                <div class="contai-billing-date">
                    <span class="contai-billing-date-day"><?php echo esc_html($formatted_date); ?></span>
                    <span class="contai-billing-date-time"><?php echo esc_html($formatted_time); ?></span>
                </div>
            </td>
            <td>
                <span class="contai-billing-description"><?php echo esc_html($description); ?></span>
            </td>
            <td>
                <?php if (!empty($reference)): ?>
                    <code class="contai-billing-reference"><?php echo esc_html($reference); ?></code>
                <?php else: ?>
                    <span class="contai-billing-no-link">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="contai-status-badge <?php echo esc_attr($status_class); ?>">
                    <span class="dashicons <?php echo esc_attr($status_icon); ?>"></span>
                    <?php echo esc_html(ucfirst($status)); ?>
                </span>
            </td>
            <td class="contai-col-right">
                <span class="contai-billing-amount">
                    <?php echo esc_html(number_format((float) $amount, 2)); ?>
                    <span class="contai-billing-currency"><?php echo esc_html($currency); ?></span>
                </span>
            </td>
            <td class="contai-col-center">
                <?php if (!empty($payment_url)): ?>
                    <a href="<?php echo esc_url($payment_url); ?>" class="contai-billing-pay-link" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Pay now', '1platform-content-ai'); ?>
                        <span class="dashicons dashicons-external"></span>
                    </a>
                <?php else: ?>
                    <span class="contai-billing-no-link">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function getStatusIcon(string $status): string
    {
        $status_lower = strtolower($status);

        $map = [
            'completed' => 'dashicons-yes-alt',
            'success'   => 'dashicons-yes-alt',
            'paid'      => 'dashicons-yes-alt',
            'approved'  => 'dashicons-yes-alt',
            'pending'   => 'dashicons-clock',
            'processing'=> 'dashicons-clock',
            'created'   => 'dashicons-clock',
            'failed'    => 'dashicons-dismiss',
            'cancelled' => 'dashicons-dismiss',
            'expired'   => 'dashicons-dismiss',
        ];

        return $map[$status_lower] ?? 'dashicons-marker';
    }

    private function getStatusClass(string $status): string
    {
        $status_lower = strtolower($status);

        $map = [
            'completed' => 'contai-status-badge--completed',
            'success'   => 'contai-status-badge--completed',
            'paid'      => 'contai-status-badge--completed',
            'approved'  => 'contai-status-badge--completed',
            'pending'   => 'contai-status-badge--pending',
            'processing'=> 'contai-status-badge--pending',
            'created'   => 'contai-status-badge--pending',
            'failed'    => 'contai-status-badge--failed',
            'cancelled' => 'contai-status-badge--failed',
            'expired'   => 'contai-status-badge--failed',
        ];

        return $map[$status_lower] ?? 'contai-status-badge--default';
    }

    private function renderPagination(int $limit, int $skip, int $count, ?int $total): void
    {
        $base_url = admin_url('admin.php?page=contai-billing&section=billing-history');
        $prev_skip = max(0, $skip - $limit);
        $next_skip = $skip + $limit;

        $has_prev = $skip > 0;
        $has_next = $count >= $limit;

        if (!$has_prev && !$has_next) {
            return;
        }

        ?>
        <div class="contai-billing-pagination">
            <div class="contai-billing-pagination-buttons">
                <?php if ($has_prev): ?>
                    <a href="<?php echo esc_url(add_query_arg(['limit' => $limit, 'skip' => $prev_skip], $base_url)); ?>" class="contai-billing-page-btn">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                    </a>
                <?php else: ?>
                    <span class="contai-billing-page-btn disabled">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                    </span>
                <?php endif; ?>

                <?php if ($has_next): ?>
                    <a href="<?php echo esc_url(add_query_arg(['limit' => $limit, 'skip' => $next_skip], $base_url)); ?>" class="contai-billing-page-btn">
                        <?php esc_html_e('Next', '1platform-content-ai'); ?>
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                <?php else: ?>
                    <span class="contai-billing-page-btn disabled">
                        <?php esc_html_e('Next', '1platform-content-ai'); ?>
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderEmptyState(): void
    {
        $overview_url = admin_url('admin.php?page=contai-billing&section=overview');
        ?>
        <div class="contai-billing-empty-state">
            <div class="contai-billing-empty-icon">
                <span class="dashicons dashicons-portfolio"></span>
            </div>
            <h3 class="contai-billing-empty-title">
                <?php esc_html_e('No transactions yet', '1platform-content-ai'); ?>
            </h3>
            <p class="contai-billing-empty-description">
                <?php esc_html_e('Your billing history will appear here once you add credit to your balance and start using the platform.', '1platform-content-ai'); ?>
            </p>
            <a href="<?php echo esc_url($overview_url); ?>" class="button button-primary contai-billing-empty-cta">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e('Add credit to balance', '1platform-content-ai'); ?>
            </a>
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
        <div class="contai-settings-panel contai-panel-billing-history">
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
}
