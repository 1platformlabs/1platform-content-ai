<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/APILogRepository.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class ContaiAPILogsPanel {

    private ContaiAPILogRepository $repository;
    private string $filter_type;
    private int $per_page = 20;

    public function __construct() {
        $this->repository = new ContaiAPILogRepository();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only filter parameter for log viewing.
        $this->filter_type = isset($_GET['filter']) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
        $this->handle_actions();
    }

    private function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below via check_admin_referer().
        if (!isset($_POST['contai_api_logs_action'])) {
            return;
        }

        check_admin_referer('contai_api_logs_action', 'contai_api_logs_nonce');

        if (isset($_POST['clear_all_logs'])) {
            $this->repository->deleteAll();
            add_action('admin_notices', [$this, 'render_success_notice']);
        } elseif (isset($_POST['clear_old_logs'])) {
            $days = isset($_POST['days']) ? absint( wp_unslash( $_POST['days'] ) ) : 7;
            $deleted = $this->repository->deleteOlderThan($days);
            add_action('admin_notices', function() use ($deleted) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                    /* translators: %d: number of deleted log entries */
                    printf(esc_html__('Deleted %d log entries.', '1platform-content-ai'), intval($deleted)); ?></p>
                </div>
                <?php
            });
        }
    }

    public function render_success_notice(): void {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('All logs cleared successfully.', '1platform-content-ai'); ?></p>
        </div>
        <?php
    }

    public function render(): void {
        $total_logs = $this->repository->count();
        $total_errors = $this->repository->countErrors();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination parameter.
        $current_page = isset($_GET['paged']) ? max(1, absint( wp_unslash( $_GET['paged'] ) )) : 1;
        $offset = ($current_page - 1) * $this->per_page;

        if ($this->filter_type === 'errors') {
            $logs = $this->repository->getErrors($this->per_page, $offset);
            $total = $total_errors;
        } else {
            $logs = $this->repository->getAll($this->per_page, $offset);
            $total = $total_logs;
        }

        $total_pages = ceil($total / $this->per_page);
        ?>
        <div class="contai-settings-panel">
            <div class="contai-panel-header">
                <div class="contai-panel-title-group">
                    <h2 class="contai-panel-title">
                        <span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e('API Request Logs', '1platform-content-ai'); ?>
                    </h2>
                    <p class="contai-panel-description">
                        <?php esc_html_e('Monitor all API requests and responses', '1platform-content-ai'); ?>
                    </p>
                </div>
            </div>

            <div class="contai-panel-body">
                <!-- Stats Summary -->
                <div class="contai-logs-stats">
                    <div class="contai-stat-card">
                        <span class="dashicons dashicons-chart-line"></span>
                        <div class="contai-stat-content">
                            <div class="contai-stat-value"><?php echo esc_html($total_logs); ?></div>
                            <div class="contai-stat-label"><?php esc_html_e('Total Requests', '1platform-content-ai'); ?></div>
                        </div>
                    </div>
                    <div class="contai-stat-card contai-stat-error">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="contai-stat-content">
                            <div class="contai-stat-value"><?php echo esc_html($total_errors); ?></div>
                            <div class="contai-stat-label"><?php esc_html_e('Errors', '1platform-content-ai'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="contai-logs-filters">
                    <?php $this->render_filter_tab('all', __('All Logs', '1platform-content-ai'), $total_logs); ?>
                    <?php $this->render_filter_tab('errors', __('Errors Only', '1platform-content-ai'), $total_errors); ?>
                </div>

                <!-- Logs Table -->
                <?php if (empty($logs)): ?>
                    <div class="contai-notice contai-notice-info">
                        <span class="dashicons dashicons-info"></span>
                        <p><?php esc_html_e('No logs found.', '1platform-content-ai'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="contai-logs-table-wrapper">
                        <table class="contai-logs-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('Method', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('URL', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('Status', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('Duration', '1platform-content-ai'); ?></th>
                                    <th><?php esc_html_e('Actions', '1platform-content-ai'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php $this->render_log_row($log); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="contai-pagination">
                            <?php $this->render_pagination($current_page, $total_pages); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Clear Logs Form -->
                <form method="post" class="contai-logs-clear-form">
                    <?php wp_nonce_field('contai_api_logs_action', 'contai_api_logs_nonce'); ?>
                    <input type="hidden" name="contai_api_logs_action" value="1">

                    <div class="contai-button-group">
                        <button type="submit" name="clear_old_logs" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete old logs?', '1platform-content-ai'); ?>');">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear Logs Older Than 7 Days', '1platform-content-ai'); ?>
                        </button>
                        <input type="hidden" name="days" value="7">

                        <button type="submit" name="clear_all_logs" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete ALL logs?', '1platform-content-ai'); ?>');">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e('Clear All Logs', '1platform-content-ai'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_filter_tab(string $filter, string $label, int $count): void {
        $active = $this->filter_type === $filter ? 'active' : '';
        $url = add_query_arg([
            'page' => 'contai-content-generator',
            'section' => 'api-logs',
            'filter' => $filter
        ], admin_url('admin.php'));
        ?>
        <a href="<?php echo esc_url($url); ?>" class="contai-filter-tab <?php echo esc_attr($active); ?>">
            <?php echo esc_html($label); ?>
            <span class="contai-filter-badge"><?php echo esc_html($count); ?></span>
        </a>
        <?php
    }

    private function render_log_row(array $log): void {
        $has_error = !empty($log['error']) || (isset($log['response_code']) && $log['response_code'] >= 400);
        $row_class = $has_error ? 'contai-log-error' : '';
        $status_class = $this->get_status_class($log['response_code'] ?? null);
        ?>
        <tr class="<?php echo esc_attr($row_class); ?>">
            <td class="contai-log-date">
                <?php echo esc_html(mysql2date('Y-m-d H:i:s', $log['created_at'])); ?>
            </td>
            <td class="contai-log-method">
                <span class="contai-method-badge contai-method-<?php echo esc_attr(strtolower($log['method'])); ?>">
                    <?php echo esc_html($log['method']); ?>
                </span>
            </td>
            <td class="contai-log-url" title="<?php echo esc_attr($log['url']); ?>">
                <?php echo esc_html($this->truncate_url($log['url'])); ?>
            </td>
            <td class="contai-log-status">
                <?php if (isset($log['response_code'])): ?>
                    <span class="contai-status-badge <?php echo esc_attr($status_class); ?>">
                        <?php echo esc_html($log['response_code']); ?>
                    </span>
                <?php else: ?>
                    <span class="contai-status-badge contai-status-unknown">N/A</span>
                <?php endif; ?>
            </td>
            <td class="contai-log-duration">
                <?php if (isset($log['duration'])): ?>
                    <?php echo esc_html(number_format($log['duration'], 3)); ?>s
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td class="contai-log-actions">
                <button type="button" class="button button-small contai-view-details" data-log-id="<?php echo esc_attr($log['id']); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php esc_html_e('Details', '1platform-content-ai'); ?>
                </button>
            </td>
        </tr>
        <tr class="contai-log-details" id="log-details-<?php echo esc_attr($log['id']); ?>" style="display: none;">
            <td colspan="6">
                <div class="contai-log-detail-content">
                    <?php if (!empty($log['error'])): ?>
                        <div class="contai-log-section">
                            <h4><?php esc_html_e('Error', '1platform-content-ai'); ?></h4>
                            <pre class="contai-log-error-message"><?php echo esc_html($log['error']); ?></pre>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($log['request_body'])): ?>
                        <div class="contai-log-section">
                            <h4><?php esc_html_e('Request Body', '1platform-content-ai'); ?></h4>
                            <pre class="contai-log-code"><?php echo esc_html($this->format_json($log['request_body'])); ?></pre>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($log['response_body'])): ?>
                        <div class="contai-log-section">
                            <h4><?php esc_html_e('Response Body', '1platform-content-ai'); ?></h4>
                            <pre class="contai-log-code"><?php echo esc_html($this->format_json($log['response_body'])); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    private function render_pagination(int $current_page, int $total_pages): void {
        $base_url = add_query_arg([
            'page' => 'contai-content-generator',
            'section' => 'api-logs',
            'filter' => $this->filter_type
        ], admin_url('admin.php'));

        if ($current_page > 1): ?>
            <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $base_url)); ?>" class="button">
                <?php esc_html_e('Previous', '1platform-content-ai'); ?>
            </a>
        <?php endif; ?>

        <span class="contai-pagination-info">
            <?php
            /* translators: %1$d: current page number, %2$d: total number of pages */
            printf(esc_html__('Page %1$d of %2$d', '1platform-content-ai'), intval($current_page), intval($total_pages)); ?>
        </span>

        <?php if ($current_page < $total_pages): ?>
            <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $base_url)); ?>" class="button">
                <?php esc_html_e('Next', '1platform-content-ai'); ?>
            </a>
        <?php endif;
    }

    private function get_status_class(?int $code): string {
        if ($code === null) {
            return 'contai-status-unknown';
        }

        if ($code >= 200 && $code < 300) {
            return 'contai-status-success';
        }

        if ($code >= 400 && $code < 500) {
            return 'contai-status-client-error';
        }

        if ($code >= 500) {
            return 'contai-status-server-error';
        }

        return 'contai-status-other';
    }

    private function truncate_url(string $url, int $length = 60): string {
        if (strlen($url) <= $length) {
            return $url;
        }

        return substr($url, 0, $length) . '...';
    }

    private function format_json(?string $json): string {
        if (empty($json)) {
            return '';
        }

        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }
}
