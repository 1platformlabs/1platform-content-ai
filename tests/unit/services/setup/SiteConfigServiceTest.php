<?php

namespace ContAI\Tests\Unit\Services\Setup;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSiteConfigService;
use ContaiWebsiteProvider;
use ContaiOnePlatformResponse;

class SiteConfigServiceTest extends TestCase
{
    private $mockProvider;
    private ContaiSiteConfigService $service;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->mockProvider = Mockery::mock(ContaiWebsiteProvider::class);
        $this->service = new ContaiSiteConfigService($this->mockProvider);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── saveSiteConfiguration — saves options + syncs API ──────

    public function test_save_stores_all_options_and_syncs_api(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('update_option')->andReturn(true);

        $this->mockProvider
            ->shouldReceive('updateWebsite')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['lang'] === 'es'
                    && $data['category_id'] === 'cat-123';
            }))
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'OK', 200));

        $result = $this->service->saveSiteConfiguration([
            'site_topic' => 'personal finance',
            'site_language' => 'spanish',
            'site_category' => 'cat-123',
            'wordpress_theme' => 'generatepress',
        ]);

        $this->assertTrue($result);
    }

    public function test_save_syncs_language_without_category(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('update_option')->andReturn(true);

        $this->mockProvider
            ->shouldReceive('updateWebsite')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['lang'] === 'en'
                    && !isset($data['category_id']);
            }))
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'OK', 200));

        $result = $this->service->saveSiteConfiguration([
            'site_topic' => 'technology',
            'site_language' => 'english',
            'wordpress_theme' => 'astra',
        ]);

        $this->assertTrue($result);
    }

    public function test_save_throws_when_topic_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Site topic is required');

        $this->service->saveSiteConfiguration([
            'site_topic' => '',
            'site_language' => 'spanish',
            'wordpress_theme' => 'astra',
        ]);
    }

    public function test_api_sync_failure_does_not_throw(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('update_option')->andReturn(true);
        WP_Mock::userFunction('contai_log');

        $this->mockProvider
            ->shouldReceive('updateWebsite')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        $result = $this->service->saveSiteConfiguration([
            'site_topic' => 'health',
            'site_language' => 'spanish',
            'wordpress_theme' => 'neve',
        ]);

        $this->assertTrue($result);
    }

    // ── validateSiteConfiguration ─────────────────────────────

    public function test_validate_returns_empty_for_valid_config(): void
    {
        $errors = $this->service->validateSiteConfiguration([
            'site_topic' => 'finance',
            'site_language' => 'spanish',
            'wordpress_theme' => 'astra',
        ]);

        $this->assertEmpty($errors);
    }

    public function test_validate_returns_error_for_missing_topic(): void
    {
        $errors = $this->service->validateSiteConfiguration([
            'site_topic' => '',
            'site_language' => 'spanish',
            'wordpress_theme' => 'astra',
        ]);

        $this->assertContains('Site topic is required', $errors);
    }

    public function test_validate_returns_error_for_invalid_language(): void
    {
        $errors = $this->service->validateSiteConfiguration([
            'site_topic' => 'tech',
            'site_language' => 'french',
            'wordpress_theme' => 'astra',
        ]);

        $this->assertContains('Invalid language selected', $errors);
    }

    public function test_validate_returns_all_errors_at_once(): void
    {
        $errors = $this->service->validateSiteConfiguration([
            'site_topic' => '',
            'site_language' => 'invalid',
            'wordpress_theme' => '',
        ]);

        $this->assertCount(3, $errors);
    }

    // ── getSiteConfiguration ──────────────────────────────────

    public function test_get_returns_all_fields_including_category(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_topic', Mockery::any())
            ->andReturn('finance');

        WP_Mock::userFunction('get_option')
            ->with('contai_site_theme', '')
            ->andReturn('finance');

        WP_Mock::userFunction('get_option')
            ->with('contai_site_language', 'english')
            ->andReturn('spanish');

        WP_Mock::userFunction('get_option')
            ->with('contai_site_category', '')
            ->andReturn('cat-123');

        WP_Mock::userFunction('get_option')
            ->with('contai_wordpress_theme', 'astra')
            ->andReturn('generatepress');

        $config = $this->service->getSiteConfiguration();

        $this->assertArrayHasKey('site_topic', $config);
        $this->assertArrayHasKey('site_category', $config);
        $this->assertArrayHasKey('site_language', $config);
        $this->assertArrayHasKey('wordpress_theme', $config);
        $this->assertEquals('cat-123', $config['site_category']);
    }
}
