<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/WordPressPostCreator.php';
require_once __DIR__ . '/ContentImageProcessor.php';
require_once __DIR__ . '/ImageUploader.php';
require_once __DIR__ . '/PostMetadataBuilder.php';
require_once __DIR__ . '/../content/ContentGeneratorService.php';
require_once __DIR__ . '/../category/CategoryService.php';
require_once __DIR__ . '/../../database/models/Keyword.php';

class ContaiContentGenerationException extends RuntimeException {

    private ?int $status_code;

    public function __construct(string $message, ?int $status_code = null) {
        parent::__construct($message);
        $this->status_code = $status_code;
    }

    public function getStatusCode(): ?int {
        return $this->status_code;
    }

    public function isNotFound(): bool {
        return $this->status_code === 404;
    }

    public function isClientError(): bool {
        return $this->status_code !== null && $this->status_code >= 400 && $this->status_code < 500;
    }

    public function isUnrecoverable(): bool {
        if ($this->isClientError()) {
            return true;
        }

        $unrecoverable_patterns = [
            'ValueSerp API error',
            'Cannot generate content',
        ];

        foreach ($unrecoverable_patterns as $pattern) {
            if (stripos($this->getMessage(), $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}

class ContaiPostGenerationOrchestrator {

    private ContaiContentGeneratorService $content_generator;
    private ContaiCategoryService $category_service;
    private ContaiWordPressPostCreator $post_creator;
    private ContaiContentImageProcessor $image_processor;
    private ContaiImageUploader $image_uploader;
    private ContaiPostMetadataBuilder $metadata_builder;

    public function __construct(
        ContaiContentGeneratorService $content_generator,
        ContaiCategoryService $category_service,
        ContaiWordPressPostCreator $post_creator,
        ContaiContentImageProcessor $image_processor,
        ContaiImageUploader $image_uploader,
        ContaiPostMetadataBuilder $metadata_builder
    ) {
        $this->content_generator = $content_generator;
        $this->category_service = $category_service;
        $this->post_creator = $post_creator;
        $this->image_processor = $image_processor;
        $this->image_uploader = $image_uploader;
        $this->metadata_builder = $metadata_builder;
    }

    public static function create(): self {
        $image_uploader = new ContaiImageUploader();

        return new self(
            ContaiContentGeneratorService::create(),
            new ContaiCategoryService(),
            new ContaiWordPressPostCreator(),
            new ContaiContentImageProcessor($image_uploader),
            $image_uploader,
            new ContaiPostMetadataBuilder()
        );
    }

    public function generate(ContaiKeyword $keyword, array $params): ContaiPostGenerationResult {
        $content_result = $this->generateContent($keyword, $params);

        if (!$content_result->isSuccess()) {
            throw new ContaiContentGenerationException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not rendered to browser; caught and logged internally.
                $content_result->getErrorMessage() ?? 'Failed to generate content',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status code is an integer, not rendered to browser.
                $content_result->getStatusCode()
            );
        }

        return $this->buildPost($keyword, $params, $content_result);
    }

    public function createPostFromApiResult(ContaiKeyword $keyword, array $params, array $api_result): ContaiPostGenerationResult {
        $seo_metadata = $api_result['seo_metadata'] ?? [];

        $content_result = ContaiContentGenerationResult::success(
            $api_result['title'] ?? '',
            $api_result['content'] ?? '',
            $api_result['images'] ?? [],
            $api_result['category'] ?? null,
            $api_result['url'] ?? null,
            $seo_metadata['metatitle'] ?? null,
            $seo_metadata['post_date'] ?? null
        );

        if (empty($content_result->getTitle()) || empty($content_result->getContent())) {
            throw new ContaiContentGenerationException('Remote job returned empty title or content');
        }

        return $this->buildPost($keyword, $params, $content_result);
    }

    private function buildPost(ContaiKeyword $keyword, array $params, ContaiContentGenerationResult $content_result): ContaiPostGenerationResult {
        $processed_content = $this->image_processor->process(
            $content_result->getContent(),
            $content_result->getImages()
        );

        $title = $content_result->getTitle() ?: $keyword->getKeyword();
        $post_id = $this->post_creator->create(
            $title,
            $processed_content,
            $content_result->getSlug(),
            $content_result->getPostDate(),
            $content_result->getMetatitle()
        );

        $category_id = $this->assignCategoryIfExists($post_id, $content_result->getCategory());

        $this->saveMetadata($post_id, $keyword, $params);

        $this->setFeaturedImageIfExists($post_id, $content_result->getImages());

        return new ContaiPostGenerationResult(
            $post_id,
            $this->post_creator->getPermalink($post_id),
            $category_id
        );
    }

    private function generateContent(ContaiKeyword $keyword, array $params): ContaiContentGenerationResult {
        $category_names = $this->category_service->getAllCategoryNames();

        return $this->content_generator->generateContent(
            $keyword->getTitle(),
            $params['lang'] ?? 'en',
            $params['country'] ?? 'us',
            $params['image_provider'] ?? 'pexels',
            $category_names
        );
    }

    private function assignCategoryIfExists(int $post_id, ?string $category_name): ?int {
        if (empty($category_name)) {
            return null;
        }

        $category_id = $this->category_service->findOrCreateCategoryId($category_name);

        if ($category_id !== null) {
            $this->post_creator->assignCategory($post_id, $category_id);
        }

        return $category_id;
    }

    private function saveMetadata(int $post_id, ContaiKeyword $keyword, array $params): void {
        $metadata = $this->metadata_builder->buildFromKeyword($keyword, $params);
        $this->post_creator->saveMetadata($post_id, $metadata);
    }

    private function setFeaturedImageIfExists(int $post_id, array $images): void {
        if (empty($images)) {
            return;
        }

        $first_image_url = $images[0]['url'] ?? null;

        if (empty($first_image_url)) {
            return;
        }

        $attachment_id = $this->image_uploader->uploadFromUrl($first_image_url);

        if ($attachment_id !== null) {
            $this->post_creator->setFeaturedImage($post_id, $attachment_id);
        }
    }
}

class ContaiPostGenerationResult {

    private int $post_id;
    private string $post_url;
    private ?int $category_id;

    public function __construct(int $post_id, string $post_url, ?int $category_id) {
        $this->post_id = $post_id;
        $this->post_url = $post_url;
        $this->category_id = $category_id;
    }

    public function getPostId(): int {
        return $this->post_id;
    }

    public function getPostUrl(): string {
        return $this->post_url;
    }

    public function getCategoryId(): ?int {
        return $this->category_id;
    }
}
