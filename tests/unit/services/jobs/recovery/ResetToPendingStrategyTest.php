<?php

namespace ContAI\Tests\Unit\Services\Jobs\Recovery;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiResetToPendingStrategy;
use ContaiJob;
use ContaiJobStatus;

class ResetToPendingStrategyTest extends TestCase
{
    private ContaiResetToPendingStrategy $strategy;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $this->strategy = new ContaiResetToPendingStrategy(30);
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

    private function createStuckProcessingJob(int $minutesStuck = 45): ContaiJob
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);

        $stuckTime = gmdate('Y-m-d H:i:s', time() - ($minutesStuck * 60));
        $job->setProcessedAt($stuckTime);

        return $job;
    }

    public function test_should_not_recover_when_max_attempts_reached(): void
    {
        $job = $this->createStuckProcessingJob(45);
        $job->setMaxAttempts(3);
        $job->incrementAttempts();
        $job->incrementAttempts();
        $job->incrementAttempts();

        $this->assertTrue($job->hasReachedMaxAttempts());
        $this->assertFalse($this->strategy->shouldRecover($job));
    }

    public function test_should_recover_when_under_max_attempts(): void
    {
        $job = $this->createStuckProcessingJob(45);
        $job->setMaxAttempts(3);
        $job->incrementAttempts();

        $this->assertFalse($job->hasReachedMaxAttempts());
        $this->assertTrue($this->strategy->shouldRecover($job));
    }

    public function test_recover_increments_attempts(): void
    {
        $job = $this->createStuckProcessingJob(45);

        $this->assertSame(0, $job->getAttempts());

        $this->strategy->recover($job);

        $this->assertSame(1, $job->getAttempts());
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
        $this->assertNull($job->getProcessedAt());
    }

    public function test_recover_increments_attempts_cumulatively(): void
    {
        $job = $this->createStuckProcessingJob(45);

        $this->strategy->recover($job);
        $this->assertSame(1, $job->getAttempts());

        // Simulate being claimed and stuck again
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $stuckTime = gmdate('Y-m-d H:i:s', time() - (45 * 60));
        $job->setProcessedAt($stuckTime);

        $this->strategy->recover($job);
        $this->assertSame(2, $job->getAttempts());
    }

    public function test_should_not_recover_pending_job(): void
    {
        $this->mockTime();

        $job = new ContaiJob();
        $job->setId(1);
        $job->setStatus(ContaiJobStatus::PENDING);

        $this->assertFalse($this->strategy->shouldRecover($job));
    }

    public function test_should_not_recover_recently_processing_job(): void
    {
        $job = $this->createStuckProcessingJob(5); // Only 5 min, threshold is 30

        $this->assertFalse($this->strategy->shouldRecover($job));
    }
}
