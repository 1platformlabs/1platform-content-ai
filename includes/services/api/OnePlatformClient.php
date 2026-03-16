<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/OnePlatformAuthService.php';
require_once __DIR__ . '/OnePlatformEndpoints.php';
require_once __DIR__ . '/../http/HTTPClientService.php';
require_once __DIR__ . '/../http/RateLimiter.php';
require_once __DIR__ . '/../http/RequestLogger.php';
require_once __DIR__ . '/../config/Config.php';

class ContaiOnePlatformClient {

    private const ERROR_RATE_LIMIT = 'Rate limit exceeded. Please try again later.';
    private const ERROR_AUTH_FAILED = 'Failed to obtain authentication token';
    private const ERROR_INVALID_METHOD = 'Unsupported HTTP method: %s';
    private const ERROR_REQUEST_FAILED = 'Request failed';

    private const PERMANENT_AUTH_ERRORS = [
        'invalid api key',
        'invalid apikey',
        'api key not found',
        'api key revoked',
        'api key disabled',
    ];

    private const RATE_LIMITER_KEY = 'contai_api';

    private ContaiOnePlatformAuthService $auth_service;
    private ContaiHTTPClientService $http_client;
    private ContaiRateLimiter $rate_limiter;
    private ContaiRequestLogger $logger;
    private ContaiConfig $config;
    private array $custom_headers = [];

    public function __construct(
        ContaiOnePlatformAuthService $auth_service,
        ContaiHTTPClientService $http_client,
        ContaiRateLimiter $rate_limiter,
        ContaiRequestLogger $logger,
        ContaiConfig $config
    ) {
        $this->auth_service = $auth_service;
        $this->http_client = $http_client;
        $this->rate_limiter = $rate_limiter;
        $this->logger = $logger;
        $this->config = $config;
    }

    public static function create(?ContaiConfig $config = null): self {
        if ($config === null) {
            $config = ContaiConfig::getInstance();
        }

        $auth_service = ContaiOnePlatformAuthService::create($config);
        $http_client = ContaiHTTPClientService::create($config);
        $rate_limiter = new ContaiRateLimiter(
            self::RATE_LIMITER_KEY,
            $config->getRateLimitRequests(),
            $config->getRateLimitWindow()
        );
        $logger = ContaiRequestLogger::create($config);

        return new self($auth_service, $http_client, $rate_limiter, $logger, $config);
    }

    public function get(string $endpoint, array $query_params = []): ContaiOnePlatformResponse {
        $url = $this->buildUrl($endpoint, $query_params);
        return $this->request('GET', $url);
    }

    public function post(string $endpoint, array $data = []): ContaiOnePlatformResponse {
        $url = $this->buildUrl($endpoint);
        return $this->request('POST', $url, $data);
    }

    public function put(string $endpoint, array $data = []): ContaiOnePlatformResponse {
        $url = $this->buildUrl($endpoint);
        return $this->request('PUT', $url, $data);
    }

    public function delete(string $endpoint): ContaiOnePlatformResponse {
        $url = $this->buildUrl($endpoint);
        return $this->request('DELETE', $url);
    }

    public function patch(string $endpoint, array $data = []): ContaiOnePlatformResponse {
        $url = $this->buildUrl($endpoint);
        return $this->request('PATCH', $url, $data);
    }

    private function request(string $method, string $url, ?array $data = null, int $retry_count = 0): ContaiOnePlatformResponse {
        if (!$this->isWithinRateLimit()) {
            return $this->createRateLimitResponse();
        }

        $auth_headers = $this->obtainAuthHeaders();

        if ($auth_headers === null) {
            return $this->createAuthFailedResponse();
        }

        $headers = array_merge($auth_headers, $this->custom_headers);
        $start_time = microtime(true);

        $response = $this->executeHttpRequest($method, $url, $data, $headers);

        $duration = microtime(true) - $start_time;

        $this->logRequest($method, $url, $data, $response, $duration);

        if ($this->shouldRetryRequest($response, $retry_count)) {
            $this->clearTokensForRetry($response);
            return $this->request($method, $url, $data, $retry_count + 1);
        }

        $platform_response = $this->createResponse($response);

        if (!$platform_response->isSuccess() && class_exists('ContaiClientLogReporter') && !$this->isLogEndpoint($url)) {
            ContaiClientLogReporter::report([
                'timestamp'        => gmdate('c'),
                'method'           => $method,
                'endpoint'         => $url,
                'action'           => 'api_request',
                'source_module'    => 'api_client',
                'response_status'  => $platform_response->getStatusCode(),
                'response_message' => substr($platform_response->getMessage() ?? self::ERROR_REQUEST_FAILED, 0, 2000),
                'success'          => false,
                'error_type'       => 'provider_error',
                'trace_id'         => $platform_response->getTraceId(),
            ]);
        }

        return $platform_response;
    }

    private function isWithinRateLimit(): bool {
        return $this->rate_limiter->allow();
    }

    private function createRateLimitResponse(): ContaiOnePlatformResponse {
        return new ContaiOnePlatformResponse(
            false,
            null,
            self::ERROR_RATE_LIMIT,
            429
        );
    }

    private function obtainAuthHeaders(): ?array {
        return $this->auth_service->getAuthHeaders();
    }

    private function createAuthFailedResponse(): ContaiOnePlatformResponse {
        return new ContaiOnePlatformResponse(
            false,
            null,
            self::ERROR_AUTH_FAILED,
            401
        );
    }

