<?php

namespace ContAI\Tests\Unit\Admin\Billing\Handlers;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiTopUpHandler;
use ContaiBillingService;
use ContaiOnePlatformResponse;

class RedirectException extends \RuntimeException {}

class TopUpHandlerTest extends TestCase
{
    private ContaiBillingService $service;
    private ContaiTopUpHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->service = Mockery::mock(ContaiBillingService::class);
        $this->handler = new ContaiTopUpHandler($this->service);
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_billing_topup_nonce'],
            $_POST['contai_billing_topup'],
            $_POST['contai_topup_amount'],
            $_POST['contai_topup_currency']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Security Tests ─────────────────────────────────────────────

    public function test_returns_early_when_nonce_field_not_present(): void
    {
        $this->service->shouldNotReceive('createTransaction');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_returns_early_when_nonce_is_invalid(): void
    {
        $_POST['contai_billing_topup_nonce'] = 'invalid-nonce';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('invalid-nonce', 'contai_billing_topup_action')
            ->andReturn(false);

        $this->service->shouldNotReceive('createTransaction');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_returns_early_when_user_lacks_capability(): void
    {
        $_POST['contai_billing_topup_nonce'] = 'valid-nonce';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_billing_topup_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        $this->service->shouldNotReceive('createTransaction');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    // ── Validation Tests ───────────────────────────────────────────

    public function test_topup_rejects_amount_below_minimum(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '3';
        $_POST['contai_topup_currency'] = 'USD';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->service->shouldNotReceive('createTransaction');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_bl_type=error', $redirectUrl->url);
        }
    }

    public function test_topup_rejects_amount_above_maximum(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '300';
        $_POST['contai_topup_currency'] = 'USD';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->service->shouldNotReceive('createTransaction');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_bl_type=error', $redirectUrl->url);
        }
    }

    public function test_topup_rejects_empty_currency(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '10';
        $_POST['contai_topup_currency'] = '';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->service->shouldNotReceive('createTransaction');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_bl_type=error', $redirectUrl->url);
        }
    }

    // ── Success Flow Tests ─────────────────────────────────────────

    public function test_topup_success_redirects_to_payment_url(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '25';
        $_POST['contai_topup_currency'] = 'USD';

        $paymentUrl = 'https://payment.example.com/checkout/abc123';

        $this->service
            ->shouldReceive('createTransaction')
            ->with(25.0, 'USD', 'Account top-up')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['payment_url' => $paymentUrl], 'Created', 200));

        WP_Mock::userFunction('esc_url_raw')
            ->andReturnUsing(function ($url) { return $url; });

        $capturedUrl = new class { public string $url = ''; };

        WP_Mock::userFunction('wp_redirect')
            ->once()
            ->andReturnUsing(function ($url) use ($capturedUrl) {
                $capturedUrl->url = $url;
                throw new RedirectException('redirect');
            });

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertEquals($paymentUrl, $capturedUrl->url);
        }
    }

    // ── Failure Flow Tests ─────────────────────────────────────────

    public function test_topup_api_failure_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '25';
        $_POST['contai_topup_currency'] = 'USD';

        $this->service
            ->shouldReceive('createTransaction')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Service unavailable', 503, 'trace-123'));

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_bl_type=error', $redirectUrl->url);
            $this->assertStringContainsString('contai_bl_trace_id', $redirectUrl->url);
        }
    }

    public function test_topup_missing_payment_url_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topup_amount'] = '25';
        $_POST['contai_topup_currency'] = 'USD';

        $this->service
            ->shouldReceive('createTransaction')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, [], 'Created', 200));

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_bl_type=error', $redirectUrl->url);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function mockValidRequest(): void
    {
        $_POST['contai_billing_topup_nonce'] = 'valid-nonce';
        $_POST['contai_billing_topup'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_billing_topup_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
    }

    private function expectRedirect(): object
    {
        $capture = new class { public string $url = ''; };

        WP_Mock::userFunction('admin_url')
            ->andReturn('http://example.com/wp-admin/admin.php?page=contai-billing&section=overview');

        WP_Mock::userFunction('add_query_arg')
            ->andReturnUsing(function ($args, $url) {
                return $url . '&' . http_build_query($args);
            });

        WP_Mock::userFunction('wp_safe_redirect')
            ->once()
            ->andReturnUsing(function ($url) use ($capture) {
                $capture->url = $url;
                throw new RedirectException('redirect');
            });

        return $capture;
    }
}
