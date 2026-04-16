<?php

namespace ContAI\Tests\Unit\Services\Content;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Mockery;
use ContaiContentGeneratorService;
use ContaiOnePlatformClient;

/**
 * Regression tests for the image_provider alias mapping at the API boundary.
 *
 * The API's ContentGenerationRequest schema accepts only
 * `Literal["default","alternative"]`; plugin-internal code still uses
 * `pixabay`/`pexels` (settings option default, post meta, job payloads).
 * buildRequestData() must translate internal → client-facing alias before
 * the request is sent, otherwise every content generation call returns 422.
 */
class ContentGeneratorServiceImageProviderTest extends TestCase
{
    private ContaiContentGeneratorService $service;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($value) => $value);

        $this->service = new ContaiContentGeneratorService(
            Mockery::mock(ContaiOnePlatformClient::class)
        );
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_pixabay_is_translated_to_default(): void
    {
        $data = $this->buildRequestData('pixabay');
        $this->assertSame('default', $data['image_provider']);
    }

    public function test_pexels_is_translated_to_alternative(): void
    {
        $data = $this->buildRequestData('pexels');
        $this->assertSame('alternative', $data['image_provider']);
    }

    public function test_already_aliased_values_pass_through_unchanged(): void
    {
        $this->assertSame('default', $this->buildRequestData('default')['image_provider']);
        $this->assertSame('alternative', $this->buildRequestData('alternative')['image_provider']);
    }

    public function test_unknown_value_is_passed_through_so_api_surfaces_the_validation_error(): void
    {
        $data = $this->buildRequestData('unsplash');
        $this->assertSame('unsplash', $data['image_provider']);
    }

    private function buildRequestData(string $image_provider): array
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('get_col')->andReturn([]);

        $method = (new ReflectionClass(ContaiContentGeneratorService::class))->getMethod('buildRequestData');
        $method->setAccessible(true);
        return $method->invoke($this->service, 'keyword', 'en', 'us', $image_provider, []);
    }
}
