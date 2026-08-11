<?php

namespace ContAI\Tests\Unit\Services\Plugins;

use PHPUnit\Framework\TestCase;

/**
 * T-40 / Restriction 16.
 *
 * get_plugins(), is_plugin_active_for_network(), validate_plugin(),
 * activate_plugin(), deactivate_plugins(), delete_plugins(), Plugin_Upgrader,
 * and plugins_api() are all declared in wp-admin/includes files that
 * WordPress does not autoload outside /wp-admin — including inside a cron
 * request (Action Scheduler or WP-Cron), which is exactly where
 * ContaiPluginInventoryService::collect() and
 * ContaiPluginOrderService::applyOrderAction() both run. Skipping the
 * requires does not fail loudly: it only fatals the first time one of those
 * calls is reached, which a cron cycle carrying no pending orders and no
 * inventory change never exercises — so this is pinned as a source guard,
 * not left to be discovered live in production.
 *
 * This is deliberately a source guard, not a runtime function_exists()
 * before/after assertion. This suite's WP_Mock stubs get_plugins() et al.
 * dynamically the first time a test calls WP_Mock::userFunction() for that
 * name — and once a name is defined by anything else first (a real stub file
 * required here, for instance), WP_Mock can never mock it again for the rest
 * of the process (this project carries no Patchwork dependency, which is
 * WP_Mock's only fallback for redefining an already-declared function). Any
 * approach that makes get_plugins() etc. "really" become defined during a
 * test would therefore silently and permanently break every other test in
 * this directory that still needs to mock them. The four admin includes are
 * asserted to be required, not to have materialized a given symbol;
 * ensureAdminIncludesLoaded() is also exercised directly to prove the paths
 * actually resolve under this suite's fake ABSPATH and that requiring them
 * twice does not fatal.
 */
class PluginInventoryRequiresAdminIncludesTest extends TestCase
{
    private const REQUIRED_INCLUDES = [
        "ABSPATH . 'wp-admin/includes/plugin.php'",
        "ABSPATH . 'wp-admin/includes/file.php'",
        "ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'",
        "ABSPATH . 'wp-admin/includes/plugin-install.php'",
    ];

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . $relativePath);
    }

    public function test_inventory_service_requires_all_four_admin_includes(): void
    {
        $source = $this->source('/includes/services/plugins/ContaiPluginInventoryService.php');

        foreach (self::REQUIRED_INCLUDES as $needle) {
            $this->assertStringContainsString(
                $needle,
                $source,
                "ContaiPluginInventoryService must require {$needle} before calling any wp-admin plugin function (Restriction 16)"
            );
        }
    }

    /**
     * The order service does not duplicate the four requires — it shares the
     * single copy in ContaiPluginInventoryService::ensureAdminIncludesLoaded(),
     * called before any order is dispatched. What matters here is that the
     * call is actually wired in, not skipped.
     */
    public function test_order_service_calls_the_shared_admin_includes_guard_before_dispatch(): void
    {
        $source = $this->source('/includes/services/plugins/ContaiPluginOrderService.php');

        $this->assertStringContainsString(
            'ContaiPluginInventoryService::ensureAdminIncludesLoaded();',
            $source,
            'ContaiPluginOrderService::applyOrderAction() must load the wp-admin includes before dispatching to any action (Restriction 16)'
        );
    }

    public function test_ensure_admin_includes_loaded_resolves_and_is_idempotent(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/services/plugins/ContaiPluginInventoryService.php';

        // Calling it twice must not re-declare anything or fatal — proves the
        // require_once paths actually resolve under this test's ABSPATH.
        \ContaiPluginInventoryService::ensureAdminIncludesLoaded();
        \ContaiPluginInventoryService::ensureAdminIncludesLoaded();

        $this->assertTrue(true);
    }
}
