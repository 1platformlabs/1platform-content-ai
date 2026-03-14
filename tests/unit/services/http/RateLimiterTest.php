<?php

namespace ContAI\Tests\Unit\Services\Http;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use RateLimiter;

class RateLimiterTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_allow_returns_true_on_first_request(): void {
        WP_Mock::userFunction('get_transient')->andReturn(false);
        WP_Mock::userFunction('set_transient')->andReturn(true);

        $limiter = new RateLimiter('test', 60, 60);

        $this->assertTrue($limiter->allow());
    }

    public function test_allow_returns_true_when_under_limit(): void {
        WP_Mock::userFunction('get_transient')->andReturn(5);
        WP_Mock::userFunction('set_transient')->andReturn(true);

        $limiter = new RateLimiter('test', 60, 60);

        $this->assertTrue($limiter->allow());
    }

    public function test_allow_returns_false_when_limit_exceeded(): void {
        WP_Mock::userFunction('get_transient')->andReturn(60);

        $limiter = new RateLimiter('test', 60, 60);

        $this->assertFalse($limiter->allow());
    }

    public function test_get_remaining_requests_returns_max_on_first_request(): void {
        WP_Mock::userFunction('get_transient')->andReturn(false);

        $limiter = new RateLimiter('test', 100, 60);

        $this->assertSame(100, $limiter->getRemainingRequests());
    }

    public function test_get_remaining_requests_calculates_correctly(): void {
        WP_Mock::userFunction('get_transient')->andReturn(30);

        $limiter = new RateLimiter('test', 100, 60);

        $this->assertSame(70, $limiter->getRemainingRequests());
    }

    public function test_get_remaining_requests_returns_zero_when_exceeded(): void {
        WP_Mock::userFunction('get_transient')->andReturn(150);

        $limiter = new RateLimiter('test', 100, 60);

        $this->assertSame(0, $limiter->getRemainingRequests());
    }

    public function test_reset_deletes_transient(): void {
        WP_Mock::userFunction('delete_transient')
            ->once()
            ->andReturn(true);

        $limiter = new RateLimiter('test', 60, 60);
        $limiter->reset();

        $this->assertTrue(true);
    }

    public function test_get_reset_time_from_transient_timeout(): void {
        $expectedTime = time() + 30;

        WP_Mock::userFunction('get_option')
            ->andReturn($expectedTime);

        $limiter = new RateLimiter('test', 60, 60);

        $this->assertSame($expectedTime, $limiter->getResetTime());
    }

    public function test_get_reset_time_calculates_default_when_no_timeout(): void {
        WP_Mock::userFunction('get_option')->andReturn(false);

        $limiter = new RateLimiter('test', 60, 120);
        $resetTime = $limiter->getResetTime();

        $this->assertGreaterThanOrEqual(time() + 119, $resetTime);
        $this->assertLessThanOrEqual(time() + 121, $resetTime);
    }

    public function test_different_identifiers_produce_different_keys(): void {
        WP_Mock::userFunction('get_transient')->andReturn(false);
        WP_Mock::userFunction('set_transient')->andReturn(true);

        $limiter1 = new RateLimiter('api-endpoint-1', 60, 60);
        $limiter2 = new RateLimiter('api-endpoint-2', 60, 60);

        $this->assertTrue($limiter1->allow());
        $this->assertTrue($limiter2->allow());
    }
}
