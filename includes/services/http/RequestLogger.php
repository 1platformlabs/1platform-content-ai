<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../config/Config.php';

class ContaiRequestLogger {

    private const TABLE_NAME = 'contai_api_logs';
    private const OPTION_LOGGING_ENABLED = 'contai_api_logging_enabled';
    private const OPTION_VALUE_ENABLED = '1';
    private const OPTION_VALUE_DISABLED = '0';

    private const ERROR_STATUS_CODE_THRESHOLD = 400;
    private const DEFAULT_LOG_LIMIT = 100;
    private const DEFAULT_URL_LOG_LIMIT = 50;
    private const DEFAULT_ERROR_LOG_LIMIT = 50;
    private const DEFAULT_RETENTION_DAYS = 30;

    private const SQL_ORDER_BY_DATE_DESC = 'ORDER BY created_at DESC';
    private const SQL_WHERE_ERROR = 'WHERE error IS NOT NULL OR response_code >= 400';

    private ContaiDatabase $db;
    private ContaiConfig $config;
    private string $table;
    private bool $enabled;

    public function __construct(ContaiDatabase $db, ContaiConfig $config) {
        $this->db = $db;
        $this->config = $config;
        $this->table = self::TABLE_NAME;
        $this->enabled = $this->isLoggingEnabled();
    }

    public static function create(?ContaiConfig $config = null): self {
        if ($config === null) {
            $config = ContaiConfig::getInstance();
        }

        $db = ContaiDatabase::getInstance();

        return new self($db, $config);
    }

    private function isLoggingEnabled(): bool {
        if ($this->config->isLoggingEnabled()) {
            return true;
        }

        return get_option(self::OPTION_LOGGING_ENABLED, self::OPTION_VALUE_DISABLED) === self::OPTION_VALUE_ENABLED;
    }

    public function log(string $method, string $url, ?array $request_data, ?ContaiHTTPResponse $response, float $duration): ?int {
        if (!$this->shouldLog()) {
            return null;
        }

        $log_data = $this->buildLogData($method, $url, $request_data, $response, $duration);
        $format = $this->getLogDataFormat();

        return $this->insertLog($log_data, $format);
    }

    private function shouldLog(): bool {
        return $this->enabled;
    }

    private function buildLogData(string $method, string $url, ?array $request_data, ?ContaiHTTPResponse $response, float $duration): array {
        return [
            'method' => $method,
            'url' => $url,
            'request_body' => $this->encodeRequestData($request_data),
            'response_code' => $this->extractResponseCode($response),
            'response_body' => $this->extractResponseBody($response),
            'duration' => $duration,
            'error' => $this->extractError($response),
            'created_at' => current_time('mysql'),
        ];
    }

    private function encodeRequestData(?array $request_data): ?string {
        return $request_data ? json_encode($request_data) : null;
    }

    private function extractResponseCode(?ContaiHTTPResponse $response): ?int {
        return $response ? $response->getStatusCode() : null;
    }

    private function extractResponseBody(?ContaiHTTPResponse $response): ?string {
        return $response ? $response->getBody() : null;
    }

    private function extractError(?ContaiHTTPResponse $response): ?string {
        return $response ? $response->getError() : null;
    }

    private function getLogDataFormat(): array {
        return ['%s', '%s', '%s', '%d', '%s', '%f', '%s', '%s'];
    }

    private function insertLog(array $data, array $format): ?int {
        return $this->db->insert($this->table, $data, $format);
    }

    public function getLogs(int $limit = self::DEFAULT_LOG_LIMIT, int $offset = 0): array {
        $table = $this->getFullTableName();
        $query = $this->buildGetLogsQuery($table, $limit, $offset);

        return $this->executeQuery($query);
    }

    private function getFullTableName(): string {
        return $this->db->getTableName($this->table);
    }

    private function buildGetLogsQuery(string $table, int $limit, int $offset): string {
        return $this->db->prepare(
            "SELECT * FROM {$table} " . self::SQL_ORDER_BY_DATE_DESC . " LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
    }

    public function getLogsByUrl(string $url, int $limit = self::DEFAULT_URL_LOG_LIMIT): array {
        $table = $this->getFullTableName();
        $escaped_url = $this->escapeUrlForLike($url);
        $query = $this->buildLogsByUrlQuery($table, $escaped_url, $limit);

        return $this->executeQuery($query);
    }

    private function escapeUrlForLike(string $url): string {
        return '%' . $this->db->getWpdb()->esc_like($url) . '%';
    }

    private function buildLogsByUrlQuery(string $table, string $url, int $limit): string {
        return $this->db->prepare(
            "SELECT * FROM {$table} WHERE url LIKE %s " . self::SQL_ORDER_BY_DATE_DESC . " LIMIT %d",
            $url,
            $limit
        );
    }

    public function getErrorLogs(int $limit = self::DEFAULT_ERROR_LOG_LIMIT): array {
        $table = $this->getFullTableName();
        $query = $this->buildErrorLogsQuery($table, $limit);

        return $this->executeQuery($query);
    }

    private function buildErrorLogsQuery(string $table, int $limit): string {
        return $this->db->prepare(
            "SELECT * FROM {$table} " . self::SQL_WHERE_ERROR . " " . self::SQL_ORDER_BY_DATE_DESC . " LIMIT %d",
            $limit
        );
    }

    private function executeQuery(string $query): array {
        return $this->db->getResults($query, ARRAY_A);
    }

    public function clearOldLogs(?int $days = null): int {
        $retention_days = $days ?? $this->config->getLogRetentionDays();
        $table = $this->getFullTableName();
        $cutoff_date = $this->calculateCutoffDate($retention_days);

        $query = $this->buildDeleteOldLogsQuery($table, $cutoff_date);

        $this->db->query($query);

        return $this->getAffectedRows();
    }

    private function calculateCutoffDate(int $days): string {
        return gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private function buildDeleteOldLogsQuery(string $table, string $date): string {
        return $this->db->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $date
        );
    }

    private function getAffectedRows(): int {
        return $this->db->getWpdb()->rows_affected;
    }

    public function enable(): void {
        $this->updateLoggingOption(self::OPTION_VALUE_ENABLED);
        $this->enabled = true;
    }

    public function disable(): void {
        $this->updateLoggingOption(self::OPTION_VALUE_DISABLED);
        $this->enabled = false;
    }

    private function updateLoggingOption(string $value): void {
        update_option(self::OPTION_LOGGING_ENABLED, $value);
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }
}
