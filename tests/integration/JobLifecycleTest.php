<?php

namespace ContAI\Tests\Integration;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiJob;
use ContaiJobStatus;
use ContaiJobProcessor;
use ContaiJobRepository;
use ContaiJobRecoveryService;
use ContaiJobInterface;
use ContaiResetToPendingStrategy;
use ContaiMarkAsFailedStrategy;
use ContaiConfig;
use ContaiDatabase;

/**
 * Multi-component lifecycle tests exercised against mocked WordPress and
 * MySQL infrastructure. See ./README.md for how to swap the WP_Mock layer
 * for a real WordPress+MySQL bootstrap via wp-phpunit.
 */
class JobLifecycleTest extends TestCase
{
    private ContaiJobProcessor $processor;
    private $jobRepository;
    private ContaiJobRecoveryService $recoveryService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->resetSingletons();
        $this->stubWordPressFunctions();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $this->recoveryService = new ContaiJobRecoveryService();
        $this->processor = new ContaiJobProcessor($this->recoveryService);

        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
        $this->injectProperty($this->processor, 'jobRepository', $this->jobRepository);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Happy path ──────────────────────────────────────────────────

    public function test_happy_path_job_completes_in_single_tick(): void
    {
        $job = $this->makeJob(101, 'post_generation');

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                return ['success' => true];
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        $this->jobRepository->shouldReceive('update')->once();

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::DONE, $job->getStatus());
        $this->assertSame(0, $job->getAttempts(), 'Successful jobs do not consume retry attempts');
    }

    // ── Polling re-queue ────────────────────────────────────────────

    public function test_polling_handler_can_continue_without_status_change(): void
    {
        $job = $this->makeJob(102, 'post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);

        $invocations = 0;

        $handler = new class($invocations) implements ContaiJobInterface {
            private int $count;
            public function __construct(int &$count) { $this->count = &$count; }
            public function handle(array $payload) {
                $this->count++;
                return $this->count < 3 ? ['continue' => true] : ['success' => true];
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);

        // Two continue ticks: no DB update expected.
        $this->invokeProcessJob($job);
        $this->assertSame(ContaiJobStatus::PROCESSING, $job->getStatus());

        $this->invokeProcessJob($job);
        $this->assertSame(ContaiJobStatus::PROCESSING, $job->getStatus());

        // Third tick completes the job.
        $this->jobRepository->shouldReceive('update')->once();
        $this->invokeProcessJob($job);
        $this->assertSame(ContaiJobStatus::DONE, $job->getStatus());
    }

    // ── Stuck recovery ──────────────────────────────────────────────

    public function test_stuck_job_returns_to_pending_after_threshold(): void
    {
        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(5);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(30);

        $job = $this->makeJob(103, 'post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        // Six minutes ago — beyond the 5-min reset threshold.
        $job->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 6 * 60));

        // Rebuild recovery service so it picks up the filter values.
        $service = new ContaiJobRecoveryService();
        $recovered = $service->recoverStuckJobs([$job]);

        $this->assertCount(1, $recovered);
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
        $this->assertNull($job->getProcessedAt());
        $this->assertSame(1, $job->getAttempts(), 'Recovery consumes one retry attempt');
    }

    public function test_recovery_is_idempotent_when_no_job_is_stuck(): void
    {
        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(5);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(30);

        $job = $this->makeJob(104, 'post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        // Only 60 s ago — well within both thresholds.
        $job->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 60));

        $service = new ContaiJobRecoveryService();
        $recovered = $service->recoverStuckJobs([$job]);

        $this->assertEmpty($recovered);
        $this->assertSame(ContaiJobStatus::PROCESSING, $job->getStatus());
        $this->assertSame(0, $job->getAttempts());
    }

    // ── Max attempts kill ───────────────────────────────────────────

    public function test_max_attempts_exhausted_marks_job_as_failed(): void
    {
        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(5);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(30);

        $job = $this->makeJob(105, 'post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setMaxAttempts(3);
        $job->incrementAttempts();
        $job->incrementAttempts();
        $job->incrementAttempts();
        // Recent processed_at — only max-attempts gate triggers MarkAsFailed.
        $job->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 30));

        $service = new ContaiJobRecoveryService();
        $recovered = $service->recoverStuckJobs([$job]);

        $this->assertCount(1, $recovered);
        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertNotEmpty($job->getErrorMessage());
    }

    public function test_recovery_chain_escalates_from_reset_to_failed_after_three_attempts(): void
    {
        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(5);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(30);

        $job = $this->makeJob(106, 'post_generation');
        $job->setStatus(ContaiJobStatus::PROCESSING);
        $job->setMaxAttempts(3);

        $stuckAt = gmdate('Y-m-d H:i:s', time() - 6 * 60);
        $job->setProcessedAt($stuckAt);

        $service = new ContaiJobRecoveryService();

        for ($i = 1; $i <= 3; $i++) {
            $service->recoverStuckJobs([$job]);
            $this->assertSame($i, $job->getAttempts());
            $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus(), "Iteration {$i}");

            // Simulate the next claim cycle leaving the job stuck again.
            $job->setStatus(ContaiJobStatus::PROCESSING);
            $job->setProcessedAt($stuckAt);
        }

        // Attempt 4 should escalate to FAILED (max attempts).
        $service->recoverStuckJobs([$job]);
        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
    }

    // ── Lock contention ─────────────────────────────────────────────

    public function test_concurrent_tick_only_first_acquires_lock(): void
    {
        global $wpdb;

        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            $rendered = $sql;
            foreach ($args as $arg) {
                $rendered = preg_replace('/%[sd]/', (string) $arg, $rendered, 1);
            }
            return $rendered;
        });

        // First processor gets the lock.
        $wpdb->shouldReceive('get_var')->once()->andReturn('1');
        // Second processor finds the lock taken.
        $wpdb->shouldReceive('get_var')->once()->andReturn('0');
        // Release for the first processor only.
        $wpdb->shouldReceive('query')->once();

        // First processor: lock acquired, drives the queue.
        $this->jobRepository->shouldReceive('findByStatus')->andReturn([]);
        $this->jobRepository->shouldReceive('countProcessingJobs')->andReturn(0);
        $this->jobRepository->shouldReceive('claimPendingJobs')->with(5)->andReturn([]);

        $first = $this->processor->processQueue();
        $this->assertSame(0, $first, 'First tick processed 0 jobs (queue empty)');

        // Second processor: lock denied, no work attempted.
        $secondProcessor = new ContaiJobProcessor($this->recoveryService);
        $this->injectProperty($secondProcessor, 'jobRepository', $this->jobRepository);

        $second = $secondProcessor->processQueue();
        $this->assertSame(0, $second, 'Second tick returned 0 because lock was held');
    }

    // ── Additional JobProcessor coverage ───────────────────────────

    public function test_process_job_fails_when_no_handler_registered(): void
    {
        $job = $this->makeJob(110, 'unknown_job_type');

        $this->jobRepository->shouldReceive('update')->once();

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertStringContainsString('No handler found', $job->getErrorMessage());
    }

    public function test_process_job_returns_without_update_on_retry_flag(): void
    {
        $job = $this->makeJob(111, 'post_generation');

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                return ['retry' => true];
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        // No DB update expected.
        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
    }

    public function test_process_job_tags_insufficient_credits_exception(): void
    {
        $job = $this->makeJob(112, 'post_generation');

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                throw new \RuntimeException('insufficient balance: 0.00 USD');
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        $this->jobRepository->shouldReceive('update')->once();

        WP_Mock::userFunction('contai_log')->andReturn(null);

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertStringStartsWith('INSUFFICIENT_CREDITS:', $job->getErrorMessage());
    }

    public function test_cleanup_persists_each_recovered_job(): void
    {
        global $wpdb;

        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            return $sql;
        });
        $wpdb->shouldReceive('get_var')->once()->andReturn('1');
        $wpdb->shouldReceive('query')->once();

        WP_Mock::onFilter('contai_recovery_reset_threshold_minutes')
            ->with(5)
            ->reply(5);
        WP_Mock::onFilter('contai_recovery_fail_threshold_minutes')
            ->with(30)
            ->reply(30);

        $stuckJob = $this->makeJob(113, 'post_generation');
        $stuckJob->setStatus(ContaiJobStatus::PROCESSING);
        $stuckJob->setProcessedAt(gmdate('Y-m-d H:i:s', time() - 6 * 60));

        $this->jobRepository->shouldReceive('findByStatus')
            ->with(ContaiJobStatus::PROCESSING)
            ->andReturn([$stuckJob]);
        $this->jobRepository->shouldReceive('countProcessingJobs')->andReturn(0);
        $this->jobRepository->shouldReceive('claimPendingJobs')->with(5)->andReturn([]);
        $this->jobRepository->shouldReceive('update')->once()->with($stuckJob);

        $this->processor->processQueue();

        $this->assertSame(ContaiJobStatus::PENDING, $stuckJob->getStatus());
    }

    public function test_process_queue_returns_zero_when_slots_saturated(): void
    {
        global $wpdb;

        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            return $sql;
        });
        $wpdb->shouldReceive('get_var')->once()->andReturn('1');
        $wpdb->shouldReceive('query')->once();

        $this->jobRepository->shouldReceive('findByStatus')->andReturn([]);
        $this->jobRepository->shouldReceive('countProcessingJobs')
            ->andReturn(ContaiJobProcessor::MAX_CONCURRENT_JOBS);

        $count = $this->processor->processQueue();

        $this->assertSame(0, $count);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function makeJob(int $id, string $type): ContaiJob
    {
        $job = new ContaiJob();
        $job->setId($id);
        $job->setJobType($type);
        $job->setPayload(['keyword_id' => $id]);
        return $job;
    }

    private function injectHandler(string $type, ContaiJobInterface $handler): void
    {
        $ref = new \ReflectionClass($this->processor);
        $prop = $ref->getProperty('jobHandlers');
        $prop->setAccessible(true);
        $handlers = $prop->getValue($this->processor);
        $handlers[$type] = $handler;
        $prop->setValue($this->processor, $handlers);
    }

    private function invokeProcessJob(ContaiJob $job): void
    {
        $ref = new \ReflectionClass($this->processor);
        $method = $ref->getMethod('processJob');
        $method->setAccessible(true);
        $method->invoke($this->processor, $job);
    }

    private function injectProperty(object $target, string $name, $value): void
    {
        $ref = new \ReflectionClass($target);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }

    private function resetSingletons(): void
    {
        foreach ([ContaiDatabase::class, ContaiConfig::class] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if (!$ref->hasProperty('instance')) {
                continue;
            }
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function stubWordPressFunctions(): void
    {
        WP_Mock::userFunction('current_time')->andReturnUsing(function ($format = 'mysql') {
            if ($format === 'timestamp') {
                return time();
            }
            return gmdate('Y-m-d H:i:s');
        });
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.test');
        WP_Mock::userFunction('get_option')->andReturn(false);
        WP_Mock::userFunction('update_option')->andReturn(true);
        WP_Mock::userFunction('wp_upload_dir')->andReturn([
            'basedir' => '/tmp',
            'baseurl' => '/uploads',
        ]);
    }
}
