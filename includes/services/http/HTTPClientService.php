<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../config/Config.php';

class ContaiHTTPClientService {

    private const CONTENT_TYPE_JSON = 'application/json';
    private const HEADER_CONTENT_TYPE = 'Content-Type';
    private const HEADER_ACCEPT = 'Accept';

    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH'];
    private const DEFAULT_TIMEOUT = 30;

    private int $timeout;
    private array $default_headers;
    private ContaiConfig $config;

    public function __construct(int $timeout, ContaiConfig $config) {
        $this->timeout = $timeout;
        $this->config = $config;
        $this->default_headers = $this->buildDefaultHeaders();
    }

    public static function create(?ContaiConfig $config = null): self {
        if ($config === null) {
            $config = ContaiConfig::getInstance();
        }

        $timeout = $config->getApiTimeout();

        return new self($timeout, $config);
    }

    private function buildDefaultHeaders(): array {
        return [
            self::HEADER_CONTENT_TYPE => self::CONTENT_TYPE_JSON,
            self::HEADER_ACCEPT => self::CONTENT_TYPE_JSON,
        ];
    }

    public function get(string $url, array $headers = []): ContaiHTTPResponse {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, $data = null, array $headers = []): ContaiHTTPResponse {
        return $this->request('POST', $url, $data, $headers);
    }

    public function put(string $url, $data = null, array $headers = []): ContaiHTTPResponse {
        return $this->request('PUT', $url, $data, $headers);
    }

    public function delete(string $url, array $headers = []): ContaiHTTPResponse {
        return $this->request('DELETE', $url, null, $headers);
    }

    public function patch(string $url, $data = null, array $headers = []): ContaiHTTPResponse {
        return $this->request('PATCH', $url, $data, $headers);
    }

    private function request(string $method, string $url, $data = null, array $headers = []): ContaiHTTPResponse {
        $merged_headers = array_merge($this->default_headers, $headers);

        $args = [
            'method'  => $method,
            'timeout' => $this->timeout,
            'headers' => $merged_headers,
        ];

        if ($this->shouldIncludeBody($method, $data)) {
            // Cast empty arrays to stdClass so they encode as {} instead of []
            $args['body'] = is_string($data) ? $data : wp_json_encode(
                (is_array($data) && empty($data)) ? new \stdClass() : $data
            );
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new ContaiHTTPResponse(0, null, [], $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        $parsed_headers = [];
        if ($response_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || $response_headers instanceof \Requests_Utility_CaseInsensitiveDictionary) {
            $parsed_headers = $response_headers->getAll();
        } elseif (is_array($response_headers)) {
            $parsed_headers = $response_headers;
        }

        return new ContaiHTTPResponse(
            (int) $http_code,
            $body,
            $parsed_headers,
            null
        );
    }

    private function shouldIncludeBody(string $method, $data): bool {
        return $data !== null && in_array($method, self::METHODS_WITH_BODY);
    }

    public function setTimeout(int $timeout): void {
        $this->timeout = $timeout;
    }

    public function setDefaultHeaders(array $headers): void {
        $this->default_headers = array_merge($this->default_headers, $headers);
    }
}

class ContaiHTTPResponse {

    private int $status_code;
    private ?string $body;
    private array $headers;
    private ?string $error;

    public function __construct(int $status_code, ?string $body, array $headers = [], ?string $error = null) {
        $this->status_code = $status_code;
        $this->body = $body;
        $this->headers = $headers;
        $this->error = $error;
    }

    public function getStatusCode(): int {
        return $this->status_code;
    }

    public function getBody(): ?string {
        return $this->body;
    }

    public function getJson(): ?array {
        if ($this->body === null) {
            return null;
        }
        return json_decode($this->body, true);
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $name): ?string {
        return $this->headers[$name] ?? null;
    }

    public function getError(): ?string {
        return $this->error;
    }

    public function isSuccess(): bool {
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    public function isClientError(): bool {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    public function isServerError(): bool {
        return $this->status_code >= 500 && $this->status_code < 600;
    }

    public function isUnauthorized(): bool {
        return $this->status_code === 401;
    }

    public function isForbidden(): bool {
        return $this->status_code === 403;
    }

    public function isPaymentRequired(): bool {
        return $this->status_code === 402;
    }
}
