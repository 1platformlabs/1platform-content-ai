<?php
if (!defined('ABSPATH')) exit;

class ContaiLogsAdapter {

    const ERROR_TYPE_LABELS = [
        'auth_error'       => 'Authentication Error',
        'validation_error' => 'Validation Error',
        'provider_error'   => 'Provider Error',
        'timeout_error'    => 'Timeout',
        'network_error'    => 'Network Error',
        'not_found'        => 'Not Found',
        'rate_limit'       => 'Rate Limited',
        'internal_error'   => 'Internal Error',
        'unknown_error'    => 'Unknown Error',
    ];

    const PROVIDER_DISPLAY_NAMES = [
        'publisuites'      => 'Link Building',
        'search_console'   => 'Search Console',
        'openai'           => 'AI Engine',
        'pixabay'          => 'Image Library',
        'pexels'           => 'Image Library',
        'valueserp'        => 'Search Analytics',
        'migo'             => 'Payments',
        'tributax'         => 'Invoicing',
    ];

    const VALID_ERROR_TYPES = [
        'auth_error', 'validation_error', 'provider_error', 'timeout_error',
        'network_error', 'not_found', 'rate_limit', 'internal_error', 'unknown_error',
    ];

    public static function fromListResponse(ContaiOnePlatformResponse $response): array {
        $data = $response->getData();
        return [
            'items' => array_map([self::class, 'toLogListItem'], $data['items'] ?? []),
            'pagination' => self::extractPagination($data),
        ];
    }

    public static function fromDetailResponse(ContaiOnePlatformResponse $response): array {
        $data = $response->getData();
        return self::toLogDetail($data ?? []);
    }

    public static function toLogListItem(array $item): array {
        return [
            'id'               => $item['id'] ?? '',
            'timestamp'        => self::formatTimestamp($item['timestamp'] ?? ''),
            'timestamp_raw'    => $item['timestamp'] ?? '',
            'method'           => $item['method'] ?? '',
            'endpoint'         => $item['endpoint'] ?? '',
            'action'           => $item['action'] ?? '',
            'source_module'    => $item['source_module'] ?? '',
            'website_id'       => $item['website_id'] ?? '',
            'provider'         => $item['provider'] ?? '',
            'source_type'      => $item['source_type'] ?? 'server',
            'response_status'  => (int) ($item['response_status'] ?? 0),
            'response_message' => $item['response_message'] ?? '',
            'success'          => (bool) ($item['success'] ?? false),
            'error_type'       => $item['error_type'] ?? null,
            'trace_id'   => $item['trace_id'] ?? '',
            'occurrences'      => (int) ($item['occurrences'] ?? 1),
        ];
    }

    public static function toLogDetail(array $item): array {
        return array_merge(self::toLogListItem($item), [
            'request_payload'  => $item['request_payload'] ?? null,
            'response_data'    => $item['response_data'] ?? null,
            'request_context'  => $item['request_context'] ?? null,
            'created_at'       => isset($item['created_at']) ? self::formatTimestamp($item['created_at']) : '',
        ]);
    }

    public static function extractPagination(array $data): array {
        $pagination = $data['pagination'] ?? [];
        return [
            'page'        => (int) ($pagination['page'] ?? 1),
            'page_size'   => (int) ($pagination['page_size'] ?? 20),
            'total'       => (int) ($pagination['total'] ?? 0),
            'total_pages' => (int) ($pagination['total_pages'] ?? 0),
        ];
    }

    public static function formatTimestamp(string $isoTimestamp): string {
        if (empty($isoTimestamp)) {
            return '—';
        }
        try {
            $dt = new DateTime($isoTimestamp, new DateTimeZone('UTC'));
            $dt->setTimezone(wp_timezone());
            return $dt->format(get_option('date_format') . ' ' . get_option('time_format'));
        } catch (Exception $e) {
            return $isoTimestamp;
        }
    }

    public static function getProviderDisplayName(string $provider): string {
        if (empty($provider)) {
            return '—';
        }
        return self::PROVIDER_DISPLAY_NAMES[$provider] ?? ucfirst($provider);
    }

    public static function getErrorTypeLabel(string $errorType = null): string {
        if ($errorType === null || $errorType === '') {
            return '';
        }
        return self::ERROR_TYPE_LABELS[$errorType] ?? ucfirst(str_replace('_', ' ', $errorType));
    }

    public static function getStatusBadgeClass(int $status): string {
        if ($status === 0) return 'contai-badge-neutral';
        if ($status >= 200 && $status < 300) return 'contai-badge-success';
        if ($status >= 300 && $status < 400) return 'contai-badge-info';
        if ($status >= 400 && $status < 500) return 'contai-badge-warning';
        if ($status >= 500) return 'contai-badge-danger';
        return 'contai-badge-neutral';
    }
}
