<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../providers/UserProvider.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiPublisuitesService
{
    private const OPTION_PUBLISUITES_CONFIG = 'contai_publisuites_config';

    private ContaiOnePlatformClient $client;
    private ContaiConfig $config;
    private ContaiUserProvider $userProvider;
    private ContaiWebsiteProvider $websiteProvider;

    public function __construct(
        ?ContaiOnePlatformClient $client = null,
        ?ContaiUserProvider $userProvider = null,
        ?ContaiWebsiteProvider $websiteProvider = null
    ) {
        $this->config = ContaiConfig::getInstance();
        $this->client = $client ?? ContaiOnePlatformClient::create($this->config);
        $this->userProvider = $userProvider ?? new ContaiUserProvider();
        $this->websiteProvider = $websiteProvider ?? new ContaiWebsiteProvider();
    }

    public function getPublisuitesConfig(): ?array
    {
        $config = get_option(self::OPTION_PUBLISUITES_CONFIG, null);

        if (!$config) {
            return null;
        }

        return is_array($config) ? $config : json_decode($config, true);
    }

    public function savePublisuitesConfig(array $data): bool
    {
        $config = [
            'userId' => $data['user_id'] ?? $data['userId'] ?? $this->userProvider->getUserId() ?? '',
            'websiteId' => $data['website_id'] ?? $data['websiteId'] ?? $this->websiteProvider->getWebsiteId() ?? '',
            'publisuitesId' => $data['publisuites_id'] ?? $data['publisuitesId'] ?? '',
            'status' => $data['status'] ?? 'pending_verification',
            'verificationFileName' => $data['verification_file_name'] ?? $data['verificationFileName'] ?? '',
            'verificationFileContent' => $data['verification_file_content'] ?? $data['verificationFileContent'] ?? '',
            'verified' => $data['verified'] ?? false,
            'verifiedAt' => $data['verified_at'] ?? $data['verifiedAt'] ?? null,
            'message' => $data['message'] ?? '',
        ];

        if (isset($data['marketplace_status'])) {
            $config['marketplace_status'] = sanitize_text_field($data['marketplace_status']);
        }
        if (isset($data['marketplace_status_checked_at'])) {
            $config['marketplace_status_checked_at'] = sanitize_text_field($data['marketplace_status_checked_at']);
        }

        return update_option(self::OPTION_PUBLISUITES_CONFIG, $config);
    }

    public function deletePublisuitesConfig(): bool
    {
        return delete_option(self::OPTION_PUBLISUITES_CONFIG);
    }

    public function getSiteUrl(): string
    {
        return $this->websiteProvider->getSiteUrl();
    }

    public function connectWebsite(): ContaiOnePlatformResponse
    {
        $websiteId = $this->websiteProvider->getWebsiteId();

        if (!$websiteId) {
            $result = $this->websiteProvider->ensureWebsiteExists();
            if (!$result['success']) {
                return new ContaiOnePlatformResponse(false, null, $result['message'] ?? 'Could not register website. Please try again later.', 400);
            }
            $websiteId = $this->websiteProvider->getWebsiteId();
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, ['action' => 'add']);
    }

    public function verifyWebsite(): ContaiOnePlatformResponse
    {
        $websiteId = $this->websiteProvider->getWebsiteId();
        $config = $this->getPublisuitesConfig();

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        if (!$config || empty($config['publisuitesId'])) {
            return new ContaiOnePlatformResponse(false, null, 'Marketplace not connected', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, ['action' => 'verify']);
    }

    public function createVerificationFile(): array
    {
        $config = $this->getPublisuitesConfig();

        if (!$config) {
            return [
                'success' => false,
                'message' => 'Marketplace configuration not found',
            ];
        }

        $fileName = basename($config['verificationFileName'] ?? '');
        $fileContent = $config['verificationFileContent'] ?? '';

        if (empty($fileName)) {
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
            'success' => true,
            'message' => 'Verification file created successfully',
            'file_path' => $rootPath,
        ];
    }

    public function verificationFileExists(): bool
    {
        $config = $this->getPublisuitesConfig();

        if (!$config || empty($config['verificationFileName'])) {
            return false;
        }

        return file_exists(ABSPATH . $config['verificationFileName']);
    }

    public function initializeStatus(): array
    {
        $config = $this->getPublisuitesConfig();

        if ($config) {
            return [
                'status' => 'configured',
                'config' => $config,
            ];
        }

        $websiteId = $this->websiteProvider->getWebsiteId();
        if (!$websiteId) {
            $result = $this->websiteProvider->ensureWebsiteExists();
            if (!$result['success']) {
                return [
                    'status' => 'website_required',
                    'config' => null,
                    'message' => $result['message'] ?? 'Could not register website. Please try again later.',
                ];
            }
            $websiteId = $this->websiteProvider->getWebsiteId();
        }

        return [
            'status' => 'not_connected',
            'config' => null,
        ];
    }

    public function isVerified(?array $config = null): bool
    {
        $config = $config ?? $this->getPublisuitesConfig();

        if (!$config) {
            return false;
        }

        return ($config['verified'] ?? false) === true;
    }

    public function isPendingVerification(?array $config = null): bool
    {
        return !$this->isVerified($config);
    }

    public function getOrders(int $page = 1, ?string $statusFilter = null): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $payload = [
            'action' => 'orders',
            'page'   => absint($page),
        ];

        if ($statusFilter !== null) {
            $payload['status_filter'] = sanitize_text_field($statusFilter);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, $payload);
    }

    public function viewOrder(int $orderId): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, [
            'action'   => 'view_order',
            'order_id' => absint($orderId),
        ]);
    }

    public function triggerSync(): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        $response = $this->client->post($endpoint, [
            'action' => 'sync',
        ]);

        if ($response->isSuccess()) {
            $data = $response->getData();
            if (isset($data['marketplace_status'])) {
                $config['marketplace_status'] = $data['marketplace_status'];
                $config['marketplace_status_checked_at'] = gmdate('c');
                $this->savePublisuitesConfig($config);
            }
        }

        return $response;
    }

    public function deleteWebsiteFromMarketplace(): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        if (!$config) {
            return new ContaiOnePlatformResponse(false, null, 'No marketplace configuration found', 400);
        }

        $websiteId = $this->websiteProvider->getWebsiteId();
        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);
        $response = $this->client->post($endpoint, ['action' => 'delete']);

        if (!$response->isSuccess()) {
            return $response;
        }

        $this->deleteVerificationFile($config);
        $this->deletePublisuitesConfig();

        return $response;
    }

    private function deleteVerificationFile(array $config): void
    {
        if (empty($config['verificationFileName'])) {
            return;
        }

        $filePath = ABSPATH . sanitize_file_name($config['verificationFileName']);
        $realPath = realpath($filePath);

        if ($realPath === false || strpos($realPath, realpath(ABSPATH)) !== 0) {
            error_log('[1Platform] Blocked verification file deletion outside ABSPATH');
            return;
        }

        if (!unlink($realPath)) {
            error_log('[1Platform] Failed to delete verification file');
        }
    }

    public function acceptOrder(int $orderId): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, [
            'action'   => 'accept_order',
            'order_id' => absint($orderId),
        ]);
    }

    public function rejectOrder(int $orderId): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, [
            'action'   => 'reject_order',
            'order_id' => absint($orderId),
        ]);
    }

    public function reopenOrder(int $orderId): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, [
            'action'   => 'reopen_order',
            'order_id' => absint($orderId),
        ]);
    }

    public function sendUrl(int $orderId, string $url): ContaiOnePlatformResponse
    {
        $config = $this->getPublisuitesConfig();
        $websiteId = $config['websiteId'] ?? null;

        if (!$websiteId) {
            return new ContaiOnePlatformResponse(false, null, 'Website not configured', 400);
        }

        $endpoint = ContaiOnePlatformEndpoints::websitePublisuites($websiteId);

        return $this->client->post($endpoint, [
            'action'   => 'send_url',
            'order_id' => absint($orderId),
            'url'      => esc_url_raw($url),
        ]);
    }
}
