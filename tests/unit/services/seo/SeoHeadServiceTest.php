<?php

namespace ContAI\Tests\Unit\Services\Seo;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSeoHeadService;

class SeoHeadServiceTest extends TestCase
{
    private ContaiSeoHeadService $service;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->service = new ContaiSeoHeadService();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_override_title_uses_metatitle_on_singular_post(): void
    {
        $post = Mockery::mock('WP_Post');
        $post->ID = 42;

        WP_Mock::userFunction('is_singular')->with('post')->andReturn(true);
        WP_Mock::userFunction('get_queried_object')->andReturn($post);
        WP_Mock::userFunction('get_post_meta')
            ->with(42, '_contai_metatitle', true)
            ->andReturn('BEST Running Shoes for BEGINNERS 2025');

        $result = $this->service->overrideTitleParts(['title' => 'Original Title', 'site' => 'My Site']);

        $this->assertSame('BEST Running Shoes for BEGINNERS 2025', $result['title']);
        $this->assertSame('My Site', $result['site']);
    }

    public function test_override_title_skips_non_singular_pages(): void
    {
        WP_Mock::userFunction('is_singular')->with('post')->andReturn(false);

        $result = $this->service->overrideTitleParts(['title' => 'Original Title']);

        $this->assertSame('Original Title', $result['title']);
    }

    public function test_override_title_skips_when_no_metatitle(): void
    {
        $post = Mockery::mock('WP_Post');
        $post->ID = 42;

        WP_Mock::userFunction('is_singular')->with('post')->andReturn(true);
        WP_Mock::userFunction('get_queried_object')->andReturn($post);
        WP_Mock::userFunction('get_post_meta')
            ->with(42, '_contai_metatitle', true)
            ->andReturn('');

        $result = $this->service->overrideTitleParts(['title' => 'Original Title']);

        $this->assertSame('Original Title', $result['title']);
    }

    public function test_output_meta_description_on_singular_post(): void
    {
        $post = Mockery::mock('WP_Post');
        $post->ID = 42;
        $post->post_excerpt = 'This is a test excerpt for SEO.';

        WP_Mock::userFunction('is_singular')->with('post')->andReturn(true);
        WP_Mock::userFunction('get_queried_object')->andReturn($post);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnArg(0);
        WP_Mock::userFunction('esc_attr')->andReturnArg(0);

        ob_start();
        $this->service->outputMetaDescription();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta name="description"', $output);
        $this->assertStringContainsString('This is a test excerpt for SEO.', $output);
    }

    public function test_output_meta_description_skips_empty_excerpt(): void
    {
        $post = Mockery::mock('WP_Post');
        $post->ID = 42;
        $post->post_excerpt = '';

        WP_Mock::userFunction('is_singular')->with('post')->andReturn(true);
        WP_Mock::userFunction('get_queried_object')->andReturn($post);

        ob_start();
        $this->service->outputMetaDescription();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_output_meta_description_skips_non_singular(): void
    {
        WP_Mock::userFunction('is_singular')->with('post')->andReturn(false);

        ob_start();
        $this->service->outputMetaDescription();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }
}
