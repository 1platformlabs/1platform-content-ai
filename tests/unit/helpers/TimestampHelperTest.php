<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use TimestampHelper;

class TimestampHelperTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_is_valid_timestamp_returns_false_for_null(): void {
        $this->assertFalse(TimestampHelper::isValidTimestamp(null));
    }

    public function test_is_valid_timestamp_returns_false_for_empty_string(): void {
        $this->assertFalse(TimestampHelper::isValidTimestamp(''));
    }

    public function test_is_valid_timestamp_returns_false_for_invalid_format(): void {
        $this->assertFalse(TimestampHelper::isValidTimestamp('not-a-date'));
    }

    public function test_is_valid_timestamp_returns_true_for_past_timestamp(): void {
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn(time());

        $pastTime = gmdate('Y-m-d H:i:s', time() - 3600);
        $this->assertTrue(TimestampHelper::isValidTimestamp($pastTime));
    }

    public function test_is_valid_timestamp_returns_false_for_far_future_timestamp(): void {
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn(time());

        $farFuture = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->assertFalse(TimestampHelper::isValidTimestamp($farFuture));
    }

    public function test_is_in_future_returns_true_for_future_timestamp(): void {
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn(time());

        $futureTime = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->assertTrue(TimestampHelper::isInFuture($futureTime));
    }

    public function test_is_in_future_returns_false_for_past_timestamp(): void {
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn(time());

        $pastTime = gmdate('Y-m-d H:i:s', time() - 3600);
        $this->assertFalse(TimestampHelper::isInFuture($pastTime));
    }

    public function test_get_age_in_seconds_returns_positive_for_past(): void {
        $now = time();
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn($now);

        $pastTime = gmdate('Y-m-d H:i:s', $now - 120);
        $age = TimestampHelper::getAgeInSeconds($pastTime);

        $this->assertSame(120, $age);
    }

    public function test_get_age_in_seconds_returns_zero_for_future(): void {
        $now = time();
        WP_Mock::userFunction('current_time')
            ->with('timestamp')
            ->andReturn($now);

        $futureTime = gmdate('Y-m-d H:i:s', $now + 3600);
        $age = TimestampHelper::getAgeInSeconds($futureTime);

        $this->assertSame(0, $age);
    }

    public function test_get_current_mysql_timestamp(): void {
        WP_Mock::userFunction('current_time')
            ->with('mysql')
            ->andReturn('2025-01-15 10:00:00');

        $this->assertSame('2025-01-15 10:00:00', TimestampHelper::getCurrentMySQLTimestamp());
    }
}
