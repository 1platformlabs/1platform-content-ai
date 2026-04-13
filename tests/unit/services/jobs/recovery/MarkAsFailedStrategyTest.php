<?php

namespace ContAI\Tests\Unit\Services\Jobs\Recovery;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiMarkAsFailedStrategy;
use ContaiResetToPendingStrategy;
use ContaiJob;
use ContaiJobStatus;

class MarkAsFailedStrategyTest extends TestCase
{
    private ContaiMarkAsFailedStrategy $strategy;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $this->strategy = new ContaiMarkAsFailedStrategy(240);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function mockTime(): void
    {
        WP_Mock::userFunction('current_time')
            ->withArgs(['mysql'])
            ->andReturn(gmdate('Y-m-d H:i:s'));

        WP_Mock::userFunction('current_time')
            ->withArgs(['timestamp'])
            ->andReturn(time());
    }

    public function test_should_recover_when_max_attempts_reached(): void
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setMaxAttempts(3);

        $job->incrementAttempts();
        $job->incrementAttempts();
        $job->incrementAttempts();

        // Even with recent processed_at, should recover because max attempts reached
        $recentTime = gmdate('Y-m-d H:i:s', time() - (5 * 60));
        $job->setProcessedAt($recentTime);

        $this->assertTrue($job->hasReachedMaxAttempts());
        $this->assertTrue($this->strategy->shouldRecover($job));
    }

    public function test_should_recover_after_time_threshold(): void
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);

        $stuckTime = gmdate('Y-m-d H:i:s', time() - (250 * 60));
        $job->setProcessedAt($stuckTime);

        $this->assertTrue($this->strategy->shouldRecover($job));
    }

    public function test_should_not_recover_pending_job(): void
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setStatus(ContaiJobStatus::PENDING);

        $this->assertFalse($this->strategy->shouldRecover($job));
    }

    public function test_recover_marks_as_failed_with_message(): void
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setStatus(ContaiJobStatus::PROCESSING);

        $this->strategy->recover($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertStringContainsString('240 minutes', $job->getErrorMessage());
    }

    public function test_recovery_chain_max_attempts_escalates_to_failed(): void
    {
        $this->mockTime();

        $resetStrategy = new ContaiResetToPendingStrategy(30);

        $job = new ContaiJob();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setMaxAttempts(3);

        $stuckTime = gmdate('Y-m-d H:i:s', time() - (45 * 60));
        $job->setProcessedAt($stuckTime);

        // Attempt 1: ResetToPending handles it
        $this->assertTrue($resetStrategy->shouldRecover($job));
        $resetStrategy->recover($job);
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
        $this->assertSame(1, $job->getAttempts());

        // Simulate stuck again
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setProcessedAt($stuckTime);

        // Attempt 2
        $this->assertTrue($resetStrategy->shouldRecover($job));
        $resetStrategy->recover($job);
        $this->assertSame(2, $job->getAttempts());

        // Simulate stuck again
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setProcessedAt($stuckTime);

        // Attempt 3 — last reset
        $this->assertTrue($resetStrategy->shouldRecover($job));
        $resetStrategy->recover($job);
        $this->assertSame(3, $job->getAttempts());

        // Simulate stuck again — max attempts now reached
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setProcessedAt($stuckTime);

        // ResetToPending should SKIP (max attempts reached)
        $this->assertFalse($resetStrategy->shouldRecover($job));

        // MarkAsFailed should handle it
        $this->assertTrue($this->strategy->shouldRecover($job));
        $this->strategy->recover($job);
        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
    }
}
