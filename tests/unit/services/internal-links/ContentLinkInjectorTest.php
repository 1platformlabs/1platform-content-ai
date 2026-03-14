<?php

namespace ContAI\Tests\Unit\Services\InternalLinks;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use WPContentAI\Services\InternalLinks\ContentLinkInjector;

class ContentLinkInjectorTest extends TestCase {

    private ContentLinkInjector $injector;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->injector = new ContentLinkInjector();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_inject_links_returns_original_content_when_empty(): void {
        $content = '<p>Test content</p>';
        $this->assertSame($content, $this->injector->injectLinks($content, []));
    }

    public function test_inject_links_inserts_link_at_correct_position(): void {
        WP_Mock::userFunction('esc_url')->andReturnArg(0);

        $content = 'Learn about WordPress today';
        $linkData = [
            [
                'match' => ['offset' => 12, 'length' => 9, 'text' => 'WordPress'],
                'url' => 'https://example.com/wordpress',
            ],
        ];

        $result = $this->injector->injectLinks($content, $linkData);

        $this->assertStringContainsString('<a href="https://example.com/wordpress">WordPress</a>', $result);
        $this->assertStringContainsString('Learn about', $result);
        $this->assertStringContainsString('today', $result);
    }

    public function test_inject_links_with_title(): void {
        WP_Mock::userFunction('esc_url')->andReturnArg(0);
        WP_Mock::userFunction('esc_attr')->andReturnArg(0);

        $content = 'Learn about SEO today';
        $linkData = [
            [
                'match' => ['offset' => 12, 'length' => 3, 'text' => 'SEO'],
                'url' => 'https://example.com/seo',
                'title' => 'SEO Guide',
            ],
        ];

        $result = $this->injector->injectLinks($content, $linkData);

        $this->assertStringContainsString('title="SEO Guide"', $result);
    }

    public function test_inject_links_handles_multiple_links(): void {
        WP_Mock::userFunction('esc_url')->andReturnArg(0);

        $content = 'Learn about SEO and WordPress today';
        $linkData = [
            [
                'match' => ['offset' => 12, 'length' => 3, 'text' => 'SEO'],
                'url' => 'https://example.com/seo',
            ],
            [
                'match' => ['offset' => 20, 'length' => 9, 'text' => 'WordPress'],
                'url' => 'https://example.com/wordpress',
            ],
        ];

        $result = $this->injector->injectLinks($content, $linkData);

        $this->assertStringContainsString('<a href="https://example.com/seo">SEO</a>', $result);
        $this->assertStringContainsString('<a href="https://example.com/wordpress">WordPress</a>', $result);
    }

    public function test_validate_link_data_passes_for_valid_data(): void {
        $linkData = [
            [
                'match' => ['offset' => 0, 'length' => 5, 'text' => 'Hello'],
                'url' => 'https://example.com',
            ],
        ];

        $this->assertTrue($this->injector->validateLinkData($linkData));
    }

    public function test_validate_link_data_throws_for_missing_match(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing 'match' array");

        $this->injector->validateLinkData([['url' => 'https://example.com']]);
    }

    public function test_validate_link_data_throws_for_missing_offset(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing valid 'offset'");

        $this->injector->validateLinkData([
            ['match' => ['length' => 5, 'text' => 'test'], 'url' => 'https://example.com'],
        ]);
    }

    public function test_validate_link_data_throws_for_missing_url(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing valid 'url'");

        $this->injector->validateLinkData([
            ['match' => ['offset' => 0, 'length' => 5, 'text' => 'test']],
        ]);
    }

    public function test_count_links(): void {
        $linkData = [
            ['match' => [], 'url' => 'a'],
            ['match' => [], 'url' => 'b'],
        ];

        $this->assertSame(2, $this->injector->countLinks($linkData));
    }

    public function test_count_links_returns_zero_for_empty(): void {
        $this->assertSame(0, $this->injector->countLinks([]));
    }

    public function test_remove_links(): void {
        WP_Mock::userFunction('esc_url')
            ->with('https://example.com/seo')
            ->andReturn('https://example.com/seo');

        $content = 'Learn about <a href="https://example.com/seo">SEO</a> today';
        $result = $this->injector->removeLinks($content, 'https://example.com/seo');

        $this->assertSame('Learn about SEO today', $result);
    }

    public function test_preview_links_returns_preview_data(): void {
        WP_Mock::userFunction('esc_url')->andReturnArg(0);

        $content = 'Learn about SEO in this article about SEO optimization';
        $linkData = [
            [
                'match' => ['offset' => 12, 'length' => 3, 'text' => 'SEO'],
                'url' => 'https://example.com/seo',
                'title' => 'SEO Guide',
            ],
        ];

        $previews = $this->injector->previewLinks($content, $linkData);

        $this->assertCount(1, $previews);
        $this->assertSame('https://example.com/seo', $previews[0]['url']);
        $this->assertSame('SEO', $previews[0]['matched_text']);
        $this->assertSame(12, $previews[0]['position']);
        $this->assertArrayHasKey('context', $previews[0]);
        $this->assertArrayHasKey('link_html', $previews[0]);
    }

    public function test_inject_multiple_keyword_links(): void {
        WP_Mock::userFunction('esc_url')->andReturnArg(0);

        $content = 'Learn SEO and WordPress';
        $linksByKeyword = [
            'SEO' => [
                [
                    'match' => ['offset' => 6, 'length' => 3, 'text' => 'SEO'],
                    'url' => 'https://example.com/seo',
                ],
            ],
            'WordPress' => [
                [
                    'match' => ['offset' => 14, 'length' => 9, 'text' => 'WordPress'],
                    'url' => 'https://example.com/wp',
                ],
            ],
        ];

        $result = $this->injector->injectMultipleKeywordLinks($content, $linksByKeyword);

        $this->assertStringContainsString('<a href="https://example.com/seo">SEO</a>', $result);
        $this->assertStringContainsString('<a href="https://example.com/wp">WordPress</a>', $result);
    }
}
