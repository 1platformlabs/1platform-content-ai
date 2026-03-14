<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../providers/UserProvider.php';

class ContaiCategoryAPIService {

    private ContaiOnePlatformClient $client;
    private ContaiConfig $config;
    private ContaiUserProvider $userProvider;

    private const CACHE_KEY_PREFIX = 'contai_categories_';
    private const CACHE_TTL = 900;

    public function __construct(?ContaiOnePlatformClient $client = null, ?ContaiUserProvider $userProvider = null) {
        $this->config = ContaiConfig::getInstance();
        $this->client = $client ?? ContaiOnePlatformClient::create($this->config);
        $this->userProvider = $userProvider ?? new ContaiUserProvider();
    }

    public function getCategories(bool $force_refresh = false): ContaiOnePlatformResponse {
        $userId = $this->userProvider->getUserId();

        if (!$userId) {
            return new ContaiOnePlatformResponse(false, null, 'User profile not configured', 400);
        }

        $cache_key = self::CACHE_KEY_PREFIX . $userId;
        if (!$force_refresh) {
            $cached_data = get_transient($cache_key);
            if (false !== $cached_data) {
                return new ContaiOnePlatformResponse(true, $cached_data, 'Categories retrieved from cache');
            }
        }

        $response = $this->client->get(ContaiOnePlatformEndpoints::USERS_CATEGORIES);

        if ($response->isSuccess() && $response->getData()) {
            set_transient($cache_key, $response->getData(), self::CACHE_TTL);
        }

        return $response;
    }

    public function getActiveCategories(bool $force_refresh = false): array {
        $response = $this->getCategories($force_refresh);

        if (!$response->isSuccess()) {
            return [];
        }

        $categories = $response->getData();
        if (!is_array($categories)) {
            return [];
        }

        $active_categories = array_filter($categories, function ($category) {
            return isset($category['status']) && 'active' === $category['status'];
        });

        return array_values($active_categories);
    }

    public function clearCache(): bool {
        $user_id = $this->userProvider->getUserId();
        if (!$user_id) {
            return false;
        }

        $cache_key = self::CACHE_KEY_PREFIX . $user_id;
        return delete_transient($cache_key);
    }

    public static function getCategoryTitle(array $category, string $language): string {
        $lang_key = 'en' === $language ? 'en' : 'es';

        if (isset($category['title'][$lang_key])) {
            return $category['title'][$lang_key];
        }

        if (isset($category['title']['en'])) {
            return $category['title']['en'];
        }

        if (isset($category['title']) && is_array($category['title'])) {
            $titles = array_values($category['title']);
            return $titles[0] ?? 'Unnamed Category';
        }

        return 'Unnamed Category';
    }

    public static function normalizeLanguage(string $form_language): string {
        $form_language = strtolower(trim($form_language));

        $language_map = [
            'english' => 'en',
            'spanish' => 'es',
            'en'      => 'en',
            'es'      => 'es',
        ];

        return $language_map[$form_language] ?? 'en';
    }
}
