<?php

namespace ContAI\Tests\Unit\Services\Publisuites;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ContaiOnePlatformClient;
use ContaiOnePlatformEndpoints;
use ContaiOnePlatformResponse;
use ContaiPublisuitesService;
use ContaiUserProvider;
use ContaiWebsiteProvider;

/**
 * Regression for 1platform-api#1043 (plugin side).
 *
 * The platform publishes the link-building actions under the neutral
 * `/users/websites/{id}/link-building` and the site id as `marketplace_id`.
 * The vendor-named path is only a hidden alias kept for old plugin releases,
 * so the plugin must call the neutral one and read the neutral key first.
 */
class LinkBuildingRouteTest extends TestCase
{
    private const WEBSITE_ID = 'ws-42';
    private const EXPECTED_PATH = '/users/websites/ws-42/link-building';

    private ?array $storedConfig = null;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        // The service resolves the plugin config singleton on construction.
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.test');
        WP_Mock::userFunction('get_option')->andReturnUsing(
            fn ($name, $default = false) => $name === 'contai_publisuites_config' ? $this->storedConfig : $default
        );
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_endpoint_builds_the_neutral_link_building_path(): void
    {
        $this->assertSame(self::EXPECTED_PATH, ContaiOnePlatformEndpoints::websiteLinkBuilding(self::WEBSITE_ID));
    }

    public function test_no_website_endpoint_names_the_marketplace_vendor(): void
    {
        $paths = [];
        $methods = (new ReflectionClass(ContaiOnePlatformEndpoints::class))->getMethods(ReflectionMethod::IS_STATIC);
        foreach ($methods as $method) {
            if ($method->getNumberOfRequiredParameters() !== 1 || strpos($method->getName(), 'website') !== 0) {
                continue;
            }
            $paths[] = $method->invoke(null, self::WEBSITE_ID);
        }

        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsStringIgnoringCase('publisuites', $path);
        }
    }

    public function test_connect_posts_the_add_action_to_the_neutral_path(): void
    {
        $client = Mockery::mock(ContaiOnePlatformClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with(self::EXPECTED_PATH, ['action' => 'add'])
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'ok', 200));

        $this->assertTrue($this->service($client)->connectWebsite()->isSuccess());
    }

    public function test_verify_posts_the_verify_action_to_the_neutral_path(): void
    {
        $this->storedConfig = ['publisuitesId' => '777'];

        $client = Mockery::mock(ContaiOnePlatformClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with(self::EXPECTED_PATH, ['action' => 'verify'])
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'ok', 200));

        $this->assertTrue($this->service($client)->verifyWebsite()->isSuccess());
    }

    public function test_marketplace_id_prefers_the_neutral_key(): void
    {
        $this->assertSame(55, ContaiPublisuitesService::marketplaceIdFrom(['marketplace_id' => 55, 'publisuites_id' => 11]));
        $this->assertSame(55, ContaiPublisuitesService::marketplaceIdFrom(['marketplace_id' => 55]));
    }

    public function test_marketplace_id_falls_back_to_the_legacy_key(): void
    {
        $this->assertSame(11, ContaiPublisuitesService::marketplaceIdFrom(['publisuites_id' => 11]));
        $this->assertNull(ContaiPublisuitesService::marketplaceIdFrom([]));
    }

    private function service(ContaiOnePlatformClient $client): ContaiPublisuitesService
    {
        $websiteProvider = Mockery::mock(ContaiWebsiteProvider::class);
        $websiteProvider->shouldReceive('getWebsiteId')->andReturn(self::WEBSITE_ID);

        return new ContaiPublisuitesService($client, Mockery::mock(ContaiUserProvider::class), $websiteProvider);
    }
}
