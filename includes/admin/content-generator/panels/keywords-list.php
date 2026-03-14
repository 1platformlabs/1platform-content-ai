<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../../../services/PaginationService.php';

class ContaiKeywordsListPanel {

    private const ITEMS_PER_PAGE = 50;

    private ContaiKeywordRepository $repository;

    public function __construct() {
        $this->repository = new ContaiKeywordRepository();
    }

    public function render(): void {
        ?>
        <div class="contai-settings-panel contai-panel-keywords-list">
            <div class="contai-panel-body">
                <?php $this->render_keywords_table(); ?>
            </div>
        </div>
        <?php
    }

    private function render_keywords_table(): void {
        $filters = $this->getFiltersFromRequest();
        $totalItems = $this->repository->countWithFilters($filters['search'], $filters['status']);

        if ($totalItems === 0) {
            $this->render_empty_state();
            return;
        }

        $pagination = new ContaiPaginationService(
            $filters['page'],
            $totalItems,
            self::ITEMS_PER_PAGE
        );

        $keywords = $this->repository->findWithFilters(
            $filters['search'],
            $filters['status'],
            $filters['orderby'],
            $filters['order'],
            self::ITEMS_PER_PAGE,
            $pagination->getOffset()
        );

        ?>
        <div class="contai-keywords-list-container">
            <?php $this->render_filters($filters); ?>
            <?php $this->render_table_header($filters); ?>
            <table class="contai-keywords-table">
                <thead>
                    <tr>
                        <?php $this->render_sortable_header('keyword', esc_html__('ContaiKeyword', '1platform-content-ai'), $filters); ?>
                        <?php $this->render_sortable_header('title', esc_html__('Title', '1platform-content-ai'), $filters); ?>
                        <?php $this->render_sortable_header('volume', esc_html__('Volume', '1platform-content-ai'), $filters); ?>
                        <?php $this->render_sortable_header('status', esc_html__('Status', '1platform-content-ai'), $filters); ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keywords as $keyword): ?>
                        <tr id="keyword-<?php echo esc_attr($keyword->getId()); ?>">
                            <td><strong><?php echo esc_html($keyword->getKeyword()); ?></strong></td>
                            <td><?php echo esc_html($keyword->getTitle()); ?></td>
                            <td><?php echo esc_html(number_format($keyword->getVolume())); ?></td>
                            <td>
                                <span class="contai-status-badge contai-status-<?php echo esc_attr($keyword->getStatus()); ?>">
                                    <?php echo esc_html(ucfirst($keyword->getStatus())); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php $this->render_pagination($pagination); ?>
        </div>
        <?php
    }

    private function getFiltersFromRequest(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only filter/sort/pagination parameters.
        return [
            'page' => isset($_GET['paged']) ? max(1, absint( wp_unslash( $_GET['paged'] ) )) : 1,
            'search' => isset($_GET['s']) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : null,
            'status' => isset($_GET['status']) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : null,
            'orderby' => isset($_GET['orderby']) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'volume',
            'order' => isset($_GET['order']) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private function render_filters(array $filters): void {
        ?>
        <div class="contai-keywords-filters">
            <div class="contai-filter-group">
                <input
                    type="text"
                    id="contai-keyword-search"
                    class="contai-search-input"
                    placeholder="<?php esc_attr_e('Search keywords or titles...', '1platform-content-ai'); ?>"
                    value="<?php echo esc_attr($filters['search'] ?? ''); ?>"
                >
                <button type="button" class="button contai-search-button">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Search', '1platform-content-ai'); ?>
                </button>
            </div>

            <div class="contai-filter-group">
                <select id="contai-status-filter" class="contai-status-select">
                    <option value="all" <?php selected($filters['status'], null); ?>>
                        <?php esc_html_e('All Statuses', '1platform-content-ai'); ?>
                    </option>
                    <option value="active" <?php selected($filters['status'], 'active'); ?>>
                        <?php esc_html_e('Active', '1platform-content-ai'); ?>
                    </option>
                    <option value="inactive" <?php selected($filters['status'], 'inactive'); ?>>
                        <?php esc_html_e('Inactive', '1platform-content-ai'); ?>
                    </option>
                    <option value="pending" <?php selected($filters['status'], 'pending'); ?>>
                        <?php esc_html_e('Pending', '1platform-content-ai'); ?>
                    </option>
                </select>
            </div>

            <?php if ($filters['search'] || $filters['status']): ?>
                <button type="button" class="button contai-clear-filters">
                    <?php esc_html_e('Clear Filters', '1platform-content-ai'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_table_header(array $filters): void {
        $totalItems = $this->repository->countWithFilters($filters['search'], $filters['status']);
        ?>
        <div class="contai-table-header">
            <p class="contai-items-count">
                <?php
                printf(
                    /* translators: %d: total number of keywords */
                    esc_html__('Showing %d keywords', '1platform-content-ai'),
                    intval($totalItems)
                );
                ?>
            </p>
        </div>
        <?php
    }

    private function render_sortable_header(string $column, string $label, array $filters): void {
        $isSorted = $filters['orderby'] === $column;
        $currentOrder = $filters['order'];
        $nextOrder = ($isSorted && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
        $sortClass = $isSorted ? 'sorted ' . strtolower($currentOrder) : 'sortable';

        $url = add_query_arg([
            'orderby' => $column,
            'order' => $nextOrder,
            's' => $filters['search'],
            'status' => $filters['status'],
            'paged' => 1,
        ]);
        ?>
        <th class="<?php echo esc_attr($sortClass); ?>">
            <a href="<?php echo esc_url($url); ?>">
                <?php echo esc_html($label); ?>
                <?php if ($isSorted): ?>
                    <span class="dashicons dashicons-arrow-<?php echo esc_attr( $currentOrder === 'ASC' ? 'up' : 'down' ); ?>"></span>
                <?php endif; ?>
            </a>
        </th>
        <?php
    }

    private function render_pagination(ContaiPaginationService $pagination): void {
        if (!$pagination->hasPages()) {
            return;
        }

        $filters = $this->getFiltersFromRequest();
        ?>
        <div class="contai-pagination">
            <div class="contai-pagination-info">
                <?php
                printf(
                    /* translators: %1$d: start item number, %2$d: end item number, %3$d: total number of items */
                    esc_html__('Showing %1$d to %2$d of %3$d items', '1platform-content-ai'),
                    intval($pagination->getStartItemNumber()),
                    intval($pagination->getEndItemNumber()),
                    intval($this->repository->countWithFilters($filters['search'], $filters['status']))
                );
                ?>
            </div>

            <div class="contai-pagination-links">
                <?php if ($pagination->hasPreviousPage()): ?>
                    <a href="<?php echo esc_url($this->getPaginationUrl($pagination->getPreviousPage())); ?>"
                       class="contai-page-link contai-prev-page">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        <?php esc_html_e('Previous', '1platform-content-ai'); ?>
                    </a>
                <?php endif; ?>

                <div class="contai-page-numbers">
                    <?php foreach ($pagination->getVisiblePages() as $page): ?>
                        <?php if ($page === $pagination->getCurrentPage()): ?>
                            <span class="contai-page-number contai-current-page"><?php echo esc_html($page); ?></span>
                        <?php else: ?>
                            <a href="<?php echo esc_url($this->getPaginationUrl($page)); ?>"
                               class="contai-page-number">
                                <?php echo esc_html($page); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($pagination->hasNextPage()): ?>
                    <a href="<?php echo esc_url($this->getPaginationUrl($pagination->getNextPage())); ?>"
                       class="contai-page-link contai-next-page">
                        <?php esc_html_e('Next', '1platform-content-ai'); ?>
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function getPaginationUrl(int $page): string {
        $filters = $this->getFiltersFromRequest();
        return add_query_arg([
            'paged' => $page,
            's' => $filters['search'],
            'status' => $filters['status'],
            'orderby' => $filters['orderby'],
            'order' => $filters['order'],
        ]);
    }

    private function render_empty_state(): void {
        ?>
        <div class="contai-notice contai-notice-info">
            <span class="dashicons dashicons-info"></span>
            <div>
                <p><?php esc_html_e('No keywords have been extracted yet.', '1platform-content-ai'); ?></p>
                <p>
                    <a href="<?php echo esc_url(add_query_arg('section', 'keyword-extractor', admin_url('admin.php?page=contai-content-generator'))); ?>"
                       class="button button-primary">
                        <?php esc_html_e('Extract Keywords Now', '1platform-content-ai'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
}
