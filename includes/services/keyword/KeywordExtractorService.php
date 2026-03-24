<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';
require_once __DIR__ . '/../../database/repositories/KeywordRepository.php';
require_once __DIR__ . '/../../database/models/Keyword.php';
require_once __DIR__ . '/../category/CategoryService.php';
require_once __DIR__ . '/../menu/MainMenuManager.php';

class ContaiKeywordExtractorService {

    private const ERROR_API_REQUEST_FAILED = 'Failed to extract keywords from API';
    private const ERROR_INVALID_RESPONSE = 'Invalid response from API';

    private ContaiOnePlatformClient $client;
    private ContaiKeywordRepository $repository;
    private ContaiCategoryService $category_service;
    private ContaiMainMenuManager $menu_manager;

    public function __construct(
        ContaiOnePlatformClient $client,
        ContaiKeywordRepository $repository,
        ContaiCategoryService $category_service,
        ContaiMainMenuManager $menu_manager
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->category_service = $category_service;
        $this->menu_manager = $menu_manager;
    }

    public static function create(): self {
        $client = ContaiOnePlatformClient::create();
        $repository = new ContaiKeywordRepository();
        $category_service = new ContaiCategoryService();
        $menu_manager = new ContaiMainMenuManager();

        return new self($client, $repository, $category_service, $menu_manager);
    }

    public function extractAndSaveKeywords(
        string $domain,
        string $country,
        string $lang
    ): ContaiKeywordExtractionResult {
        $response = $this->requestKeywordExtraction($domain, $country, $lang);

        if (!$response->isSuccess()) {
            return ContaiKeywordExtractionResult::failure(
                $response->getMessage() ?? self::ERROR_API_REQUEST_FAILED
            );
        }

        return $this->processAndSaveKeywords($response);
    }

    public function extractByTopicAndSave(
        string $topic,
        string $country,
        string $lang
    ): ContaiKeywordExtractionResult {
        $response = $this->requestKeywordExtractionByTopic($topic, $country, $lang);

        if (!$response->isSuccess()) {
            return ContaiKeywordExtractionResult::failure(
                $response->getMessage() ?? self::ERROR_API_REQUEST_FAILED
            );
        }

        return $this->processAndSaveKeywords($response);
    }

    private function requestKeywordExtraction(
        string $domain,
        string $country,
        string $lang
    ): ContaiOnePlatformResponse {
        $request_data = [
            'domain' => $domain,
            'country' => $country,
            'lang' => $lang,
        ];

        return $this->client->post(ContaiOnePlatformEndpoints::POSTS_KEYWORDS, $request_data);
    }

    private function requestKeywordExtractionByTopic(
        string $topic,
        string $country,
        string $lang
    ): ContaiOnePlatformResponse {
        $request_data = [
            'topic' => $topic,
            'country' => $country,
            'lang' => $lang,
        ];

        return $this->client->post(ContaiOnePlatformEndpoints::POSTS_KEYWORDS_TOPIC, $request_data);
    }

    private function processAndSaveKeywords(ContaiOnePlatformResponse $response): ContaiKeywordExtractionResult {
        $data = $response->getData();

        if (!$this->isValidResponseData($data)) {
            return ContaiKeywordExtractionResult::failure(self::ERROR_INVALID_RESPONSE);
        }

        $categories = $data['categories'] ?? [];
        $category_stats = $this->processCategories($categories);

        if (!empty($categories)) {
            $this->updateMainMenu($categories);
        }

        $keywords = $data['keywords'] ?? [];
        $keyword_stats = $this->saveKeywords($keywords);

        return ContaiKeywordExtractionResult::success(
            $keyword_stats['saved'],
            $keyword_stats['skipped'],
            $keyword_stats['total'],
            $keywords,
            $category_stats
        );
    }

    private function processCategories(array $categories): ContaiCategoryProcessingResult {
        if (empty($categories)) {
            return new ContaiCategoryProcessingResult(0, 0, 0);
        }

        return $this->category_service->processCategoriesFromResponse($categories);
    }

