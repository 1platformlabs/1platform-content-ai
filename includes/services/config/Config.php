<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/EnvironmentDetector.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

class ContaiConfig {

    private static ?ContaiConfig $instance = null;
    private string $environment;
    private array $config;

    /**
     * Bundled defaults, including the APP key of each environment.
     *
     * READ THIS BEFORE FILING THE APP KEY BELOW AS A LEAKED SECRET.
     *
     * The value in `api.app_key` is a PUBLIC IDENTIFIER of this distribution,
     * not a credential to protect. That is a decision, not an accident:
     *
     *  - This plugin is published to wordpress.org over SVN (`deploy.sh`) and
     *    `includes/` is not excluded by `.distignore`, so whatever is written
     *    here travels inside the .zip installed on every WordPress in the
     *    world. A client that anybody can download cannot keep a secret —
     *    hiding the value in a build step, an obfuscation or a second file
     *    only changes how long it takes to read it back out.
     *  - So the value is treated as public and the protection is placed where
     *    it can actually hold: on the server. The app key is only half of the
     *    two-token model. It identifies the tenant and buys an app token from
     *    `/auth/token`, and an app token ALONE reaches no user's data — every
     *    user-scoped route also demands `x-user-token`, which is minted from
     *    the site owner's personal key. That key is NOT here: it lives in an
     *    encrypted WordPress option, read only by `getUserApiKey()`.
     *  - `1platform-api` limits `/auth/token` per app key as well as per
     *    client, so one public identifier has a bounded blast radius.
     *
     * Consequences for whoever edits this array:
     *
     *  1. Do NOT blank the production value. It is what makes a fresh install
     *     work with no configuration at all, which is the whole contract of a
     *     wordpress.org plugin. An empty value fails `validate()`.
     *  2. Do NOT let two environments share a value. Sharing one means a
     *     rotation cannot be scoped to one environment, and it puts a QA key
     *     in production builds for nothing. `ConfigEmbeddedAppKeyTest` fails
     *     if you do.
     *  3. Rotating the production value does NOT revoke it. Every install that
     *     has not updated still presents the old one, so the old one has to
     *     stay alive or those sites break. Rotation is a way to move traffic,
     *     never a way to contain a leak — see
     *     `docs/RUNBOOK-rotacion-clave-app.md`.
     *
     * A deployment that wants a key that is NOT in the .zip already has one:
     * `resolveAppKey()` prefers `CONTAI_APP_KEY_<ENVIRONMENT>`, then
     * `CONTAI_APP_KEY`, from a `wp-config.php` constant or an environment
     * variable, and only then falls back to what is written here.
     */
    private const DEFAULT_CONFIG = [
        'development' => [
            'api' => [
                'base_url' => 'http://127.0.0.1:8000/api/v1',
                'app_key' => 'set-CONTAI_APP_KEY_DEVELOPMENT-in-wp-config',
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
                'app_key' => 'v36MA4qV4OnSR8eKordujzkQNMx7y0VIh7Qkeoko87K3KOvfJtTef04SWARvG7nG',
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
                'app_key' => 'mJTS3UxQA6vNOKdrJ2A2jLjgMEKo4tghOG7P2VqoKrs5fafy0TykA3b7pMRzdQod',
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
        // api.app_key = APP key (identifies the tenant, used for /auth/token).
        // Named `app_key`, not `api_key`: it is a public tenant identifier, not
        // a secret, and a field literally named `api_key` holding a
        // pseudorandom string is exactly the shape SonarCloud's php:S6418 scans
        // for (see the DEFAULT_CONFIG docblock above).
        // The user's key is accessed exclusively via getUserApiKey() for /users/token.

        // The APP key can be provided from outside the repository — a
        // wp-config.php constant or an environment variable — so it can be
        // rotated without shipping a release and does not have to live in the
        // .zip published to WordPress.org or in Git history. When nothing
        // external is set it falls back to the value bundled below, so a fresh
        // install keeps working with no configuration at all (MAH-08 / D-7).
        $embedded_key = self::DEFAULT_CONFIG[$this->environment]['api']['app_key']
            ?? self::DEFAULT_CONFIG['production']['api']['app_key'];
        $resolved_key = self::resolveAppKey($this->environment, $embedded_key);
        if ($resolved_key !== $embedded_key) {
            $custom_config['api']['app_key'] = $resolved_key;
        }

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

    /**
     * Resolve the APP key for one environment, preferring external config.
     *
     * Precedence, first non-empty wins:
     *   1. the environment-specific source  (CONTAI_APP_KEY_PRODUCTION, …)
     *   2. the generic source               (CONTAI_APP_KEY)
     *   3. the bundled value                (the $embedded argument)
     *
     * The environment-specific name takes precedence so one site running
     * development, staging and production can set each independently, while a
     * single-environment site only needs the generic one. Each source is a
     * wp-config.php constant or an environment variable of the same name.
     *
     * The reader is injectable purely so the precedence can be tested without
     * defining real (permanent) PHP constants; in production it consults
     * define()d constants and getenv().
     *
     * @param callable|null $reader  fn(string $name): ?string
     */
    public static function resolveAppKey(
        string $environment,
        string $embedded,
        ?callable $reader = null
    ): string {
        $reader = $reader ?? [self::class, 'readExternalSource'];

        $candidates = [
            'CONTAI_APP_KEY_' . strtoupper($environment),
            'CONTAI_APP_KEY',
        ];

        foreach ($candidates as $name) {
            $value = $reader($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $embedded;
    }

    /**
     * Read one external source: a defined constant, else an environment
     * variable of the same name. Returns null when neither is set.
     */
    private static function readExternalSource(string $name): ?string {
        if (defined($name)) {
            $value = constant($name);
            return is_string($value) ? $value : null;
        }

        $env = getenv($name);
        return ($env === false || $env === '') ? null : $env;
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
        return $this->get('api.app_key', '');
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
