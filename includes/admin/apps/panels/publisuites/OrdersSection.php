<?php

if (!defined('ABSPATH')) exit;

/**
 * Publisuites Orders section with tab-based UX.
 *
 * Renders orders in three tabs: Action Required, In Progress, History.
 * Each tab has its own layout optimized for the workflow stage.
 *
 * Receives a fully-resolved $view_data array — no decision logic here.
 */
class ContaiPublisuitesOrdersSection
{
    private array $view_data;

    /** @var array Orders requiring user action. */
    private array $action_required = [];

    /** @var array Orders currently being processed. */
    private array $in_progress = [];

    /** @var array Completed, cancelled, or rejected orders. */
    private array $history = [];

    private const STATUS_LABELS = [
        'pending'       => 'Pending',
        'accepted'      => 'Accepted',
        'deliver_url'   => 'Send URL',
        'in_review'     => 'In Review',
        'modifications' => 'Changes Requested',
        'completed'     => 'Completed',
        'cancelled'     => 'Cancelled',
        'rejected'      => 'Rejected',
    ];

    private const STATUS_STYLES = [
        'pending'       => ['bg' => '#fef3c7', 'color' => '#92400e'],
        'accepted'      => ['bg' => '#dbeafe', 'color' => '#1e40af'],
        'deliver_url'   => ['bg' => '#ffedd5', 'color' => '#9a3412'],
        'in_review'     => ['bg' => '#e0e7ff', 'color' => '#3730a3'],
        'modifications' => ['bg' => '#fce7f3', 'color' => '#9d174d'],
        'completed'     => ['bg' => '#d1fae5', 'color' => '#065f46'],
        'cancelled'     => ['bg' => '#fee2e2', 'color' => '#991b1b'],
        'rejected'      => ['bg' => '#fee2e2', 'color' => '#991b1b'],
    ];

    private const ACTION_REQUIRED_STATUSES = ['pending', 'deliver_url', 'modifications'];
    private const IN_PROGRESS_STATUSES     = ['accepted', 'in_review'];
    private const HISTORY_STATUSES         = ['completed', 'cancelled', 'rejected'];

    public function __construct(array $view_data)
    {
        $this->view_data = $view_data;
        $this->categorizeOrders();
    }

    /**
     * Categorize orders into the three tab groups.
     */
    private function categorizeOrders(): void
    {
        $orders = $this->view_data['orders'] ?? [];

        foreach ($orders as $order) {
            $status = $this->extractField($order, 'status', 'pending');

            if (in_array($status, self::ACTION_REQUIRED_STATUSES, true)) {
                $this->action_required[] = $order;
            } elseif (in_array($status, self::IN_PROGRESS_STATUSES, true)) {
                $this->in_progress[] = $order;
            } else {
                $this->history[] = $order;
            }
        }
    }

