<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . '/../../services/logs/ContaiLogsService.php';
require_once plugin_dir_path(__FILE__) . '/../../adapters/ContaiLogsAdapter.php';
require_once plugin_dir_path(__FILE__) . '/../../services/logs/ContaiClientLogReporter.php';

class ContaiLogsPanel {

    private ContaiLogsService $service;
    private array $filters = [];
    private array $logData = [];
    private ?string $errorMessage = null;
    private ?string $successMessage = null;

    public function __construct() {
        $this->service = ContaiLogsService::getInstance();
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', '1platform-content-ai'));
        }

        $this->handleClearLogsAction();
        $this->loadFilters();
        $this->fetchLogs();

        // Enqueue CSS using admin-level base URL (avoids /../ in URLs)
        $admin_base_url = plugin_dir_url(dirname(__FILE__));

        contai_enqueue_style_with_version(
            'contai-base',
            $admin_base_url . 'content-generator/assets/css/base.css'
        );

        contai_enqueue_style_with_version(
            'contai-logs-panel',
            $admin_base_url . 'logs/assets/css/logs-panel.css',
            ['contai-base']
        );

        echo '<div class="wrap">';
        $this->renderPageHeader();
        $this->renderNotices();
        $this->renderPendingBufferBanner();

        // Check if viewing detail
        $detail_id = isset($_GET['log_id']) ? sanitize_text_field(wp_unslash($_GET['log_id'])) : '';
        if (!empty($detail_id)) {
            $this->renderDetailView($detail_id);
        } else {
            $this->renderStats();
            $this->renderFilters();
            if ($this->errorMessage) {
                $this->renderErrorState();
            } elseif (empty($this->logData['items'])) {
                $this->renderEmptyState();
            } else {
                $this->renderTable();
            }
        }

        echo '</div>';

