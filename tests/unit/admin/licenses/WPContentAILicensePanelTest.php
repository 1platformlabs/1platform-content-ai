<?php

namespace ContAI\Tests\Unit\Admin\Licenses;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use WPContentAILicensePanel;
use ContaiUserProfileService;
use ContaiOnePlatformAuthService;
use ContaiOnePlatformResponse;
use ContaiWebsiteProvider;

class WPContentAILicensePanelTest extends TestCase
{
    private WPContentAILicensePanel $panel;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_validateConnectionStatus_clears_stale_errors_on_success(): void
    {
        $service = Mockery::mock(ContaiUserProfileService::class);
        $authService = Mockery::mock(ContaiOnePlatformAuthService::class);

        $service->shouldReceive('fetchUserProfile')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['id' => '123', 'username' => 'test', 'is_active' => true], 'OK', 200));

        $service->shouldReceive('saveUserProfile')
            ->once()
            ->with(Mockery::type('array'));

        $authService->shouldReceive('clearErrors')->once();

        $panel = new WPContentAILicensePanel($service, $authService);

        $method = new \ReflectionMethod($panel, 'validateConnectionStatus');
        $method->setAccessible(true);

        $result = $method->invoke($panel);

        $this->assertTrue($result);
    }

    public function test_validateConnectionStatus_clears_errors_after_successful_retry(): void
    {
        $service = Mockery::mock(ContaiUserProfileService::class);
        $authService = Mockery::mock(ContaiOnePlatformAuthService::class);

        // First attempt fails
        $service->shouldReceive('fetchUserProfile')
            ->twice()
            ->andReturn(
                new ContaiOnePlatformResponse(false, null, 'Auth failed', 401),
                new ContaiOnePlatformResponse(true, ['id' => '123', 'username' => 'test', 'is_active' => true], 'OK', 200)
            );

        $service->shouldReceive('saveUserProfile')->once();

        WP_Mock::userFunction('contai_log');

        // Force refresh succeeds
        $authService->shouldReceive('forceRefreshAllTokens')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Tokens refreshed']);

        // Errors cleared after retry success
        $authService->shouldReceive('clearErrors')->once();

        $panel = new WPContentAILicensePanel($service, $authService);

        $method = new \ReflectionMethod($panel, 'validateConnectionStatus');
        $method->setAccessible(true);

        $result = $method->invoke($panel);

        $this->assertTrue($result);
    }

    public function test_validateConnectionStatus_does_not_clear_errors_on_failure(): void
    {
        $service = Mockery::mock(ContaiUserProfileService::class);
        $authService = Mockery::mock(ContaiOnePlatformAuthService::class);

        // Both attempts fail
        $service->shouldReceive('fetchUserProfile')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Auth failed', 401));

        WP_Mock::userFunction('contai_log');

        $authService->shouldReceive('forceRefreshAllTokens')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Failed']);

        // clearErrors should NOT be called
        $authService->shouldNotReceive('clearErrors');

        $panel = new WPContentAILicensePanel($service, $authService);

        $method = new \ReflectionMethod($panel, 'validateConnectionStatus');
        $method->setAccessible(true);

        $result = $method->invoke($panel);

        $this->assertFalse($result);
    }
}
