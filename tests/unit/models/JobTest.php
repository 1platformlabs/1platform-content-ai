<?php

namespace ContAI\Tests\Unit\Models;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use Job;
use JobStatus;

class JobTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function mockCurrentTime(): void {
        WP_Mock::userFunction('current_time')
            ->andReturn('2025-01-15 10:00:00');
    }

    public function test_constructor_sets_defaults(): void {
        $this->mockCurrentTime();

        $job = new Job();

        $this->assertSame(JobStatus::PENDING, $job->getStatus());
        $this->assertSame(0, $job->getPriority());
        $this->assertSame(0, $job->getAttempts());
        $this->assertSame(3, $job->getMaxAttempts());
    }

    public function test_create_factory_sets_properties(): void {
        $this->mockCurrentTime();

        $job = Job::create('post_generation', ['keyword_id' => 42], 5);

        $this->assertSame('post_generation', $job->getJobType());
        $this->assertSame(['keyword_id' => 42], $job->getPayload());
        $this->assertSame(5, $job->getPriority());
        $this->assertSame(JobStatus::PENDING, $job->getStatus());
    }

    public function test_set_status_with_valid_status(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setStatus(JobStatus::PROCESSING);

        $this->assertSame(JobStatus::PROCESSING, $job->getStatus());
    }

    public function test_set_status_with_invalid_status_throws_exception(): void {
        $this->mockCurrentTime();

        $job = new Job();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid job status: invalid');
        $job->setStatus('invalid');
    }

    public function test_increment_attempts(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $this->assertSame(0, $job->getAttempts());

        $job->incrementAttempts();
        $this->assertSame(1, $job->getAttempts());

        $job->incrementAttempts();
        $this->assertSame(2, $job->getAttempts());
    }

    public function test_has_reached_max_attempts(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setMaxAttempts(2);

        $this->assertFalse($job->hasReachedMaxAttempts());

        $job->incrementAttempts();
        $this->assertFalse($job->hasReachedMaxAttempts());

        $job->incrementAttempts();
        $this->assertTrue($job->hasReachedMaxAttempts());
    }

    public function test_mark_as_processing(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->markAsProcessing();

        $this->assertSame(JobStatus::PROCESSING, $job->getStatus());
        $this->assertSame('2025-01-15 10:00:00', $job->getProcessedAt());
    }

    public function test_mark_as_completed(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->markAsCompleted();

        $this->assertSame(JobStatus::DONE, $job->getStatus());
    }

    public function test_mark_as_failed_without_message(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->markAsFailed();

        $this->assertSame(JobStatus::FAILED, $job->getStatus());
        $this->assertNull($job->getErrorMessage());
    }

    public function test_mark_as_failed_with_message(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->markAsFailed('API timeout');

        $this->assertSame(JobStatus::FAILED, $job->getStatus());
        $this->assertSame('API timeout', $job->getErrorMessage());
    }

    public function test_set_payload_with_array(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setPayload(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $job->getPayload());
    }

    public function test_set_payload_with_json_string(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setPayload('{"key":"value"}');

        $this->assertSame(['key' => 'value'], $job->getPayload());
    }

    public function test_to_array_returns_complete_data(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setId(1);
        $job->setJobType('post_generation');
        $job->setPayload(['keyword_id' => 10]);
        $job->setPriority(5);

        $array = $job->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame('post_generation', $array['job_type']);
        $this->assertSame(JobStatus::PENDING, $array['status']);
        $this->assertSame(5, $array['priority']);
        $this->assertSame(0, $array['attempts']);
        $this->assertSame(3, $array['max_attempts']);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertArrayHasKey('updated_at', $array);
    }

    public function test_id_getter_setter(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $this->assertNull($job->getId());

        $job->setId(42);
        $this->assertSame(42, $job->getId());
    }

    public function test_max_attempts_getter_setter(): void {
        $this->mockCurrentTime();

        $job = new Job();
        $job->setMaxAttempts(5);

        $this->assertSame(5, $job->getMaxAttempts());
    }
}
