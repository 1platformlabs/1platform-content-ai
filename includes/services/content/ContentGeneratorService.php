<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../api/OnePlatformClient.php';
require_once __DIR__ . '/../api/OnePlatformEndpoints.php';

class ContaiContentGeneratorService {

    private const ERROR_API_REQUEST_FAILED = 'Failed to generate content from API';
    private const ERROR_INVALID_RESPONSE = 'Invalid response from API';

    // Translate internal provider identifiers to the client-facing aliases the API accepts
    // (Literal["default","alternative"]). Keeps the plugin free of external provider names
    // on the wire while preserving the existing internal vocabulary (options, post meta).
    private const IMAGE_PROVIDER_MAP = [
        'pixabay' => 'default',
        'pexels'  => 'alternative',
    ];

    private ContaiOnePlatformClient $client;

    public function __construct(ContaiOnePlatformClient $client) {
        $this->client = $client;
    }

    public static function create(): self {
        $client = ContaiOnePlatformClient::create();
        return new self($client);
    }

    /**
     * Create a remote async content generation job.
     *
     * Returns an array with 'job_id' and 'status' on success, or
     * an array with 'success' => false and error details on failure.
     *
     * @return array{success: bool, job_id?: string, status?: string, message?: string, status_code?: int}
     */
    public function createRemoteContentJob(
        string $keyword,
        string $lang,
        string $country,
        string $image_provider,
        array $categories = []
    ): array {
        $request_data = $this->buildRequestData($keyword, $lang, $country, $image_provider, $categories);

        $response = $this->client->post(ContaiOnePlatformEndpoints::POSTS_CONTENT, $request_data);

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getMessage() ?? self::ERROR_API_REQUEST_FAILED,
                'status_code' => $response->getStatusCode(),
            ];
        }

        $data = $response->getData();

        if (empty($data['job_id'])) {
            return [
                'success' => false,
                'message' => 'API did not return a job_id',
                'status_code' => $response->getStatusCode(),
            ];
        }

        return [
            'success' => true,
            'job_id' => sanitize_text_field($data['job_id']),
            'status' => sanitize_text_field($data['status'] ?? 'pending'),
        ];
    }

    /**
     * Poll the remote content generation job by ID.
     *
     * @return array{success: bool, status?: string, result?: array, error?: string, message?: string, status_code?: int}
     */
    public function getRemoteJobStatus(string $remote_job_id): array {
        $endpoint = ContaiOnePlatformEndpoints::postsContentJobById($remote_job_id);
        $response = $this->client->get($endpoint);

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getMessage() ?? 'Failed to poll remote job status',
                'status_code' => $response->getStatusCode(),
            ];
        }

        $data = $response->getData();

        return [
            'success' => true,
            'status' => sanitize_text_field($data['status'] ?? 'unknown'),
            'result' => is_array($data['result'] ?? null) ? $data['result'] : null,
            'error' => isset($data['error']) ? sanitize_text_field($data['error']) : null,
        ];
    }

    public function generateContent(
        string $keyword,
        string $lang,
        string $country,
        string $image_provider,
        array $categories = []
    ): ContaiContentGenerationResult {
        $response = $this->requestContentGeneration(
            $keyword,
            $lang,
            $country,
            $image_provider,
            $categories
        );

        if (!$response->isSuccess()) {
            return ContaiContentGenerationResult::failure(
                $response->getMessage() ?? self::ERROR_API_REQUEST_FAILED,
                $response->getStatusCode()
            );
        }

        return $this->processResponse($response);
    }

    private function buildRequestData(
        string $keyword,
        string $lang,
        string $country,
        string $image_provider,
        array $categories
    ): array {
        $request_data = [
            'keyword' => sanitize_text_field($keyword),
            'lang' => sanitize_text_field($lang),
            'country' => sanitize_text_field($country),
            'image_provider' => $this->resolveApiImageProvider($image_provider),
        ];

        if (!empty($categories)) {
            $request_data['categories'] = array_map('sanitize_text_field', $categories);
        }

        $used_urls = $this->getUsedFeaturedImageUrls();
        if (!empty($used_urls)) {
            $request_data['exclude_image_urls'] = array_values($used_urls);
        }

        return $request_data;
    }

    private function resolveApiImageProvider(string $image_provider): string {
        $sanitized = sanitize_text_field($image_provider);
        return self::IMAGE_PROVIDER_MAP[$sanitized] ?? $sanitized;
    }

    private function requestContentGeneration(
        string $keyword,
        string $lang,
        string $country,
        string $image_provider,
        array $categories
    ): ContaiOnePlatformResponse {
        $request_data = $this->buildRequestData($keyword, $lang, $country, $image_provider, $categories);

        return $this->client->post(ContaiOnePlatformEndpoints::POSTS_CONTENT, $request_data);
    }

    private function processResponse(ContaiOnePlatformResponse $response): ContaiContentGenerationResult {
        $data = $response->getData();

        if (!$this->isValidResponseData($data)) {
            return ContaiContentGenerationResult::failure(self::ERROR_INVALID_RESPONSE);
        }

        $seo_metadata = $data['seo_metadata'] ?? [];

        return ContaiContentGenerationResult::success(
            $data['title'] ?? '',
            $data['content'] ?? '',
            $data['images'] ?? [],
            $data['category'] ?? null,
            $data['url'] ?? null,
            $seo_metadata['metatitle'] ?? null,
            $seo_metadata['meta_description'] ?? null,
            $seo_metadata['post_date'] ?? null
        );
    }

    private function isValidResponseData($data): bool {
        return is_array($data)
            && isset($data['title'])
            && isset($data['content']);
    }

    private function getUsedFeaturedImageUrls(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_contai_featured_image_source' AND meta_value != ''"
        );

        return is_array($results) ? $results : [];
    }
}

