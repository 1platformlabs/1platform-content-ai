<?php

namespace ContAI\Tests\Unit\Models;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use JobStatus;

class JobStatusTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_pending_constant_value(): void {
        $this->assertSame('pending', JobStatus::PENDING);
    }

    public function test_processing_constant_value(): void {
        $this->assertSame('processing', JobStatus::PROCESSING);
    }

    public function test_done_constant_value(): void {
        $this->assertSame('done', JobStatus::DONE);
    }

    public function test_failed_constant_value(): void {
        $this->assertSame('failed', JobStatus::FAILED);
    }

    public function test_all_returns_complete_status_list(): void {
        $expected = ['pending', 'processing', 'done', 'failed'];
        $this->assertSame($expected, JobStatus::all());
    }

    /**
     * @dataProvider validStatusProvider
     */
    public function test_is_valid_returns_true_for_valid_statuses(string $status): void {
        $this->assertTrue(JobStatus::isValid($status));
    }

    public function validStatusProvider(): array {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
            'done' => ['done'],
            'failed' => ['failed'],
        ];
    }

    /**
     * @dataProvider invalidStatusProvider
     */
    public function test_is_valid_returns_false_for_invalid_statuses(string $status): void {
        $this->assertFalse(JobStatus::isValid($status));
    }

    public function invalidStatusProvider(): array {
        return [
            'empty string' => [''],
            'random string' => ['unknown'],
            'uppercase' => ['PENDING'],
            'mixed case' => ['Pending'],
            'with spaces' => [' pending '],
        ];
    }
}
