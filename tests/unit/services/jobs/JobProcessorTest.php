<?php

namespace ContAI\Tests\Unit\Services\Jobs;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiJobProcessor;
use ContaiJobRepository;
use ContaiJobRecoveryService;
use ContaiJob;
use ContaiJobStatus;
use ContaiJobInterface;
use ContaiDatabase;
use ContaiConfig;

class JobProcessorTest extends TestCase
{
    private ContaiJobProcessor $processor;
    private $jobRepository;
    private $recoveryService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Reset singletons
        $dbRef = new \ReflectionClass(ContaiDatabase::class);
        $instanceProp = $dbRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);

        $configRef = new \ReflectionClass(ContaiConfig::class);
        $configProp = $configRef->getProperty('instance');
        $configProp->setAccessible(true);
        $configProp->setValue(null, null);

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        // Mock WP functions needed by handler constructors
        WP_Mock::userFunction('current_time')->andReturn('2025-01-15 10:00:00');
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.com');
        WP_Mock::userFunction('get_option')->andReturn(false);
        WP_Mock::userFunction('wp_upload_dir')->andReturn(['basedir' => '/tmp', 'baseurl' => '/uploads']);

        $this->recoveryService = Mockery::mock(ContaiJobRecoveryService::class);
        $this->processor = new ContaiJobProcessor($this->recoveryService);

        // Inject mock repository
        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
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

    private function createJob(int $id, string $type = 'post_generation'): ContaiJob
    {
        $job = new ContaiJob();
        $job->setId($id);
        $job->setJobType($type);
        $job->setPayload(['keyword_id' => $id]);
        return $job;
    }

    public function test_process_job_catches_throwable_type_error(): void
    {
        $job = $this->createJob(1);

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                throw new \TypeError('Argument must be of type string, null given');
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        $this->jobRepository->shouldReceive('update')->once();

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertSame(1, $job->getAttempts());
        $this->assertStringContainsString('Argument must be of type string', $job->getErrorMessage());
    }

    public function test_process_job_catches_throwable_value_error(): void
    {
        $job = $this->createJob(2);

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                throw new \ValueError('Value out of range');
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        $this->jobRepository->shouldReceive('update')->once();

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertStringContainsString('Value out of range', $job->getErrorMessage());
    }

    public function test_process_job_still_catches_regular_exceptions(): void
    {
        $job = $this->createJob(3);

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                throw new \RuntimeException('API timeout');
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        $this->jobRepository->shouldReceive('update')->once();

        $this->invokeProcessJob($job);

        $this->assertSame(ContaiJobStatus::FAILED, $job->getStatus());
        $this->assertSame(1, $job->getAttempts());
        $this->assertStringContainsString('API timeout', $job->getErrorMessage());
    }

    public function test_process_job_marks_completed_on_success(): void
    {
        $job = $this->createJob(4);

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
    }

    public function test_process_job_returns_on_continue(): void
    {
        $job = $this->createJob(5);

        $handler = new class implements ContaiJobInterface {
            public function handle(array $payload) {
                return ['continue' => true];
            }
            public function getType() { return 'post_generation'; }
        };

        $this->injectHandler('post_generation', $handler);
        // No repository update expected for 'continue'

        $this->invokeProcessJob($job);

        // Status should remain unchanged (handler manages its own state)
        $this->assertSame(ContaiJobStatus::PENDING, $job->getStatus());
    }
}
