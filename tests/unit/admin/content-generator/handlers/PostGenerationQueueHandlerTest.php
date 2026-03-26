<?php

namespace ContAI\Tests\Unit\Admin\ContentGenerator\Handlers;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiPostGenerationQueueHandler;
use ContaiQueueManager;

class PostGenRedirectException extends \Error {}

class PostGenerationQueueHandlerTest extends TestCase
{
    private ContaiQueueManager $queueManager;
    private ContaiPostGenerationQueueHandler $handler;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Mock billing cache so CreditGuard passes without hitting BillingService
        WP_Mock::userFunction('get_transient')
            ->with('contai_billing_cache')
            ->andReturn(['balance' => 100.00, 'currency' => 'USD']);

        $this->queueManager = Mockery::mock(ContaiQueueManager::class);
        $this->handler = new ContaiPostGenerationQueueHandler($this->queueManager);
    }

    public function tearDown(): void
    {
        unset(
            $_POST['contai_post_generator_nonce'],
            $_POST['contai_enqueue_posts'],
            $_POST['contai_clear_queue'],
            $_POST['post_count'],
            $_POST['content_lang'],
            $_POST['content_country'],
            $_POST['image_provider'],
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

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');
        $this->queueManager->shouldNotReceive('clearAllJobs');

        $this->handler->handleRequest();

        $this->assertTrue(true);
    }

    public function test_invalid_nonce_redirects_with_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_post_generator_nonce'] = 'invalid';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('invalid', 'contai_post_generator_nonce')
            ->andReturn(false);

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_insufficient_capability_redirects_with_error(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_post_generator_nonce'] = 'valid-nonce';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_post_generator_nonce')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    // ── Enqueue Validation Tests ───────────────────────────────────

    public function test_enqueue_rejects_zero_post_count(): void
    {
        $this->mockValidRequest('contai_enqueue_posts');
        $_POST['post_count'] = '0';
        $_POST['content_lang'] = 'en';
        $_POST['content_country'] = 'us';
        $_POST['image_provider'] = 'pexels';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });
        WP_Mock::userFunction('absint')->andReturnUsing(function ($val) { return abs(intval($val)); });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_enqueue_rejects_count_above_100(): void
    {
        $this->mockValidRequest('contai_enqueue_posts');
        $_POST['post_count'] = '150';
        $_POST['content_lang'] = 'en';
        $_POST['content_country'] = 'us';
        $_POST['image_provider'] = 'pexels';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });
        WP_Mock::userFunction('absint')->andReturnUsing(function ($val) { return abs(intval($val)); });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_enqueue_rejects_empty_language(): void
    {
        $this->mockValidRequest('contai_enqueue_posts');
        $_POST['post_count'] = '5';
        $_POST['content_lang'] = '';
        $_POST['content_country'] = 'us';
        $_POST['image_provider'] = 'pexels';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });
        WP_Mock::userFunction('absint')->andReturnUsing(function ($val) { return abs(intval($val)); });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    public function test_enqueue_rejects_invalid_image_provider(): void
    {
        $this->mockValidRequest('contai_enqueue_posts');
        $_POST['post_count'] = '5';
        $_POST['content_lang'] = 'en';
        $_POST['content_country'] = 'us';
        $_POST['image_provider'] = 'unsplash';

        WP_Mock::userFunction('__')->andReturnUsing(function ($text) { return $text; });
        WP_Mock::userFunction('absint')->andReturnUsing(function ($val) { return abs(intval($val)); });

        $this->queueManager->shouldNotReceive('enqueuePostGeneration');

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('error=1', $redirectUrl->url);
        }
    }

    // ── Enqueue Success ────────────────────────────────────────────

    public function test_enqueue_posts_success(): void
    {
        $this->mockValidRequest('contai_enqueue_posts');
        $_POST['post_count'] = '10';
        $_POST['content_lang'] = 'en';
        $_POST['content_country'] = 'us';
        $_POST['image_provider'] = 'pexels';

        WP_Mock::userFunction('absint')->andReturnUsing(function ($val) { return abs(intval($val)); });

        $this->queueManager
            ->shouldReceive('enqueuePostGeneration')
            ->once()
            ->with(10, Mockery::on(function ($config) {
                return $config['lang'] === 'en'
                    && $config['country'] === 'us'
                    && $config['image_provider'] === 'pexels';
            }))
            ->andReturn(10);

        WP_Mock::userFunction('_n')->andReturnUsing(function ($single, $plural, $count) {
            return $count === 1 ? $single : $plural;
        });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('success=1', $redirectUrl->url);
        }
    }

    // ── Clear Queue Tests ──────────────────────────────────────────

    public function test_clear_queue_success(): void
    {
        $this->mockValidRequest('contai_clear_queue');

        $this->queueManager
            ->shouldReceive('clearAllJobs')
            ->once()
            ->andReturn(5);

        WP_Mock::userFunction('_n')->andReturnUsing(function ($single, $plural, $count) {
            return $count === 1 ? $single : $plural;
        });

        $redirectUrl = $this->expectRedirect();

        try {
            $this->handler->handleRequest();
            $this->fail('Expected redirect');
        } catch (PostGenRedirectException $e) {
            $this->assertStringContainsString('success=1', $redirectUrl->url);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function mockValidRequest(string $action): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['contai_post_generator_nonce'] = 'valid-nonce';
        $_POST[$action] = '1';

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_unslash')
            ->andReturnUsing(function ($val) { return $val; });

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid-nonce', 'contai_post_generator_nonce')
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
                throw new PostGenRedirectException('redirect');
            });

        return $capture;
    }
}
