<?php

namespace ContAI\Tests\Unit\Services\Setup;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPostGenerationSetupService;
use ContaiQueueManager;

/**
 * Regression tests for PostGenerationSetupService.
 *
 * Validates the fix for GitHub issue #55: Site Wizard re-execution hangs
 * at waitForPosts because a batch with total=0 was never considered complete.
 */
class PostGenerationSetupServiceTest extends TestCase
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

    public function test_getBatchStatus_zero_total_is_complete(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(0);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [0],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_123');

        $this->assertTrue(
            $status['is_complete'],
            'A batch with total=0 and completed=0 must be considered complete (no posts to wait for)'
        );
    }

    public function test_getBatchStatus_incomplete_batch(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(5);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [10],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_456');

        $this->assertFalse(
            $status['is_complete'],
            'A batch with 5/10 completed must NOT be considered complete'
        );
    }

    public function test_getBatchStatus_all_completed(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(10);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [10],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_789');

        $this->assertTrue(
            $status['is_complete'],
            'A batch with 10/10 completed must be considered complete'
        );
    }
}
