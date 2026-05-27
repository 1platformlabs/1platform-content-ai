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
 * - #55: batch with total=0 must be considered complete.
 * - #69: failed post_generation jobs must count as finished so waitForPosts
 *   doesn't stall.
 * - #109 / #110: the site-wizard silently marked a 100-post batch "complete"
 *   when the keyword pool ran out at 12. getBatchStatus now exposes a
 *   `requested` count + `is_short` / `shortfall` so SiteGenerationJob can
 *   fail loudly instead of accepting a short load.
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
        $this->mockWpdb(0, 0);
        $this->mockBatchOptions('batch_test_123', 0, 0);

        $status = $this->newService()->getBatchStatus('batch_test_123');

        $this->assertTrue($status['is_complete']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame(0, $status['finished']);
        $this->assertFalse($status['is_short']);
        $this->assertSame(0, $status['shortfall']);
    }

    public function test_getBatchStatus_incomplete_batch(): void
    {
        $this->mockWpdb(5, 0);
        $this->mockBatchOptions('batch_test_456', 10, 10);

        $status = $this->newService()->getBatchStatus('batch_test_456');

        $this->assertFalse($status['is_complete']);
        $this->assertSame(5, $status['completed']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame(5, $status['finished']);
        $this->assertFalse($status['is_short']);
    }

    public function test_getBatchStatus_all_completed(): void
    {
        $this->mockWpdb(10, 0);
        $this->mockBatchOptions('batch_test_789', 10, 10);

        $status = $this->newService()->getBatchStatus('batch_test_789');

        $this->assertTrue($status['is_complete']);
        $this->assertSame(10, $status['completed']);
        $this->assertSame(0, $status['failed']);
        $this->assertSame(10, $status['finished']);
        $this->assertFalse($status['is_short']);
    }

    public function test_getBatchStatus_failed_jobs_count_as_finished(): void
    {
        $this->mockWpdb(7, 3);
        $this->mockBatchOptions('batch_test_failed', 10, 10);

        $status = $this->newService()->getBatchStatus('batch_test_failed');

        $this->assertTrue($status['is_complete']);
        $this->assertSame(7, $status['completed']);
        $this->assertSame(3, $status['failed']);
        $this->assertSame(10, $status['finished']);
    }

    public function test_getBatchStatus_all_failed_is_complete(): void
    {
        $this->mockWpdb(0, 10);
        $this->mockBatchOptions('batch_test_all_failed', 10, 10);

        $status = $this->newService()->getBatchStatus('batch_test_all_failed');

        $this->assertTrue($status['is_complete']);
        $this->assertSame(0, $status['completed']);
        $this->assertSame(10, $status['failed']);
        $this->assertSame(10, $status['finished']);
    }

    public function test_getBatchStatus_partial_failure_still_incomplete(): void
    {
        $this->mockWpdb(3, 2);
        $this->mockBatchOptions('batch_test_partial', 10, 10);

        $status = $this->newService()->getBatchStatus('batch_test_partial');

        $this->assertFalse($status['is_complete']);
        $this->assertSame(3, $status['completed']);
        $this->assertSame(2, $status['failed']);
        $this->assertSame(5, $status['finished']);
    }

    // ── #109 / #110: short batch surfaces shortfall ─────────────────

    public function test_getBatchStatus_short_batch_marks_is_short_true(): void
    {
        // Requested 100 posts, only 12 enqueued (keyword pool exhausted),
        // all 12 finished. Batch is complete BUT is_short must be true so
        // SiteGenerationJob can fail loudly instead of marking the run "done".
        $this->mockWpdb(12, 0);
        $this->mockBatchOptions('batch_short_109', 100, 12);

        $status = $this->newService()->getBatchStatus('batch_short_109');

        $this->assertTrue($status['is_complete'], 'all enqueued jobs finished');
        $this->assertTrue($status['is_short'], 'requested > enqueued must surface as short');
        $this->assertSame(100, $status['requested']);
        $this->assertSame(12, $status['total']);
        $this->assertSame(88, $status['shortfall']);
    }

    public function test_getBatchStatus_legacy_batch_without_requested_option_is_not_short(): void
    {
        // Batches that started before the `_requested` option existed must
        // behave as before — no shortfall surfaced, no retroactive failure.
        $this->mockWpdb(10, 0);
        $this->mockBatchOptions('batch_legacy', null, 10);

        $status = $this->newService()->getBatchStatus('batch_legacy');

        $this->assertTrue($status['is_complete']);
        $this->assertFalse($status['is_short']);
        $this->assertSame(0, $status['shortfall']);
        $this->assertSame(10, $status['requested'], 'requested falls back to total when option is unset');
    }

    public function test_enqueuePostGeneration_persists_requested_and_enqueued_counts(): void
    {
        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        $mockQueue->shouldReceive('enqueuePostGeneration')->andReturn(12);

        WP_Mock::userFunction('current_time')->andReturn('2026-05-27 12:00:00');
        WP_Mock::userFunction('contai_log')->andReturn(null);

        $requestedCaptured = null;
        $totalCaptured     = null;
        WP_Mock::userFunction('update_option')->andReturnUsing(
            function ($key, $value) use (&$requestedCaptured, &$totalCaptured) {
                if (preg_match('/_requested$/', $key)) {
                    $requestedCaptured = $value;
                } elseif (preg_match('/_total$/', $key)) {
                    $totalCaptured = $value;
                }
                return true;
            }
        );

        $service = new ContaiPostGenerationSetupService($mockQueue);
        $result  = $service->enqueuePostGeneration(100, ['lang' => 'en']);

        $this->assertSame(100, $requestedCaptured, 'requested count must be persisted to _requested option');
        $this->assertSame(12, $totalCaptured, 'enqueued count must be persisted to _total option');
        $this->assertSame(100, $result['requested_count']);
        $this->assertSame(12, $result['enqueued_count']);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function newService(): ContaiPostGenerationSetupService
    {
        $mockQueue = Mockery::mock(ContaiQueueManager::class);
        return new ContaiPostGenerationSetupService($mockQueue);
    }

    private function mockWpdb(int $done, int $failed): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('');
        $wpdb->shouldReceive('get_var')->andReturn($done, $failed);
        $wpdb->shouldReceive('esc_like')->andReturn('');
    }

    /**
     * @param int|null $requested null = option unset (legacy batch)
     */
    private function mockBatchOptions(string $batchId, ?int $requested, int $total): void
    {
        WP_Mock::userFunction('get_option')
            ->with("contai_batch_{$batchId}_total", 0)
            ->andReturn($total);

        if ($requested === null) {
            // Mirror the production fallback: when _requested is unset, the
            // code passes $total as the default and gets $total back.
            WP_Mock::userFunction('get_option')
                ->with("contai_batch_{$batchId}_requested", $total)
                ->andReturn($total);
        } else {
            WP_Mock::userFunction('get_option')
                ->with("contai_batch_{$batchId}_requested", $total)
                ->andReturn($requested);
        }
    }
}
