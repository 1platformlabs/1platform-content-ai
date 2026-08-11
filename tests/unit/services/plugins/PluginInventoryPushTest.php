<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

class PluginInventoryPushTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── T-22: ten activations in one request schedule exactly one push ──
    // (includes/plugins/hooks.php's contai_plugins_maybe_schedule_push())

    public function test_ten_plugin_activations_in_one_request_schedule_exactly_one_push(): void
    {
        $scheduled = false;

        WP_Mock::userFunction('wp_next_scheduled')
            ->with('contai_plugins_push')
            ->andReturnUsing(function () use (&$scheduled) {
                return $scheduled;
            });

        WP_Mock::userFunction('wp_schedule_single_event')
            ->once()
            ->with(Mockery::type('int'), 'contai_plugins_push')
            ->andReturnUsing(function () use (&$scheduled) {
                $scheduled = true;
                return true;
            });

        for ($i = 0; $i < 10; $i++) {
            \contai_plugins_maybe_schedule_push();
        }

        $this->assertTrue(true); // WP_Mock verifies the ->once() on tearDown.
    }

    public function test_schedule_is_skipped_entirely_when_a_push_is_already_pending(): void
    {
        WP_Mock::userFunction('wp_next_scheduled')
            ->with('contai_plugins_push')
            ->andReturn(1234567890);

        WP_Mock::userFunction('wp_schedule_single_event')->never();

        \contai_plugins_maybe_schedule_push();

        $this->assertTrue(true);
    }

    // ── T-23: fingerprint stability ──────────────────────────────

    private function baseItems(): array
    {
        return [
            [
                'file' => 'akismet/akismet.php', 'name' => 'Akismet Anti-spam', 'version' => '5.3',
                'active' => true, 'network_active' => false, 'update_available' => null, 'auto_update' => false,
            ],
            [
                'file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7.2',
                'active' => false, 'network_active' => false, 'update_available' => null, 'auto_update' => true,
            ],
        ];
    }

    private function service($client = null, $websites = null): \ContaiPluginInventoryService
    {
        return new \ContaiPluginInventoryService(
            $client ?? Mockery::mock('ContaiOnePlatformClient'),
            $websites ?? Mockery::mock('ContaiWebsiteProvider')
        );
    }

    public function test_fingerprint_is_stable_when_the_item_list_is_reordered(): void
    {
        $service  = $this->service();
        $items    = $this->baseItems();
        $reversed = array_reverse($items);

        $this->assertNotSame($items, $reversed, 'Sanity: the two lists really are in a different order');
        $this->assertSame($service->fingerprint($items), $service->fingerprint($reversed));
    }

    public function test_fingerprint_changes_when_a_single_plugin_version_changes(): void
    {
        $service = $this->service();
        $items   = $this->baseItems();

        $changed             = $items;
        $changed[0]['version'] = '5.4';

        $this->assertNotSame($service->fingerprint($items), $service->fingerprint($changed));
    }

    // ── push(): fingerprint gating, force sources, enrollment gate ──

    private function stubCollectFor(array $items): void
    {
        $pluginsMap = [];
        $activePlugins = [];
        $autoUpdatePlugins = [];

        foreach ($items as $item) {
            $pluginsMap[$item['file']] = ['Name' => $item['name'], 'Version' => $item['version']];
            if (!empty($item['active'])) {
                $activePlugins[] = $item['file'];
            }
            if (!empty($item['auto_update'])) {
                $autoUpdatePlugins[] = $item['file'];
            }
        }

        WP_Mock::userFunction('get_plugins')->andReturn($pluginsMap);
        WP_Mock::userFunction('get_option')
            ->with('active_plugins', [])
            ->andReturn($activePlugins);
        WP_Mock::userFunction('get_site_transient')
            ->with('update_plugins')
            ->andReturn(false);
        WP_Mock::userFunction('get_site_option')
            ->with('auto_update_plugins', [])
            ->andReturn($autoUpdatePlugins);
        WP_Mock::userFunction('is_plugin_active_for_network')->andReturn(false);
    }

    public function test_push_skips_the_api_call_when_the_fingerprint_is_unchanged(): void
    {
        $items = $this->baseItems();
        $this->stubCollectFor($items);

        $websites = Mockery::mock('ContaiWebsiteProvider');
        $websites->shouldReceive('getWebsiteId')->andReturn('site-1');

        $client = Mockery::mock('ContaiOnePlatformClient');
        $client->shouldReceive('put')->never();

        $service     = $this->service($client, $websites);
        $unchanged   = $service->fingerprint($items);

        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_inventory_fingerprint', '')
            ->andReturn($unchanged);

        $service->push('poll');

        $this->assertTrue(true);
    }

    public function test_push_publishes_and_stores_the_new_fingerprint_when_it_changed(): void
    {
        $items = $this->baseItems();
        $this->stubCollectFor($items);

        $websites = Mockery::mock('ContaiWebsiteProvider');
        $websites->shouldReceive('getWebsiteId')->andReturn('site-1');

        $response = Mockery::mock('ContaiOnePlatformResponse');
        $response->shouldReceive('isSuccess')->andReturn(true);

        $client = Mockery::mock('ContaiOnePlatformClient');
        $client->shouldReceive('put')
            ->once()
            ->withArgs(function ($endpoint, $payload) {
                return $endpoint === '/users/websites/site-1/plugins'
                    && ($payload['source'] ?? null) === 'poll'
                    && count($payload['items'] ?? []) === 2;
            })
            ->andReturn($response);

        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_inventory_fingerprint', '')
            ->andReturn('a-stale-fingerprint');
        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_plugin_inventory_fingerprint', Mockery::type('string'), false);

        $this->service($client, $websites)->push('poll');

        $this->assertTrue(true);
    }

    public function test_push_force_publishes_on_bootstrap_even_when_the_fingerprint_is_unchanged(): void
    {
        $items = $this->baseItems();
        $this->stubCollectFor($items);

        $websites = Mockery::mock('ContaiWebsiteProvider');
        $websites->shouldReceive('getWebsiteId')->andReturn('site-1');

        $response = Mockery::mock('ContaiOnePlatformResponse');
        $response->shouldReceive('isSuccess')->andReturn(true);

        $client = Mockery::mock('ContaiOnePlatformClient');
        $client->shouldReceive('put')->once()->andReturn($response);

        $service   = $this->service($client, $websites);
        $unchanged = $service->fingerprint($items);

        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_inventory_fingerprint', '')
            ->andReturn($unchanged);
        WP_Mock::userFunction('update_option')->once();

        $service->push('bootstrap');

        $this->assertTrue(true);
    }

    public function test_push_no_ops_when_the_site_has_never_been_enrolled(): void
    {
        $client = Mockery::mock('ContaiOnePlatformClient');
        $client->shouldReceive('put')->never();

        $websites = Mockery::mock('ContaiWebsiteProvider');
        $websites->shouldReceive('getWebsiteId')->andReturn(null);

        $this->service($client, $websites)->push('poll');

        $this->assertTrue(true);
    }
}