    private function executeHttpRequest(string $method, string $url, ?array $data, array $headers): ContaiHTTPResponse {
        return match($method) {
            'GET' => $this->http_client->get($url, $headers),
            'POST' => $this->http_client->post($url, $data, $headers),
            'PUT' => $this->http_client->put($url, $data, $headers),
            'PATCH' => $this->http_client->patch($url, $data, $headers),
            'DELETE' => $this->http_client->delete($url, $headers),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            default => throw new InvalidArgumentException(sprintf(self::ERROR_INVALID_METHOD, $method)),
        };
    }

    private function logRequest(string $method, string $url, ?array $data, ContaiHTTPResponse $response, float $duration): void {
        $this->logger->log($method, $url, $data, $response, $duration);
    }

    /**
     * Determine if the request should be retried after an auth error.
     *
     * Retries on 401/403 up to max_retries (1), but never retries
     * permanent errors like "Invalid API key".
     */
    private function shouldRetryRequest(ContaiHTTPResponse $response, int $retry_count): bool {
        if ($retry_count >= $this->config->getMaxRetries()) {
            return false;
        }

        if (!$response->isForbidden() && !$response->isUnauthorized()) {
            return false;
        }

        if ($this->isPermanentAuthError($response)) {
            contai_log('Content AI: Permanent auth error detected, skipping retry');
            return false;
        }

        return true;
    }

    /**
     * Check if the API response indicates a non-retryable auth error.
     *
     * "Invalid API key" means the key itself is wrong — refreshing tokens
     * with the same key will never succeed.
     */
    private function isPermanentAuthError(ContaiHTTPResponse $response): bool {
        $json = $response->getJson();

        if (!$json) {
            return false;
        }

        $message = strtolower($json['msg'] ?? $json['message'] ?? '');

        foreach (self::PERMANENT_AUTH_ERRORS as $error_pattern) {
            if (strpos($message, $error_pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear both app and user tokens before retry.
     *
     * Always clears both tokens because a 401 could be caused by either
     * an expired app token or user token. Clearing both ensures the retry
     * generates fresh tokens for the entire auth chain.
     */
    private function clearTokensForRetry(ContaiHTTPResponse $response): void {
        $this->auth_service->clearToken();
    }

    private function createResponse(ContaiHTTPResponse $http_response): ContaiOnePlatformResponse {
        $json = $http_response->getJson();
        $trace_id = $http_response->getHeader('x-trace-id');

        if ($http_response->isSuccess()) {
            return $this->createSuccessResponse($json, $http_response->getStatusCode(), $trace_id);
        }

        return $this->createErrorResponse($json, $http_response, $trace_id);
    }

    private function createSuccessResponse(array $json, int $status_code, ?string $trace_id = null): ContaiOnePlatformResponse {
        return new ContaiOnePlatformResponse(
            $json['success'] ?? true,
            $json['data'] ?? $json,
            $json['msg'] ?? null,
            $status_code,
            $trace_id
        );
    }

    private function createErrorResponse(?array $json, ContaiHTTPResponse $http_response, ?string $trace_id = null): ContaiOnePlatformResponse {
        $error_message = $json['msg'] ?? $http_response->getError() ?? self::ERROR_REQUEST_FAILED;

        return new ContaiOnePlatformResponse(
            false,
            null,
            $error_message,
            $http_response->getStatusCode(),
            $trace_id
        );
    }

    private function isLogEndpoint(string $url): bool {
        return strpos($url, '/logs/client') !== false;
    }

    private function buildUrl(string $endpoint, array $query_params = []): string {
        $base_url = rtrim($this->config->getApiBaseUrl(), '/');
        $url = $base_url . '/' . ltrim($endpoint, '/');

        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        return $url;
    }

    public function getRateLimitInfo(): array {
        return [
            'remaining' => $this->rate_limiter->getRemainingRequests(),
            'reset_at' => $this->rate_limiter->getResetTime(),
        ];
    }

    public function getAuthInfo(): array {
        return $this->auth_service->getTokenInfo();
    }

    public function clearCache(): void {
        $this->auth_service->clearToken();
    }

    public function getConfig(): ContaiConfig {
        return $this->config;
    }

    public function getEnvironment(): string {
        return $this->config->getEnvironment();
    }

    public function setCustomHeaders(array $headers): void {
        $this->custom_headers = $headers;
    }

    public function getCustomHeaders(): array {
        return $this->custom_headers;
    }

    public function addCustomHeader(string $name, string $value): void {
        $this->custom_headers[$name] = $value;
    }

    public function removeCustomHeader(string $name): void {
        unset($this->custom_headers[$name]);
    }
}

class ContaiOnePlatformResponse {

    private bool $success;
    private $data;
    private ?string $message;
    private int $status_code;
    private ?string $trace_id;

    public function __construct(bool $success, $data, ?string $message = null, int $status_code = 200, ?string $trace_id = null) {
        $this->success = $success;
        $this->data = $data;
        $this->message = $message;
        $this->status_code = $status_code;
        $this->trace_id = $trace_id;
    }

    public function isSuccess(): bool {
        return $this->success;
    }

    public function getData() {
        return $this->data;
    }

    public function getMessage(): ?string {
        return $this->message;
    }

    public function getStatusCode(): int {
        return $this->status_code;
    }

    public function getTraceId(): ?string {
        return $this->trace_id;
    }

    public function toArray(): array {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'message' => $this->message,
            'status_code' => $this->status_code,
            'trace_id' => $this->trace_id,
        ];
    }
}
