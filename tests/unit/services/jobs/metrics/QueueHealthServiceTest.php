<?php

namespace ContAI\Tests\Unit\Services\Jobs\Metrics;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiQueueHealthService;
use ContaiJobRepository;
use ContaiJobStatus;

require_once dirname(__DIR__, 5) . '/includes/services/jobs/metrics/QueueHealthService.php';

class QueueHealthServiceTest extends TestCase
{
    private $jobRepository;
    private $wpdb;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;
        // get_var/prepare passthrough for the SELECT TIMESTAMPDIFF query
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql) {
            return $sql;
        });

        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_getSnapshot_returnsExpectedShape(): void
    {
        $future = time() + 30;

        WP_Mock::userFunction('wp_next_scheduled')
            ->withArgs(['contai_process_job_queue'])
            ->andReturn($future);
        WP_Mock::userFunction('get_option')
            ->withArgs(['contai_last_tick_at', null])
            ->andReturn('2026-05-19 10:00:00');

        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PENDING)
            ->andReturn(3);
        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PROCESSING)
            ->andReturn(1);

        $this->wpdb->shouldReceive('get_var')->andReturn('42');

        $service = new ContaiQueueHealthService($this->jobRepository);
        $snapshot = $service->getSnapshot();

        $this->assertSame(
            [
                'wp_cron_disabled',
                'cron_event_scheduled',
                'next_run_at',
                'next_run_overdue_seconds',
                'pending',
                'processing',
                'longest_processing_age_seconds',
                'last_tick_at',
            ],
            array_keys($snapshot)
        );

        $this->assertFalse($snapshot['wp_cron_disabled']);
        $this->assertTrue($snapshot['cron_event_scheduled']);
        $this->assertSame($future, $snapshot['next_run_at']);
        $this->assertSame(0, $snapshot['next_run_overdue_seconds']);
        $this->assertSame(3, $snapshot['pending']);
        $this->assertSame(1, $snapshot['processing']);
        $this->assertSame(42, $snapshot['longest_processing_age_seconds']);
        $this->assertSame('2026-05-19 10:00:00', $snapshot['last_tick_at']);
    }

    public function test_getSnapshot_reportsOverdueWhenScheduleInThePast(): void
    {
        $past = time() - 600;

        WP_Mock::userFunction('wp_next_scheduled')
            ->withArgs(['contai_process_job_queue'])
            ->andReturn($past);
        WP_Mock::userFunction('get_option')
            ->withArgs(['contai_last_tick_at', null])
            ->andReturn(null);

        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PENDING)
            ->andReturn(0);
        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PROCESSING)
            ->andReturn(0);

        $this->wpdb->shouldReceive('get_var')->andReturn(null);

        $service = new ContaiQueueHealthService($this->jobRepository);
        $snapshot = $service->getSnapshot();

        $this->assertGreaterThanOrEqual(600, $snapshot['next_run_overdue_seconds']);
        $this->assertSame(0, $snapshot['longest_processing_age_seconds']);
        $this->assertNull($snapshot['last_tick_at']);
    }

    public function test_getSnapshot_reportsCronAbsentWhenNotScheduled(): void
    {
        WP_Mock::userFunction('wp_next_scheduled')
            ->withArgs(['contai_process_job_queue'])
            ->andReturn(false);
        WP_Mock::userFunction('get_option')
            ->withArgs(['contai_last_tick_at', null])
            ->andReturn(null);

        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PENDING)
            ->andReturn(0);
        $this->jobRepository->shouldReceive('countByStatus')
            ->with(ContaiJobStatus::PROCESSING)
            ->andReturn(0);

        $this->wpdb->shouldReceive('get_var')->andReturn(null);

        $service = new ContaiQueueHealthService($this->jobRepository);
        $snapshot = $service->getSnapshot();

        $this->assertFalse($snapshot['cron_event_scheduled']);
        $this->assertNull($snapshot['next_run_at']);
        $this->assertSame(0, $snapshot['next_run_overdue_seconds']);
    }
}
