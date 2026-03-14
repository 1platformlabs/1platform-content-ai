<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../http/HTTPClientService.php';
require_once __DIR__ . '/../config/Config.php';

class ContaiOnePlatformAuthService {

    /**
     * Application API key - loaded from ContaiConfig based on detected environment.
     * QA (.local domains): uses staging API key
     * Production: uses production API key
     */

    private const OPTION_APP_TOKEN = 'contai_app_access_token';
    private const OPTION_APP_TOKEN_EXPIRES = 'contai_app_token_expires_at';

    private const OPTION_USER_TOKEN = 'contai_user_access_token';
    private const OPTION_USER_TOKEN_EXPIRES = 'contai_user_token_expires_at';

    private const OPTION_APP_TOKEN_ERROR = 'contai_app_token_error';
    private const OPTION_USER_TOKEN_ERROR = 'contai_user_token_error';

    private const ERROR_APP_AUTH_HTTP = 'Content AI App Auth Error: %s';
    private const ERROR_APP_AUTH_FAILED = 'Content AI App Auth Failed: %s';
    private const ERROR_APP_NO_TOKEN = 'Content AI App Auth: No token in response';
    private const ERROR_USER_AUTH_HTTP = 'Content AI User Auth Error: %s';
    private const ERROR_USER_AUTH_FAILED = 'Content AI User Auth Failed: %s';
    private const ERROR_USER_NO_TOKEN = 'Content AI User Auth: No token in response';

    private const DEFAULT_TOKEN_EXPIRY = 1800;
    private const API_KEY_FIELD = 'apiKey';
    private const RESPONSE_ACCESS_TOKEN_KEY = 'access_token';
    private const RESPONSE_EXPIRES_IN_KEY = 'expires_in';

    private const LEGACY_TRANSIENT_TOKEN = 'contai_legacy_token';
    private const LEGACY_TRANSIENT_EXPIRES = 'contai_legacy_token_expires';

    private ContaiHTTPClientService $http_client;
    private ContaiConfig $config;
    private string $app_auth_url;
    private string $user_auth_url;

    public function __construct(ContaiHTTPClientService $http_client, ContaiConfig $config) {
        $this->http_client = $http_client;
        $this->config = $config;
        $this->app_auth_url = $this->buildAppAuthUrl();
        $this->user_auth_url = $this->buildUserAuthUrl();
    }

    public static function create(?ContaiConfig $config = null): self {
        if ($config === null) {
            $config = ContaiConfig::getInstance();
        }

        $http_client = ContaiHTTPClientService::create($config);

        return new self($http_client, $config);
    }

    private function buildAppAuthUrl(): string {
        $base_url = rtrim($this->config->getApiBaseUrl(), '/');
        return $base_url . $this->config->getAuthEndpoint();
    }

    private function buildUserAuthUrl(): string {
        $base_url = rtrim($this->config->getApiBaseUrl(), '/');
        return $base_url . $this->config->getUserTokenEndpoint();
    }

    /**
     * Build authentication headers for all API requests.
     *
     * Returns both Authorization (app token) and x-user-token (user token).
     * Auto-refreshes expired tokens transparently.
     *
     * cURL example for a typical authenticated API request (import to Postman):
     * curl -X GET https://api-qa.1platform.pro/api/v1/users/profile \
     *   -H "Content-Type: application/json" \
     *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
     *   -H "x-user-token: <USER_ACCESS_TOKEN>"
     *
     * @return array|null Headers array or null if token acquisition failed.
     */
    public function getAuthHeaders(): ?array {
        $app_token = $this->getAppToken();

        if ($app_token === null) {
            $this->storeError(self::OPTION_APP_TOKEN_ERROR, 'Failed to obtain app authentication token');
            return null;
        }

        $this->clearError(self::OPTION_APP_TOKEN_ERROR);

        $user_token = $this->getUserToken($app_token);

        if ($user_token === null) {
            $this->storeError(self::OPTION_USER_TOKEN_ERROR, 'Failed to obtain user authentication token. Check your API key.');
            return null;
        }

        $this->clearError(self::OPTION_USER_TOKEN_ERROR);

        return [
            'Authorization' => 'Bearer ' . $app_token,
            'x-user-token'  => $user_token,
        ];
    }

    /**
     * @deprecated Use getAuthHeaders() instead. Returns app token for backward compatibility.
     */
    public function getToken(): ?string {
        return $this->getAppToken();
    }

