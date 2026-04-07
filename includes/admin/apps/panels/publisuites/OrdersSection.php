<?php

if (!defined('ABSPATH')) exit;

/**
 * Publisuites Orders table section.
 *
 * Renders the sponsored post orders table with sync controls,
 * status badges, and per-order action forms.
 *
 * Receives a fully-resolved $view_data array — no decision logic here.
 */
class ContaiPublisuitesOrdersSection
{
    private array $view_data;

    /**
     * Status badge configuration: CSS modifier → [background, text color, border].
     */
    private const STATUS_STYLES = [
        'pending'     => ['#fef9c3', '#854d0e', '#fde047'],
        'accepted'    => ['#dbeafe', '#1e40af', '#93c5fd'],
        'deliver_url' => ['#ffedd5', '#9a3412', '#fdba74'],
        'completed'   => ['#d1fae5', '#065f46', '#a7f3d0'],
        'cancelled'   => ['#fee2e2', '#991b1b', '#fca5a5'],
        'rejected'    => ['#fee2e2', '#991b1b', '#fca5a5'],
    ];

    public function __construct(array $view_data)
    {
        $this->view_data = $view_data;
    }

    public function render(): void
    {
        ?>
        <div class="contai-ps-orders">
            <?php $this->renderHeader(); ?>
            <?php $this->renderStaleWarning(); ?>
            <?php $this->renderTable(); ?>
            <?php $this->renderPagination(); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Header: title + sync button + last synced
       ------------------------------------------------------------------ */

    private function renderHeader(): void
    {
        ?>
        <div class="contai-ps-orders__header">
            <h3 class="contai-ps-orders__title">
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php esc_html_e('Sponsored Post Orders', '1platform-content-ai'); ?>
            </h3>
            <div class="contai-ps-orders__header-actions">
                <?php if ($this->view_data['last_synced_at']) : ?>
                    <span class="contai-ps-orders__synced-ago">
                        <?php
                        printf(
                            /* translators: %s: human-readable time difference, e.g. "5m ago" */
                            esc_html__('Synced %s', '1platform-content-ai'),
                            esc_html($this->humanizeTimeDiff($this->view_data['last_synced_at']))
                        );
                        ?>
                    </span>
                <?php endif; ?>
                <form method="post" class="contai-ps-orders__sync-form">
                    <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
                    <input type="hidden" name="contai_sync_publisuites" value="1">
                    <button type="submit" class="button button-secondary contai-ps-orders__sync-btn">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <?php esc_html_e('Sync Now', '1platform-content-ai'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Stale data warning
       ------------------------------------------------------------------ */

    private function renderStaleWarning(): void
    {
        if (empty($this->view_data['stale'])) {
            return;
        }
        ?>
        <div class="contai-info-box contai-info-box-warning" role="alert">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <p>
                <?php esc_html_e('Order data may be outdated. Click "Sync Now" to fetch the latest orders from the marketplace.', '1platform-content-ai'); ?>
            </p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Orders table
       ------------------------------------------------------------------ */

    private function renderTable(): void
    {
        $orders = $this->view_data['orders'] ?? [];

        if (empty($orders)) {
            $this->renderEmptyState();
            return;
        }
        ?>
        <div class="contai-ps-orders__table-wrap">
            <table class="contai-ps-orders__table widefat striped">
                <thead>
                    <tr>
                        <th class="contai-ps-orders__col-id"><?php esc_html_e('ID', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-type"><?php esc_html_e('Type', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-sale-date"><?php esc_html_e('Sale Date', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-deadline"><?php esc_html_e('Deadline', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-earnings"><?php esc_html_e('Earnings', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-status"><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-actions"><?php esc_html_e('Actions', '1platform-content-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) : ?>
                        <?php $this->renderRow($order); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderRow(object $order): void
    {
        $order_id = $order->id ?? '';
        $status   = $order->status ?? 'pending';
        ?>
        <tr class="contai-ps-orders__row">
            <td class="contai-ps-orders__cell-id contai-ps-mono">
                #<?php echo esc_html($order_id); ?>
            </td>
            <td class="contai-ps-orders__cell-type">
                <?php echo esc_html($this->formatType($order->type ?? '')); ?>
            </td>
            <td class="contai-ps-orders__cell-sale-date">
                <?php echo esc_html($this->formatDate($order->sale_date ?? '')); ?>
            </td>
            <td class="contai-ps-orders__cell-deadline">
                <?php echo esc_html($this->formatDate($order->deadline ?? '')); ?>
            </td>
            <td class="contai-ps-orders__cell-earnings">
                <?php echo esc_html($this->formatEarnings($order->earnings ?? 0)); ?>
            </td>
            <td class="contai-ps-orders__cell-status">
                <?php $this->renderStatusBadge($status); ?>
            </td>
            <td class="contai-ps-orders__cell-actions">
                <?php $this->renderActions($order_id, $status); ?>
            </td>
        </tr>
        <?php
    }

    /* ------------------------------------------------------------------
       Status badge
       ------------------------------------------------------------------ */

    private function renderStatusBadge(string $status): void
    {
        $styles = self::STATUS_STYLES[$status] ?? self::STATUS_STYLES['pending'];
        $label  = ucfirst(str_replace('_', ' ', $status));
        ?>
        <span
            class="contai-ps-orders__status contai-ps-orders__status--<?php echo esc_attr($status); ?>"
            style="background:<?php echo esc_attr($styles[0]); ?>;color:<?php echo esc_attr($styles[1]); ?>;border-color:<?php echo esc_attr($styles[2]); ?>;"
        >
            <?php echo esc_html($label); ?>
        </span>
        <?php
    }

    /* ------------------------------------------------------------------
       Row actions
       ------------------------------------------------------------------ */

    private function renderActions(string $order_id, string $status): void
    {
        ?>
        <div class="contai-ps-orders__actions">
            <?php if ($status === 'pending') : ?>
                <?php $this->renderAcceptRejectForms($order_id); ?>
            <?php elseif ($status === 'deliver_url') : ?>
                <?php $this->renderSendUrlForm($order_id); ?>
            <?php endif; ?>
            <?php $this->renderViewLink($order_id); ?>
        </div>
        <?php
    }

    private function renderAcceptRejectForms(string $order_id): void
    {
        ?>
        <form method="post" class="contai-ps-orders__action-form contai-ps-orders__action-form--inline">
            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
            <input type="hidden" name="contai_accept_order" value="1">
            <input type="hidden" name="contai_order_id" value="<?php echo esc_attr($order_id); ?>">
            <button type="submit" class="button button-primary contai-ps-orders__btn-accept">
                <?php esc_html_e('Accept', '1platform-content-ai'); ?>
            </button>
        </form>
        <form method="post" class="contai-ps-orders__action-form contai-ps-orders__action-form--inline"
              data-confirm="<?php esc_attr_e('Are you sure you want to reject this order?', '1platform-content-ai'); ?>">
            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
            <input type="hidden" name="contai_reject_order" value="1">
            <input type="hidden" name="contai_order_id" value="<?php echo esc_attr($order_id); ?>">
            <button type="submit" class="button contai-ps-btn--danger">
                <?php esc_html_e('Reject', '1platform-content-ai'); ?>
            </button>
        </form>
        <?php
    }

    private function renderSendUrlForm(string $order_id): void
    {
        ?>
        <form method="post" class="contai-ps-orders__action-form contai-ps-orders__action-form--url">
            <?php wp_nonce_field($this->view_data['nonce_action'], $this->view_data['nonce_field']); ?>
            <input type="hidden" name="contai_send_url" value="1">
            <input type="hidden" name="contai_order_id" value="<?php echo esc_attr($order_id); ?>">
            <input
                type="url"
                name="contai_delivery_url"
                class="contai-ps-orders__url-input"
                placeholder="https://..."
                required
                aria-label="<?php esc_attr_e('Delivery URL', '1platform-content-ai'); ?>"
            >
            <button type="submit" class="button button-primary contai-ps-orders__btn-send">
                <?php esc_html_e('Send', '1platform-content-ai'); ?>
            </button>
        </form>
        <?php
    }

    private function renderViewLink(string $order_id): void
    {
        ?>
        <button
            type="button"
            class="button contai-ps-orders__btn-view"
            data-order-id="<?php echo esc_attr($order_id); ?>"
            aria-label="<?php echo esc_attr(sprintf(
                    /* translators: %s: order ID */
                    __('View order #%s', '1platform-content-ai'),
                    $order_id
                )); ?>"
        >
            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
        </button>
        <?php
    }

    /* ------------------------------------------------------------------
       Empty state
       ------------------------------------------------------------------ */

    private function renderEmptyState(): void
    {
        ?>
        <div class="contai-ps-orders__empty">
            <span class="dashicons dashicons-format-aside contai-ps-orders__empty-icon" aria-hidden="true"></span>
            <p class="contai-ps-orders__empty-text">
                <?php esc_html_e('No sponsored post orders yet. Orders will appear here once they are available in the marketplace.', '1platform-content-ai'); ?>
            </p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Pagination
       ------------------------------------------------------------------ */

    private function renderPagination(): void
    {
        $total     = (int) ($this->view_data['total'] ?? 0);
        $page      = (int) ($this->view_data['page'] ?? 1);
        $page_size = (int) ($this->view_data['page_size'] ?? 10);

        if ($total <= $page_size) {
            return;
        }

        $total_pages = (int) ceil($total / $page_size);
        ?>
        <div class="contai-ps-orders__pagination">
            <span class="contai-ps-orders__pagination-info">
                <?php
                printf(
                    /* translators: 1: current page, 2: total pages, 3: total items */
                    esc_html__('Page %1$d of %2$d (%3$d orders)', '1platform-content-ai'),
                    $page,
                    $total_pages,
                    $total
                );
                ?>
            </span>
            <div class="contai-ps-orders__pagination-buttons">
                <?php if ($page > 1) : ?>
                    <a
                        href="<?php echo esc_url(add_query_arg('ps_page', $page - 1)); ?>"
                        class="button contai-ps-orders__page-btn"
                    >
                        &laquo; <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($page < $total_pages) : ?>
                    <a
                        href="<?php echo esc_url(add_query_arg('ps_page', $page + 1)); ?>"
                        class="button contai-ps-orders__page-btn"
                    >
                        <?php esc_html_e('Next', '1platform-content-ai'); ?> &raquo;
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Helpers
       ------------------------------------------------------------------ */

    /**
     * Convert an ISO 8601 timestamp to a human-readable "Xm ago" / "Xh ago" string.
     */
    private function humanizeTimeDiff(string $isoTimestamp): string
    {
        try {
            $then = new DateTime($isoTimestamp);
            $now  = new DateTime('now', $then->getTimezone());
            $diff = $now->getTimestamp() - $then->getTimestamp();

            if ($diff < 0) {
                return __('just now', '1platform-content-ai');
            }

            if ($diff < 60) {
                return __('just now', '1platform-content-ai');
            }

            if ($diff < 3600) {
                $minutes = (int) floor($diff / 60);
                /* translators: %d: number of minutes */
                return sprintf(_n('%dm ago', '%dm ago', $minutes, '1platform-content-ai'), $minutes);
            }

            if ($diff < 86400) {
                $hours = (int) floor($diff / 3600);
                /* translators: %d: number of hours */
                return sprintf(_n('%dh ago', '%dh ago', $hours, '1platform-content-ai'), $hours);
            }

            $days = (int) floor($diff / 86400);
            /* translators: %d: number of days */
            return sprintf(_n('%dd ago', '%dd ago', $days, '1platform-content-ai'), $days);
        } catch (Exception $e) {
            return $isoTimestamp;
        }
    }

    /**
     * Format a date string using WordPress date/time settings.
     */
    private function formatDate(string $dateString): string
    {
        if (empty($dateString)) {
            return '—';
        }

        try {
            $date = new DateTime($dateString);
            return $date->format(get_option('date_format'));
        } catch (Exception $e) {
            return $dateString;
        }
    }

    /**
     * Format order type for display.
     */
    private function formatType(string $type): string
    {
        $types = [
            'post' => __('Post', '1platform-content-ai'),
            'link' => __('Link', '1platform-content-ai'),
        ];

        return $types[strtolower($type)] ?? ucfirst($type);
    }

    /**
     * Format earnings as currency.
     */
    private function formatEarnings($amount): string
    {
        return '$' . number_format((float) $amount, 2);
    }
}
