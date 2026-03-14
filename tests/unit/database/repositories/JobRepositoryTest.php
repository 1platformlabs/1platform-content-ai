<?php

namespace ContAI\Tests\Unit\Database\Repositories;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use Database;
use JobRepository;
use Job;
use JobStatus;

class JobRepositoryTest extends TestCase {

    private $dbMock;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->setupDatabaseMock();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function setupDatabaseMock(): void {
        $this->dbMock = Mockery::mock(Database::class);

        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, $this->dbMock);
    }

    private function mockCurrentTime(): void {
        WP_Mock::userFunction('current_time')
            ->andReturn('2025-01-15 10:00:00');
    }

    public function test_create_inserts_job_and_sets_id(): void {
        $this->mockCurrentTime();

        $job = Job::create('post_generation', ['keyword_id' => 42], 5);

        $this->dbMock->shouldReceive('insert')
            ->once()
            ->with('contai_jobs', Mockery::type('array'))
            ->andReturn(10);

        $result = (new JobRepository())->create($job);

        $this->assertInstanceOf(Job::class, $result);
        $this->assertSame(10, $result->getId());
    }

    public function test_create_returns_false_on_failure(): void {
        $this->mockCurrentTime();

        $job = Job::create('post_generation', ['keyword_id' => 1]);

        $this->dbMock->shouldReceive('insert')
            ->once()
            ->andReturn(0);

        $result = (new JobRepository())->create($job);

        $this->assertFalse($result);
    }

    public function test_update_returns_false_for_job_without_id(): void {
        $this->mockCurrentTime();

        $job = new Job();

        $result = (new JobRepository())->update($job);

        $this->assertFalse($result);
    }

    public function test_update_delegates_to_database(): void {
        $this->mockCurrentTime();

        $job = Job::create('post_generation', ['keyword_id' => 1]);
        $job->setId(5);
        $job->setStatus(JobStatus::DONE);

        $this->dbMock->shouldReceive('update')
            ->once()
            ->with('contai_jobs', Mockery::type('array'), ['id' => 5])
            ->andReturn(1);

        $result = (new JobRepository())->update($job);

        $this->assertTrue($result);
    }

    public function test_find_by_id_returns_job_when_found(): void {
        $this->mockCurrentTime();

        $rowData = [
            'id' => 5,
            'job_type' => 'post_generation',
            'status' => 'pending',
            'payload' => '{"keyword_id":42}',
            'priority' => 0,
            'attempts' => 0,
            'max_attempts' => 3,
            'error_message' => null,
            'created_at' => '2025-01-15 10:00:00',
            'updated_at' => '2025-01-15 10:00:00',
            'processed_at' => null,
        ];

        $this->dbMock->shouldReceive('getTableName')
            ->with('contai_jobs')
            ->andReturn('wp_contai_jobs');

        $this->dbMock->shouldReceive('prepare')
            ->once()
            ->andReturn('SELECT * FROM wp_contai_jobs WHERE id = 5');

        $this->dbMock->shouldReceive('getRow')
            ->once()
            ->andReturn($rowData);

        $job = (new JobRepository())->findById(5);

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame(5, $job->getId());
        $this->assertSame('post_generation', $job->getJobType());
        $this->assertSame(JobStatus::PENDING, $job->getStatus());
    }

    public function test_find_by_id_returns_null_when_not_found(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')->andReturn(null);

        $result = (new JobRepository())->findById(999);

        $this->assertNull($result);
    }

    public function test_find_by_status_returns_hydrated_jobs(): void {
        $this->mockCurrentTime();

        $rows = [
            [
                'id' => 1,
                'job_type' => 'post_generation',
                'status' => 'pending',
                'payload' => '{}',
                'priority' => 0,
                'attempts' => 0,
                'max_attempts' => 3,
                'error_message' => null,
                'created_at' => '2025-01-15 10:00:00',
                'updated_at' => '2025-01-15 10:00:00',
                'processed_at' => null,
            ],
        ];

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getResults')->andReturn($rows);

        $jobs = (new JobRepository())->findByStatus(JobStatus::PENDING);

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(Job::class, $jobs[0]);
    }

    public function test_find_by_status_with_limit(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getResults')->andReturn([]);

        $jobs = (new JobRepository())->findByStatus(JobStatus::PENDING, 5, 0);

        $this->assertIsArray($jobs);
    }

    public function test_count_by_status(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('3');

        $count = (new JobRepository())->countByStatus(JobStatus::PENDING);

        $this->assertSame(3, $count);
    }

    public function test_count_pending_jobs(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('7');

        $this->assertSame(7, (new JobRepository())->countPendingJobs());
    }

    public function test_delete_by_id(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('delete')
            ->with('contai_jobs', ['id' => 5])
            ->once()
            ->andReturn(1);

        $this->assertTrue((new JobRepository())->deleteById(5));
    }

    public function test_delete_by_id_returns_false_when_not_found(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('delete')
            ->once()
            ->andReturn(0);

        $this->assertFalse((new JobRepository())->deleteById(999));
    }

    public function test_find_stuck_jobs(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getResults')->andReturn([]);

        $jobs = (new JobRepository())->findStuckJobs(30);

        $this->assertIsArray($jobs);
        $this->assertEmpty($jobs);
    }

    public function test_delete_all_active_jobs(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('DELETE query');
        $this->dbMock->shouldReceive('query')
            ->once()
            ->andReturn(true);

        $result = (new JobRepository())->deleteAllActiveJobs();

        $this->assertTrue($result);
    }

    public function test_has_pending_job_for_post_returns_true(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('1');

        $result = (new JobRepository())->hasPendingJobForPost('internal_link', 42);

        $this->assertTrue($result);
    }

    public function test_has_pending_job_for_post_returns_false(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('0');

        $result = (new JobRepository())->hasPendingJobForPost('internal_link', 42);

        $this->assertFalse($result);
    }

    public function test_hydrate_reconstructs_attempts(): void {
        $this->mockCurrentTime();

        $rowData = [
            'id' => 1,
            'job_type' => 'post_generation',
            'status' => 'failed',
            'payload' => '{}',
            'priority' => 0,
            'attempts' => 3,
            'max_attempts' => 3,
            'error_message' => 'API timeout',
            'created_at' => '2025-01-15 10:00:00',
            'updated_at' => '2025-01-15 10:00:00',
            'processed_at' => '2025-01-15 10:05:00',
        ];

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_jobs');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')->andReturn($rowData);

        $job = (new JobRepository())->findById(1);

        $this->assertSame(3, $job->getAttempts());
        $this->assertSame('API timeout', $job->getErrorMessage());
        $this->assertSame('2025-01-15 10:05:00', $job->getProcessedAt());
    }
}
