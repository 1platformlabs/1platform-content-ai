<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/EnvironmentDetector.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

class ContaiConfig {

    private static ?ContaiConfig $instance = null;
    private string $environment;
    private array $config;

    private const DEFAULT_CONFIG = [
        'development' => [
            'api' => [
                'base_url' => 'http://127.0.0.1:8000/api/v1',
                'api_key' => 'hwL1z90qwEKxoaAb0XIzET4Q32pJ6eQ3ISwaweyKt4g4wfmAd8Pbz6DLLUTpx1EN',
                'timeout' => 180,
                'rate_limit_requests' => 120,
                'rate_limit_window' => 60,
                'max_retries' => 1,
                'auth_endpoint' => '/auth/token',
                'user_token_endpoint' => '/users/token',
                'token_buffer_time' => 60,
            ],
            'features' => [
                'logging_enabled' => true,
                'cache_enabled' => false,
                'debug_mode' => true,
            ],
            'logging' => [
                'retention_days' => 7,
            ],
            'menu' => [
                'max_categories' => 10,
            ],
            'internal_links' => [
                'enabled' => true,
                'max_links_per_post' => 10,
                'max_links_per_keyword' => 10,
                'max_links_per_target' => 10,
                'batch_size' => 10,
                'excluded_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'a'],
                'case_insensitive' => true,
                'word_boundaries' => true,
                'same_category_only' => true,
                'min_keyword_length' => 1,
                'distribute_links' => true,
            ],
        ],
        'staging' => [
            'api' => [
                'base_url' => 'https://api-qa.1platform.pro/api/v1',
                'api_key' => 'hwL1z90qwEKxoaAb0XIzET4Q32pJ6eQ3ISwaweyKt4g4wfmAd8Pbz6DLLUTpx1EN',
                'timeout' => 180,
                'rate_limit_requests' => 100,
                'rate_limit_window' => 60,
                'max_retries' => 1,
                'auth_endpoint' => '/auth/token',
                'user_token_endpoint' => '/users/token',
                'token_buffer_time' => 60,
            ],
            'features' => [
                'logging_enabled' => true,
                'cache_enabled' => true,
                'debug_mode' => false,
            ],
            'logging' => [
                'retention_days' => 14,
            ],
            'menu' => [
                'max_categories' => 10,
            ],
            'internal_links' => [
                'enabled' => true,
                'max_links_per_post' => 10,
                'max_links_per_keyword' => 10,
                'max_links_per_target' => 10,
                'batch_size' => 10,
                'excluded_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'a'],
                'case_insensitive' => true,
                'word_boundaries' => true,
                'same_category_only' => true,
                'min_keyword_length' => 1,
                'distribute_links' => true,
            ],
        ],
        'production' => [
            'api' => [
                'base_url' => 'https://api.1platform.pro/api/v1',
                'api_key' => 'qYadhvY9cskDj7QRNTInciRC0IlkqJ7L9z3LVLKcmOGDWJsl2cD6KDcmMhYJ4nVl',
                'timeout' => 180,
                'rate_limit_requests' => 120,
                'rate_limit_window' => 60,
                'max_retries' => 1,
                'auth_endpoint' => '/auth/token',
                'user_token_endpoint' => '/users/token',
                'token_buffer_time' => 60,
            ],
            'features' => [
                'logging_enabled' => false,
                'cache_enabled' => true,
                'debug_mode' => false,
            ],
            'logging' => [
                'retention_days' => 30,
            ],
            'menu' => [
                'max_categories' => 10,
            ],
            'internal_links' => [
                'enabled' => true,
                'max_links_per_post' => 10,
                'max_links_per_keyword' => 10,
                'max_links_per_target' => 10,
                'batch_size' => 10,
                'excluded_tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'a'],
                'case_insensitive' => true,
                'word_boundaries' => true,
                'same_category_only' => true,
                'min_keyword_length' => 1,
                'distribute_links' => true,
            ],
        ],
    ];

    private function __construct() {
        $this->environment = ContaiEnvironmentDetector::detect();
        $this->config = $this->loadConfig();
    }