    /**
     * Get a valid app token, refreshing if expired.
     *
     * cURL example (import to Postman):
     * curl -X POST https://api-qa.1platform.pro/api/v1/auth/token \
     *   -H "Content-Type: application/json" \
     *   -d '{"apiKey": "<APP_API_KEY from ContaiConfig>"}'
     */
    private function getAppToken(): ?string {
        $cached = $this->getCachedOptionToken(self::OPTION_APP_TOKEN, self::OPTION_APP_TOKEN_EXPIRES);

        if ($cached !== null) {
            return $cached;
        }

        return $this->generateNewAppToken();
    }

    private function generateNewAppToken(): ?string {
        $api_key = $this->config->getApiKey();
        $masked_key = !empty($api_key) ? substr($api_key, 0, 8) . '...' : '(empty)';
        contai_log(sprintf('Content AI App Auth: requesting token from %s (key: %s)', $this->app_auth_url, $masked_key));

        $response = $this->http_client->post($this->app_auth_url, [
            self::API_KEY_FIELD => $api_key,
        ]);

        return $this->processTokenResponse(
            $response,
            self::OPTION_APP_TOKEN,
            self::OPTION_APP_TOKEN_EXPIRES,
            self::ERROR_APP_AUTH_HTTP,
            self::ERROR_APP_AUTH_FAILED,
            self::ERROR_APP_NO_TOKEN
        );
    }

    /**
     * Get a valid user token, refreshing if expired.
     *
     * cURL example (import to Postman):
     * curl -X POST https://api-qa.1platform.pro/api/v1/users/token \
     *   -H "Content-Type: application/json" \
     *   -H "Authorization: Bearer <APP_ACCESS_TOKEN>" \
     *   -d '{"apiKey": "<USER_API_KEY>"}'
     */
    private function getUserToken(string $app_token): ?string {
        $cached = $this->getCachedOptionToken(self::OPTION_USER_TOKEN, self::OPTION_USER_TOKEN_EXPIRES);

        if ($cached !== null) {
            return $cached;
        }

        return $this->generateNewUserToken($app_token);
    }

    private function generateNewUserToken(string $app_token): ?string {
        $user_api_key = $this->config->getUserApiKey();

        if (empty($user_api_key)) {
            contai_log('Content AI User Auth: No user API key configured');
            return null;
        }

        $masked_key = substr($user_api_key, 0, 8) . '...';
        contai_log(sprintf('Content AI User Auth: requesting token from %s (key: %s)', $this->user_auth_url, $masked_key));

        $response = $this->http_client->post($this->user_auth_url, [
            self::API_KEY_FIELD => $user_api_key,
        ], [
            'Authorization' => 'Bearer ' . $app_token,
        ]);

        return $this->processTokenResponse(
            $response,
            self::OPTION_USER_TOKEN,
            self::OPTION_USER_TOKEN_EXPIRES,
            self::ERROR_USER_AUTH_HTTP,
            self::ERROR_USER_AUTH_FAILED,
            self::ERROR_USER_NO_TOKEN
        );
    }

    private function processTokenResponse(
        ContaiHTTPResponse $response,
        string $token_option,
        string $expires_option,
        string $error_http_template,
        string $error_failed_template,
        string $error_no_token_message
    ): ?string {
        if (!$response->isSuccess()) {
            $error = $response->getError() ?: 'HTTP ' . $response->getStatusCode();
            contai_log(sprintf($error_http_template, $error));
            return null;
        }

        $data = $response->getJson();

        if (!isset($data['success']) || !$data['success']) {
            $message = $data['msg'] ?? 'Unknown error';
            contai_log(sprintf($error_failed_template, $message));
            return null;
        }

        $token = $data['data'][self::RESPONSE_ACCESS_TOKEN_KEY] ?? null;
        $expires_in = $data['data'][self::RESPONSE_EXPIRES_IN_KEY] ?? self::DEFAULT_TOKEN_EXPIRY;

        if (!$token) {
            contai_log($error_no_token_message);
            return null;
        }

        $this->storeOptionToken($token, $expires_in, $token_option, $expires_option);

        return $token;
    }

    private function getCachedOptionToken(string $token_option, string $expires_option): ?string {
        $token = get_option($token_option, '');

        if (empty($token)) {
            return null;
        }

        $expires_at = (int) get_option($expires_option, 0);

        if (empty($expires_at)) {
            return null;
        }

        $buffer_time = $this->config->getTokenBufferTime();

        if (time() >= ($expires_at - $buffer_time)) {
            return null;
        }

        return $token;
    }

    private function storeOptionToken(string $token, int $expires_in, string $token_option, string $expires_option): void {
        $expires_at = time() + $expires_in;

        update_option($token_option, $token, false);
        update_option($expires_option, $expires_at, false);
    }

