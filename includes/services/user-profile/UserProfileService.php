<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiUserProfileService
{
    private const OPTION_USER_PROFILE = 'contai_user_profile';
    private const OPTION_API_KEY = 'contai_api_key';

    private ContaiOnePlatformClient $client;
    private ContaiConfig $config;
    private ContaiWebsiteProvider $websiteProvider;

    public function __construct(?ContaiOnePlatformClient $client = null, ?ContaiWebsiteProvider $websiteProvider = null)
    {
        $this->config = ContaiConfig::getInstance();
        $this->client = $client ?? ContaiOnePlatformClient::create($this->config);
        $this->websiteProvider = $websiteProvider ?? new ContaiWebsiteProvider();
    }

    public function hasApiKey(): bool
    {
        $encryptedKey = get_option(self::OPTION_API_KEY, '');
        return !empty($encryptedKey);
    }

    public function saveApiKey(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return false;
        }

        $encryptedKey = contai_encrypt_api_key($apiKey);
        return update_option(self::OPTION_API_KEY, $encryptedKey);
    }

    public function deleteApiKey(): bool
    {
        $this->disconnectWebsiteLocally();
        $this->deleteUserProfile();
        return delete_option(self::OPTION_API_KEY);
    }

    private function disconnectWebsiteLocally(): void
    {
        $this->websiteProvider->deleteWebsiteConfig();
    }

    public function getUserProfile(): ?array
    {
        $profile = get_option(self::OPTION_USER_PROFILE, null);

        if (!$profile) {
            return null;
        }

        return is_array($profile) ? $profile : json_decode($profile, true);
    }

    public function saveUserProfile(array $data): bool
    {
        $profile = [
            'userId' => $data['id'] ?? '',
            'username' => $data['username'] ?? '',
            'status' => ($data['is_active'] ?? false) ? 'active' : 'inactive',
        ];

        return update_option(self::OPTION_USER_PROFILE, $profile);
    }

    public function deleteUserProfile(): bool
    {
        return delete_option(self::OPTION_USER_PROFILE);
    }

    public function fetchUserProfile(): ContaiOnePlatformResponse
    {
        return $this->client->get(ContaiOnePlatformEndpoints::USERS_PROFILE);
    }

    public function initializeUserProfile(): array
    {
        if (!$this->hasApiKey()) {
            return [
                'status' => 'no_license',
                'profile' => null,
            ];
        }

        $cachedProfile = $this->getUserProfile();

        if ($cachedProfile) {
            return [
                'status' => 'active',
                'profile' => $cachedProfile,
            ];
        }

        $response = $this->fetchUserProfile();

        if (!$response->isSuccess()) {
            return [
                'status' => 'error',
                'profile' => null,
                'message' => $response->getMessage(),
            ];
        }

        $data = $response->getData();
        $this->saveUserProfile($data);

        return [
            'status' => 'active',
            'profile' => $this->getUserProfile(),
        ];
    }

    public function refreshUserProfile(): array
    {
        if (!$this->hasApiKey()) {
            return [
                'success' => false,
                'message' => 'No API key configured',
            ];
        }

        $response = $this->fetchUserProfile();

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getMessage(),
            ];
        }

        $data = $response->getData();
        $this->saveUserProfile($data);

        return [
            'success' => true,
            'profile' => $this->getUserProfile(),
        ];
    }
}
