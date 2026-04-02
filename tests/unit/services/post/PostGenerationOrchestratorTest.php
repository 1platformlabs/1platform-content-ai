<?php

namespace ContAI\Tests\Unit\Services\Post;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPostGenerationOrchestrator;
use ContaiContentGeneratorService;
use ContaiCategoryService;
use ContaiWordPressPostCreator;
use ContaiContentImageProcessor;
use ContaiImageUploader;
use ContaiPostMetadataBuilder;

class PostGenerationOrchestratorTest extends TestCase
{
    private ContaiContentGeneratorService $content_generator;
    private ContaiCategoryService $category_service;
    private ContaiWordPressPostCreator $post_creator;
    private ContaiContentImageProcessor $image_processor;
    private ContaiImageUploader $image_uploader;
    private ContaiPostMetadataBuilder $metadata_builder;
    private ContaiPostGenerationOrchestrator $orchestrator;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->content_generator = Mockery::mock(ContaiContentGeneratorService::class);
        $this->category_service = Mockery::mock(ContaiCategoryService::class);
        $this->post_creator = Mockery::mock(ContaiWordPressPostCreator::class);
        $this->image_processor = Mockery::mock(ContaiContentImageProcessor::class);
        $this->image_uploader = Mockery::mock(ContaiImageUploader::class);
        $this->metadata_builder = Mockery::mock(ContaiPostMetadataBuilder::class);

        $this->orchestrator = new ContaiPostGenerationOrchestrator(
            $this->content_generator,
            $this->category_service,
            $this->post_creator,
            $this->image_processor,
            $this->image_uploader,
            $this->metadata_builder
        );
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Featured Image Deduplication Tests ─────────────────────────

    public function test_featured_image_skips_already_used_url_and_picks_next(): void
    {
        $images = [
            ['url' => 'https://images.example.com/image-already-used.jpg'],
            ['url' => 'https://images.example.com/image-unique.jpg'],
            ['url' => 'https://images.example.com/image-third.jpg'],
        ];

        $this->setupPostCreation();

        // Simulate that first image is already used as featured image
        $this->mockUsedFeaturedImageUrls(['https://images.example.com/image-already-used.jpg']);

        // Claim the URL before uploading
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(1, '_contai_featured_image_source', 'https://images.example.com/image-unique.jpg');

        // Expect the unique (second) image to be uploaded
        $this->image_uploader
            ->shouldReceive('uploadFromUrl')
            ->once()
            ->with('https://images.example.com/image-unique.jpg')
            ->andReturn(42);

        $this->post_creator
            ->shouldReceive('setFeaturedImage')
            ->once()
            ->with(1, 42);

        $this->executeCreatePostFromApiResult($images);
    }

    public function test_featured_image_skipped_when_all_candidates_already_used(): void
    {
        $images = [
            ['url' => 'https://images.example.com/image-a.jpg'],
            ['url' => 'https://images.example.com/image-b.jpg'],
        ];

        $this->setupPostCreation();

        // Both images already used
        $this->mockUsedFeaturedImageUrls([
            'https://images.example.com/image-a.jpg',
            'https://images.example.com/image-b.jpg',
        ]);

        // Should NOT attempt upload or set featured image — avoids duplication
        $this->image_uploader->shouldNotReceive('uploadFromUrl');
        $this->post_creator->shouldNotReceive('setFeaturedImage');

        $this->executeCreatePostFromApiResult($images);
    }

    public function test_featured_image_uses_first_when_none_previously_used(): void
    {
        $images = [
            ['url' => 'https://images.example.com/fresh-image.jpg'],
            ['url' => 'https://images.example.com/another-image.jpg'],
        ];

        $this->setupPostCreation();

        // No images used yet
        $this->mockUsedFeaturedImageUrls([]);

        // Claim the URL before uploading
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(1, '_contai_featured_image_source', 'https://images.example.com/fresh-image.jpg');

        $this->image_uploader
            ->shouldReceive('uploadFromUrl')
            ->once()
            ->with('https://images.example.com/fresh-image.jpg')
            ->andReturn(5);

        $this->post_creator
            ->shouldReceive('setFeaturedImage')
            ->once()
            ->with(1, 5);

        $this->executeCreatePostFromApiResult($images);
    }

    public function test_featured_image_claim_cleaned_up_on_upload_failure(): void
    {
        $images = [
            ['url' => 'https://images.example.com/will-fail.jpg'],
            ['url' => 'https://images.example.com/another.jpg'],
        ];

        $this->setupPostCreation();

        // First image is unused
        $this->mockUsedFeaturedImageUrls([]);

        // Claim the URL before uploading
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(1, '_contai_featured_image_source', 'https://images.example.com/will-fail.jpg');

        // Upload fails
        $this->image_uploader
            ->shouldReceive('uploadFromUrl')
            ->once()
            ->with('https://images.example.com/will-fail.jpg')
            ->andReturn(null);

        // Should NOT set featured image
        $this->post_creator->shouldNotReceive('setFeaturedImage');

        // Should clean up the claim
        WP_Mock::userFunction('delete_post_meta')
            ->once()
            ->with(1, '_contai_featured_image_source');

        $this->executeCreatePostFromApiResult($images);
    }

    public function test_featured_image_handles_empty_images_array(): void
    {
        $images = [];

        $this->setupPostCreation();

        // Should not attempt upload or DB query
        $this->image_uploader->shouldNotReceive('uploadFromUrl');
        $this->post_creator->shouldNotReceive('setFeaturedImage');

        $this->executeCreatePostFromApiResult($images);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function setupPostCreation(): void
    {
        $this->image_processor
            ->shouldReceive('process')
            ->once()
            ->andReturn('<p>Content</p>');

        $this->post_creator
            ->shouldReceive('create')
            ->once()
            ->andReturn(1);

        $this->category_service
            ->shouldReceive('findOrCreateCategoryId')
            ->andReturn(1);

        $this->post_creator
            ->shouldReceive('assignCategory')
            ->andReturn(null);

        $this->metadata_builder
            ->shouldReceive('buildFromKeyword')
            ->once()
            ->andReturn([]);

        $this->post_creator
            ->shouldReceive('saveMetadata')
            ->once();

        $this->post_creator
            ->shouldReceive('getPermalink')
            ->once()
            ->andReturn('https://example.com/test-post');
    }

    private function mockUsedFeaturedImageUrls(array $urls): void
    {
        global $wpdb;
        $wpdb = Mockery::mock(\stdClass::class);
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () { return 'prepared_query'; });
        $wpdb->shouldReceive('get_col')
            ->once()
            ->with('prepared_query')
            ->andReturn($urls);
    }

    private function executeCreatePostFromApiResult(array $images): void
    {
        $keyword = Mockery::mock(\ContaiKeyword::class);
        $keyword->shouldReceive('getId')->andReturn(1);
        $keyword->shouldReceive('getKeyword')->andReturn('test keyword');
        $keyword->shouldReceive('getTitle')->andReturn('Test Title');
        $keyword->shouldReceive('getVolume')->andReturn(100);

        $api_result = [
            'title' => 'Test Article Title',
            'content' => '<p>Test content</p>',
            'images' => $images,
            'category' => 'Finance',
            'url' => 'test-article',
            'seo_metadata' => [
                'metatitle' => 'Test Meta Title',
                'post_date' => '2026-01-15T10:00:00',
            ],
        ];

        $this->orchestrator->createPostFromApiResult(
            $keyword,
            ['lang' => 'en', 'country' => 'us', 'image_provider' => 'stock'],
            $api_result
        );
    }
}
