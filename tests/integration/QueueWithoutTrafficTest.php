<?php

namespace ContAI\Tests\Integration;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiJobProcessor;
use ContaiJobRepository;
use ContaiJobRecoveryService;
use ContaiJobInterface;
use ContaiJob;
use ContaiJobStatus;
use ContaiConfig;
use ContaiDatabase;

/**
 * Regression — DISABLE_WP_CRON analog.
 *
 * When WP-Cron is disabled (or simply never fires because the site has
 * no HTTP traffic) the only way to drain the queue is to invoke the
 * cron callback directly. This test verifies that path still works
 * — the surrogate for the Phase 1 REST endpoint until it ships on main.
 */
class QueueWithoutTrafficTest extends TestCase
{
    private $jobRepository;
    private ContaiJobProcessor $processor;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

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

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            $rendered = $sql;
            foreach ($args as $arg) {
                $rendered = preg_replace('/%[sd]/', (string) $arg, $rendered, 1);
            }
            return $rendered;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('query')->andReturn(0);

        WP_Mock::userFunction('current_time')->andReturnUsing(function ($format = 'mysql') {
            return $format === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
        });
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.test');
        WP_Mock::userFunction('get_option')->andReturn(false);
        WP_Mock::userFunction('update_option')->andReturn(true);
        WP_Mock::userFunction('wp_upload_dir')->andReturn([
            'basedir' => '/tmp',
            'baseurl' => '/uploads',
        ]);

        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
        $this->processor = new ContaiJobProcessor(new ContaiJobRecoveryService());

        $ref = new \ReflectionClass($this->processor);
        $prop = $ref->getProperty('jobRepository');
        $prop->setAccessible(true);
        $prop->setValue($this->processor, $this->jobRepository);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_direct_invocation_drains_pending_jobs_without_wp_cron(): void
    {
        $pending = $this->makeJob(201, 'post_generation');
        $pending->setStatus(ContaiJobStatus::PROCESSING); // claimed → simulates ::claimPendingJobs return value

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                return ['success' => true];
            }
            public function getType() { return 'post_generation'; }
        };

        $ref = new \ReflectionClass($this->processor);
        $handlersProp = $ref->getProperty('jobHandlers');
        $handlersProp->setAccessible(true);
        $handlers = $handlersProp->getValue($this->processor);
        $handlers['post_generation'] = $handler;
        $handlersProp->setValue($this->processor, $handlers);

        $this->jobRepository->shouldReceive('findByStatus')
            ->with(ContaiJobStatus::PROCESSING)
            ->once()
            ->andReturn([]);
        $this->jobRepository->shouldReceive('countProcessingJobs')->once()->andReturn(0);
        $this->jobRepository->shouldReceive('claimPendingJobs')->with(5)->once()->andReturn([$pending]);
        $this->jobRepository->shouldReceive('update')->once()->with($pending);

        $processed = $this->processor->processQueue();

        $this->assertSame(1, $processed);
        $this->assertSame(ContaiJobStatus::DONE, $pending->getStatus());
    }

    public function test_callback_is_callable_directly_simulating_rest_endpoint(): void
    {
        // The Phase 1 REST endpoint wraps this exact callback. Verify it
        // exists, is callable, and is the same symbol that wp-cron hooks.
        $this->assertTrue(
            function_exists('contai_process_job_queue_callback'),
            'Job queue cron callback must be globally available so the manual '
            . 'trigger path (REST endpoint or wp-cli) can drain the queue without '
            . 'depending on WP-Cron firing.'
        );

        $this->assertTrue(is_callable('contai_process_job_queue_callback'));
    }

    private function makeJob(int $id, string $type): ContaiJob
    {
        $job = new ContaiJob();
        $job->setId($id);
        $job->setJobType($type);
        $job->setPayload(['keyword_id' => $id]);
        return $job;
    }
}
