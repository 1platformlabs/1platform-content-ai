<?php

namespace ContAI\Tests\Unit\Services\Toc;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use TocConfiguration;

class TocConfigurationTest extends TestCase {

    private TocConfiguration $config;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->config = new TocConfiguration();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_get_returns_stored_value(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_toc_config', \Mockery::type('array'))
            ->andReturn(['enabled' => false, 'title' => 'Custom TOC']);

        $this->assertFalse($this->config->get('enabled'));
        $this->assertSame('Custom TOC', $this->config->get('title'));
    }

    public function test_get_returns_default_when_key_missing(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn([]);

        $result = $this->config->get('nonexistent', 'fallback');

        $this->assertSame('fallback', $result);
    }

    public function test_get_all_merges_defaults_with_stored(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_toc_config', [])
            ->andReturn(['title' => 'Custom']);

        $all = $this->config->getAll();

        $this->assertSame('Custom', $all['title']);
        $this->assertTrue($all['enabled']);
        $this->assertSame([2, 3, 4], $all['heading_levels']);
    }

    public function test_is_enabled_returns_boolean(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['enabled' => true]);

        $this->assertTrue($this->config->isEnabled());
    }

    public function test_get_post_types_returns_array(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['post_types' => ['post', 'page']]);

        $this->assertSame(['post', 'page'], $this->config->getPostTypes());
    }

    public function test_get_heading_levels(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['heading_levels' => [2, 3]]);

        $this->assertSame([2, 3], $this->config->getHeadingLevels());
    }

    public function test_get_min_headings(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['min_headings' => 5]);

        $this->assertSame(5, $this->config->getMinHeadings());
    }

    public function test_update_sanitizes_and_saves(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn([]);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('get_post_types')
            ->with(['public' => true], 'names')
            ->andReturn(['post' => 'post', 'page' => 'page']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_toc_config', \Mockery::type('array'))
            ->andReturn(true);

        $result = $this->config->update(['title' => 'New Title']);

        $this->assertTrue($result);
    }

    public function test_reset_restores_defaults(): void {
        WP_Mock::userFunction('update_option')
            ->once()
            ->with('contai_toc_config', \Mockery::type('array'))
            ->andReturn(true);

        $this->assertTrue($this->config->reset());
    }

    public function test_should_show_title(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['show_title' => true]);

        $this->assertTrue($this->config->shouldShowTitle());
    }

    public function test_should_show_toggle(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['show_toggle' => false]);

        $this->assertFalse($this->config->shouldShowToggle());
    }

    public function test_get_theme(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['theme' => 'black']);

        $this->assertSame('black', $this->config->getTheme());
    }

    public function test_should_smooth_scroll(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['smooth_scroll' => true]);

        $this->assertTrue($this->config->shouldSmoothScroll());
    }

    public function test_get_position(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['position' => 'top']);

        $this->assertSame('top', $this->config->getPosition());
    }

    public function test_get_exclude_patterns(): void {
        WP_Mock::userFunction('get_option')
            ->andReturn(['exclude_patterns' => ['TOC*', 'References']]);

        $this->assertSame(['TOC*', 'References'], $this->config->getExcludePatterns());
    }
}
