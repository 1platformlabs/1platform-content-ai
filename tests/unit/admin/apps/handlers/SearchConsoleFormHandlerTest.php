<?php

namespace ContAI\Tests\Unit\Admin\Apps\Handlers;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSearchConsoleFormHandler;
use ContaiSearchConsoleSetupService;
use ContaiWebsiteProvider;
use ContaiSearchConsoleService;
use ContaiOnePlatformResponse;

class RedirectException extends \RuntimeException {}

class SearchConsoleFormHandlerTest extends TestCase
{
    private ContaiWebsiteProvider $websiteProvider;
    private ContaiSearchConsoleService $service;
    private ContaiSearchConsoleFormHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->websiteProvider = Mockery::mock(ContaiWebsiteProvider::class);
        $this->service = Mockery::mock(ContaiSearchConsoleService::class);
        $this->handler = new ContaiSearchConsoleFormHandler($this->websiteProvider, $this->service);
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_search_console_nonce'],
            $_POST['contai_setup_search_console'],
            $_POST['contai_disconnect_website'],
            $_POST['contai_delete_website']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_disconnect_website_success_clears_remote_and_local_state(): void
    {
        $this->mockValidRequest('contai_disconnect_website');

        $this->websiteProvider
            ->shouldReceive('deleteWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(true, null, 'Deleted', 200));

        $this->websiteProvider
            ->shouldReceive('deleteWebsiteConfig')
            ->once()
            ->andReturn(true);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=success', $redirectUrl->url);
        }
    }

    public function test_disconnect_website_api_failure_preserves_local_state_and_shows_error(): void
    {
        $this->mockValidRequest('contai_disconnect_website');

        $this->websiteProvider
            ->shouldReceive('deleteWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Server error', 500));

