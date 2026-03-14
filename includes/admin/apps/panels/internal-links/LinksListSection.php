<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../../services/PaginationService.php';

use WPContentAI\ContaiDatabase\Repositories\ContaiInternalLinkRepository;

class ContaiInternalLinksListSection
{
    private const ITEMS_PER_PAGE = 50;

    private $repository;

    public function __construct(ContaiInternalLinkRepository $repository)
    {
        $this->repository = $repository;
    }

    public function render(): void
    {
        $filters = $this->getFiltersFromRequest();
        $totalItems = $this->repository->countAll($filters['status']);

        ?>
        <div class="contai-settings-section contai-section-separator">
            <h2 class="contai-section-title">
                <span class="dashicons dashicons-admin-links"></span>
                <?php esc_html_e('Existing Internal Links', '1platform-content-ai'); ?>
            </h2>

            <?php if ($totalItems === 0): ?>
                <?php $this->renderEmptyState(); ?>
            <?php else: ?>
                <?php $this->renderLinksTable($filters, $totalItems); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderLinksTable(array $filters, int $totalItems): void
    {
        $pagination = new ContaiPaginationService(
            $filters['page'],
            $totalItems,
            self::ITEMS_PER_PAGE
        );

        $links = $this->repository->findAllWithPostDetails(
            self::ITEMS_PER_PAGE,
            $pagination->getOffset(),
            $filters['status']
        );

        ?>
        <div class="contai-internal-links-list-container">
            <?php $this->renderFilters($filters); ?>
            <?php $this->renderTableHeader($totalItems); ?>

            <table class="contai-internal-links-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Source Post', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Target Post', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('ContaiKeyword', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Created', '1platform-content-ai'); ?></th>
                        <th><?php esc_html_e('Actions', '1platform-content-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $link): ?>
                        <?php $this->renderLinkRow($link); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php $this->renderPagination($pagination); ?>
        </div>
        <?php
    }

    private function renderLinkRow(array $link): void
    {
        ?>
        <tr id="link-<?php echo esc_attr($link['id']); ?>">
            <td>
                <strong>
                    <a href="<?php echo esc_url(get_edit_post_link($link['source_post_id'])); ?>" target="_blank">
                        <?php echo esc_html($link['source_post_title']); ?>
                    </a>
                </strong>
            </td>
            <td>
                <a href="<?php echo esc_url(get_edit_post_link($link['target_post_id'])); ?>" target="_blank">
                    <?php echo esc_html($link['target_post_title']); ?>
                </a>
            </td>
            <td>
                <code><?php echo esc_html($link['keyword_text'] ?? 'N/A'); ?></code>
            </td>
            <td>
                <span class="contai-status-badge contai-status-<?php echo esc_attr($link['status']); ?>">
                    <?php echo esc_html(ucfirst($link['status'])); ?>
                </span>
            </td>
            <td>
                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($link['created_at']))); ?>
            </td>
            <td>
                <a href="<?php echo esc_url(get_permalink($link['source_post_id'])); ?>"
                   target="_blank" class="button button-small">
                    <?php esc_html_e('View', '1platform-content-ai'); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    private function renderFilters(array $filters): void
    {
        ?>
        <div class="contai-internal-links-filters">
            <div class="contai-filter-group">
                <label for="contai-link-status-filter"><?php esc_html_e('Status:', '1platform-content-ai'); ?></label>
                <select id="contai-link-status-filter" class="contai-status-select">
                    <option value="all" <?php selected($filters['status'], ''); ?>>
                        <?php esc_html_e('All Statuses', '1platform-content-ai'); ?>
                    </option>
                    <option value="active" <?php selected($filters['status'], 'active'); ?>>
                        <?php esc_html_e('Active', '1platform-content-ai'); ?>
                    </option>
                    <option value="inactive" <?php selected($filters['status'], 'inactive'); ?>>
                        <?php esc_html_e('Inactive', '1platform-content-ai'); ?>
                    </option>
                </select>
            </div>

            <?php if ($filters['status']): ?>
                <button type="button" class="button contai-clear-filters">
                    <?php esc_html_e('Clear Filters', '1platform-content-ai'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderTableHeader(int $totalItems): void
    {
        ?>
        <div class="contai-table-header">
            <p class="contai-items-count">
                <?php
                /* translators: %d: total number of internal links */
                printf(esc_html__('Showing %d internal links', '1platform-content-ai'), intval($totalItems)); ?>
            </p>
        </div>
        <?php
    }

    private function renderPagination(ContaiPaginationService $pagination): void
    {
        if ($pagination->getTotalPages() <= 1) {
            return;
        }

        $current = $pagination->getCurrentPage();
        $total = $pagination->getTotalPages();
        $base_url = add_query_arg('section', 'internal-links');

        ?>
        <div class="contai-pagination">
            <?php if ($current > 1): ?>
                <a href="<?php echo esc_url(add_query_arg('paged', $current - 1, $base_url)); ?>" class="button">
                    <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                </a>
            <?php endif; ?>

            <span class="contai-pagination-info">
                <?php
                /* translators: %1$d: current page number, %2$d: total number of pages */
                printf(esc_html__('Page %1$d of %2$d', '1platform-content-ai'), intval($current), intval($total)); ?>
            </span>

            <?php if ($current < $total): ?>
                <a href="<?php echo esc_url(add_query_arg('paged', $current + 1, $base_url)); ?>" class="button">
                    <?php esc_html_e('Next', '1platform-content-ai'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderEmptyState(): void
    {
        ?>
        <div class="contai-empty-state">
            <div class="contai-empty-state-icon">
                <span class="dashicons dashicons-admin-links"></span>
            </div>
            <h3 class="contai-empty-state-title">
                <?php esc_html_e('No internal links found', '1platform-content-ai'); ?>
            </h3>
            <p class="contai-empty-state-description">
                <?php esc_html_e('Internal links will appear here once posts are processed. Make sure Internal Links is enabled in settings above.', '1platform-content-ai'); ?>
            </p>
        </div>
        <?php
    }

    private function getFiltersFromRequest(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only filter/pagination parameters.
        return [
            'page' => isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1,
            'status' => isset($_GET['link_status']) ? sanitize_text_field(wp_unslash($_GET['link_status'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
}
