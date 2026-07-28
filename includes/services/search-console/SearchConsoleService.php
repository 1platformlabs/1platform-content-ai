<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiSearchConsoleService
{
    private ContaiOnePlatformClient $client;
    private ContaiWebsiteProvider $websiteProvider;

    public function __construct(
        ?ContaiOnePlatformClient $client = null,
        ?ContaiWebsiteProvider $websiteProvider = null
    ) {
        $this->client          = $client ?? ContaiOnePlatformClient::create();
        $this->websiteProvider = $websiteProvider ?? new ContaiWebsiteProvider();
    }

    public function addToSearchConsole(): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteSearchConsole($websiteConfig['websiteId']);

        return $this->client->post($endpoint, ['action' => 'add']);
    }

    /**
     * Search Console performance for the configured website (SCP-04).
     *
     * Read-only and free — unlike add/verify/sitemaps, this costs nothing, so
     * it goes through no credit guard.
     *
     * @param string $period One of 24h|7d|28d|3m.
     */
    public function getPerformance(string $period = '28d'): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteSearchConsolePerformance($websiteConfig['websiteId']);

        return $this->client->get($endpoint, ['period' => $period]);
    }

    /**
     * Real sitemap health as Search Console reports it (SCP-05). Free read.
     */
    public function getSitemapsStatus(): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteSearchConsoleSitemaps($websiteConfig['websiteId']);

        return $this->client->get($endpoint);
    }

    public function submitSitemaps(array $sitemaps): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        if (empty($sitemaps)) {
            return new ContaiOnePlatformResponse(false, null, 'No sitemaps to submit', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteSearchConsole($websiteConfig['websiteId']);

        return $this->client->post($endpoint, [
            'action'  => 'sitemaps',
            'payload' => [
                'sitemaps' => $sitemaps,
            ],
        ]);
    }

    public function verifyWebsite(): ContaiOnePlatformResponse
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['websiteId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websiteSearchConsole($websiteConfig['websiteId']);

        return $this->client->post($endpoint, ['action' => 'verify']);
    }

    public function createVerificationFile(): array
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig) {
            return [
                'success' => false,
                'message' => 'Website configuration not found',
            ];
        }

        $fileName    = sanitize_file_name($websiteConfig['file_name'] ?? '');
        $fileContent = $websiteConfig['file_content'] ?? '';

        if (empty($fileName) || empty($fileContent)) {
            return [
                'success' => false,
                'message' => 'Verification file information not available',
            ];
        }

        $rootPath = ABSPATH . $fileName;

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        $result = $wp_filesystem ? $wp_filesystem->put_contents($rootPath, $fileContent, FS_CHMOD_FILE) : false;

        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to create verification file. Check file permissions.',
            ];
        }

        return [
            'success'   => true,
            'message'   => 'Verification file created successfully',
            'file_path' => $rootPath,
        ];
    }

    public function verificationFileExists(): bool
    {
        $websiteConfig = $this->websiteProvider->getWebsiteConfig();

        if (!$websiteConfig || empty($websiteConfig['file_name'])) {
            return false;
        }

        return file_exists(ABSPATH . $websiteConfig['file_name']);
    }
}
