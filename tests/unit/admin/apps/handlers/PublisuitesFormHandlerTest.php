<?php

namespace ContAI\Tests\Unit\Admin\Apps\Handlers;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPublisuitesFormHandler;
use ContaiPublisuitesService;
use ContaiOnePlatformResponse;

class PublisuitesRedirectException extends \RuntimeException {}

class PublisuitesFormHandlerTest extends TestCase
{
    private ContaiPublisuitesService $service;
    private ContaiPublisuitesFormHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->service = Mockery::mock(ContaiPublisuitesService::class);
        $this->handler = new ContaiPublisuitesFormHandler($this->service);
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_publisuites_nonce'],
            $_POST['contai_connect_publisuites'],
            $_POST['contai_verify_publisuites'],
            $_POST['contai_disconnect_publisuites'],
            $_POST['contai_create_verification_file'],
            $_POST['contai_setup_publisuites']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Security Tests ─────────────────────────────────────────────

    public function test_returns_early_when_nonce_field_not_present(): void
    {
        $this->service->shouldNotReceive('connectWebsite');
        $this->service->shouldNotReceive('verifyWebsite');
        $this->service->shouldNotReceive('deletePublisuitesConfig');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_returns_early_when_nonce_is_invalid(): void
    {
        $_POST['contai_publisuites_nonce'] = 'invalid-nonce';
        $_POST['contai_connect_publisuites'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('invalid-nonce', 'contai_publisuites_action')
            ->andReturn(false);

        $this->service->shouldNotReceive('connectWebsite');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_returns_early_when_user_lacks_capability(): void
    {
        $_POST['contai_publisuites_nonce'] = 'valid-nonce';
        $_POST['contai_connect_publisuites'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_publisuites_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        $this->service->shouldNotReceive('connectWebsite');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    // ── Connect Tests ──────────────────────────────────────────────

    public function test_connect_success_saves_config_and_redirects(): void
    {
        $this->mockValidRequest('contai_connect_publisuites');

        $responseData = [
            'publisuites_id' => 'ps-123',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => 'verification-content',
            'message' => 'Connected',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $responseData, 'Connected', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['publisuites_id'] === 'ps-123'
                    && $config['status'] === 'pending_verification'
                    && $config['verified'] === false;
            }));

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    public function test_connect_api_failure_redirects_with_error(): void
    {
        $this->mockValidRequest('contai_connect_publisuites');

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'API error', 500, 'trace-456'));

        $this->service->shouldNotReceive('savePublisuitesConfig');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=error', $redirectUrl->url);
            $this->assertStringContainsString('trace-456', $redirectUrl->url);
        }
    }

    // ── Verify Tests ───────────────────────────────────────────────

    public function test_verify_success_updates_config_to_active(): void
    {
        $this->mockValidRequest('contai_verify_publisuites');

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-01-01'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-123', 'status' => 'pending_verification']);

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['verified'] === true && $config['status'] === 'active';
            }));

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    public function test_verify_api_failure_redirects_with_error(): void
    {
        $this->mockValidRequest('contai_verify_publisuites');

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Verification failed', 400, 'trace-789'));

        $this->service->shouldNotReceive('getPublisuitesConfig');
        $this->service->shouldNotReceive('savePublisuitesConfig');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=error', $redirectUrl->url);
        }
    }

    // ── Disconnect Tests ───────────────────────────────────────────

    public function test_disconnect_deletes_local_config_and_redirects(): void
    {
        $this->mockValidRequest('contai_disconnect_publisuites');

        $this->service
            ->shouldReceive('deletePublisuitesConfig')
            ->once();

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    // ── Verification File Tests ────────────────────────────────────

    public function test_create_verification_file_success(): void
    {
        $this->mockValidRequest('contai_create_verification_file');

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created successfully']);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    public function test_create_verification_file_failure(): void
    {
        $this->mockValidRequest('contai_create_verification_file');

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Cannot write file']);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=error', $redirectUrl->url);
        }
    }

    // ── One-Click Setup Tests ─────────────────────────────────────

    public function test_setup_success_redirects_with_success_message(): void
    {
        $this->mockValidRequest('contai_setup_publisuites');

        $connectData = [
            'publisuites_id' => 'ps-123',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => '<html>verify</html>',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $connectData, 'Added', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->twice();

        $this->service
            ->shouldReceive('createVerificationFile')
            ->once()
            ->andReturn(['success' => true, 'message' => 'File created']);

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-03-26'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-123']);

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
            $this->assertStringContainsString('marketplace', urldecode($redirectUrl->url));
        }
    }

    public function test_setup_failure_redirects_with_error_message(): void
    {
        $this->mockValidRequest('contai_setup_publisuites');

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Credentials not configured', 400));

        $this->service->shouldNotReceive('savePublisuitesConfig');
        $this->service->shouldNotReceive('createVerificationFile');
        $this->service->shouldNotReceive('verifyWebsite');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=error', $redirectUrl->url);
            $this->assertStringContainsString('Failed', urldecode($redirectUrl->url));
        }
    }

    public function test_setup_does_not_break_existing_connect_action(): void
    {
        $this->mockValidRequest('contai_connect_publisuites');

        $responseData = [
            'publisuites_id' => 'ps-999',
            'verification_file_name' => 'verify.html',
            'verification_file_content' => 'content',
            'message' => 'Connected',
        ];

        $this->service
            ->shouldReceive('connectWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, $responseData, 'Connected', 200));

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once();

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    public function test_setup_does_not_break_existing_verify_action(): void
    {
        $this->mockValidRequest('contai_verify_publisuites');

        $this->service
            ->shouldReceive('verifyWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, ['verified' => true, 'verified_at' => '2026-01-01'], 'Verified', 200));

        $this->service
            ->shouldReceive('getPublisuitesConfig')
            ->once()
            ->andReturn(['publisuites_id' => 'ps-123', 'status' => 'pending_verification']);

        $this->service
            ->shouldReceive('savePublisuitesConfig')
            ->once()
            ->with(Mockery::on(function ($config) {
                return $config['verified'] === true && $config['status'] === 'active';
            }));

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PublisuitesRedirectException $e) {
            $this->assertStringContainsString('contai_ps_type=success', $redirectUrl->url);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function mockValidRequest(string $action): void
    {
        $_POST['contai_publisuites_nonce'] = 'valid-nonce';
        $_POST[$action] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_publisuites_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
    }

    private function expectRedirect(): object
    {
        $capture = new class { public string $url = ''; };

        WP_Mock::userFunction('admin_url')
            ->andReturn('http://example.com/wp-admin/admin.php?page=contai-apps&section=publisuites');

        WP_Mock::userFunction('add_query_arg')
            ->andReturnUsing(function ($args, $url) {
                return $url . '&' . http_build_query($args);
            });

        WP_Mock::userFunction('wp_safe_redirect')
            ->once()
            ->andReturnUsing(function ($url) use ($capture) {
                $capture->url = $url;
                throw new PublisuitesRedirectException('redirect');
            });

        return $capture;
    }
}
