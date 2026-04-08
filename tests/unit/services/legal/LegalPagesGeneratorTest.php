<?php

namespace ContAI\Tests\Unit\Services\Legal;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiLegalPagesGenerator;
use ContaiLegalPagesAPIClient;
use ContaiLegalInfoService;

class LegalPagesGeneratorTest extends TestCase
{
    private $apiClient;
    private $legalInfoService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->apiClient = Mockery::mock(ContaiLegalPagesAPIClient::class);
        $this->legalInfoService = Mockery::mock(ContaiLegalInfoService::class);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── ensureRequiredLegalPages: creates fallback for missing page ──

    public function test_generate_creates_fallback_for_missing_legal_page(): void
    {
        $this->setupValidLegalInfo();

        // API returns 4 pages but NOT cookie-policy
        $response = Mockery::mock(\ContaiOnePlatformResponse::class);
        $response->shouldReceive('isSuccess')->andReturn(true);
        $response->shouldReceive('getData')->andReturn([
            'pages' => [
                'privacy-policy' => ['title' => 'Privacy Policy', 'content' => '<p>Privacy content</p>'],
                'legal-policy' => ['title' => 'Legal Notice', 'content' => '<p>Legal content</p>'],
                'about-me' => ['title' => 'About Me', 'content' => '<p>About content</p>'],
                'contact' => ['title' => 'Contact', 'content' => '<p>Contact content</p>'],
            ],
            'meta' => ['slug_map' => [
                'privacy-policy' => 'privacy-policy',
                'legal-policy' => 'legal-notice',
                'about-me' => 'about-me',
                'contact' => 'contact',
            ]],
            'lang' => 'en',
        ]);

        $this->apiClient
            ->shouldReceive('generateLegalPages')
            ->once()
            ->andReturn($response);

        // processPage: each page already exists (skipped)
        WP_Mock::userFunction('get_page_by_path')
            ->andReturn((object) ['ID' => 1, 'post_status' => 'publish']);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('sanitize_email')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('sanitize_title')
            ->andReturnUsing(function ($v) { return strtolower(str_replace(' ', '-', $v)); });
        WP_Mock::userFunction('esc_html')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('esc_html__')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('wp_kses_post')
            ->andReturnUsing(function ($v) { return $v; });

        WP_Mock::userFunction('get_option')
            ->with('contai_site_theme', '')
            ->andReturn('Tech Blog');
        WP_Mock::userFunction('get_option')
            ->with('contai_legal_owner', '')
            ->andReturn('John Doe');
        WP_Mock::userFunction('get_option')
            ->with('contai_legal_email', '')
            ->andReturn('john@example.com');

        // ensureRequiredLegalPages: 4 already exist by meta, cookie-policy does not
        $callCount = 0;
        WP_Mock::userFunction('get_posts')
            ->andReturnUsing(function ($args) use (&$callCount) {
                // processPage trash checks return empty
                if (isset($args['post_status']) && $args['post_status'] === 'trash') {
                    return [];
                }
                // ensureRequiredLegalPages meta lookups
                if (isset($args['meta_key']) && $args['meta_key'] === '_contai_legal_key') {
                    if ($args['meta_value'] === 'cookie-policy') {
                        return []; // missing
                    }
                    return [(object) ['ID' => 10, 'post_status' => 'publish']];
                }
                return [];
            });

        // Fallback creation for cookie-policy
        WP_Mock::userFunction('wp_insert_post')
            ->once()
            ->andReturn(99);

        WP_Mock::userFunction('update_post_meta')
            ->times(4); // source, key, lang, generated_at

        WP_Mock::userFunction('current_time')
            ->andReturn('2026-04-07 12:00:00');

        WP_Mock::userFunction('is_wp_error')
            ->andReturn(false);

        WP_Mock::userFunction('__')
            ->andReturnUsing(function ($text) { return $text; });

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $result = $generator->generate();

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(1, $result['created']);
    }

