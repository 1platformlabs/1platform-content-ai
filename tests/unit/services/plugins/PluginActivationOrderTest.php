<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

class PluginActivationOrderTest extends TestCase
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

    private function service(): \ContaiPluginOrderService
    {
        return new \ContaiPluginOrderService(
            Mockery::mock('ContaiOnePlatformClient'),
            Mockery::mock('ContaiWebsiteProvider'),
            Mockery::mock('ContaiPluginInventoryService')
        );
    }

    public function test_activate_calls_activate_plugin_in_silent_mode_with_the_correct_fourth_argument(): void
    {
        WP_Mock::userFunction('activate_plugin')
            ->once()
            // network_wide=false (the order carries no such field), and the
            // 4th argument — silent mode — must be true: a dry-run include
            // first, returning a WP_Error instead of a fatal white-screen if
            // the plugin errors out during an unattended activation.
            ->with('acme/acme.php', '', false, true)
            ->andReturn(null);

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $result = $this->service()->applyOrderAction([
            'action'      => 'activate',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_wp_error_from_activate_plugin_is_reported_as_failed_not_thrown(): void
    {
        $error = Mockery::mock('WP_Error');
        $error->shouldReceive('get_error_code')->andReturn('plugin_fatal_error');
        $error->shouldReceive('get_error_message')->andReturn('Parse error in acme.php');

        WP_Mock::userFunction('activate_plugin')->andReturn($error);
        WP_Mock::userFunction('is_wp_error')->with($error)->andReturn(true);

        $result = $this->service()->applyOrderAction([
            'action'      => 'activate',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('plugin_fatal_error', $result['error_code']);
        $this->assertSame('Parse error in acme.php', $result['error_detail']);
    }

    public function test_missing_plugin_file_fails_without_calling_activate_plugin(): void
    {
        WP_Mock::userFunction('activate_plugin')->never();

        $result = $this->service()->applyOrderAction([
            'action' => 'activate',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_plugin_file', $result['error_code']);
    }
}