    public static function getInstance(): ContaiConfig {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function loadConfig(): array {
        $base_config = self::DEFAULT_CONFIG[$this->environment] ?? self::DEFAULT_CONFIG['production'];

        $custom_config = $this->loadCustomConfig();

        return $this->mergeConfigs($base_config, $custom_config);
    }

    private function loadCustomConfig(): array {
        $custom_config = [];

        // NOTE: The user's personal API key (contai_api_key) is NOT loaded here.
        // api.api_key = APP key (hardcoded per environment, used for /auth/token)
        // The user's key is accessed exclusively via getUserApiKey() for /users/token.

        $logging_enabled = get_option('contai_logging_enabled');
        if ($logging_enabled !== false) {
            $custom_config['features']['logging_enabled'] = (bool) $logging_enabled;
        }

        $base_url = get_option('contai_api_base_url', '');
        if (!empty($base_url)) {
            $custom_config['api']['base_url'] = $base_url;
        }

        return $custom_config;
    }

    private function mergeConfigs(array $base, array $custom): array {
        foreach ($custom as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfigs($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function getApiConfig(): array {
        return $this->get('api', []);
    }

    public function getFeatures(): array {
        return $this->get('features', []);
    }

    public function getLoggingConfig(): array {
        return $this->get('logging', []);
    }

    public function isFeatureEnabled(string $feature): bool {
        return (bool) $this->get("features.{$feature}", false);
    }

    public function getEnvironment(): string {
        return $this->environment;
    }

    public function isDevelopment(): bool {
        return ContaiEnvironmentDetector::isDevelopment();
    }

    public function isStaging(): bool {
        return ContaiEnvironmentDetector::isStaging();
    }

    public function isProduction(): bool {
        return ContaiEnvironmentDetector::isProduction();
    }

    public function getApiBaseUrl(): string {
        return $this->get('api.base_url', 'https://api.1platform.pro/v1');
    }

    public function getApiTimeout(): int {
        return (int) $this->get('api.timeout', 180);
    }

    public function getRateLimitRequests(): int {
        return (int) $this->get('api.rate_limit_requests', 60);
    }

    public function getRateLimitWindow(): int {
        return (int) $this->get('api.rate_limit_window', 60);
    }

    public function getMaxRetries(): int {
        return (int) $this->get('api.max_retries', 1);
    }

    public function getAuthEndpoint(): string {
        return $this->get('api.auth_endpoint', ContaiOnePlatformEndpoints::AUTH_TOKEN);
    }

    public function getUserTokenEndpoint(): string {
        return $this->get('api.user_token_endpoint', ContaiOnePlatformEndpoints::USERS_TOKEN);
    }

    public function getTokenBufferTime(): int {
        return (int) $this->get('api.token_buffer_time', 60);
    }

    public function isLoggingEnabled(): bool {
        return $this->isFeatureEnabled('logging_enabled');
    }

    public function isCacheEnabled(): bool {
        return $this->isFeatureEnabled('cache_enabled');
    }

    public function isDebugMode(): bool {
        return $this->isFeatureEnabled('debug_mode');
    }

    public function getLogRetentionDays(): int {
        return (int) $this->get('logging.retention_days', 30);
    }

    public function getMaxMenuCategories(): int {
        return (int) $this->get('menu.max_categories', 6);
    }

    public function getApiKey(): string {
        return $this->get('api.api_key', '');
    }

    public function getUserApiKey(): string {
        $key = contai_get_decrypted_option('contai_api_key');
        return $key ?: '';
    }

    public function validate(): array {
        $errors = [];

        if (empty($this->getApiKey())) {
            $errors[] = 'API Key is required';
        }

        if (empty($this->getApiBaseUrl())) {
            $errors[] = 'API Base URL is required';
        }

        if ($this->getApiTimeout() <= 0) {
            $errors[] = 'API Timeout must be greater than 0';
        }

        if ($this->getRateLimitRequests() <= 0) {
            $errors[] = 'Rate Limit Requests must be greater than 0';
        }

        return $errors;
    }

    public function isValid(): bool {
        return empty($this->validate());
    }

    public static function reset(): void {
        self::$instance = null;
        ContaiEnvironmentDetector::reset();
    }

    public function getInternalLinksConfig(): array {
        return $this->get('internal_links', []);
    }

    public function isInternalLinksEnabled(): bool {
        return (bool) $this->get('internal_links.enabled', true);
    }

    public function getMaxLinksPerPost(): int {
        return (int) $this->get('internal_links.max_links_per_post', 10);
    }

    public function getMaxLinksPerKeyword(): int {
        return (int) $this->get('internal_links.max_links_per_keyword', 10);
    }

    public function getMaxLinksPerTarget(): int {
        return (int) $this->get('internal_links.max_links_per_target', 10);
    }

    public function getInternalLinksBatchSize(): int {
        return (int) $this->get('internal_links.batch_size', 10);
    }

    public function getExcludedTags(): array {
        return $this->get('internal_links.excluded_tags', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style', 'a']);
    }

    public function isCaseInsensitiveMatching(): bool {
        return (bool) $this->get('internal_links.case_insensitive', true);
    }

    public function useWordBoundaries(): bool {
        return (bool) $this->get('internal_links.word_boundaries', true);
    }

    public function isSameCategoryOnly(): bool {
        return (bool) $this->get('internal_links.same_category_only', true);
    }

    public function getMinKeywordLength(): int {
        return (int) $this->get('internal_links.min_keyword_length', 1);
    }

    public function shouldDistributeLinks(): bool {
        return (bool) $this->get('internal_links.distribute_links', true);
    }
}
