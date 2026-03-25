<?php

namespace ContAI\Tests\Unit\Services\CategoryAPI;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiCategoryAPIService;
use ContaiOnePlatformClient;
use ContaiOnePlatformResponse;
use ContaiUserProvider;

/**
 * Tests for CategoryAPIService::getActiveCategories() and getCategories().
 *
 * Validates the fix for GitHub issue #16: categories not loading in Site Wizard
 * for domains that previously ran the wizard. Root cause was the activateLicense()
 * step double-encrypting the API key, causing all authenticated API calls to fail.
 */
class CategoryAPIServiceGetActiveCategoriesTest extends TestCase {

    private ContaiUserProvider $userProvider;
    private ContaiOnePlatformClient $client;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // Config singleton needs these WP functions during initialization
        \ContaiConfig::reset();
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.local');
        WP_Mock::userFunction('get_option')
            ->with('contai_logging_enabled')
            ->andReturn(false);
        WP_Mock::userFunction('get_option')
            ->with('contai_api_base_url', '')
            ->andReturn('');

        $this->userProvider = $this->createMock(ContaiUserProvider::class);
        $this->client = $this->createMock(ContaiOnePlatformClient::class);
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        \ContaiConfig::reset();
        parent::tearDown();
    }

    private function createService(): ContaiCategoryAPIService {
        return new ContaiCategoryAPIService($this->client, $this->userProvider);
    }

    public function test_returns_active_categories_from_api(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $apiCategories = [
            ['id' => 'cat-1', 'title' => ['en' => 'Tech'], 'status' => 'active'],
            ['id' => 'cat-2', 'title' => ['en' => 'Sports'], 'status' => 'inactive'],
            ['id' => 'cat-3', 'title' => ['en' => 'Finance'], 'status' => 'active'],
        ];

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(true, $apiCategories, 'OK')
        );

        WP_Mock::userFunction('set_transient')
            ->once()
            ->with('contai_categories_user-123', $apiCategories, 900);

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertCount(2, $result);
        $this->assertSame('cat-1', $result[0]['id']);
        $this->assertSame('cat-3', $result[1]['id']);
    }

    public function test_returns_empty_array_when_user_id_missing(): void {
        $this->userProvider->method('getUserId')->willReturn(null);

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_api_fails(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(false, null, 'Auth failed', 401)
        );

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertSame([], $result);
    }

    public function test_returns_categories_from_cache(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        $cachedCategories = [
            ['id' => 'cat-1', 'title' => ['en' => 'Tech'], 'status' => 'active'],
        ];

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn($cachedCategories);

        // Client should NOT be called when cache is available
        $this->client->expects($this->never())->method('get');

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertCount(1, $result);
        $this->assertSame('cat-1', $result[0]['id']);
    }

    public function test_force_refresh_bypasses_cache(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        $apiCategories = [
            ['id' => 'cat-new', 'title' => ['en' => 'New'], 'status' => 'active'],
        ];

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(true, $apiCategories, 'OK')
        );

        WP_Mock::userFunction('set_transient')
            ->once()
            ->with('contai_categories_user-123', $apiCategories, 900);

        $service = $this->createService();
        $result = $service->getActiveCategories(true);

        $this->assertCount(1, $result);
        $this->assertSame('cat-new', $result[0]['id']);
    }

    public function test_returns_empty_array_when_all_categories_inactive(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $apiCategories = [
            ['id' => 'cat-1', 'title' => ['en' => 'Tech'], 'status' => 'inactive'],
            ['id' => 'cat-2', 'title' => ['en' => 'Sports'], 'status' => 'disabled'],
        ];

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(true, $apiCategories, 'OK')
        );

        WP_Mock::userFunction('set_transient')
            ->once();

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_api_returns_non_array(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(true, 'not an array', 'OK')
        );

        $service = $this->createService();
        $result = $service->getActiveCategories();

        $this->assertSame([], $result);
    }

    public function test_active_categories_reindexed_from_zero(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $apiCategories = [
            ['id' => 'cat-1', 'title' => ['en' => 'Inactive'], 'status' => 'inactive'],
            ['id' => 'cat-2', 'title' => ['en' => 'Active'], 'status' => 'active'],
        ];

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(true, $apiCategories, 'OK')
        );

        WP_Mock::userFunction('set_transient')->once();

        $service = $this->createService();
        $result = $service->getActiveCategories();

        // array_values should reindex so key 0 exists
        $this->assertArrayHasKey(0, $result);
        $this->assertSame('cat-2', $result[0]['id']);
    }

    public function test_does_not_cache_failed_api_response(): void {
        $this->userProvider->method('getUserId')->willReturn('user-123');

        WP_Mock::userFunction('get_transient')
            ->with('contai_categories_user-123')
            ->andReturn(false);

        $this->client->method('get')->willReturn(
            new ContaiOnePlatformResponse(false, null, 'Server error', 500)
        );

        $service = $this->createService();
        $result = $service->getActiveCategories();

        // On failure, result is empty and no cache is set
        $this->assertSame([], $result);
    }
}
