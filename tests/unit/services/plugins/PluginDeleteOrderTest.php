<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

class PluginDeleteOrderTest extends TestCase
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

    // ── T-24: sequence — deactivate BEFORE delete, not just "both happened" ──

    public function test_an_active_plugin_is_deactivated_before_it_is_deleted(): void
    {
        $calls = [];

        WP_Mock::userFunction('is_plugin_active')
            ->with('acme/acme.php')
            ->andReturn(true);

        WP_Mock::userFunction('deactivate_plugins')
            ->once()
            ->with(['acme/acme.php'])
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'deactivate_plugins';
            });

        WP_Mock::userFunction('delete_plugins')
            ->once()
            ->with(['acme/acme.php'])
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'delete_plugins';
                return true;
            });

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $result = $this->service()->applyOrderAction([
            'action'      => 'delete',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['deactivate_plugins', 'delete_plugins'],
            $calls,
            'deactivate_plugins must run BEFORE delete_plugins, not merely alongside it'
        );
    }

    public function test_an_inactive_plugin_deletes_directly_without_deactivating(): void
    {
        WP_Mock::userFunction('is_plugin_active')
            ->with('acme/acme.php')
            ->andReturn(false);

        WP_Mock::userFunction('deactivate_plugins')->never();

        WP_Mock::userFunction('delete_plugins')
            ->once()
            ->with(['acme/acme.php'])
            ->andReturn(true);

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $result = $this->service()->applyOrderAction([
            'action'      => 'delete',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertTrue($result['success']);
    }

    // ── Failure shapes ────────────────────────────────────────────

    public function test_delete_plugins_returning_false_is_reported_as_a_generic_failure(): void
    {
        WP_Mock::userFunction('is_plugin_active')->andReturn(false);
        WP_Mock::userFunction('delete_plugins')->andReturn(false);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $result = $this->service()->applyOrderAction([
            'action'      => 'delete',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('delete_failed', $result['error_code']);
    }

    public function test_delete_plugins_wp_error_is_reported_with_its_own_error_code(): void
    {
        $error = Mockery::mock('WP_Error');
        $error->shouldReceive('get_error_code')->andReturn('fs_unavailable');
        $error->shouldReceive('get_error_message')->andReturn('Could not access the filesystem');

        WP_Mock::userFunction('is_plugin_active')->andReturn(false);
        WP_Mock::userFunction('delete_plugins')->andReturn($error);
        WP_Mock::userFunction('is_wp_error')->with($error)->andReturn(true);

        $result = $this->service()->applyOrderAction([
            'action'      => 'delete',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('fs_unavailable', $result['error_code']);
        $this->assertSame('Could not access the filesystem', $result['error_detail']);
    }

    public function test_missing_plugin_file_fails_without_calling_delete_plugins(): void
    {
        WP_Mock::userFunction('delete_plugins')->never();
        WP_Mock::userFunction('deactivate_plugins')->never();
        WP_Mock::userFunction('is_plugin_active')->never();

        $result = $this->service()->applyOrderAction([
            'action' => 'delete',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_plugin_file', $result['error_code']);
    }

    // ── deactivate order — the sibling action delete's own deactivation
    // step mirrors, but with its own guard (validate_plugin, not
    // is_plugin_active) since a standalone deactivate order can target a
    // file that no longer exists on disk. ──────────────────────────

    public function test_deactivate_order_validates_then_deactivates(): void
    {
        WP_Mock::userFunction('validate_plugin')
            ->once()
            ->with('acme/acme.php')
            ->andReturn(0); // real WordPress returns an int (the plugin file) on success.

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        WP_Mock::userFunction('deactivate_plugins')
            ->once()
            ->with(['acme/acme.php']);

        $result = $this->service()->applyOrderAction([
            'action'      => 'deactivate',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_deactivate_order_reports_failed_when_validate_plugin_errors_without_deactivating(): void
    {
        $error = Mockery::mock('WP_Error');
        $error->shouldReceive('get_error_code')->andReturn('plugin_not_found');
        $error->shouldReceive('get_error_message')->andReturn('The plugin does not exist.');

        WP_Mock::userFunction('validate_plugin')->andReturn($error);
        WP_Mock::userFunction('is_wp_error')->with($error)->andReturn(true);
        WP_Mock::userFunction('deactivate_plugins')->never();

        $result = $this->service()->applyOrderAction([
            'action'      => 'deactivate',
            'plugin_file' => 'gone/gone.php',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('plugin_not_found', $result['error_code']);
    }

    public function test_deactivate_order_missing_plugin_file_fails_without_calling_validate_plugin(): void
    {
        WP_Mock::userFunction('validate_plugin')->never();
        WP_Mock::userFunction('deactivate_plugins')->never();

        $result = $this->service()->applyOrderAction([
            'action' => 'deactivate',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('missing_plugin_file', $result['error_code']);
    }
}
