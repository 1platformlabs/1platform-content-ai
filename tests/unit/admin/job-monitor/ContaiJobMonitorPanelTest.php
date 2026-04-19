<?php

namespace ContAI\Tests\Unit\Admin\JobMonitor;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiJobMonitorPanel;

class ContaiJobMonitorPanelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/../../../../includes/admin/job-monitor/panels/ContaiJobMonitorPanel.php';
    }

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_wrapperClass_adds_contai_app_when_flag_is_on(): void
    {
        $this->stubFlag(true);

        $wrapperClass = ContaiJobMonitorPanel::wrapperClass();

        $this->assertStringContainsString('contai-app', $wrapperClass);
        $this->assertStringContainsString('contai-page', $wrapperClass);
        $this->assertStringContainsString('wrap', $wrapperClass);
    }

    public function test_wrapperClass_omits_contai_app_when_flag_is_off(): void
    {
        $this->stubFlag(false);

        $wrapperClass = ContaiJobMonitorPanel::wrapperClass();

        $this->assertStringNotContainsString('contai-app', $wrapperClass);
        $this->assertStringNotContainsString('contai-page', $wrapperClass);
        $this->assertStringContainsString('wrap', $wrapperClass);
    }

    /**
     * Install (once) a stub for contai_ui_v3_enabled() that reads a process-global
     * value. The function is created the first time a test runs; subsequent tests
     * just flip the flag.
     */
    private function stubFlag(bool $enabled): void
    {
        $GLOBALS['__contai_ui_v3_stub'] = $enabled;

        if (!function_exists('contai_ui_v3_enabled')) {
            eval('function contai_ui_v3_enabled(): bool { return !empty($GLOBALS["__contai_ui_v3_stub"]); }');
        }
    }
}
