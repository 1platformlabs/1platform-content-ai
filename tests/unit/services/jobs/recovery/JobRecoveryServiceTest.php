<?php

namespace ContAI\Tests\Unit\Services\Jobs\Recovery;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiJobRecoveryService;
use ContaiResetToPendingStrategy;
use ContaiMarkAsFailedStrategy;
use ContaiJob;
use ContaiJobStatus;

class JobRecoveryServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('current_time')->andReturnUsing(function ($format = 'mysql') {
            return $format === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
        });
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_default_strategies_honour_filter_overrides(): void
    {
        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(10);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(120);

        $service = new ContaiJobRecoveryService();

        $strategiesRef = new \ReflectionClass($service);
        $prop = $strategiesRef->getProperty('strategies');
        $prop->setAccessible(true);
        $strategies = $prop->getValue($service);

        $this->assertCount(2, $strategies);
        $this->assertInstanceOf(ContaiResetToPendingStrategy::class, $strategies[0]);
        $this->assertInstanceOf(ContaiMarkAsFailedStrategy::class, $strategies[1]);
    }

    public function test_default_thresholds_apply_when_no_filter_is_registered(): void
    {
        // 6 min stuck > default 5 min reset threshold → should be re-queued.
        $job = new ContaiJob();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 6 * 60));

        $service = new ContaiJobRecoveryService();
        $recovered = $service->recoverStuckJobs([$job]);

        $this->assertCount(1, $recovered);
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
        $this->assertSame(1, $job->getAttempts());
    }

    public function test_recover_returns_empty_when_no_jobs_match_any_strategy(): void
    {
        $pendingJob = new ContaiJob();
        $pendingJob->setId(2);
        $pendingJob->setJobType('post_generation');
        $pendingJob->setStatus(ContaiJobStatus::PENDING);

        $doneJob = new ContaiJob();
        $doneJob->setId(3);
        $doneJob->setJobType('post_generation');
        $doneJob->setStatus(ContaiJobStatus::DONE);

        $service = new ContaiJobRecoveryService();
        $recovered = $service->recoverStuckJobs([$pendingJob, $doneJob]);

        $this->assertEmpty($recovered);
    }

    public function test_explicit_strategies_override_defaults(): void
    {
        $custom = new ContaiResetToPendingStrategy(1);
        $service = new ContaiJobRecoveryService([$custom]);

        $job = new ContaiJob();
        $job->setId(4);
        $job->setJobType('post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 90));

        $recovered = $service->recoverStuckJobs([$job]);

        $this->assertCount(1, $recovered);
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
    }
}
