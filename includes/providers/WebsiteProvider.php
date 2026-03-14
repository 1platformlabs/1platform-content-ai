<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../services/api/OnePlatformClient.php';
require_once __DIR__ . '/../services/api/OnePlatformEndpoints.php';
require_once __DIR__ . '/UserProvider.php';

class ContaiWebsiteProvider
{
    private const OPTION_USER_WEBSITE = 'contai_user_website';

    private const LANGUAGE_MAP = [
        'english' => 'en',
        'spanish' => 'es',
        'en'      => 'en',
        'es'      => 'es',
    ];

    private ContaiOnePlatformClient $client;
    private ContaiUserProvider $userProvider;

    public function __construct(
        ?ContaiOnePlatformClient $client = null,
        ?ContaiUserProvider $userProvider = null
    ) {
        $this->client       = $client ?? ContaiOnePlatformClient::create();
        $this->userProvider = $userProvider ?? new ContaiUserProvider();
    }

    // ── WordPress Site Metadata ──────────────────────────────────

    public function getSiteUrl(): string
    {
        return trailingslashit(home_url());
    }

    public function getSiteName(): string
    {
        return sanitize_text_field(get_bloginfo('name'));
    }

    public function getSiteDescription(): string
    {
        return sanitize_text_field(get_bloginfo('description'));
    }

    // ── Delegated to ContaiUserProvider ────────────────────────────────

    public function getUserProfile(): ?array
    {
        return $this->userProvider->getUserProfile();
    }

    public function getUserId(): ?string
    {
        return $this->userProvider->getUserId();
    }

    // ── Website ContaiConfig CRUD (contai_user_website option) ────────────

    public function getWebsiteConfig(): ?array
    {
        $config = get_option(self::OPTION_USER_WEBSITE, null);

        if (!$config) {
            return null;
        }

        return is_array($config) ? $config : json_decode($config, true);
    }

    public function getWebsiteId(): ?string
    {
        $config = $this->getWebsiteConfig();
        return $config['websiteId'] ?? null;
    }

    public function saveWebsiteConfig(array $data): bool
    {
        $config = [
            'userId'       => $data['user_id'] ?? '',
            'websiteId'    => $data['id'] ?? '',
            'status'       => $data['status'] ?? 'pending_verification',
            'url'          => $data['url'] ?? '',
            'file_name'    => $data['actions']['search_console']['verification']['file_name'] ?? '',
            'file_content' => $data['actions']['search_console']['verification']['file_content'] ?? '',
            'verified'     => $data['actions']['search_console']['verification']['verified'] ?? false,
            'sitemaps'     => $data['actions']['search_console']['sitemaps'] ?? [],
        ];

        return update_option(self::OPTION_USER_WEBSITE, $config);
    }

    public function saveSearchConsoleConfig(array $data): bool
    {
        $config = $this->getWebsiteConfig();

        if (!$config) {
            return false;
        }

        $verification = $data['verification'] ?? [];

        $config['file_name']    = $verification['file_name'] ?? $config['file_name'] ?? '';
        $config['file_content'] = $verification['file_content'] ?? $config['file_content'] ?? '';
        $config['verified']     = $verification['verified'] ?? $config['verified'] ?? false;

        if ($config['verified']) {
            $config['status'] = 'active';
        }

        return update_option(self::OPTION_USER_WEBSITE, $config);
    }

    public function saveSitemapsConfig(array $data): bool
    {
        $config = $this->getWebsiteConfig();

        if (!$config) {
            return false;
        }

        $config['sitemaps'] = $data['sitemaps'] ?? $config['sitemaps'] ?? [];

        return update_option(self::OPTION_USER_WEBSITE, $config);
    }

    public function deleteWebsiteConfig(): bool
    {
        return delete_option(self::OPTION_USER_WEBSITE);
    }

    // ── Plugin Site Configuration ────────────────────────────────

    public function getCategoryId(): ?string
    {
        $category_id = get_option('contai_site_category', '');

        if (empty($category_id)) {
            $category_id = get_option('contai_site_category', '');
        }

        return !empty($category_id) ? sanitize_text_field($category_id) : null;
    }

    public function getLanguageCode(): ?string
    {
        $language = get_option('contai_site_language', '');

        if (empty($language)) {
            $language = get_option('contai_site_language', '');
        }

        if (empty($language)) {
            return null;
        }

        $normalized = strtolower(trim($language));
        return self::LANGUAGE_MAP[$normalized] ?? 'en';
    }

    // ── Website API Operations ───────────────────────────────────

    public function getSitemapUrls(): array
    {
        $sitemapUrl = $this->getSiteUrl() . 'sitemap.xml';

        $response = wp_remote_get($sitemapUrl);

        if (is_wp_error($response)) {
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return [];
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!empty($content_type) && stripos($content_type, 'xml') === false) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        if ($xml === false) {
            return [];
        }

        $urls = [];
        foreach ($xml->sitemap as $sitemap) {
            $urls[] = (string) $sitemap->loc;
        }

        return $urls;
    }

