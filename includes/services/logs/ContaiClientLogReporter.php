<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ContaiLogsService.php';

class ContaiClientLogReporter {

    private const BUFFER_TRANSIENT = 'contai_client_logs_buffer';
    private const LOCK_TRANSIENT = 'contai_sync_buffer_lock';
    private const MAX_BUFFER_SIZE = 100;
    private const BUFFER_EXPIRY = 7 * DAY_IN_SECONDS;
    private const LOCK_EXPIRY = 60;

    private const ALLOWED_ENTRY_KEYS = [
        'timestamp', 'method', 'endpoint', 'action', 'source_module',
        'website_id', 'provider', 'response_status', 'response_message',
        'success', 'error_type', 'trace_id', 'request_context',
    ];

    private const ALLOWED_CONTEXT_KEYS = [
        'wp_error_code', 'wp_error_message', 'php_version', 'wp_version', 'plugin_version',
    ];

    private const WP_ERROR_TYPE_MAP = [
        'http_request_failed'  => 'network_error',
        'http_request_timeout' => 'timeout_error',
        'http_failure'         => 'network_error',
    ];

    public static function report(array $entry): void {
        $sanitized = self::sanitizeEntry($entry);

        $service = ContaiLogsService::getInstance();
        $response = $service->reportClientLogs([$sanitized]);

        if (!$response->isSuccess()) {
            self::bufferLocally($sanitized);
        }
    }

    public static function bufferLocally(array $entry): void {
        $sanitized = self::sanitizeEntry($entry);
        $buffer = get_transient(self::BUFFER_TRANSIENT);

        if (!is_array($buffer)) {
            $buffer = [];
        }

        $buffer[] = $sanitized;

        // FIFO: remove oldest if over limit
        while (count($buffer) > self::MAX_BUFFER_SIZE) {
            array_shift($buffer);
        }

        set_transient(self::BUFFER_TRANSIENT, $buffer, self::BUFFER_EXPIRY);
    }

    public static function syncBuffer(): void {
        $lock = get_transient(self::LOCK_TRANSIENT);
        if ($lock) {
            return;
        }

        set_transient(self::LOCK_TRANSIENT, true, self::LOCK_EXPIRY);

        try {
            $buffer = get_transient(self::BUFFER_TRANSIENT);

            if (!is_array($buffer) || empty($buffer)) {
                delete_transient(self::LOCK_TRANSIENT);
                return;
            }

            // Process in chunks of 50
            $chunks = array_chunk($buffer, 50);
            $remainingEntries = [];

            foreach ($chunks as $chunk) {
                $service = ContaiLogsService::getInstance();
                $response = $service->reportClientLogs($chunk);

                if (!$response->isSuccess()) {
                    // Backend still down, keep all entries
                    $remainingEntries = array_merge($remainingEntries, $chunk);
                    continue;
                }

                $data = $response->getData();
                $rejected = $data['rejected'] ?? 0;

                if ($rejected > 0 && !empty($data['errors'])) {
                    // Keep rejected entries
                    $rejectedIndices = array_column($data['errors'], 'index');
                    foreach ($rejectedIndices as $index) {
                        if (isset($chunk[$index])) {
                            $remainingEntries[] = $chunk[$index];
                        }
                    }
                }
            }

            if (empty($remainingEntries)) {
                delete_transient(self::BUFFER_TRANSIENT);
            } else {
                set_transient(self::BUFFER_TRANSIENT, $remainingEntries, self::BUFFER_EXPIRY);
            }
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public static function getBufferCount(): int {
        $buffer = get_transient(self::BUFFER_TRANSIENT);
        return is_array($buffer) ? count($buffer) : 0;
    }

    public static function buildEntryFromWpError(
        WP_Error $error,
        string $method,
        string $endpoint,
        string $action,
        string $sourceModule,
        ?string $websiteId = null,
        ?string $provider = null
    ): array {
        return [
            'timestamp'        => gmdate('c'),
            'method'           => $method,
            'endpoint'         => $endpoint,
            'action'           => $action,
            'source_module'    => $sourceModule,
            'website_id'       => $websiteId,
            'provider'         => $provider,
            'response_status'  => 0,
            'response_message' => substr($error->get_error_message(), 0, 2000),
            'success'          => false,
            'error_type'       => self::mapWpErrorToType($error->get_error_code()),
            'trace_id'   => null,
            'request_context'  => [
                'wp_error_code'    => $error->get_error_code(),
                'wp_error_message' => substr($error->get_error_message(), 0, 200),
                'php_version'      => PHP_VERSION,
                'wp_version'       => get_bloginfo('version'),
                'plugin_version'   => defined('CONTAI_VERSION') ? CONTAI_VERSION : '2.3.5',
            ],
        ];
    }

    private static function mapWpErrorToType(string $code): string {
        return self::WP_ERROR_TYPE_MAP[$code] ?? 'unknown_error';
    }

    private static function sanitizeEntry(array $entry): array {
        $sanitized = [];

        foreach (self::ALLOWED_ENTRY_KEYS as $key) {
            if (isset($entry[$key])) {
                $sanitized[$key] = $entry[$key];
            }
        }

        // Truncate response_message
        if (isset($sanitized['response_message'])) {
            $sanitized['response_message'] = substr($sanitized['response_message'], 0, 2000);
        }

        // Filter request_context
        if (isset($sanitized['request_context']) && is_array($sanitized['request_context'])) {
            $filteredContext = [];
            foreach (self::ALLOWED_CONTEXT_KEYS as $key) {
                if (isset($sanitized['request_context'][$key])) {
                    $filteredContext[$key] = substr((string) $sanitized['request_context'][$key], 0, 200);
                }
            }
            $sanitized['request_context'] = $filteredContext;
        }

        return $sanitized;
    }
}