class ContaiContentGenerationResult {

    private bool $success;
    private ?string $error_message;
    private ?int $status_code;
    private string $title;
    private string $content;
    private array $images;
    private ?string $category;
    private ?string $slug;
    private ?string $metatitle;
    private ?string $meta_description;
    private ?string $post_date;

    private function __construct(
        bool $success,
        string $title = '',
        string $content = '',
        array $images = [],
        ?string $category = null,
        ?string $slug = null,
        ?string $metatitle = null,
        ?string $meta_description = null,
        ?string $post_date = null,
        ?string $error_message = null,
        ?int $status_code = null
    ) {
        $this->success = $success;
        $this->title = $title;
        $this->content = $content;
        $this->images = $images;
        $this->category = $category;
        $this->slug = $slug;
        $this->metatitle = $metatitle;
        $this->meta_description = $meta_description;
        $this->post_date = $post_date;
        $this->error_message = $error_message;
        $this->status_code = $status_code;
    }

    public static function success(
        string $title,
        string $content,
        array $images = [],
        ?string $category = null,
        ?string $slug = null,
        ?string $metatitle = null,
        ?string $meta_description = null,
        ?string $post_date = null
    ): self {
        return new self(true, $title, $content, $images, $category, $slug, $metatitle, $meta_description, $post_date, null, 200);
    }

    public static function failure(string $error_message, ?int $status_code = null): self {
        return new self(false, '', '', [], null, null, null, null, null, $error_message, $status_code);
    }

    public function isSuccess(): bool {
        return $this->success;
    }

    public function getErrorMessage(): ?string {
        return $this->error_message;
    }

    public function getTitle(): string {
        return $this->title;
    }

    public function getContent(): string {
        return $this->content;
    }

    public function getImages(): array {
        return $this->images;
    }

    public function getCategory(): ?string {
        return $this->category;
    }

    public function getSlug(): ?string {
        return $this->slug;
    }

    public function getMetatitle(): ?string {
        return $this->metatitle;
    }

    public function getMetaDescription(): ?string {
        return $this->meta_description;
    }

    public function getPostDate(): ?string {
        return $this->post_date;
    }

    public function getStatusCode(): ?int {
        return $this->status_code;
    }

    public function toArray(): array {
        return [
            'success' => $this->success,
            'title' => $this->title,
            'content' => $this->content,
            'images' => $this->images,
            'category' => $this->category,
            'slug' => $this->slug,
            'metatitle' => $this->metatitle,
            'meta_description' => $this->meta_description,
            'post_date' => $this->post_date,
            'error_message' => $this->error_message,
            'status_code' => $this->status_code,
        ];
    }
}