        $this->websiteProvider
            ->shouldNotReceive('deleteWebsiteConfig');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=error', $redirectUrl->url);
        }
    }

    public function test_disconnect_website_404_treated_as_already_disconnected(): void
    {
        $this->mockValidRequest('contai_disconnect_website');

        $this->websiteProvider
            ->shouldReceive('deleteWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Not found', 404));

        $this->websiteProvider
            ->shouldReceive('deleteWebsiteConfig')
            ->once()
            ->andReturn(true);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=success', $redirectUrl->url);
        }
    }

    public function test_disconnect_requires_valid_nonce(): void
    {
        $_POST['contai_search_console_nonce'] = 'invalid-nonce';
        $_POST['contai_disconnect_website'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('invalid-nonce', 'contai_search_console_action')
            ->andReturn(false);

        $this->websiteProvider->shouldNotReceive('deleteWebsite');
        $this->websiteProvider->shouldNotReceive('deleteWebsiteConfig');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_disconnect_requires_manage_options_capability(): void
    {
        $_POST['contai_search_console_nonce'] = 'valid-nonce';
        $_POST['contai_disconnect_website'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_search_console_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        $this->websiteProvider->shouldNotReceive('deleteWebsite');
        $this->websiteProvider->shouldNotReceive('deleteWebsiteConfig');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_disconnect_website_auth_failure_preserves_local_state(): void
    {
        $this->mockValidRequest('contai_disconnect_website');

        $this->websiteProvider
            ->shouldReceive('deleteWebsite')
            ->once()
            ->andReturn(new ContaiOnePlatformResponse(false, null, 'Unauthorized', 401));

        $this->websiteProvider
            ->shouldNotReceive('deleteWebsiteConfig');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=error', $redirectUrl->url);
        }
    }

    public function test_setup_success_redirects_with_success_message(): void
    {
        $this->mockValidRequest('contai_setup_search_console');

        $addResponse = new ContaiOnePlatformResponse(true, ['file_name' => 'google123.html', 'file_content' => 'google-site-verification: google123'], 'Added', 200);
        $verifyResponse = new ContaiOnePlatformResponse(true, ['verified' => true, 'status' => 'active'], 'Verified', 200);
        $sitemapResponse = new ContaiOnePlatformResponse(true, ['sitemaps' => []], 'Submitted', 200);

        $this->service->shouldReceive('addToSearchConsole')->once()->andReturn($addResponse);
        $this->service->shouldReceive('createVerificationFile')->once()->andReturn(['success' => true, 'message' => 'File created']);
        $this->service->shouldReceive('verifyWebsite')->once()->andReturn($verifyResponse);
        $this->service->shouldReceive('submitSitemaps')->once()->andReturn($sitemapResponse);

        $this->websiteProvider->shouldReceive('saveSearchConsoleConfig')->twice();
        $this->websiteProvider->shouldReceive('getSitemapUrls')->once()->andReturn(['https://example.com/sitemap.xml']);
        $this->websiteProvider->shouldReceive('saveSitemapsConfig')->once();

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=success', $redirectUrl->url);
        }
    }

    public function test_setup_failure_at_add_redirects_with_error(): void
    {
        $this->mockValidRequest('contai_setup_search_console');

        $addResponse = new ContaiOnePlatformResponse(false, null, 'Google API error', 502);

        $this->service->shouldReceive('addToSearchConsole')->once()->andReturn($addResponse);
        $this->service->shouldNotReceive('createVerificationFile');
        $this->service->shouldNotReceive('verifyWebsite');

        $this->websiteProvider->shouldNotReceive('saveSearchConsoleConfig');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=error', $redirectUrl->url);
            $this->assertStringContainsString('Google', $redirectUrl->url);
        }
    }

    public function test_setup_failure_at_verify_redirects_with_error(): void
    {
        $this->mockValidRequest('contai_setup_search_console');

        $addResponse = new ContaiOnePlatformResponse(true, ['file_name' => 'google123.html', 'file_content' => 'content'], 'Added', 200);
        $verifyResponse = new ContaiOnePlatformResponse(false, null, 'Verification failed', 502);

        $this->service->shouldReceive('addToSearchConsole')->once()->andReturn($addResponse);
        $this->service->shouldReceive('createVerificationFile')->once()->andReturn(['success' => true, 'message' => 'File created']);
        $this->service->shouldReceive('verifyWebsite')->once()->andReturn($verifyResponse);

        $this->websiteProvider->shouldReceive('saveSearchConsoleConfig')->once();

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=error', $redirectUrl->url);
            $this->assertStringContainsString('Verification', $redirectUrl->url);
        }
    }

    public function test_setup_succeeds_even_when_sitemaps_fail(): void
    {
        $this->mockValidRequest('contai_setup_search_console');

        $addResponse = new ContaiOnePlatformResponse(true, ['file_name' => 'google123.html', 'file_content' => 'content'], 'Added', 200);
        $verifyResponse = new ContaiOnePlatformResponse(true, ['verified' => true, 'status' => 'active'], 'Verified', 200);
        $sitemapResponse = new ContaiOnePlatformResponse(false, null, 'Sitemap error', 502);

        $this->service->shouldReceive('addToSearchConsole')->once()->andReturn($addResponse);
        $this->service->shouldReceive('createVerificationFile')->once()->andReturn(['success' => true, 'message' => 'File created']);
        $this->service->shouldReceive('verifyWebsite')->once()->andReturn($verifyResponse);
        $this->service->shouldReceive('submitSitemaps')->once()->andReturn($sitemapResponse);

        $this->websiteProvider->shouldReceive('saveSearchConsoleConfig')->twice();
        $this->websiteProvider->shouldReceive('getSitemapUrls')->once()->andReturn(['https://example.com/sitemap.xml']);
        $this->websiteProvider->shouldNotReceive('saveSitemapsConfig');

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected RedirectException');
        } catch (RedirectException $e) {
            $this->assertStringContainsString('contai_sc_type=success', $redirectUrl->url);
        }
    }

    private function mockValidRequest(string $action): void
    {
        $_POST['contai_search_console_nonce'] = 'valid-nonce';
        $_POST[$action] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_search_console_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
    }

    private function expectRedirect(): object
    {
        $capture = new class { public string $url = ''; };

        WP_Mock::userFunction('admin_url')
            ->andReturn('http://example.com/wp-admin/admin.php?page=contai-apps&section=search-console');

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
