<?php

namespace ContAI\Tests\Unit\Services\Toc;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use TocWordPressIntegration;
use TocGenerator;
use TocConfiguration;
use HeadingParser;
use AnchorGenerator;
use TocBuilder;
use ContentInjector;

class TocWordPressIntegrationTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── register ───────────────────────────────────────────────────

    public function test_register_adds_content_filter_and_scripts_action(): void {
        $integration = $this->createIntegration();

        WP_Mock::expectFilterAdded('the_content', [$integration, 'processContent'], 100);
        WP_Mock::expectActionAdded('wp_enqueue_scripts', [$integration, 'enqueueAssets']);

        $integration->register();

        $this->addToAssertionCount(1);
    }

    // ── processContent: theme applied ──────────────────────────────

    public function test_process_content_applies_theme_class_from_config(): void {
        $integration = $this->createIntegration(['theme' => 'black', 'min_headings' => 2]);
        $this->mockShouldProcessTrue();

        WP_Mock::userFunction('esc_attr')->andReturnArg(0);
        WP_Mock::userFunction('esc_html')->andReturnArg(0);
        WP_Mock::userFunction('esc_attr__')->andReturnArg(0);
        WP_Mock::userFunction('wp_kses_post')->andReturnArg(0);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnUsing(function ($s) {
            return strip_tags($s);
        });
        WP_Mock::userFunction('remove_accents')->andReturnArg(0);

        $content = '<h2>First Heading</h2><p>Text</p><h2>Second Heading</h2><p>More text</p>';
        $result = $integration->processContent($content);

        $this->assertStringContainsString('toc-theme-black', $result);
        $this->assertStringContainsString('toc-container', $result);
    }

    // ── processContent: shouldProcess guards ───────────────────────

    public function test_process_content_returns_original_when_disabled(): void {
        $integration = $this->createIntegration(['enabled' => false]);

        $result = $integration->processContent('<h2>Original</h2>');

        $this->assertSame('<h2>Original</h2>', $result);
    }

    public function test_process_content_returns_original_when_not_singular(): void {
        $integration = $this->createIntegration();
        WP_Mock::userFunction('is_singular')->andReturn(false);

        $result = $integration->processContent('<h2>Original</h2>');

        $this->assertSame('<h2>Original</h2>', $result);
    }

    public function test_process_content_returns_original_on_front_page(): void {
        $integration = $this->createIntegration();
        WP_Mock::userFunction('is_singular')->andReturn(true);
        WP_Mock::userFunction('is_front_page')->andReturn(true);

        $result = $integration->processContent('<h2>Original</h2>');

        $this->assertSame('<h2>Original</h2>', $result);
    }

    public function test_process_content_returns_original_on_password_protected(): void {
        $integration = $this->createIntegration();
        WP_Mock::userFunction('is_singular')->andReturn(true);
        WP_Mock::userFunction('is_front_page')->andReturn(false);
        WP_Mock::userFunction('post_password_required')->andReturn(true);

        $result = $integration->processContent('<h2>Original</h2>');

        $this->assertSame('<h2>Original</h2>', $result);
    }

    public function test_process_content_returns_original_when_post_type_not_allowed(): void {
        $integration = $this->createIntegration();
        WP_Mock::userFunction('is_singular')->andReturn(true);
        WP_Mock::userFunction('is_front_page')->andReturn(false);
        WP_Mock::userFunction('post_password_required')->andReturn(false);
        WP_Mock::userFunction('get_post_type')->andReturn('product');

        $result = $integration->processContent('<h2>Original</h2>');

        $this->assertSame('<h2>Original</h2>', $result);
    }

    // ── enqueueAssets ──────────────────────────────────────────────

    public function test_enqueue_assets_loads_css_and_js_when_enabled(): void {
        $integration = $this->createIntegration([
            'show_toggle' => true,
            'smooth_scroll' => true,
        ]);
        $this->mockShouldProcessTrue();

        WP_Mock::userFunction('plugin_dir_url')
            ->andReturn('https://example.com/wp-content/plugins/1platform-content-ai/');

        WP_Mock::userFunction('wp_enqueue_style')
            ->once()
            ->with('contai-toc', \Mockery::type('string'), [], \Mockery::type('string'));

        WP_Mock::userFunction('wp_enqueue_script')
            ->once()
            ->with('contai-toc', \Mockery::type('string'), [], \Mockery::type('string'), true);

        WP_Mock::userFunction('wp_localize_script')
            ->once()
            ->with('contai-toc', 'contaiTocConfig', \Mockery::on(function ($data) {
                return $data['smoothScroll'] === true && $data['smoothScrollOffset'] === 30;
            }));

        $integration->enqueueAssets();

        $this->addToAssertionCount(1);
    }

    public function test_enqueue_assets_skips_js_when_no_toggle_no_scroll(): void {
        $integration = $this->createIntegration([
            'show_toggle' => false,
            'smooth_scroll' => false,
        ]);
        $this->mockShouldProcessTrue();

        WP_Mock::userFunction('plugin_dir_url')
            ->andReturn('https://example.com/wp-content/plugins/1platform-content-ai/');

        WP_Mock::userFunction('wp_enqueue_style')->once();
        WP_Mock::userFunction('wp_enqueue_script')->never();
        WP_Mock::userFunction('wp_localize_script')->never();

        $integration->enqueueAssets();

        $this->addToAssertionCount(1);
    }

    public function test_enqueue_assets_skips_all_when_disabled(): void {
        $integration = $this->createIntegration(['enabled' => false]);

        WP_Mock::userFunction('wp_enqueue_style')->never();
        WP_Mock::userFunction('wp_enqueue_script')->never();

        $integration->enqueueAssets();

        $this->addToAssertionCount(1);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function createIntegration(array $configOverrides = []): TocWordPressIntegration {
        $defaults = [
            'enabled' => true,
            'post_types' => ['post', 'page'],
            'heading_levels' => [2, 3, 4],
            'min_headings' => 2,
            'position' => 'before_first_heading',
            'title' => 'Table of Contents',
            'show_title' => true,
            'show_toggle' => true,
            'initial_state' => 'show',
            'show_hierarchy' => true,
            'numbered_list' => true,
            'exclude_patterns' => [],
            'theme' => 'grey',
            'lowercase_anchors' => true,
            'hyphenate_anchors' => true,
            'smooth_scroll' => true,
        ];

        $merged = array_merge($defaults, $configOverrides);

        WP_Mock::userFunction('get_option')->andReturn($merged);

        $config = new TocConfiguration();
        $parser = new HeadingParser();
        $anchor_gen = new AnchorGenerator(
            $merged['lowercase_anchors'],
            $merged['hyphenate_anchors']
        );
        $builder = new TocBuilder();
        $injector = new ContentInjector();

        $generator = new TocGenerator($parser, $anchor_gen, $builder, $injector, $config);

        return new TocWordPressIntegration($generator, $config);
    }

    private function mockShouldProcessTrue(): void {
        WP_Mock::userFunction('is_singular')->andReturn(true);
        WP_Mock::userFunction('is_front_page')->andReturn(false);
        WP_Mock::userFunction('post_password_required')->andReturn(false);
        WP_Mock::userFunction('get_post_type')->andReturn('post');
    }
}
