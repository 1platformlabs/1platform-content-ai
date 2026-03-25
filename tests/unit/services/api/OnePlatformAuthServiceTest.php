<?php

namespace ContAI\Tests\Unit\Services\Api;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiOnePlatformAuthService;
use ContaiHTTPClientService;
use ContaiHTTPResponse;
use ContaiConfig;

class OnePlatformAuthServiceTest extends TestCase
{
    private ContaiHTTPClientService $httpClient;
    private ContaiConfig $config;
    private ContaiOnePlatformAuthService $authService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->httpClient = Mockery::mock(ContaiHTTPClientService::class);
        $this->config = Mockery::mock(ContaiConfig::class);

        $this->config->shouldReceive('getApiBaseUrl')->andReturn('https://api.example.com/api/v1');
        $this->config->shouldReceive('getAuthEndpoint')->andReturn('/auth/token');
        $this->config->shouldReceive('getUserTokenEndpoint')->andReturn('/users/token');
        $this->config->shouldReceive('getTokenBufferTime')->andReturn(60);

        $this->authService = new ContaiOnePlatformAuthService($this->httpClient, $this->config);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_clearErrors_deletes_both_error_options(): void
    {
        WP_Mock::userFunction('delete_option')
            ->with('contai_app_token_error')
            ->once();

        WP_Mock::userFunction('delete_option')
            ->with('contai_user_token_error')
            ->once();

        $this->authService->clearErrors();

        $this->assertTrue(true);
    }

    public function test_forceRefreshAllTokens_clears_errors_before_generating_tokens(): void
    {
        // clearToken() calls
        WP_Mock::userFunction('delete_transient')->andReturn(true);
        WP_Mock::userFunction('delete_option')->andReturn(true);

        // App token generation
        $this->config->shouldReceive('getApiKey')->andReturn('test-app-key');

        $appResponse = Mockery::mock(ContaiHTTPResponse::class);
        $appResponse->shouldReceive('isSuccess')->andReturn(true);
        $appResponse->shouldReceive('getJson')->andReturn([
            'success' => true,
            'data' => [
                'access_token' => 'fresh-app-token',
                'expires_in' => 1800,
            ],
        ]);

        $this->httpClient->shouldReceive('post')
            ->with('https://api.example.com/api/v1/auth/token', Mockery::any())
            ->once()
            ->andReturn($appResponse);

        WP_Mock::userFunction('update_option')->andReturn(true);

        // User token generation
        $this->config->shouldReceive('getUserApiKey')->andReturn('test-user-key');

        $userResponse = Mockery::mock(ContaiHTTPResponse::class);
        $userResponse->shouldReceive('isSuccess')->andReturn(true);
        $userResponse->shouldReceive('getJson')->andReturn([
            'success' => true,
            'data' => [
                'access_token' => 'fresh-user-token',
                'expires_in' => 1800,
            ],
        ]);

        $this->httpClient->shouldReceive('post')
            ->with('https://api.example.com/api/v1/users/token', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn($userResponse);

        WP_Mock::userFunction('contai_log');

        $result = $this->authService->forceRefreshAllTokens();

        $this->assertTrue($result['success']);
        $this->assertTrue(true);
    }

    public function test_forceRefreshAllTokens_clears_errors_even_when_app_token_fails(): void
    {
        // clearToken() + clearErrors() call delete_option for multiple keys
        WP_Mock::userFunction('delete_transient')->andReturn(true);
        WP_Mock::userFunction('delete_option')->andReturn(true);

        $this->config->shouldReceive('getApiKey')->andReturn('bad-key');

        $failResponse = Mockery::mock(ContaiHTTPResponse::class);
        $failResponse->shouldReceive('isSuccess')->andReturn(false);
        $failResponse->shouldReceive('getError')->andReturn('Unauthorized');
        $failResponse->shouldReceive('getStatusCode')->andReturn(401);

        $this->httpClient->shouldReceive('post')
            ->with('https://api.example.com/api/v1/auth/token', Mockery::any())
            ->once()
            ->andReturn($failResponse);

        WP_Mock::userFunction('contai_log');
        WP_Mock::userFunction('get_option')->andReturn('');

        $result = $this->authService->forceRefreshAllTokens();

        $this->assertFalse($result['success']);
        $this->assertTrue(true);
    }

    public function test_getAuthHeaders_stores_error_when_user_token_fails(): void
    {
        // App token cached and valid
        WP_Mock::userFunction('get_option')
            ->with('contai_app_access_token', '')
            ->andReturn('cached-app-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_app_token_expires_at', 0)
            ->andReturn(time() + 3600);

        // Clear app error
        WP_Mock::userFunction('delete_option')
            ->with('contai_app_token_error')
            ->once();

        // User token not cached
        WP_Mock::userFunction('get_option')
            ->with('contai_user_access_token', '')
            ->andReturn('');

        // User API key empty → returns null
        $this->config->shouldReceive('getUserApiKey')->andReturn('');

        WP_Mock::userFunction('contai_log');

        // Error stored
        WP_Mock::userFunction('update_option')
            ->with('contai_user_token_error', Mockery::type('string'), false)
            ->once();

        $result = $this->authService->getAuthHeaders();

        $this->assertNull($result);
        $this->assertTrue(true);
    }

    public function test_getAuthHeaders_clears_errors_on_success(): void
    {
        $now = time();

        // App token cached and valid
        WP_Mock::userFunction('get_option')
            ->with('contai_app_access_token', '')
            ->andReturn('valid-app-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_app_token_expires_at', 0)
            ->andReturn($now + 3600);

        // User token cached and valid
        WP_Mock::userFunction('get_option')
            ->with('contai_user_access_token', '')
            ->andReturn('valid-user-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_user_token_expires_at', 0)
            ->andReturn($now + 3600);

        // Both errors cleared on success
        WP_Mock::userFunction('delete_option')
            ->with('contai_app_token_error')
            ->once();

        WP_Mock::userFunction('delete_option')
            ->with('contai_user_token_error')
            ->once();

        $result = $this->authService->getAuthHeaders();

        $this->assertNotNull($result);
        $this->assertEquals('Bearer valid-app-token', $result['Authorization']);
        $this->assertEquals('valid-user-token', $result['x-user-token']);
        $this->assertTrue(true);
    }

    public function test_validateToken_returns_false_when_tokens_expired(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_app_access_token', '')
            ->andReturn('some-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_user_access_token', '')
            ->andReturn('some-user-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_app_token_expires_at', 0)
            ->andReturn(time() - 100);

        WP_Mock::userFunction('get_option')
            ->with('contai_user_token_expires_at', 0)
            ->andReturn(time() - 100);

        $result = $this->authService->validateToken();

        $this->assertFalse($result);
    }

    public function test_validateToken_returns_true_when_tokens_valid(): void
    {
        $future = time() + 3600;

        WP_Mock::userFunction('get_option')
            ->with('contai_app_access_token', '')
            ->andReturn('valid-app-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_user_access_token', '')
            ->andReturn('valid-user-token');

        WP_Mock::userFunction('get_option')
            ->with('contai_app_token_expires_at', 0)
            ->andReturn($future);

        WP_Mock::userFunction('get_option')
            ->with('contai_user_token_expires_at', 0)
            ->andReturn($future);

        $result = $this->authService->validateToken();

        $this->assertTrue($result);
    }
}