    public function searchWebsite(): ContaiOnePlatformResponse
    {
        return $this->client->get(ContaiOnePlatformEndpoints::USERS_WEBSITES, ['url' => $this->getSiteUrl()]);
    }

    public function deleteWebsite(): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteById($websiteConfig['websiteId']);

        return $this->client->delete($endpoint);
    }

    public function updateWebsite(array $data): ContaiOnePlatformResponse
    {
        $websiteId = $this->getWebsiteId();

        if (empty($websiteId)) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteById($websiteId);

        return $this->client->patch($endpoint, $data);
    }

    public function getWebsiteFromApi(): ?ContaiOnePlatformResponse
    {
        $websiteConfig = $this->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return null;
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteById($websiteConfig['websiteId']);

        return $this->client->get($endpoint);
    }

    public function syncWebsiteStatus(): bool
    {
        $response = $this->getWebsiteFromApi();

        if (!$response || !$response->isSuccess()) {
            return false;
        }

        return $this->saveWebsiteConfig($response->getData());
    }

    public function initializeWebsiteStatus(): array
    {
        $websiteConfig = $this->getWebsiteConfig();

        if ($websiteConfig) {
            $this->syncWebsiteStatus();

            return [
                'status' => 'configured',
                'config' => $this->getWebsiteConfig(),
            ];
        }

        $searchResponse = $this->searchWebsite();

        if ($searchResponse->isSuccess()) {
            $this->saveWebsiteConfig($searchResponse->getData());

            return [
                'status' => 'configured',
                'config' => $this->getWebsiteConfig(),
            ];
        }

        if ($searchResponse->getStatusCode() === 404) {
            return [
                'status' => 'not_found',
                'config' => null,
            ];
        }

        return [
            'status' => 'error',
            'config' => null,
            'message' => $searchResponse->getMessage(),
        ];
    }

    /**
     * Ensure a website record exists for the current WordPress site.
     *
     * Checks local config first, then searches the API by URL, and creates
     * the website if not found (404). Used during license activation.
     *
     * @return array{success: bool, action: string, message: string}
     */
    public function ensureWebsiteExists(): array
    {
        $existingConfig = $this->getWebsiteConfig();

        if ($existingConfig && !empty($existingConfig['websiteId'])) {
            $this->debugLog('Website already configured locally (ID: ' . $existingConfig['websiteId'] . '), syncing status');
            $this->syncWebsiteStatus();

            return [
                'success' => true,
                'action'  => 'already_configured',
                'message' => 'Website already configured',
            ];
        }

        $this->debugLog('Searching for website by URL: ' . $this->getSiteUrl());
        $searchResponse = $this->searchWebsite();

        if ($searchResponse->isSuccess()) {
            $this->debugLog('Website found in API, saving config locally');
            $this->saveWebsiteConfig($searchResponse->getData());

            return [
                'success' => true,
                'action'  => 'linked',
                'message' => 'Website already exists',
            ];
        }

        if ($searchResponse->getStatusCode() !== 404) {
            $this->debugLog('Website search failed with HTTP ' . $searchResponse->getStatusCode());

            return [
                'success' => false,
                'action'  => 'search_error',
                'message' => $searchResponse->getMessage() ?? 'Failed to search for website',
            ];
        }

        $this->debugLog('Website not found (404), creating new website');
        $createResponse = $this->addWebsiteForActivation();

        if (!$createResponse->isSuccess()) {
            $this->debugLog('Website creation failed (HTTP ' . $createResponse->getStatusCode() . '): ' . $createResponse->getMessage());

            return [
                'success' => false,
                'action'  => 'create_error',
                'message' => $createResponse->getMessage() ?? 'Failed to create website',
            ];
        }

        $this->saveWebsiteConfig($createResponse->getData());
        $this->debugLog('Website created and saved successfully');

        return [
            'success' => true,
            'action'  => 'created',
            'message' => 'Website added successfully',
        ];
    }

    /**
     * Create website during license activation.
     * Unlike addWebsite(), category_id is optional here since the user
     * may not have configured it yet during first-time activation.
     */
    private function addWebsiteForActivation(): ContaiOnePlatformResponse
    {
        $data = [
            'url'              => $this->getSiteUrl(),
            'site_name'        => $this->getSiteName(),
            'site_description' => $this->getSiteDescription(),
        ];

        $category_id = $this->getCategoryId();
        if (!empty($category_id)) {
            $data['category_id'] = $category_id;
        }

        $lang = $this->getLanguageCode();
        if (!empty($lang)) {
            $data['lang'] = $lang;
        }
        $response = $this->client->post(ContaiOnePlatformEndpoints::USERS_WEBSITES, $data);

        if ($response->isSuccess() && empty($this->getSiteDescription())) {
            $responseData = $response->getData();
            $tagline = $responseData['site_description'] ?? '';

            if (!empty($tagline)) {
                update_option('blogdescription', sanitize_text_field($tagline));
            }
        }

        return $response;
    }

    private function debugLog(string $message): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        contai_log('[Content AI] Website Setup: ' . $message);
    }
}