    public function render(): void
    {
        ?>
        <div class="contai-ps-orders">
            <?php $this->renderHeader(); ?>
            <?php $this->renderStaleWarning(); ?>
            <?php $this->renderTabs(); ?>
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
       Tab navigation + tab panels
       ------------------------------------------------------------------ */

    private function renderTabs(): void
    {
        $action_count  = count($this->action_required);
        $progress_count = count($this->in_progress);
        $history_count  = count($this->history);
        ?>
        <nav class="contai-ps-orders__tabs-nav" role="tablist">
            <button type="button"
                    class="contai-ps-orders__tab active"
                    data-tab="action-required"
                    role="tab"
                    aria-selected="true"
                    aria-controls="ps-tab-action-required">
                <span class="dashicons dashicons-warning"></span>
                <span><?php esc_html_e('Action Required', '1platform-content-ai'); ?></span>
                <?php if ($action_count > 0) : ?>
                    <span class="contai-ps-orders__tab-badge contai-ps-orders__tab-badge--alert"><?php echo esc_html($action_count); ?></span>
                <?php endif; ?>
            </button>
            <button type="button"
                    class="contai-ps-orders__tab"
                    data-tab="in-progress"
                    role="tab"
                    aria-selected="false"
                    aria-controls="ps-tab-in-progress">
                <span class="dashicons dashicons-clock"></span>
                <span><?php esc_html_e('In Progress', '1platform-content-ai'); ?></span>
                <?php if ($progress_count > 0) : ?>
                    <span class="contai-ps-orders__tab-badge"><?php echo esc_html($progress_count); ?></span>
                <?php endif; ?>
            </button>
            <button type="button"
                    class="contai-ps-orders__tab"
                    data-tab="history"
                    role="tab"
                    aria-selected="false"
                    aria-controls="ps-tab-history">
                <span class="dashicons dashicons-backup"></span>
                <span><?php esc_html_e('History', '1platform-content-ai'); ?></span>
                <?php if ($history_count > 0) : ?>
                    <span class="contai-ps-orders__tab-badge"><?php echo esc_html($history_count); ?></span>
                <?php endif; ?>
            </button>
        </nav>

        <div class="contai-ps-orders__tab-content active" id="ps-tab-action-required" role="tabpanel">
            <?php $this->renderActionRequiredTab(); ?>
        </div>

        <div class="contai-ps-orders__tab-content" id="ps-tab-in-progress" role="tabpanel">
            <?php $this->renderInProgressTab(); ?>
        </div>

        <div class="contai-ps-orders__tab-content" id="ps-tab-history" role="tabpanel">
            <?php $this->renderHistoryTab(); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Tab 1: Action Required — full card layout
       ------------------------------------------------------------------ */

    private function renderActionRequiredTab(): void
    {
        if (empty($this->action_required)) {
            ?>
            <div class="contai-ps-orders__empty">
                <span class="dashicons dashicons-yes-alt contai-ps-orders__empty-icon" aria-hidden="true"></span>
                <p class="contai-ps-orders__empty-text">
                    <?php esc_html_e("No orders requiring action. You're all caught up!", '1platform-content-ai'); ?>
                </p>
            </div>
            <?php
            return;
        }

        foreach ($this->action_required as $order) {
            $this->renderActionRequiredCard($order);
        }
    }

    private function renderActionRequiredCard($order): void
    {
        $order_id   = $this->extractField($order, 'publisuites_order_id');
        $status     = $this->extractField($order, 'status', 'pending');
        $type       = $this->extractField($order, 'order_type');
        $earnings   = $this->extractField($order, 'earnings');
        $deadline   = $this->extractField($order, 'deadline_date');
        $sale_date  = $this->extractField($order, 'sale_date');
        $website    = $this->extractField($order, 'website');

        $deadline_class = $this->getDeadlineUrgency($deadline);
        ?>
        <div class="contai-ps-orders__card">
            <div class="contai-ps-orders__card-header">
                <div class="contai-ps-orders__card-id">
                    <span class="contai-ps-mono">#<?php echo esc_html($order_id); ?></span>
                    <?php $this->renderTypeBadge($type); ?>
                </div>
                <span class="contai-ps-orders__card-earnings"><?php echo esc_html($this->formatEarnings($earnings)); ?></span>
            </div>

            <div class="contai-ps-orders__card-meta">
                <div class="contai-ps-orders__meta-item">
                    <span class="contai-ps-orders__meta-label"><?php esc_html_e('Status', '1platform-content-ai'); ?></span>
                    <?php $this->renderStatusBadge($status); ?>
                </div>
                <div class="contai-ps-orders__meta-item">
                    <span class="contai-ps-orders__meta-label"><?php esc_html_e('Deadline', '1platform-content-ai'); ?></span>
                    <span class="<?php echo esc_attr($deadline_class); ?>"><?php echo esc_html($this->formatDate($deadline)); ?></span>
                </div>
                <div class="contai-ps-orders__meta-item">
                    <span class="contai-ps-orders__meta-label"><?php esc_html_e('Sale Date', '1platform-content-ai'); ?></span>
                    <span><?php echo esc_html($this->formatDate($sale_date)); ?></span>
                </div>
                <div class="contai-ps-orders__meta-item">
                    <span class="contai-ps-orders__meta-label"><?php esc_html_e('Website', '1platform-content-ai'); ?></span>
                    <span><?php echo esc_html($website ?: '—'); ?></span>
                </div>
            </div>

            <div class="contai-ps-orders__card-actions">
                <?php if ($status === 'pending') : ?>
                    <?php $this->renderAcceptRejectForms($order_id); ?>
                <?php elseif ($status === 'deliver_url' || $status === 'modifications') : ?>
                    <?php $this->renderSendUrlForm($order_id); ?>
                <?php endif; ?>
            </div>

            <?php $this->renderDetailPanel($order, $order_id); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Tab 2: In Progress — compact cards
       ------------------------------------------------------------------ */

    private function renderInProgressTab(): void
    {
        if (empty($this->in_progress)) {
            ?>
            <div class="contai-ps-orders__empty">
                <span class="dashicons dashicons-clock contai-ps-orders__empty-icon" aria-hidden="true"></span>
                <p class="contai-ps-orders__empty-text">
                    <?php esc_html_e('No orders in progress.', '1platform-content-ai'); ?>
                </p>
            </div>
            <?php
            return;
        }

        foreach ($this->in_progress as $order) {
            $this->renderInProgressCard($order);
        }
    }

    private function renderInProgressCard($order): void
    {
        $order_id  = $this->extractField($order, 'publisuites_order_id');
        $status    = $this->extractField($order, 'status', 'accepted');
        $earnings  = $this->extractField($order, 'earnings');
        $deadline  = $this->extractField($order, 'deadline_date');
        $website   = $this->extractField($order, 'website');

        $deadline_class = $this->getDeadlineUrgency($deadline);
        ?>
        <div class="contai-ps-orders__card contai-ps-orders__card--compact">
            <div class="contai-ps-orders__card-header">
                <div class="contai-ps-orders__card-id">
                    <span class="contai-ps-mono">#<?php echo esc_html($order_id); ?></span>
                    <?php $this->renderStatusBadge($status); ?>
                </div>
                <div class="contai-ps-orders__card-header-right">
                    <span class="contai-ps-orders__card-earnings"><?php echo esc_html($this->formatEarnings($earnings)); ?></span>
                    <button type="button" class="button contai-ps-orders__btn-view"
                            onclick="document.getElementById('ps-detail-<?php echo esc_attr($order_id); ?>').toggleAttribute('open')">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                </div>
            </div>

            <div class="contai-ps-orders__card-meta contai-ps-orders__card-meta--inline">
                <span class="<?php echo esc_attr($deadline_class); ?>">
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <?php echo esc_html($this->formatDate($deadline)); ?>
                </span>
                <span>
                    <span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
                    <?php echo esc_html($website ?: '—'); ?>
                </span>
            </div>

            <?php $this->renderDetailPanel($order, $order_id); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
       Tab 3: History — compact table
       ------------------------------------------------------------------ */

    private function renderHistoryTab(): void
    {
        if (empty($this->history)) {
            ?>
            <div class="contai-ps-orders__empty">
                <span class="dashicons dashicons-backup contai-ps-orders__empty-icon" aria-hidden="true"></span>
                <p class="contai-ps-orders__empty-text">
                    <?php esc_html_e('No order history yet.', '1platform-content-ai'); ?>
                </p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="contai-ps-orders__table-wrap">
            <table class="contai-ps-orders__table widefat striped">
                <thead>
                    <tr>
                        <th class="contai-ps-orders__col-id"><?php esc_html_e('ID', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-type"><?php esc_html_e('Type', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-sale-date"><?php esc_html_e('Date', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-status"><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                        <th class="contai-ps-orders__col-earnings"><?php esc_html_e('Earnings', '1platform-content-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->history as $order) : ?>
                        <?php $this->renderHistoryRow($order); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderHistoryRow($order): void
    {
        $order_id  = $this->extractField($order, 'publisuites_order_id');
        $status    = $this->extractField($order, 'status', 'completed');
        $type      = $this->extractField($order, 'order_type');
        $sale_date = $this->extractField($order, 'sale_date');
        $earnings  = $this->extractField($order, 'earnings');
        ?>
        <tr class="contai-ps-orders__row">
            <td class="contai-ps-orders__cell-id contai-ps-mono">#<?php echo esc_html($order_id); ?></td>
            <td class="contai-ps-orders__cell-type"><?php echo esc_html($this->formatType($type)); ?></td>
            <td class="contai-ps-orders__cell-sale-date"><?php echo esc_html($this->formatDate($sale_date)); ?></td>
            <td class="contai-ps-orders__cell-status"><?php $this->renderStatusBadge($status); ?></td>
            <td class="contai-ps-orders__cell-earnings"><?php echo esc_html($this->formatEarnings($earnings)); ?></td>
        </tr>
        <?php
    }

    /* ------------------------------------------------------------------
       Type badge
       ------------------------------------------------------------------ */

    private function renderTypeBadge(string $type): void
    {
        $lower = strtolower($type);
        $bg    = $lower === 'post' ? '#dbeafe' : '#ede9fe';
        $color = $lower === 'post' ? '#1e40af' : '#6d28d9';
        ?>
        <span class="contai-ps-orders__type-badge"
              style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($color); ?>;">
            <?php echo esc_html($this->formatType($type)); ?>
        </span>
        <?php
    }

    /* ------------------------------------------------------------------
       Status badge
       ------------------------------------------------------------------ */

    private function renderStatusBadge(string $status): void
    {
        $styles = self::STATUS_STYLES[$status] ?? self::STATUS_STYLES['pending'];
        $label  = self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
        ?>
        <span class="contai-ps-orders__status contai-ps-orders__status--<?php echo esc_attr($status); ?>"
              style="background:<?php echo esc_attr($styles['bg']); ?>;color:<?php echo esc_attr($styles['color']); ?>;">
            <?php echo esc_html($label); ?>
        </span>
        <?php
    }

    /* ------------------------------------------------------------------
       Action forms
       ------------------------------------------------------------------ */

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
            <input type="url"
                   name="contai_delivery_url"
                   class="contai-ps-orders__url-input"
                   placeholder="https://..."
                   required
                   aria-label="<?php esc_attr_e('Delivery URL', '1platform-content-ai'); ?>">
            <button type="submit" class="button button-primary contai-ps-orders__btn-send">
                <?php esc_html_e('Send', '1platform-content-ai'); ?>
            </button>
        </form>
        <?php
    }

    /* ------------------------------------------------------------------
       Detail panel (expandable)
       ------------------------------------------------------------------ */

    private function renderDetailPanel($order, string $order_id): void
    {
        $suggested_title = $this->extractField($order, 'suggested_title');
        $order_details   = $this->extractField($order, 'order_details');
        $min_words       = $this->extractField($order, 'min_words');
        $social_media    = $this->extractField($order, 'social_media');
        $links           = $this->extractField($order, 'links');
        ?>
        <details class="contai-ps-orders__detail" id="ps-detail-<?php echo esc_attr($order_id); ?>">
            <summary><?php esc_html_e('Order Details', '1platform-content-ai'); ?></summary>
            <div class="contai-ps-orders__detail-body">

                <?php if ($suggested_title) : ?>
                    <div class="contai-ps-orders__detail-row">
                        <span class="contai-ps-orders__detail-label"><?php esc_html_e('Suggested Title', '1platform-content-ai'); ?></span>
                        <span class="contai-ps-orders__detail-value"><?php echo esc_html($suggested_title); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($order_details) : ?>
                    <div class="contai-ps-orders__detail-row">
                        <span class="contai-ps-orders__detail-label"><?php esc_html_e('Briefing', '1platform-content-ai'); ?></span>
                        <div class="contai-ps-orders__detail-value contai-ps-orders__detail-briefing">
                            <?php echo esc_html($order_details); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="contai-ps-orders__detail-specs">
                    <?php if ($min_words) : ?>
                        <span class="contai-ps-orders__spec-badge">
                            <span class="dashicons dashicons-editor-spellcheck" aria-hidden="true"></span>
                            <?php
                            printf(
                                /* translators: %s: minimum word count */
                                esc_html__('Min. %s words', '1platform-content-ai'),
                                esc_html($min_words)
                            );
                            ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($social_media) : ?>
                        <span class="contai-ps-orders__spec-badge">
                            <span class="dashicons dashicons-share" aria-hidden="true"></span>
                            <?php esc_html_e('Social Media', '1platform-content-ai'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($links) && is_array($links)) : ?>
                    <div class="contai-ps-orders__detail-row">
                        <span class="contai-ps-orders__detail-label"><?php esc_html_e('Links', '1platform-content-ai'); ?></span>
                        <table class="contai-ps-orders__links-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('URL', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('Anchor', '1platform-content-ai'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $link) : ?>
                                    <tr>
                                        <td><?php echo esc_html($this->extractField($link, 'url')); ?></td>
                                        <td><?php echo esc_html($this->extractField($link, 'anchor')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="contai-ps-orders__detail-footer">
                    <?php $view_id = (string) $this->extractField($order, 'view_order_id', $order_id); ?>
                    <a href="<?php echo esc_url('https://www.publisuites.com/publishers/websites/view-order/' . $view_id . '/'); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="button button-secondary">
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        <?php esc_html_e('View on marketplace', '1platform-content-ai'); ?>
                    </a>
                </div>

            </div>
        </details>
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
                    <a href="<?php echo esc_url(add_query_arg('ps_page', $page - 1)); ?>"
                       class="button contai-ps-orders__page-btn">
                        &laquo; <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($page < $total_pages) : ?>
                    <a href="<?php echo esc_url(add_query_arg('ps_page', $page + 1)); ?>"
                       class="button contai-ps-orders__page-btn">
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
     * Extract a field from an order (array or object).
     *
     * @param mixed  $order   Order data (array or object).
     * @param string $field   Field name.
     * @param mixed  $default Default value.
     * @return mixed
     */
    private function extractField($order, string $field, $default = '')
    {
        if (is_array($order)) {
            return isset($order[$field]) ? $order[$field] : $default;
        }

        if (is_object($order)) {
            return isset($order->$field) ? $order->$field : $default;
        }

        return $default;
    }

    /**
     * Convert an ISO 8601 timestamp to a human-readable "Xm ago" string.
     */
    private function humanizeTimeDiff(string $isoTimestamp): string
    {
        try {
            $then = new DateTime($isoTimestamp);
            $now  = new DateTime('now', $then->getTimezone());
            $diff = $now->getTimestamp() - $then->getTimestamp();

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
     * Format a date string for display.
     *
     * Supports d/m/y H:i, d/m/y, and ISO 8601 formats.
     */
    private function formatDate(string $dateString): string
    {
        if (empty($dateString)) {
            return '—';
        }

        $formats = [
            'd/m/y H:i',
            'd/m/Y H:i',
            'd/m/y',
            'd/m/Y',
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format(get_option('date_format'));
            }
        }

        try {
            $date = new DateTime($dateString);
            return $date->format(get_option('date_format'));
        } catch (Exception $e) {
            return $dateString;
        }
    }

    /**
     * Get CSS class for deadline urgency.
     */
    private function getDeadlineUrgency(string $deadline): string
    {
        if (empty($deadline)) {
            return '';
        }

        $formats = [
            'd/m/y H:i',
            'd/m/Y H:i',
            'd/m/y',
            'd/m/Y',
        ];

        $deadlineDate = null;

        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $deadline);
            if ($parsed !== false) {
                $deadlineDate = $parsed;
                break;
            }
        }

        if ($deadlineDate === null) {
            try {
                $deadlineDate = new DateTime($deadline);
            } catch (Exception $e) {
                return '';
            }
        }

        $now  = new DateTime();
        $diff = $deadlineDate->getTimestamp() - $now->getTimestamp();

        if ($diff < 0) {
            return 'contai-ps-orders__deadline--overdue';
        }

        if ($diff < 86400) {
            return 'contai-ps-orders__deadline--urgent';
        }

        if ($diff < 172800) {
            return 'contai-ps-orders__deadline--warning';
        }

        return '';
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
     * Format earnings for display.
     *
     * The API returns earnings as a string like "10 €", so return as-is or fallback.
     */
    private function formatEarnings($amount): string
    {
        if (is_string($amount) && $amount !== '') {
            return $amount;
        }

        return '—';
    }
}
