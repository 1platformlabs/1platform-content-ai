<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * T-25 / D-13 — the site refuses any order that targets the platform's own
 * plugin file, even though the server is also expected to never construct
 * such an order. This is defense in depth for a response tampered with in
 * transit, so the order fabricated below is treated exactly as if it had
 * arrived from the API — the guard must not rely on the server having
 * already filtered it out.
 */
class PluginSelfTargetGuardTest extends TestCase
{
    private const SELF_PLUGIN_FILE = '1platform-content-ai/1platform-content-ai.php';

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

    /**
     * @dataProvider selfTargetingActionsProvider
     */
    public function test_self_targeting_order_is_refused_without_touching_the_plugin(string $action): void
    {
        // Zero invocations, not merely "the report ended up failed" — a spy
        // with none of these ever called is what actually proves the plugin
        // was left untouched.
        WP_Mock::userFunction('delete_plugins')->never();
        WP_Mock::userFunction('deactivate_plugins')->never();
        WP_Mock::userFunction('activate_plugin')->never();
        WP_Mock::userFunction('validate_plugin')->never();
        WP_Mock::userFunction('is_plugin_active')->never();

        // Fabricated exactly as the shape GET .../plugin-orders documents —
        // as if the API itself had (wrongly, or maliciously in transit) sent
        // this order.
        $order = [
            'id'          => '66b0000000000000000000aa',
            'action'      => $action,
            'plugin_file' => self::SELF_PLUGIN_FILE,
            'plugin_slug' => null,
            'status'      => 'pending',
        ];

        $result = $this->service()->applyOrderAction($order);

        $this->assertFalse($result['success']);
        $this->assertSame('plugin_order_self_target', $result['error_code']);
    }

    public function selfTargetingActionsProvider(): array
    {
        return [
            'delete'     => ['delete'],
            'deactivate' => ['deactivate'],
            'activate'   => ['activate'],
        ];
    }

    /**
     * Control: an order targeting a DIFFERENT plugin file for the same
     * actions must NOT be refused — the guard has to discriminate on the
     * specific file, not reject every order of these types.
     */
    public function test_an_order_targeting_a_different_plugin_is_not_refused(): void
    {
        WP_Mock::userFunction('is_plugin_active')->with('acme/acme.php')->andReturn(false);
        WP_Mock::userFunction('delete_plugins')->with(['acme/acme.php'])->andReturn(true);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $result = $this->service()->applyOrderAction([
            'action'      => 'delete',
            'plugin_file' => 'acme/acme.php',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error_code', $result);
    }

    /**
     * sync never names a specific plugin to act on — it is a no-op besides
     * the inventory re-publish every order triggers — so the guard does not
     * apply to it even if plugin_file happens to equal the platform's own.
     */
    public function test_sync_order_naming_the_platform_plugin_is_not_refused(): void
    {
        $result = $this->service()->applyOrderAction([
            'action'      => 'sync',
            'plugin_file' => self::SELF_PLUGIN_FILE,
        ]);

        $this->assertTrue($result['success']);
    }
}
