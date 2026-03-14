<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use JobDetailsFormatter;

class JobDetailsFormatterTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @dataProvider jobTypeProvider
     */
    public function test_format_job_type_with_known_types(string $type, string $expected): void {
        $this->assertSame($expected, JobDetailsFormatter::formatJobType($type));
    }

    public function jobTypeProvider(): array {
        return [
            'post_generation' => ['post_generation', 'Post Generation'],
            'internal_link' => ['internal_link', 'Internal Links'],
            'keyword_extraction' => ['keyword_extraction', 'Keyword Extraction'],
            'site_generation' => ['site_generation', 'Site Generation'],
        ];
    }

    public function test_format_job_type_with_unknown_type_formats_nicely(): void {
        $this->assertSame('Custom Job Type', JobDetailsFormatter::formatJobType('custom_job_type'));
    }

    public function test_format_status_returns_html_with_color(): void {
        WP_Mock::userFunction('esc_attr')->andReturnArg(0);
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $result = JobDetailsFormatter::formatStatus('pending');

        $this->assertStringContainsString('#0073aa', $result);
        $this->assertStringContainsString('Pending', $result);
    }

    public function test_format_status_with_unknown_status(): void {
        WP_Mock::userFunction('esc_attr')->andReturnArg(0);
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $result = JobDetailsFormatter::formatStatus('unknown');

        $this->assertStringContainsString('Unknown', $result);
        $this->assertStringContainsString('#000', $result);
    }

    /**
     * @dataProvider durationProvider
     */
    public function test_format_duration(int $seconds, string $expected): void {
        $this->assertSame($expected, JobDetailsFormatter::formatDuration($seconds));
    }

    public function durationProvider(): array {
        return [
            'seconds only' => [45, '45s'],
            'minutes and seconds' => [125, '2m 5s'],
            'hours and minutes' => [3725, '1h 2m'],
            'zero seconds' => [0, '0s'],
            'exactly one minute' => [60, '1m 0s'],
            'exactly one hour' => [3600, '1h 0m'],
        ];
    }

    public function test_format_duration_with_null(): void {
        $this->assertSame('N/A', JobDetailsFormatter::formatDuration(null));
    }

    public function test_format_duration_with_negative(): void {
        $this->assertSame('N/A', JobDetailsFormatter::formatDuration(-5));
    }

    public function test_format_payload_summary_with_keyword_id(): void {
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $payload = json_encode(['keyword_id' => 42]);
        $result = JobDetailsFormatter::formatPayloadSummary($payload);

        $this->assertStringContainsString('Keyword ID: 42', $result);
    }

    public function test_format_payload_summary_with_multiple_fields(): void {
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $payload = json_encode(['keyword_id' => 1, 'post_id' => 99, 'keyword' => 'seo tips']);
        $result = JobDetailsFormatter::formatPayloadSummary($payload);

        $this->assertStringContainsString('Keyword ID: 1', $result);
        $this->assertStringContainsString('Post ID: 99', $result);
        $this->assertStringContainsString('Keyword: "seo tips"', $result);
    }

    public function test_format_payload_summary_with_invalid_json(): void {
        $this->assertSame('Invalid payload', JobDetailsFormatter::formatPayloadSummary('not json'));
    }

    public function test_format_payload_summary_with_empty_payload(): void {
        $this->assertSame('No details', JobDetailsFormatter::formatPayloadSummary('{}'));
    }

    public function test_format_priority_high(): void {
        $result = JobDetailsFormatter::formatPriority(10);
        $this->assertStringContainsString('#dc3232', $result);
        $this->assertStringContainsString('High', $result);
    }

    public function test_format_priority_medium(): void {
        $result = JobDetailsFormatter::formatPriority(5);
        $this->assertStringContainsString('#f0b849', $result);
        $this->assertStringContainsString('Medium', $result);
    }

    public function test_format_priority_normal(): void {
        $result = JobDetailsFormatter::formatPriority(2);
        $this->assertStringContainsString('#72777c', $result);
        $this->assertStringContainsString('Normal', $result);
    }

    public function test_format_attempts_low_percentage(): void {
        $result = JobDetailsFormatter::formatAttempts(1, 5);
        $this->assertStringContainsString('#46b450', $result);
        $this->assertStringContainsString('1 / 5', $result);
    }

    public function test_format_attempts_medium_percentage(): void {
        $result = JobDetailsFormatter::formatAttempts(3, 5);
        $this->assertStringContainsString('#f0b849', $result);
    }

    public function test_format_attempts_high_percentage(): void {
        $result = JobDetailsFormatter::formatAttempts(4, 5);
        $this->assertStringContainsString('#dc3232', $result);
    }

    public function test_format_attempts_zero_max(): void {
        $result = JobDetailsFormatter::formatAttempts(0, 0);
        $this->assertStringContainsString('0 / 0', $result);
    }

    public function test_format_date_time_with_null(): void {
        $this->assertSame('N/A', JobDetailsFormatter::formatDateTime(null));
    }

    public function test_format_date_time_with_empty_string(): void {
        $this->assertSame('N/A', JobDetailsFormatter::formatDateTime(''));
    }

    public function test_get_status_badge_class(): void {
        $this->assertSame('contai-badge-info', JobDetailsFormatter::getStatusBadgeClass('pending'));
        $this->assertSame('contai-badge-warning', JobDetailsFormatter::getStatusBadgeClass('processing'));
        $this->assertSame('contai-badge-success', JobDetailsFormatter::getStatusBadgeClass('done'));
        $this->assertSame('contai-badge-danger', JobDetailsFormatter::getStatusBadgeClass('failed'));
        $this->assertSame('contai-badge-default', JobDetailsFormatter::getStatusBadgeClass('unknown'));
    }

    public function test_is_job_stuck_returns_false_for_non_processing(): void {
        $job = ['status' => 'pending', 'processed_at' => '2020-01-01 00:00:00'];
        $this->assertFalse(JobDetailsFormatter::isJobStuck($job));
    }

    public function test_is_job_stuck_returns_false_for_empty_processed_at(): void {
        $job = ['status' => 'processing', 'processed_at' => ''];
        $this->assertFalse(JobDetailsFormatter::isJobStuck($job));
    }

    public function test_is_job_stuck_returns_true_for_old_processing_job(): void {
        $oldTime = gmdate('Y-m-d H:i:s', time() - 3600);
        $job = ['status' => 'processing', 'processed_at' => $oldTime];

        $this->assertTrue(JobDetailsFormatter::isJobStuck($job));
    }

    public function test_is_job_stuck_returns_false_for_recent_processing_job(): void {
        $recentTime = gmdate('Y-m-d H:i:s', time() - 60);
        $job = ['status' => 'processing', 'processed_at' => $recentTime];

        $this->assertFalse(JobDetailsFormatter::isJobStuck($job));
    }

    public function test_format_payload_summary_truncates_long_title(): void {
        WP_Mock::userFunction('esc_html')->andReturnArg(0);

        $longTitle = str_repeat('A', 100);
        $payload = json_encode(['title' => $longTitle]);
        $result = JobDetailsFormatter::formatPayloadSummary($payload);

        $this->assertStringContainsString('...', $result);
    }
}
