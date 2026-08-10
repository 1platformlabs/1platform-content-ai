<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * T-26 / T-27 / D-10 — the site must never download a plugin from anywhere
 * but the official downloads.wordpress.org host. The order never carries a
 * URL, only a plugin_slug the site resolves itself via plugins_api(), so
 * this is the one moment an attacker-controlled host could ever reach the
 * downloader.
 */
class PluginInstallSourceTest extends TestCase
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

    private function serviceWithUpgrader($upgrader): \ContaiPluginOrderService
    {
        return new \ContaiPluginOrderService(
            Mockery::mock('ContaiOnePlatformClient'),
            Mockery::mock('ContaiWebsiteProvider'),
            Mockery::mock('ContaiPluginInventoryService'),
            $upgrader
        );
    }

    // ── T-26: refused — the downloader is NEVER invoked ───────────

    public function test_a_non_wordpress_org_download_host_is_refused_and_never_reaches_the_downloader(): void
    {
        $api = (object) [
            'slug'          => 'evil-plugin',
            'download_link' => 'https://attacker.example.com/evil-plugin.zip',
        ];

        WP_Mock::userFunction('plugins_api')
            ->once()
            ->with('plugin_information', Mockery::on(function ($args) {
                return ($args['slug'] ?? null) === 'evil-plugin';
            }))
            ->andReturn($api);

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        // Zero calls, not "the order ended up failed" — a stub that always
        // refuses everything would still pass an assertion on the result
        // alone, so the proof has to be on the downloader itself.
        $upgrader = Mockery::mock();
        $upgrader->shouldNotReceive('install');

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action'      => 'install',
            'plugin_slug' => 'evil-plugin',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('install_source_not_allowed', $result['error_code']);
    }

    public function test_a_protocol_relative_or_hostless_download_link_is_also_refused(): void
    {
        $api = (object) [
            'slug'          => 'weird-plugin',
            'download_link' => '/not-a-real-url',
        ];

        WP_Mock::userFunction('plugins_api')->andReturn($api);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $upgrader = Mockery::mock();
        $upgrader->shouldNotReceive('install');

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action'      => 'install',
            'plugin_slug' => 'weird-plugin',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('install_source_not_allowed', $result['error_code']);
    }

    // ── T-27: control — the official host DOES call the installer ────

    public function test_the_official_wordpress_org_host_calls_the_downloader_exactly_once(): void
    {
        $download_link = 'https://downloads.wordpress.org/plugin/akismet.5.3.zip';
        $api = (object) [
            'slug'          => 'akismet',
            'download_link' => $download_link,
        ];

        WP_Mock::userFunction('plugins_api')->once()->andReturn($api);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $upgrader = Mockery::mock();
        $upgrader->shouldReceive('install')
            ->once()
            ->with($download_link)
            ->andReturn(true);

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action'      => 'install',
            'plugin_slug' => 'akismet',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_installer_returning_wp_error_is_reported_and_not_thrown(): void
    {
        $download_link = 'https://downloads.wordpress.org/plugin/akismet.5.3.zip';
        $api = (object) ['slug' => 'akismet', 'download_link' => $download_link];

        $installError = Mockery::mock('WP_Error');
        $installError->shouldReceive('get_error_code')->andReturn('install_failed_fs');
        $installError->shouldReceive('get_error_message')->andReturn('Destination folder already exists');

        WP_Mock::userFunction('plugins_api')->andReturn($api);
        WP_Mock::userFunction('is_wp_error')
            ->andReturnUsing(function ($value) use ($installError) {
                return $value === $installError;
            });

        $upgrader = Mockery::mock();
        $upgrader->shouldReceive('install')->once()->andReturn($installError);

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action'      => 'install',
            'plugin_slug' => 'akismet',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('install_failed_fs', $result['error_code']);
    }

    // ── Lookup failure ─────────────────────────────────────────────

    public function test_plugins_api_wp_error_is_reported_and_never_reaches_the_downloader(): void
    {
        $error = Mockery::mock('WP_Error');
        $error->shouldReceive('get_error_code')->andReturn('plugins_api_failed');
        $error->shouldReceive('get_error_message')->andReturn('Could not reach the plugin directory');

        WP_Mock::userFunction('plugins_api')->andReturn($error);
        WP_Mock::userFunction('is_wp_error')->with($error)->andReturn(true);

        $upgrader = Mockery::mock();
        $upgrader->shouldNotReceive('install');

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action'      => 'install',
            'plugin_slug' => 'akismet',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('plugins_api_failed', $result['error_code']);
    }

    public function test_missing_plugin_slug_fails_without_calling_plugins_api(): void
    {
        WP_Mock::userFunction('plugins_api')->never();

        $upgrader = Mockery::mock();
        $upgrader->shouldNotReceive('install');

        $result = $this->serviceWithUpgrader($upgrader)->applyOrderAction([
            'action' => 'install',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_plugin_slug', $result['error_code']);
    }
}