    /**
     * Force-refresh both app and user tokens regardless of expiration.
     *
     * Clears stored tokens, generates fresh ones, and returns the new
     * auth headers. Used by the admin "Refresh Tokens" button.
     *
     * @return array{success: bool, message: string, headers?: array}
     */
    public function forceRefreshAllTokens(): array {
        $this->clearToken();

        $app_token = $this->generateNewAppToken();

        if ($app_token === null) {
            $error = self::getAppTokenError();
            return [
                'success' => false,
                'message' => $error ?? 'Failed to refresh app token',
            ];
        }

        $user_token = $this->generateNewUserToken($app_token);

        if ($user_token === null) {
            $error = self::getUserTokenError();
            return [
                'success' => false,
                'message' => $error ?? 'Failed to refresh user token. Check your API key.',
            ];
        }

        $this->clearError(self::OPTION_APP_TOKEN_ERROR);
        $this->clearError(self::OPTION_USER_TOKEN_ERROR);

        return [
            'success' => true,
            'message' => 'Tokens refreshed successfully',
        ];
    }

    public function clearToken(): void {
        delete_transient(self::LEGACY_TRANSIENT_TOKEN);
        delete_transient(self::LEGACY_TRANSIENT_EXPIRES);

        delete_option(self::OPTION_APP_TOKEN);
        delete_option(self::OPTION_APP_TOKEN_EXPIRES);

        delete_option(self::OPTION_USER_TOKEN);
        delete_option(self::OPTION_USER_TOKEN_EXPIRES);

        delete_option(self::OPTION_APP_TOKEN_ERROR);
        delete_option(self::OPTION_USER_TOKEN_ERROR);
    }

    public function clearUserToken(): void {
        delete_option(self::OPTION_USER_TOKEN);
        delete_option(self::OPTION_USER_TOKEN_EXPIRES);
        delete_option(self::OPTION_USER_TOKEN_ERROR);
    }

    public function getTokenInfo(): array {
        $app_token = get_option(self::OPTION_APP_TOKEN, '');
        $app_expires = (int) get_option(self::OPTION_APP_TOKEN_EXPIRES, 0);
        $user_token = get_option(self::OPTION_USER_TOKEN, '');
        $user_expires = (int) get_option(self::OPTION_USER_TOKEN_EXPIRES, 0);

        $now = time();
        $buffer = $this->config->getTokenBufferTime();

        return [
            'app_token' => [
                'has_token' => !empty($app_token),
                'expires_at' => $app_expires > 0 ? $app_expires : null,
                'is_expired' => empty($app_token) || $now >= ($app_expires - $buffer),
                'time_until_expiry' => $app_expires > 0 ? max(0, $app_expires - $now) : 0,
            ],
            'user_token' => [
                'has_token' => !empty($user_token),
                'expires_at' => $user_expires > 0 ? $user_expires : null,
                'is_expired' => empty($user_token) || $now >= ($user_expires - $buffer),
                'time_until_expiry' => $user_expires > 0 ? max(0, $user_expires - $now) : 0,
            ],
            'has_token' => !empty($app_token) && !empty($user_token),
            'is_expired' => empty($app_token) || $now >= ($app_expires - $buffer) || empty($user_token) || $now >= ($user_expires - $buffer),
        ];
    }

    public function validateToken(): bool {
        $app_token = get_option(self::OPTION_APP_TOKEN, '');
        $user_token = get_option(self::OPTION_USER_TOKEN, '');

        if (empty($app_token) || empty($user_token)) {
            return false;
        }

        $buffer = $this->config->getTokenBufferTime();
        $now = time();
        $app_expires = (int) get_option(self::OPTION_APP_TOKEN_EXPIRES, 0);
        $user_expires = (int) get_option(self::OPTION_USER_TOKEN_EXPIRES, 0);

        return $now < ($app_expires - $buffer) && $now < ($user_expires - $buffer);
    }

    private function storeError(string $option_key, string $message): void {
        update_option($option_key, $message, false);
    }

    private function clearError(string $option_key): void {
        delete_option($option_key);
    }

    public static function getAppTokenError(): ?string {
        $error = get_option(self::OPTION_APP_TOKEN_ERROR, '');
        return !empty($error) ? $error : null;
    }

    public static function getUserTokenError(): ?string {
        $error = get_option(self::OPTION_USER_TOKEN_ERROR, '');
        return !empty($error) ? $error : null;
    }

    public static function hasTokenErrors(): bool {
        return self::getAppTokenError() !== null || self::getUserTokenError() !== null;
    }
}