    private function updateMainMenu(array $categories): void {
        try {
            $this->menu_manager->updateMainMenuWithCategories($categories);
        } catch (Exception $e) {
            contai_log("Error updating main menu: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    private function isValidResponseData($data): bool {
        return is_array($data)
            && isset($data['keywords'])
            && is_array($data['keywords']);
    }

    private function saveKeywords(array $keywords): array {
        $saved = 0;
        $skipped = 0;
        $total = count($keywords);

        foreach ($keywords as $keyword_data) {
            if ($this->saveKeyword($keyword_data)) {
                $saved++;
            } else {
                $skipped++;
            }
        }

        return compact('saved', 'skipped', 'total');
    }

    private function saveKeyword(array $data): bool {
        if (!$this->isValidKeywordData($data)) {
            return false;
        }

        $keyword_text = $data['keyword'];

        if ($this->repository->exists($keyword_text)) {
            return false;
        }

        return $this->createKeyword($data);
    }

    private function isValidKeywordData(array $data): bool {
        return isset($data['keyword'])
            && isset($data['title'])
            && isset($data['volume'])
            && isset($data['url']);
    }

    private function createKeyword(array $data): bool {
        try {
            $keyword = new ContaiKeyword([
                'keyword' => $data['keyword'],
                'original_keyword' => $data['original_keyword'] ?? null,
                'title' => $data['title'],
                'original_title' => $data['original_title'] ?? null,
                'volume' => (int) $data['volume'],
                'url' => $data['url'],
                'status' => ContaiKeyword::STATUS_PENDING,
            ]);

            if (!$keyword->isValid()) {
                return false;
            }

            $id = $this->repository->create($keyword);

            return $id !== null;
        } catch (Exception $e) {
            contai_log("Error creating keyword: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            return false;
        }
    }
}

class ContaiKeywordExtractionResult {

    private bool $success;
    private ?string $error_message;
    private int $saved_count;
    private int $skipped_count;
    private int $total_count;
    private array $keywords;
    private ?ContaiCategoryProcessingResult $category_stats;

    private function __construct(
        bool $success,
        ?string $error_message = null,
        int $saved_count = 0,
        int $skipped_count = 0,
        int $total_count = 0,
        array $keywords = [],
        ?ContaiCategoryProcessingResult $category_stats = null
    ) {
        $this->success = $success;
        $this->error_message = $error_message;
        $this->saved_count = $saved_count;
        $this->skipped_count = $skipped_count;
        $this->total_count = $total_count;
        $this->keywords = $keywords;
        $this->category_stats = $category_stats;
    }

    public static function success(
        int $saved,
        int $skipped,
        int $total,
        array $keywords,
        ContaiCategoryProcessingResult $category_stats
    ): self {
        return new self(true, null, $saved, $skipped, $total, $keywords, $category_stats);
    }

    public static function failure(string $error_message): self {
        return new self(false, $error_message);
    }

    public function isSuccess(): bool {
        return $this->success;
    }

    public function getErrorMessage(): ?string {
        return $this->error_message;
    }

    public function getSavedCount(): int {
        return $this->saved_count;
    }

    public function getSkippedCount(): int {
        return $this->skipped_count;
    }

    public function getTotalCount(): int {
        return $this->total_count;
    }

    public function getKeywords(): array {
        return $this->keywords;
    }

    public function getCategoryStats(): ?ContaiCategoryProcessingResult {
        return $this->category_stats;
    }

    public function toArray(): array {
        $result = [
            'success' => $this->success,
            'error_message' => $this->error_message,
            'saved_count' => $this->saved_count,
            'skipped_count' => $this->skipped_count,
            'total_count' => $this->total_count,
            'keywords' => $this->keywords,
        ];

        if ($this->category_stats !== null) {
            $result['category_stats'] = $this->category_stats->toArray();
        }

        return $result;
    }
}
