<?php

namespace ContAI\Tests\Unit\Services\Post;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiImageUploader;

class ImageUploaderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        WP_Mock::userFunction('contai_log')->andReturn(null);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function mockSuccessfulUpload(): void
    {
        WP_Mock::userFunction('download_url')->andReturn('/tmp/test-image.jpg');
        WP_Mock::userFunction('wp_parse_url')->andReturnUsing(function ($url, $component) {
            return parse_url($url, $component);
        });
        WP_Mock::userFunction('media_handle_sideload')->andReturn(42);
        WP_Mock::userFunction('wp_delete_file')->andReturn(true);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);
    }

    public function test_upload_sets_alt_text_when_provided(): void
    {
        $this->mockSuccessfulUpload();
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(42, '_wp_attachment_image_alt', 'Best running shoes 2025')
            ->andReturn(true);

        $uploader = new ContaiImageUploader();
        $result = $uploader->uploadFromUrl('https://example.com/shoe.jpg', 'Best running shoes 2025');

        $this->assertSame(42, $result);
    }

    public function test_upload_skips_alt_text_when_empty(): void
    {
        $this->mockSuccessfulUpload();
        WP_Mock::userFunction('update_post_meta')->never();

        $uploader = new ContaiImageUploader();
        $result = $uploader->uploadFromUrl('https://example.com/shoe.jpg');

        $this->assertSame(42, $result);
    }
}