        $this->renderInlineScript();
    }

    private function handleClearLogsAction(): void {
        if (!isset($_POST['contai_clear_logs_action'])) {
            return;
        }

        if (!isset($_POST['contai_clear_logs_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['contai_clear_logs_nonce'])), 'contai_clear_logs')) {
            $this->errorMessage = 'Security check failed.';
            return;
        }

        if (!current_user_can('manage_options')) {
            $this->errorMessage = 'Unauthorized.';
            return;
        }

        $payload = [];
        if (!empty($_POST['clear_provider'])) {
            $payload['provider'] = sanitize_text_field(wp_unslash($_POST['clear_provider']));
        }
        if (!empty($_POST['clear_website_id'])) {
            $payload['website_id'] = sanitize_text_field(wp_unslash($_POST['clear_website_id']));
        }

        $response = $this->service->clearLogs($payload);

        if ($response->isSuccess()) {
            $data = $response->getData();
            $count = $data['deleted_count'] ?? 0;
            $this->successMessage = sprintf('%d log(s) cleared successfully.', (int) $count);
        } else {
            $this->errorMessage = $response->getMessage() ?? 'Failed to clear logs.';
        }
    }

    private function loadFilters(): void {
        $this->filters = [
            'website_id'     => isset($_GET['website_id']) ? sanitize_text_field(wp_unslash($_GET['website_id'])) : '',
            'provider'       => isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '',
            'success'        => isset($_GET['success']) ? sanitize_text_field(wp_unslash($_GET['success'])) : '',
            'error_type'     => isset($_GET['error_type']) ? sanitize_text_field(wp_unslash($_GET['error_type'])) : '',
            'status_code'    => isset($_GET['status_code']) ? absint($_GET['status_code']) : '',
            'endpoint'       => isset($_GET['endpoint']) ? sanitize_text_field(wp_unslash($_GET['endpoint'])) : '',
            'source_type'    => isset($_GET['source_type']) ? sanitize_text_field(wp_unslash($_GET['source_type'])) : '',
            'trace_id' => isset($_GET['trace_id']) ? sanitize_text_field(wp_unslash($_GET['trace_id'])) : '',
            'from_date'      => isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '',
            'to_date'        => isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '',
            'page'           => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
            'page_size'      => 20,
        ];

        if (!empty($this->filters['error_type']) && !in_array($this->filters['error_type'], ContaiLogsAdapter::VALID_ERROR_TYPES, true)) {
            $this->filters['error_type'] = '';
        }

        if (!empty($this->filters['source_type']) && !in_array($this->filters['source_type'], ['server', 'client'], true)) {
            $this->filters['source_type'] = '';
        }

        if ($this->filters['success'] !== '' && !in_array($this->filters['success'], ['true', 'false'], true)) {
            $this->filters['success'] = '';
        }
    }

    private function fetchLogs(): void {
        $response = $this->service->listLogs($this->filters);

        if ($response->isSuccess()) {
            $this->logData = ContaiLogsAdapter::fromListResponse($response);
        } else {
            $this->errorMessage = $response->getMessage() ?? 'Failed to fetch logs.';
            $this->logData = ['items' => [], 'pagination' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 0]];
        }
    }

    private function renderPageHeader(): void {
        echo '<div class="contai-page-header">';
        echo '<h1><span class="dashicons dashicons-media-text"></span>' . esc_html__('Logs', '1platform-content-ai') . '</h1>';
        echo '<p class="contai-page-subtitle">' . esc_html__('Monitor API requests, diagnose integration issues, and track errors across all modules.', '1platform-content-ai') . '</p>';
        echo '</div>';
    }

    private function renderNotices(): void {
        if (!empty($this->successMessage)) {
            echo '<div class="contai-notice contai-notice-success"><span class="dashicons dashicons-yes-alt"></span><p>' . esc_html($this->successMessage) . '</p></div>';
        }
    }

    private function renderPendingBufferBanner(): void {
        $count = ContaiClientLogReporter::getBufferCount();
        if ($count <= 0) {
            return;
        }
        echo '<div class="contai-notice contai-notice-warning"><span class="dashicons dashicons-warning"></span>';
        echo '<p>';
        printf(
            esc_html__('%d error(s) pending sync. These occurred while the backend was unreachable and will be synchronized automatically on the next successful connection.', '1platform-content-ai'),
            (int) $count
        );
        echo '</p></div>';
    }

    private function renderStats(): void {
        $pagination = $this->logData['pagination'] ?? ['total' => 0, 'page' => 1, 'total_pages' => 0];
        $total = $pagination['total'];
        $page = $pagination['page'];
        $totalPages = $pagination['total_pages'];

        // Count errors/successes in current page
        $errors = 0;
        $successes = 0;
        foreach ($this->logData['items'] as $item) {
            if ($item['success']) {
                $successes++;
            } else {
                $errors++;
            }
        }

        echo '<div class="contai-logs-stats">';

        // Total logs
        echo '<div class="contai-logs-stat-card">';
        echo '<div class="contai-logs-stat-icon contai-logs-stat-icon--total"><span class="dashicons dashicons-database"></span></div>';
        echo '<div class="contai-logs-stat-info">';
        echo '<div class="contai-logs-stat-value">' . esc_html(number_format_i18n($total)) . '</div>';
        echo '<div class="contai-logs-stat-label">' . esc_html__('Total Logs', '1platform-content-ai') . '</div>';
        echo '</div></div>';

        // Errors on page
        echo '<div class="contai-logs-stat-card">';
        echo '<div class="contai-logs-stat-icon contai-logs-stat-icon--errors"><span class="dashicons dashicons-dismiss"></span></div>';
        echo '<div class="contai-logs-stat-info">';
        echo '<div class="contai-logs-stat-value">' . esc_html($errors) . '</div>';
        echo '<div class="contai-logs-stat-label">' . esc_html__('Errors on Page', '1platform-content-ai') . '</div>';
        echo '</div></div>';

        // Successes on page
        echo '<div class="contai-logs-stat-card">';
        echo '<div class="contai-logs-stat-icon contai-logs-stat-icon--success"><span class="dashicons dashicons-yes-alt"></span></div>';
        echo '<div class="contai-logs-stat-info">';
        echo '<div class="contai-logs-stat-value">' . esc_html($successes) . '</div>';
        echo '<div class="contai-logs-stat-label">' . esc_html__('Success on Page', '1platform-content-ai') . '</div>';
        echo '</div></div>';

        // Current page
        echo '<div class="contai-logs-stat-card">';
        echo '<div class="contai-logs-stat-icon contai-logs-stat-icon--page"><span class="dashicons dashicons-admin-page"></span></div>';
        echo '<div class="contai-logs-stat-info">';
        echo '<div class="contai-logs-stat-value">' . esc_html($page) . ' <span class="contai-logs-stat-value-total">/ ' . esc_html($totalPages ?: 1) . '</span></div>';
        echo '<div class="contai-logs-stat-label">' . esc_html__('Current Page', '1platform-content-ai') . '</div>';
        echo '</div></div>';

        echo '</div>';
    }

    private function getActiveFilterCount(): int {
        $count = 0;
        $filterKeys = ['trace_id', 'provider', 'error_type', 'source_type', 'success', 'from_date', 'to_date', 'website_id'];
        foreach ($filterKeys as $key) {
            if (!empty($this->filters[$key])) {
                $count++;
            }
        }
        return $count;
    }

    private function renderFilters(): void {
        $baseUrl = admin_url('admin.php?page=contai-logs');
        $activeCount = $this->getActiveFilterCount();
        $isOpen = $activeCount > 0;

        echo '<div class="contai-logs-filters' . ($isOpen ? ' is-open' : '') . '" id="contai-logs-filters">';

        // Toggle header
        echo '<button type="button" class="contai-logs-filters-toggle" onclick="document.getElementById(\'contai-logs-filters\').classList.toggle(\'is-open\')">';
        echo '<div class="contai-logs-filters-toggle-left">';
        echo '<span class="dashicons dashicons-filter"></span>';
        echo '<span>' . esc_html__('Filters', '1platform-content-ai') . '</span>';
        if ($activeCount > 0) {
            echo '<span class="contai-logs-active-count">' . esc_html($activeCount) . '</span>';
        }
        echo '</div>';
        echo '<div class="contai-logs-filters-toggle-right">';
        echo '<span class="dashicons dashicons-arrow-down-alt2"></span>';
        echo '</div>';
        echo '</button>';

        // Filter body
        echo '<div class="contai-logs-filters-body">';
        echo '<form method="get" action="' . esc_url($baseUrl) . '">';
        echo '<input type="hidden" name="page" value="contai-logs" />';

        echo '<div class="contai-logs-filters-grid">';

        // Trace ID
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Trace ID', '1platform-content-ai') . '</label>';
        echo '<input type="text" name="trace_id" class="contai-input" value="' . esc_attr($this->filters['trace_id']) . '" placeholder="req_..." />';
        echo '</div>';

        // Provider
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Provider', '1platform-content-ai') . '</label>';
        echo '<select name="provider" class="contai-select">';
        echo '<option value="">' . esc_html__('All Providers', '1platform-content-ai') . '</option>';
        foreach (ContaiLogsAdapter::PROVIDER_DISPLAY_NAMES as $value => $label) {
            $selected = ($this->filters['provider'] === $value) ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Error Type
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Error Type', '1platform-content-ai') . '</label>';
        echo '<select name="error_type" class="contai-select">';
        echo '<option value="">' . esc_html__('All Types', '1platform-content-ai') . '</option>';
        foreach (ContaiLogsAdapter::ERROR_TYPE_LABELS as $value => $label) {
            $selected = ($this->filters['error_type'] === $value) ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Source Type
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Source', '1platform-content-ai') . '</label>';
        echo '<select name="source_type" class="contai-select">';
        echo '<option value="">' . esc_html__('All Sources', '1platform-content-ai') . '</option>';
        $selected_server = ($this->filters['source_type'] === 'server') ? ' selected' : '';
        $selected_client = ($this->filters['source_type'] === 'client') ? ' selected' : '';
        echo '<option value="server"' . $selected_server . '>' . esc_html__('Server', '1platform-content-ai') . '</option>';
        echo '<option value="client"' . $selected_client . '>' . esc_html__('Client', '1platform-content-ai') . '</option>';
        echo '</select>';
        echo '</div>';

        // Status
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Status', '1platform-content-ai') . '</label>';
        echo '<select name="success" class="contai-select">';
        echo '<option value="">' . esc_html__('All', '1platform-content-ai') . '</option>';
        $selected_true = ($this->filters['success'] === 'true') ? ' selected' : '';
        $selected_false = ($this->filters['success'] === 'false') ? ' selected' : '';
        echo '<option value="true"' . $selected_true . '>' . esc_html__('Success', '1platform-content-ai') . '</option>';
        echo '<option value="false"' . $selected_false . '>' . esc_html__('Error', '1platform-content-ai') . '</option>';
        echo '</select>';
        echo '</div>';

        // From Date
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('From Date', '1platform-content-ai') . '</label>';
        echo '<input type="date" name="from_date" class="contai-input" value="' . esc_attr($this->filters['from_date']) . '" />';
        echo '</div>';

        // To Date
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('To Date', '1platform-content-ai') . '</label>';
        echo '<input type="date" name="to_date" class="contai-input" value="' . esc_attr($this->filters['to_date']) . '" />';
        echo '</div>';

        // Website ID
        echo '<div class="contai-form-group">';
        echo '<label class="contai-label">' . esc_html__('Website ID', '1platform-content-ai') . '</label>';
        echo '<input type="text" name="website_id" class="contai-input" value="' . esc_attr($this->filters['website_id']) . '" placeholder="abc123" />';
        echo '</div>';

        echo '</div>'; // end grid

        // Actions row
        echo '<div class="contai-logs-filters-actions">';
        echo '<div class="contai-logs-filters-actions-left">';
        echo '<button type="submit" class="button button-primary contai-logs-filters-apply"><span class="dashicons dashicons-search"></span> ' . esc_html__('Apply Filters', '1platform-content-ai') . '</button>';
        echo '<a href="' . esc_url($baseUrl) . '" class="button button-secondary">' . esc_html__('Reset', '1platform-content-ai') . '</a>';
        echo '</div>';

        // Clear Logs (right-aligned, subtle)
        echo '<form method="post" class="contai-logs-clear-form" onsubmit="return confirm(\'' . esc_js(__('Are you sure you want to clear all logs? This action cannot be undone.', '1platform-content-ai')) . '\');">';
        wp_nonce_field('contai_clear_logs', 'contai_clear_logs_nonce');
        echo '<input type="hidden" name="contai_clear_logs_action" value="1" />';
        if (!empty($this->filters['provider'])) {
            echo '<input type="hidden" name="clear_provider" value="' . esc_attr($this->filters['provider']) . '" />';
        }
        if (!empty($this->filters['website_id'])) {
            echo '<input type="hidden" name="clear_website_id" value="' . esc_attr($this->filters['website_id']) . '" />';
        }
        echo '<button type="submit" class="contai-logs-clear-btn"><span class="dashicons dashicons-trash"></span> ' . esc_html__('Clear Logs', '1platform-content-ai') . '</button>';
        echo '</form>';

        echo '</div>'; // end actions

        echo '</form>';
        echo '</div>'; // end filter body
        echo '</div>'; // end filters container
    }

    private function renderTable(): void {
        echo '<div class="contai-logs-table-card">';
        echo '<div class="contai-logs-table-wrap">';
        echo '<table class="contai-logs-table">';

        // Header — consolidated columns
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Status', '1platform-content-ai') . '</th>';
        echo '<th>' . esc_html__('Request', '1platform-content-ai') . '</th>';
        echo '<th>' . esc_html__('Module', '1platform-content-ai') . '</th>';
        echo '<th>' . esc_html__('Message', '1platform-content-ai') . '</th>';
        echo '<th>' . esc_html__('Source', '1platform-content-ai') . '</th>';
        echo '<th>' . esc_html__('Time', '1platform-content-ai') . '</th>';
        echo '<th></th>';
        echo '</tr></thead>';

        echo '<tbody>';
        foreach ($this->logData['items'] as $item) {
            $this->renderTableRow($item);
        }
        echo '</tbody>';

        echo '</table>';
        echo '</div>';

        // Pagination inside the card
        $this->renderPagination();

        echo '</div>';
    }

    private function renderTableRow(array $item): void {
        $rowClass = $item['success'] ? 'contai-row-success' : 'contai-row-error';
        echo '<tr class="' . esc_attr($rowClass) . '">';

        // Status — combined HTTP code + result with dot indicator
        $badgeClass = ContaiLogsAdapter::getStatusBadgeClass($item['response_status']);
        $statusCode = $item['response_status'] > 0 ? $item['response_status'] : 'N/A';
        $dotClass = $item['success'] ? 'contai-logs-status-dot--success' : 'contai-logs-status-dot--error';
        echo '<td>';
        echo '<span class="contai-logs-status-dot ' . esc_attr($dotClass) . '"></span>';
        echo '<span class="contai-badge ' . esc_attr($badgeClass) . '">' . esc_html($statusCode) . '</span>';
        echo '</td>';

        // Request — method + endpoint stacked, trace ID below
        $method = strtoupper($item['method'] ?: 'GET');
        $methodClass = 'contai-logs-method-' . strtolower($method);
        $endpoint = $item['endpoint'] ?: '—';
        // Show only the path portion for readability
        $endpointPath = $endpoint;
        if (strpos($endpoint, '/api/') !== false) {
            $endpointPath = substr($endpoint, strpos($endpoint, '/api/'));
        }
        $endpointDisplay = strlen($endpointPath) > 45 ? substr($endpointPath, 0, 45) . '...' : $endpointPath;

        echo '<td>';
        echo '<div><span class="contai-logs-cell-method ' . esc_attr($methodClass) . '">' . esc_html($method) . '</span>';
        echo '<span class="contai-logs-cell-endpoint" title="' . esc_attr($endpoint) . '">' . esc_html($endpointDisplay) . '</span></div>';
        if (!empty($item['trace_id'])) {
            echo '<div class="contai-logs-cell-sub contai-logs-cell-trace" title="' . esc_attr($item['trace_id']) . '">' . esc_html($item['trace_id']) . '</div>';
        }
        echo '</td>';

        // Module — provider + action stacked
        $provider = ContaiLogsAdapter::getProviderDisplayName($item['provider']);
        $action = !empty($item['action']) ? $item['action'] : '';
        echo '<td>';
        echo '<div class="contai-logs-cell-main">' . esc_html($provider) . '</div>';
        if ($action) {
            echo '<div class="contai-logs-cell-sub">' . esc_html($action) . '</div>';
        }
        echo '</td>';

        // Message
        $msg = $item['response_message'] ?: '—';
        $msgDisplay = strlen($msg) > 50 ? substr($msg, 0, 50) . '...' : $msg;
        echo '<td>';
        if (!$item['success']) {
            $errorLabel = ContaiLogsAdapter::getErrorTypeLabel($item['error_type']);
            if (!empty($errorLabel)) {
                echo '<span class="contai-badge contai-badge-danger contai-logs-message-badge">' . esc_html($errorLabel) . '</span><br>';
            }
        }
        echo '<span class="contai-logs-cell-sub" title="' . esc_attr($msg) . '">' . esc_html($msgDisplay) . '</span>';
        echo '</td>';

        // Source
        $sourceClass = ($item['source_type'] === 'client') ? 'contai-badge-client' : 'contai-badge-info';
        echo '<td><span class="contai-badge ' . esc_attr($sourceClass) . '">' . esc_html(ucfirst($item['source_type'])) . '</span></td>';

        // Time — relative-style display
        echo '<td><span class="contai-logs-cell-sub contai-logs-cell-time">' . esc_html($item['timestamp']) . '</span></td>';

        // Actions
        $detailUrl = admin_url('admin.php?page=contai-logs&log_id=' . urlencode($item['id']));
        echo '<td><a href="' . esc_url($detailUrl) . '" class="contai-logs-view-btn"><span class="dashicons dashicons-visibility"></span></a></td>';

        echo '</tr>';
    }

    private function renderDetailView(string $logId): void {
        $response = $this->service->getLogDetail($logId);

        if (!$response->isSuccess()) {
            echo '<div class="contai-notice contai-notice-error"><span class="dashicons dashicons-warning"></span><p>' . esc_html($response->getMessage() ?? 'Log not found.') . '</p></div>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=contai-logs')) . '" class="contai-logs-detail-back"><span class="dashicons dashicons-arrow-left-alt2"></span> ' . esc_html__('Back to Logs', '1platform-content-ai') . '</a>';
            return;
        }

        $detail = ContaiLogsAdapter::fromDetailResponse($response);

        // Back button
        echo '<a href="' . esc_url(admin_url('admin.php?page=contai-logs')) . '" class="contai-logs-detail-back"><span class="dashicons dashicons-arrow-left-alt2"></span> ' . esc_html__('Back to Logs', '1platform-content-ai') . '</a>';

        echo '<div class="contai-logs-table-card">';

        // Detail header with status
        $dotClass = $detail['success'] ? 'contai-logs-status-dot--success' : 'contai-logs-status-dot--error';
        $successBadge = $detail['success']
            ? '<span class="contai-badge contai-badge-success">Success</span>'
            : '<span class="contai-badge contai-badge-danger">Error</span>';
        $badgeClass = ContaiLogsAdapter::getStatusBadgeClass($detail['response_status']);
        $statusDisplay = $detail['response_status'] > 0 ? $detail['response_status'] : 'N/A';

        echo '<div class="contai-logs-detail-header">';
        echo '<div class="contai-logs-detail-header-text">';
        echo '<h3>' . esc_html__('Log Detail', '1platform-content-ai') . '</h3>';
        echo '<div class="contai-logs-detail-header-meta">';
        echo '<span class="contai-logs-status-dot ' . esc_attr($dotClass) . '"></span>';
        echo $successBadge;
        echo '<span class="contai-badge ' . esc_attr($badgeClass) . '">' . esc_html($statusDisplay) . '</span>';
        if (!empty($detail['trace_id'])) {
            echo '<code class="contai-logs-detail-trace">' . esc_html($detail['trace_id']) . '</code>';
        }
        echo '</div></div></div>';

        // Info grid
        echo '<div class="contai-logs-detail-grid">';

        $this->renderDetailField('Timestamp', $detail['timestamp'] . (!empty($detail['timestamp_raw']) ? ' (' . $detail['timestamp_raw'] . ' UTC)' : ''));
        $this->renderDetailField('Method', $detail['method'] ?: '—');
        $this->renderDetailField('Endpoint', $detail['endpoint'] ?: '—');
        $this->renderDetailField('Action', $detail['action'] ?: '—');
        $this->renderDetailField('Module', $detail['source_module'] ?: '—');
        $this->renderDetailField('Provider', ContaiLogsAdapter::getProviderDisplayName($detail['provider']));
        $this->renderDetailField('Website ID', $detail['website_id'] ?: '—');
        $this->renderDetailField('Source Type', ucfirst($detail['source_type']));
        $this->renderDetailField('Response Message', $detail['response_message'] ?: '—');

        if (!empty($detail['error_type'])) {
            $this->renderDetailField('Error Type', ContaiLogsAdapter::getErrorTypeLabel($detail['error_type']));
        }

        if ($detail['occurrences'] > 1) {
            $this->renderDetailField('Occurrences', (string) $detail['occurrences']);
        }

        echo '</div>';

        // Request Payload
        if (!empty($detail['request_payload'])) {
            echo '<div class="contai-logs-detail-payload">';
            echo '<p class="contai-logs-detail-payload-title">' . esc_html__('Request Payload', '1platform-content-ai') . '</p>';
            echo '<pre class="contai-logs-detail-code"><code>' . esc_html(wp_json_encode($detail['request_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</code></pre>';
            echo '</div>';
        }

        // Response Data
        if (!empty($detail['response_data'])) {
            echo '<div class="contai-logs-detail-payload">';
            echo '<p class="contai-logs-detail-payload-title">' . esc_html__('Response Data', '1platform-content-ai') . '</p>';
            echo '<pre class="contai-logs-detail-code"><code>' . esc_html(wp_json_encode($detail['response_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</code></pre>';
            echo '</div>';
        }

        // Client Context
        if (!empty($detail['request_context']) && $detail['source_type'] === 'client') {
            echo '<div class="contai-logs-detail-payload">';
            echo '<p class="contai-logs-detail-payload-title">' . esc_html__('Client Context', '1platform-content-ai') . '</p>';
            echo '<table class="contai-logs-context-table"><tbody>';
            $allowedKeys = ['wp_error_code', 'wp_error_message', 'php_version', 'wp_version', 'plugin_version'];
            foreach ($allowedKeys as $key) {
                if (isset($detail['request_context'][$key])) {
                    echo '<tr><td>' . esc_html($key) . '</td><td>' . esc_html($detail['request_context'][$key]) . '</td></tr>';
                }
            }
            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</div>'; // end card
    }

    private function renderDetailField(string $label, string $value): void {
        echo '<div class="contai-logs-detail-field">';
        echo '<div class="contai-logs-detail-field-label">' . esc_html($label) . '</div>';
        echo '<div class="contai-logs-detail-field-value">' . esc_html($value) . '</div>';
        echo '</div>';
    }

    private function renderPagination(): void {
        $pagination = $this->logData['pagination'];

        if ($pagination['total'] === 0) {
            return;
        }

        $page = $pagination['page'];
        $totalPages = $pagination['total_pages'];
        $total = $pagination['total'];
        $pageSize = $pagination['page_size'];

        $from = (($page - 1) * $pageSize) + 1;
        $to = min($page * $pageSize, $total);

        echo '<div class="contai-logs-pagination">';

        echo '<span class="contai-logs-pagination-info">';
        printf(
            esc_html__('Showing %1$d–%2$d of %3$d logs', '1platform-content-ai'),
            $from,
            $to,
            $total
        );
        echo '</span>';

        echo '<div class="contai-logs-pagination-nav">';

        if ($page > 1) {
            $prevUrl = $this->buildPaginationUrl($page - 1);
            echo '<a href="' . esc_url($prevUrl) . '" class="contai-logs-pagination-btn">&larr; ' . esc_html__('Previous', '1platform-content-ai') . '</a>';
        }

        if ($page < $totalPages) {
            $nextUrl = $this->buildPaginationUrl($page + 1);
            echo '<a href="' . esc_url($nextUrl) . '" class="contai-logs-pagination-btn">' . esc_html__('Next', '1platform-content-ai') . ' &rarr;</a>';
        }

        echo '</div></div>';
    }

    private function buildPaginationUrl(int $page): string {
        $params = ['page' => 'contai-logs', 'paged' => $page];

        foreach (['website_id', 'provider', 'success', 'error_type', 'status_code', 'endpoint', 'source_type', 'trace_id', 'from_date', 'to_date'] as $key) {
            if (!empty($this->filters[$key])) {
                $params[$key] = $this->filters[$key];
            }
        }

        return admin_url('admin.php?' . http_build_query($params));
    }

    private function renderEmptyState(): void {
        echo '<div class="contai-logs-table-card">';
        echo '<div class="contai-logs-empty">';
        echo '<div class="contai-logs-empty-icon"><span class="dashicons dashicons-media-text"></span></div>';
        echo '<p class="contai-logs-empty-title">' . esc_html__('No logs found', '1platform-content-ai') . '</p>';
        echo '<p class="contai-logs-empty-text">' . esc_html__('No logs match the selected filters. Try adjusting your search criteria.', '1platform-content-ai') . '</p>';
        echo '</div></div>';
    }

    private function renderErrorState(): void {
        echo '<div class="contai-logs-table-card contai-logs-error-wrap">';
        echo '<div class="contai-notice contai-notice-error"><span class="dashicons dashicons-warning"></span><p>' . esc_html($this->errorMessage) . '</p></div>';
        echo '</div>';
    }

    private function renderInlineScript(): void {
        ?>
        <script>
        (function() {
            // Auto-open filters if they have active values (already handled via PHP class)
            // Add keyboard shortcut: press 'f' to toggle filters when not focused on input
            document.addEventListener('keydown', function(e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
                if (e.key === 'f' || e.key === 'F') {
                    var filters = document.getElementById('contai-logs-filters');
                    if (filters) {
                        filters.classList.toggle('is-open');
                        e.preventDefault();
                    }
                }
            });
        })();
        </script>
        <?php
    }
}
