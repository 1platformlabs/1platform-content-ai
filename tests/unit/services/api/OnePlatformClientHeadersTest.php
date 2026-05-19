<?php

namespace ContAI\Tests\Unit\Services\Api;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use ReflectionClass;
use Mockery;
use ContaiOnePlatformClient;
use ContaiOnePlatformAuthService;
use ContaiHTTPClientService;
use ContaiHTTPResponse;
use ContaiRateLimiter;
use ContaiRequestLogger;
use ContaiConfig;

/**
 * Locks in that every outbound API call advertises the originating WordPress
 * site URL via the X-Site-URL header. The 1Platform API uses this header to
 * map heartbeats to the Website document and to ping only sites with PENDING
 * jobs (Phase 2 of the queue recovery plan).
 */
class OnePlatformClientHeadersTest extends TestCase
{
    private ContaiOnePlatformClient $client;
    private $authService;
    private $httpClient;
    private $rateLimiter;
    private $config;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        if (!defined('CONTAI_VERSION')) {
            define('CONTAI_VERSION', '2.36.0');
        }

        $this->authService = Mockery::mock(ContaiOnePlatformAuthService::class);
        $this->httpClient = Mockery::mock(ContaiHTTPClientService::class);
        $this->rateLimiter = Mockery::mock(ContaiRateLimiter::class);
        $this->config = Mockery::mock(ContaiConfig::class);

        $this->authService->shouldReceive('getAuthHeaders')->andReturn(['Authorization' => 'Bearer test']);
        $this->rateLimiter->shouldReceive('allow')->andReturn(true);
        $this->config->shouldReceive('getMaxRetries')->andReturn(1);
        $this->config->shouldReceive('getApiBaseUrl')->andReturn('https://api.example.test/api/v1');

        $this->client = new ContaiOnePlatformClient(
            $this->authService,
            $this->httpClient,
            $this->rateLimiter,
            Mockery::mock(ContaiRequestLogger::class)->shouldIgnoreMissing(),
            $this->config
        );
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_sendsXSiteUrlHeaderOnAllRequests(): void
    {
        $captured = [];

        WP_Mock::userFunction('home_url')->andReturn('https://wpcontentclub.local');

        $okResponse = new ContaiHTTPResponse(200, '{"success":true}', [], null);

        $this->httpClient->shouldReceive('get')
            ->andReturnUsing(function ($url, $headers) use (&$captured, $okResponse) {
                $captured['GET'] = $headers;
                return $okResponse;
            });
        $this->httpClient->shouldReceive('post')
            ->andReturnUsing(function ($url, $data, $headers) use (&$captured, $okResponse) {
                $captured['POST'] = $headers;
                return $okResponse;
            });
        $this->httpClient->shouldReceive('put')
            ->andReturnUsing(function ($url, $data, $headers) use (&$captured, $okResponse) {
                $captured['PUT'] = $headers;
                return $okResponse;
            });
        $this->httpClient->shouldReceive('patch')
            ->andReturnUsing(function ($url, $data, $headers) use (&$captured, $okResponse) {
                $captured['PATCH'] = $headers;
                return $okResponse;
            });
        $this->httpClient->shouldReceive('delete')
            ->andReturnUsing(function ($url, $headers) use (&$captured, $okResponse) {
                $captured['DELETE'] = $headers;
                return $okResponse;
            });

        $this->client->get('/health');
        $this->client->post('/posts/content', ['k' => 'v']);
        $this->client->put('/posts/content/abc', ['k' => 'v']);
        $this->client->patch('/posts/content/abc', ['k' => 'v']);
        $this->client->delete('/posts/content/abc');

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertArrayHasKey('X-Site-URL', $captured[$method], "X-Site-URL must be present on {$method}");
            $this->assertSame('https://wpcontentclub.local', $captured[$method]['X-Site-URL']);
            $this->assertArrayHasKey('X-Plugin-Version', $captured[$method], "X-Plugin-Version regression: must remain present on {$method}");
        }
    }
}
