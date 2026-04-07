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
 *
 * Validates the fix for GitHub issue #69: Site Wizard stuck at 58% because
 * failed post_generation jobs were not counted as finished, preventing
 * waitForPosts from ever completing the batch.
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
        $wpdb->shouldReceive('get_var')->andReturn(0, 0);
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
        $this->assertSame(0, $status['failed']);
        $this->assertSame(0, $status['finished']);
    }

    public function test_getBatchStatus_incomplete_batch(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(5, 0);
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
        $this->assertSame(5, $status['completed']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame(5, $status['finished']);
    }

    public function test_getBatchStatus_all_completed(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(10, 0);
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
        $this->assertSame(10, $status['completed']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame(10, $status['finished']);
    }

    /**
     * Regression test for #69: failed post_generation jobs must count as finished
     * so the wizard doesn't stall at waitForPosts indefinitely.
     */
    public function test_getBatchStatus_failed_jobs_count_as_finished(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(7, 3);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [10],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_failed');

        $this->assertTrue(
            $status['is_complete'],
            'A batch with 7 done + 3 failed = 10 finished out of 10 total must be considered complete'
        );
        $this->assertSame(7, $status['completed']);
        $this->assertSame(3, $status['failed']);
        $this->assertSame(10, $status['finished']);
    }

    /**
     * Regression test for #69: batch with all jobs failed should still complete.
     */
    public function test_getBatchStatus_all_failed_is_complete(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(0, 10);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [10],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_all_failed');

        $this->assertTrue(
            $status['is_complete'],
            'A batch with 0 done + 10 failed = 10 finished must be considered complete (no posts to wait for)'
        );
        $this->assertSame(0, $status['completed']);
        $this->assertSame(10, $status['failed']);
        $this->assertSame(10, $status['finished']);
    }

    /**
     * Regression test for #69: batch still in progress with some failures.
     */
    public function test_getBatchStatus_partial_failure_still_incomplete(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn(3, 2);
        $wpdb->shouldReceive('esc_like')->andReturn('');

        WP_Mock::userFunction('get_option', [
            'return_in_order' => [10],
        ]);

        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $service = new ContaiPostGenerationSetupService($mockQueue);

        $status = $service->getBatchStatus('batch_test_partial');

        $this->assertFalse(
            $status['is_complete'],
            'A batch with 3 done + 2 failed = 5 finished out of 10 total must NOT be considered complete'
        );
        $this->assertSame(3, $status['completed']);
        $this->assertSame(2, $status['failed']);
        $this->assertSame(5, $status['finished']);
    }
}
