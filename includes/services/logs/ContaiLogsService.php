<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';

class ContaiLogsService {

    private static ?self $instance = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function listLogs(array $filters = []): ContaiOnePlatformResponse {
        $client = ContaiOnePlatformClient::create();
        $params = $this->buildFilterParams($filters);
        return $client->get('/logs', $params);
    }

    public function getLogDetail(string $logId): ContaiOnePlatformResponse {
        $client = ContaiOnePlatformClient::create();
        return $client->get('/logs/' . sanitize_text_field($logId));
    }

    public function clearLogs(array $payload = []): ContaiOnePlatformResponse {
        $client = ContaiOnePlatformClient::create();
        // For DELETE with body, we need to use the client
        // But the existing client's delete() doesn't accept data
        // We'll send the filters as query params instead
        return $client->delete('/logs?' . http_build_query($payload));
    }

    public function reportClientLogs(array $entries): ContaiOnePlatformResponse {
        $client = ContaiOnePlatformClient::create();
        return $client->post('/logs/client', ['entries' => $entries]);
    }

    private function buildFilterParams(array $filters): array {
        $params = [];
        $allowed = [
            'website_id', 'provider', 'success', 'error_type',
            'status_code', 'endpoint', 'source_type', 'trace_id',
            'from_date', 'to_date', 'page', 'page_size'
        ];

        foreach ($allowed as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $params[$key] = $filters[$key];
            }
        }

        // Defaults
        if (!isset($params['page'])) {
            $params['page'] = 1;
        }
        if (!isset($params['page_size'])) {
            $params['page_size'] = 20;
        }

        return $params;
    }
}
