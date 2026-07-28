<?php

namespace ContAI\Tests\Unit\Services\SearchConsole;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSearchConsolePerformanceRestController;
use ContaiSearchConsoleService;
use ContaiOnePlatformResponse;

/**
 * SCP-04/SCP-05 — the plugin's REST proxy for the two FREE Search Console
 * read endpoints.
 *
 * What matters here is the edge: only administrators may call it, only the
 * four real periods are accepted, and an upstream refusal the panel renders
 * differently (409 not-verified, 429 quota) is not flattened into a generic
 * 502.
 */
class SearchConsolePerformanceRestControllerTest extends TestCase
{
    private $service;
    private ContaiSearchConsolePerformanceRestController $controller;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->service = Mockery::mock(ContaiSearchConsoleService::class);
        $this->controller = new ContaiSearchConsolePerformanceRestController($this->service);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_permission_callback_requires_manage_options(): void
    {
        WP_Mock::userFunction('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(true);

        $this->assertTrue($this->controller->check_admin_permission());
    }

    public function test_permission_callback_refuses_a_user_without_the_capability(): void
    {
        WP_Mock::userFunction('current_user_can')
            ->once()
            ->with('manage_options')
            ->andReturn(false);

        $this->assertFalse($this->controller->check_admin_permission());
    }

    public function test_performance_forwards_the_requested_period(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($v) => $v);

        $this->service
            ->shouldReceive('getPerformance')
            ->once()
            ->with('7d')
            ->andReturn(new ContaiOnePlatformResponse(true, ['period' => '7d'], null, 200));

        $response = $this->controller->performance($this->request(['period' => '7d']));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertSame('7d', $response->get_data()['data']['period']);
    }

    public function test_performance_falls_back_to_the_default_period_for_an_unknown_value(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($v) => $v);

        // A value that slipped past validate_callback must never be forwarded:
        // the API would answer 422 and the panel would show a generic failure.
        $this->service
            ->shouldReceive('getPerformance')
            ->once()
            ->with('28d')
            ->andReturn(new ContaiOnePlatformResponse(true, [], null, 200));

        $response = $this->controller->performance($this->request(['period' => 'all-time']));

        $this->assertSame(200, $response->get_status());
    }

    public function test_performance_preserves_a_not_verified_refusal(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($v) => $v);

        $this->service
            ->shouldReceive('getPerformance')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Website is not verified', 409));

        $response = $this->controller->performance($this->request([]));

        $this->assertSame(409, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_performance_preserves_a_quota_refusal(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($v) => $v);

        $this->service
            ->shouldReceive('getPerformance')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Quota exceeded', 429));

        $response = $this->controller->performance($this->request([]));

        $this->assertSame(429, $response->get_status());
    }

    public function test_performance_collapses_any_other_upstream_failure_to_502(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnUsing(fn($v) => $v);

        $this->service
            ->shouldReceive('getPerformance')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'boom', 503));

        $response = $this->controller->performance($this->request([]));

        $this->assertSame(502, $response->get_status());
    }

    public function test_sitemaps_returns_the_status_payload(): void
    {
        $this->service
            ->shouldReceive('getSitemapsStatus')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(
                true,
                ['sitemaps' => [], 'google_status_available' => false],
                null,
                200
            ));

        $response = $this->controller->sitemaps($this->request([]));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['data']['google_status_available']);
    }

    private function request(array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request();
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }
}
