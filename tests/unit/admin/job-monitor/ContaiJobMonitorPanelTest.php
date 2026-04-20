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

    public function test_wrapperClass_always_contains_contai_app(): void
    {
        $wrapperClass = ContaiJobMonitorPanel::wrapperClass();

        $this->assertStringContainsString('wrap', $wrapperClass);
        $this->assertStringContainsString('contai-app', $wrapperClass);
        $this->assertStringContainsString('contai-page', $wrapperClass);
        $this->assertStringContainsString('contai-job-monitor', $wrapperClass);
    }
}
