<?php

namespace ContAI\Tests\Unit\Services\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Mockery;
use ContaiOnePlatformClient;
use ContaiOnePlatformResponse;
use ContaiHTTPResponse;
use ContaiOnePlatformAuthService;
use ContaiHTTPClientService;
use ContaiRateLimiter;
use ContaiRequestLogger;
use ContaiConfig;

/**
 * Regression tests for createErrorResponse fallback behavior (issue #100).
 *
 * When the API returns a non-2xx status with a non-JSON body (e.g. an upstream
 * gateway HTML page), every diagnostic field is null and the user previously
 * saw the bare string "Request failed". These tests lock in that the fallback
 * now includes the HTTP status code.
 */
class OnePlatformClientErrorResponseTest extends TestCase
{
    private ContaiOnePlatformClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new ContaiOnePlatformClient(
            Mockery::mock(ContaiOnePlatformAuthService::class),
            Mockery::mock(ContaiHTTPClientService::class),
            Mockery::mock(ContaiRateLimiter::class),
            Mockery::mock(ContaiRequestLogger::class),
            Mockery::mock(ContaiConfig::class),
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fallback_includes_http_status_when_no_json_and_no_wp_error(): void
    {
        $http_response = new ContaiHTTPResponse(502, '<html>502 Bad Gateway</html>', [], null);

        $platform_response = $this->invokeCreateErrorResponse(null, $http_response);

        $this->assertFalse($platform_response->isSuccess());
        $this->assertSame(502, $platform_response->getStatusCode());
        $this->assertSame('Request failed (HTTP 502)', $platform_response->getMessage());
    }

    public function test_fallback_keeps_bare_message_when_status_is_zero(): void
    {
        $http_response = new ContaiHTTPResponse(0, null, [], null);

        $platform_response = $this->invokeCreateErrorResponse(null, $http_response);

        $this->assertSame('Request failed', $platform_response->getMessage());
    }

    public function test_api_msg_field_takes_precedence_over_fallback(): void
    {
        $http_response = new ContaiHTTPResponse(502, '{"msg":"Search provider unavailable"}', [], null);

        $platform_response = $this->invokeCreateErrorResponse(
            ['msg' => 'Search provider unavailable'],
            $http_response
        );

        $this->assertSame('Search provider unavailable', $platform_response->getMessage());
    }

    public function test_wp_error_message_takes_precedence_over_fallback(): void
    {
        $http_response = new ContaiHTTPResponse(0, null, [], 'cURL error 28: Operation timed out');

        $platform_response = $this->invokeCreateErrorResponse(null, $http_response);

        $this->assertSame('cURL error 28: Operation timed out', $platform_response->getMessage());
    }

    private function invokeCreateErrorResponse(?array $json, ContaiHTTPResponse $http_response): ContaiOnePlatformResponse
    {
        $method = (new ReflectionClass(ContaiOnePlatformClient::class))->getMethod('createErrorResponse');
        $method->setAccessible(true);
        return $method->invoke($this->client, $json, $http_response, null);
    }
}
