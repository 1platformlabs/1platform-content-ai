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
     * Configure WP_Mock so the real contai_ui_v3_enabled() from
     * includes/helpers/ui-flag.php resolves to the desired boolean by
     * stubbing the WP functions it reads (user meta + site option).
     */
    private function stubFlag(bool $enabled): void
    {
        WP_Mock::userFunction('get_current_user_id', [
            'return' => 1,
        ]);
        WP_Mock::userFunction('get_user_meta', [
            'args'   => [1, 'contai_ui_v3', true],
            'return' => $enabled ? 'on' : 'off',
        ]);
        // Fallback if the user-meta branch is not taken (defensive — the "on"/"off"
        // values short-circuit before the option lookup, but any other meta value
        // would fall through and read the site-wide option).
        WP_Mock::userFunction('get_option', [
            'args'   => ['contai_ui_v3', false],
            'return' => $enabled,
        ]);
    }
}
