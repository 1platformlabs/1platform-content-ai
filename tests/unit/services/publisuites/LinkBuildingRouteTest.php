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

        $constants = (new ReflectionClass(ContaiOnePlatformEndpoints::class))->getConstants();
        foreach ($constants as $value) {
            if (is_string($value)) {
                $paths[] = $value;
            }
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

    /**
     * The other eight actions also travel to the neutral path, each with its own action name.
     *
     * @dataProvider remainingActions
     */
    public function test_every_remaining_action_posts_to_the_neutral_path(string $expectedAction, callable $call): void
    {
        $this->storedConfig = ['websiteId' => self::WEBSITE_ID, 'publisuitesId' => '777'];
        WP_Mock::userFunction('absint')->andReturnUsing(fn ($value) => abs((int) $value));
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('esc_url_raw')->andReturnArg(0);
        WP_Mock::userFunction('delete_option')->andReturn(true);

        $client = Mockery::mock(ContaiOnePlatformClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with(self::EXPECTED_PATH, Mockery::on(fn ($payload) => ($payload['action'] ?? null) === $expectedAction))
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'ok', 200));

        $this->assertTrue($call($this->service($client))->isSuccess());
    }

    public function remainingActions(): array
    {
        return [
            'orders'       => ['orders', fn (ContaiPublisuitesService $s) => $s->getOrders(2, 'pending')],
            'view_order'   => ['view_order', fn (ContaiPublisuitesService $s) => $s->viewOrder(9)],
            'sync'         => ['sync', fn (ContaiPublisuitesService $s) => $s->triggerSync()],
            'delete'       => ['delete', fn (ContaiPublisuitesService $s) => $s->deleteWebsiteFromMarketplace()],
            'accept_order' => ['accept_order', fn (ContaiPublisuitesService $s) => $s->acceptOrder(9)],
            'reject_order' => ['reject_order', fn (ContaiPublisuitesService $s) => $s->rejectOrder(9)],
            'reopen_order' => ['reopen_order', fn (ContaiPublisuitesService $s) => $s->reopenOrder(9)],
            'send_url'     => ['send_url', fn (ContaiPublisuitesService $s) => $s->sendUrl(9, 'https://example.test/post')],
        ];
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
