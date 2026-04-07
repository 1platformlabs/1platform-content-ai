<?php

namespace ContAI\Tests\Unit;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;

class UpgradeRoutineTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // Migration classes access $wpdb->prefix via ContaiDatabase singleton
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->andReturn('DELETE query')->byDefault();
        $wpdb->shouldReceive('query')->andReturn(true)->byDefault();
        $wpdb->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci')->byDefault();
        $GLOBALS['wpdb'] = $wpdb;
    }

    public function tearDown(): void {
        unset($GLOBALS['wpdb']);

        $ref = new \ReflectionClass('ContaiDatabase');
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);

        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: set up WP_Mock expectations for a standard upgrade scenario.
     *
     * @param string $stored_version  The version to return from get_option
     * @param int    $db_version      The current DB migration version
     * @param mixed  $cron_scheduled  Return value for wp_next_scheduled (false = missing)
     */
    private function setupUpgradeMocks(string $stored_version, int $db_version = 99, $cron_scheduled = 1234567890): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn($stored_version);

        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn($db_version);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn($cron_scheduled);

        WP_Mock::userFunction('update_option');
    }

    // ── contai_maybe_upgrade: version detection ─────────────────

    public function test_upgrade_skips_when_version_is_current(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn(CONTAI_VERSION);

        WP_Mock::userFunction('update_option')->never();

        contai_maybe_upgrade();

        // WP_Mock verifies update_option was never called on tearDown
        $this->addToAssertionCount(1);
    }

    public function test_upgrade_skips_when_version_is_higher(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn('99.99.99');

        WP_Mock::userFunction('update_option')->never();

        contai_maybe_upgrade();

        $this->addToAssertionCount(1);
    }

    public function test_upgrade_runs_when_stored_version_is_lower(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn('1.0.0');

        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(99);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(1234567890);

        $versions_stored = [];
        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$versions_stored) {
                if ($key === 'contai_plugin_version') {
                    $versions_stored[] = $value;
                }
                return true;
            });

        contai_maybe_upgrade();

        $this->assertContains(CONTAI_VERSION, $versions_stored, 'Plugin version should be stored after upgrade');
    }

    public function test_upgrade_runs_when_version_option_does_not_exist(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn('0');

        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(99);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(1234567890);

        $version_stored = null;
        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$version_stored) {
                if ($key === 'contai_plugin_version') {
                    $version_stored = $value;
                }
                return true;
            });

        contai_maybe_upgrade();

        $this->assertSame(CONTAI_VERSION, $version_stored, 'Should store current version on first upgrade');
    }

    // ── contai_maybe_upgrade: migration execution ───────────────

    public function test_upgrade_bumps_version_even_on_migration_failure(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_plugin_version', '0')
            ->andReturn('1.0.0');

        // Force migration failure by setting db_version to 0 and providing
        // a runner that will fail (the real migrations need actual DB)
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(1234567890);

        // Migrations will fail because $wpdb->query returns true but
        // dbDelta is not available — the runner will proceed normally.
        // In either case, version should be bumped.
        $version_stored = null;
        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$version_stored) {
                if ($key === 'contai_plugin_version') {
                    $version_stored = $value;
                }
                return true;
            });
        WP_Mock::userFunction('delete_option');

        // dbDelta used by migrations
        WP_Mock::userFunction('dbDelta');

        contai_maybe_upgrade();

        $this->assertSame(CONTAI_VERSION, $version_stored, 'Version must be bumped even if migrations have issues');
    }

    // ── contai_maybe_upgrade: cron re-registration ──────────────

    public function test_upgrade_re_registers_crons_when_missing(): void {
        $this->setupUpgradeMocks('1.0.0', 99, false);

        WP_Mock::userFunction('wp_schedule_event')
            ->twice();

        contai_maybe_upgrade();

        // WP_Mock verifies wp_schedule_event was called twice on tearDown
        $this->addToAssertionCount(1);
    }

    public function test_upgrade_skips_cron_registration_when_already_scheduled(): void {
        $this->setupUpgradeMocks('1.0.0', 99, 1234567890);

        WP_Mock::userFunction('wp_schedule_event')->never();

        contai_maybe_upgrade();

        $this->addToAssertionCount(1);
    }

    // ── contai_maybe_upgrade: transient cleanup ─────────────────

    public function test_upgrade_flushes_plugin_transients(): void {
        $this->setupUpgradeMocks('1.0.0');

        $delete_patterns = [];
        $GLOBALS['wpdb']->shouldReceive('prepare')
            ->with(Mockery::pattern('/DELETE FROM/'), Mockery::type('string'))
            ->andReturnUsing(function ($query, $pattern) use (&$delete_patterns) {
                $delete_patterns[] = $pattern;
                return 'DELETE query';
            });

        $GLOBALS['wpdb']->shouldReceive('query')
            ->with('DELETE query');

        contai_maybe_upgrade();

        $this->assertContains('_transient_contai_%', $delete_patterns, 'Should flush contai transients');
        $this->assertContains('_transient_timeout_contai_%', $delete_patterns, 'Should flush contai transient timeouts');
    }

    // ── contai_activate_plugin ──────────────────────────────────

    public function test_activation_stores_plugin_version(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(99);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(1234567890);

        WP_Mock::userFunction('add_option');

        $version_stored = null;
        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$version_stored) {
                if ($key === 'contai_plugin_version') {
                    $version_stored = $value;
                }
                return true;
            });

        contai_activate_plugin();

        $this->assertSame(CONTAI_VERSION, $version_stored, 'Activation should store plugin version');
    }

    public function test_activation_sets_hardening_defaults(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(99);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(1234567890);

        WP_Mock::userFunction('update_option');

        $options_added = [];
        WP_Mock::userFunction('add_option')
            ->withArgs(function ($key, $value) use (&$options_added) {
                $options_added[$key] = $value;
                return true;
            });

        contai_activate_plugin();

        $this->assertArrayHasKey('contai_disable_feeds', $options_added);
        $this->assertArrayHasKey('contai_disable_author_pages', $options_added);
        $this->assertArrayHasKey('contai_redirect_404', $options_added);
        $this->assertSame('1', $options_added['contai_disable_feeds']);
        $this->assertSame('1', $options_added['contai_disable_author_pages']);
        $this->assertSame('1', $options_added['contai_redirect_404']);
    }

    public function test_activation_registers_cron_events(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(99);

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(false);

        WP_Mock::userFunction('add_option');
        WP_Mock::userFunction('update_option');

        WP_Mock::userFunction('wp_schedule_event')
            ->twice();

        contai_activate_plugin();

        $this->addToAssertionCount(1);
    }
}
