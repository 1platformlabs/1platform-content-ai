<?php

namespace ContAI\Tests\Unit\Admin\ContentGenerator\Handlers;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiKeywordExtractionHandler;
use ContaiJobRepository;
use ContaiJob;
use ContaiKeywordExtractionJob;

class KeywordRedirectException extends \Error {}

class KeywordExtractionHandlerTest extends TestCase
{
    private ContaiJobRepository $jobRepository;
    private ContaiKeywordExtractionHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Mock billing cache so CreditGuard passes without hitting BillingService
        WP_Mock::userFunction('get_transient')
            ->with('contai_billing_cache')
            ->andReturn(['balance' => 100.00, 'currency' => 'USD']);

        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
        $this->handler = new ContaiKeywordExtractionHandler($this->jobRepository);
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_keyword_extractor_nonce'],
            $_POST['contai_extract_keywords'],
            $_POST['contai_topic'],
            $_POST['contai_country'],
            $_POST['contai_target_language'],
            $_SERVER['REQUEST_METHOD']
        );
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Security Tests ─────────────────────────────────────────────

    public function test_returns_early_when_not_post_request(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->jobRepository->shouldNotReceive('create');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_returns_early_when_no_request_method(): void
    {
        unset($_SERVER['REQUEST_METHOD']);

        $this->jobRepository->shouldNotReceive('create');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_invalid_nonce_redirects_with_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_keyword_extractor_nonce'] = 'invalid';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('invalid', 'contai_keyword_extractor_nonce')
            ->andReturn(false);

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_insufficient_capability_redirects_with_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_keyword_extractor_nonce'] = 'valid-nonce';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_keyword_extractor_nonce')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    // ── Validation Tests ───────────────────────────────────────────

    public function test_empty_topic_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = '';
        $_POST['contai_country'] = 'us';
        $_POST['contai_target_language'] = 'en';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_topic_too_short_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = 'ab';
        $_POST['contai_country'] = 'us';
        $_POST['contai_target_language'] = 'en';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_invalid_language_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = 'machine learning';
        $_POST['contai_country'] = 'us';
        $_POST['contai_target_language'] = 'fr';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_invalid_country_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = 'machine learning';
        $_POST['contai_country'] = 'fr';
        $_POST['contai_target_language'] = 'en';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository->shouldNotReceive('create');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    // ── Success Flow ───────────────────────────────────────────────

    public function test_extract_keywords_success_creates_job_and_redirects(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = 'machine learning';
        $_POST['contai_country'] = 'us';
        $_POST['contai_target_language'] = 'en';

        WP_Mock::userFunction('current_time')
            ->andReturn('2026-01-01 00:00:00');

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($job) {
                return $job instanceof ContaiJob;
            }))
            ->andReturn(true);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('success=1', $redirectUrl->url);
        }
    }

    // ── Failure Flow ───────────────────────────────────────────────

    public function test_extract_keywords_job_creation_failure_redirects_with_error(): void
    {
        $this->mockValidRequest();
        $_POST['contai_topic'] = 'machine learning';
        $_POST['contai_country'] = 'us';
        $_POST['contai_target_language'] = 'en';

        WP_Mock::userFunction('current_time')
            ->andReturn('2026-01-01 00:00:00');

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->jobRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(false);

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (KeywordRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function mockValidRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_keyword_extractor_nonce'] = 'valid-nonce';
        $_POST['contai_extract_keywords'] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_keyword_extractor_nonce')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
    }

    private function expectRedirect(): object
    {
        $capture = new class { public string $url = ''; };

        WP_Mock::userFunction('admin_url')
            ->andReturn('http://example.com/wp-admin/admin.php');

        WP_Mock::userFunction('add_query_arg')
            ->andReturnUsing(function ($args, $url) {
                return $url . '?' . http_build_query($args);
            });

        WP_Mock::userFunction('wp_safe_redirect')
            ->once()
            ->andReturnUsing(function ($url) use ($capture) {
                $capture->url = $url;
                throw new KeywordRedirectException('redirect');
            });

        return $capture;
    }
}