    public function test_generate_restores_trashed_page_in_ensure_step(): void
    {
        $this->setupValidLegalInfo();

        // Must return at least one page to avoid the "API returned no pages" early return
        $response = Mockery::mock(\ContaiOnePlatformResponse::class);
        $response->shouldReceive('isSuccess')->andReturn(true);
        $response->shouldReceive('getData')->andReturn([
            'pages' => [
                'legal-policy' => ['title' => 'Legal Notice', 'content' => '<p>Legal</p>'],
            ],
            'meta' => ['slug_map' => ['legal-policy' => 'legal-notice']],
            'lang' => 'en',
        ]);

        $this->apiClient
            ->shouldReceive('generateLegalPages')
            ->once()
            ->andReturn($response);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('sanitize_email')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('sanitize_title')
            ->andReturnUsing(function ($v) { return strtolower(str_replace(' ', '-', $v)); });
        WP_Mock::userFunction('esc_html')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('esc_html__')
            ->andReturnUsing(function ($v) { return $v; });

        WP_Mock::userFunction('get_option')
            ->with('contai_site_theme', '')
            ->andReturn('Blog');
        WP_Mock::userFunction('get_option')
            ->with('contai_legal_owner', '')
            ->andReturn('Owner');
        WP_Mock::userFunction('get_option')
            ->with('contai_legal_email', '')
            ->andReturn('owner@test.com');

        // processPage: legal-notice already exists (skipped)
        WP_Mock::userFunction('get_page_by_path')
            ->andReturn((object) ['ID' => 1, 'post_status' => 'publish']);

        WP_Mock::userFunction('wp_kses_post')
            ->andReturnUsing(function ($v) { return $v; });

        // ensureRequiredLegalPages: privacy-policy is in trash, rest missing
        WP_Mock::userFunction('get_posts')
            ->andReturnUsing(function ($args) {
                if (isset($args['meta_key']) && $args['meta_key'] === '_contai_legal_key') {
                    if ($args['meta_value'] === 'privacy-policy') {
                        return [(object) ['ID' => 55, 'post_status' => 'trash']];
                    }
                    // legal-policy already exists from API
                    if ($args['meta_value'] === 'legal-policy') {
                        return [(object) ['ID' => 1, 'post_status' => 'publish']];
                    }
                    return []; // missing pages
                }
                return [];
            });

        // Trashed page should be restored
        WP_Mock::userFunction('wp_untrash_post')
            ->once()
            ->with(55);

        // Missing pages: cookie-policy, about-me, contact (3 missing)
        WP_Mock::userFunction('wp_insert_post')
            ->times(3)
            ->andReturn(100);

        WP_Mock::userFunction('update_post_meta');

        WP_Mock::userFunction('current_time')
            ->andReturn('2026-04-07 12:00:00');

        WP_Mock::userFunction('is_wp_error')
            ->andReturn(false);

        WP_Mock::userFunction('__')
            ->andReturnUsing(function ($text) { return $text; });

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $result = $generator->generate();

        // 3 fallback pages created; privacy-policy restored from trash, legal-policy already exists
        // wp_untrash_post called once verifies the trash restore
        $this->assertEquals(3, $result['created']);
    }

    public function test_generate_returns_error_when_api_fails(): void
    {
        $this->setupValidLegalInfo();

        $response = Mockery::mock(\ContaiOnePlatformResponse::class);
        $response->shouldReceive('isSuccess')->andReturn(false);
        $response->shouldReceive('getMessage')->andReturn('API error');

        $this->apiClient
            ->shouldReceive('generateLegalPages')
            ->once()
            ->andReturn($response);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($v) { return $v; });
        WP_Mock::userFunction('sanitize_email')
            ->andReturnUsing(function ($v) { return $v; });

        WP_Mock::userFunction('get_option')
            ->with('contai_site_theme', '')
            ->andReturn('Blog');

        WP_Mock::userFunction('__')
            ->andReturnUsing(function ($text) { return $text; });

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $result = $generator->generate();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_generate_returns_error_on_validation_failure(): void
    {
        $this->legalInfoService
            ->shouldReceive('getLegalInfo')
            ->once()
            ->andReturn([]);

        $this->legalInfoService
            ->shouldReceive('validateLegalInfo')
            ->once()
            ->andReturn(['Owner is required']);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $result = $generator->generate();

        $this->assertFalse($result['success']);
        $this->assertEquals(['Owner is required'], $result['errors']);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function setupValidLegalInfo(): void
    {
        $this->legalInfoService
            ->shouldReceive('getLegalInfo')
            ->once()
            ->andReturn([
                'owner' => 'John Doe',
                'email' => 'john@example.com',
                'address' => '123 Main St',
                'activity' => 'Technology Blog',
            ]);

        $this->legalInfoService
            ->shouldReceive('validateLegalInfo')
            ->once()
            ->andReturn([]);
    }
}
