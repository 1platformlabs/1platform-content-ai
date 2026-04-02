<?php

namespace ContAI\Tests\Unit\Services\Post;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiContentImageProcessor;
use ContaiImageUploader;

class ContentImageProcessorTest extends TestCase
{
    private $mockUploader;
    private ContaiContentImageProcessor $processor;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->mockUploader = Mockery::mock(ContaiImageUploader::class);
        $this->processor = new ContaiContentImageProcessor($this->mockUploader);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_process_passes_alt_text_to_uploader(): void
    {
        $external_url = 'https://images.pexels.com/photos/123/shoe.jpg';
        $local_url = 'https://example.com/wp-content/uploads/shoe.jpg';
        $content = '<p>Text</p><img src="' . $external_url . '" alt="" />';

        WP_Mock::userFunction('esc_attr')->andReturnArg(0);

        $this->mockUploader
            ->shouldReceive('uploadFromUrl')
            ->once()
            ->with($external_url, 'running shoes')
            ->andReturn(42);

        $this->mockUploader
            ->shouldReceive('getAttachmentUrl')
            ->once()
            ->with(42)
            ->andReturn($local_url);

        $result = $this->processor->process($content, [['url' => $external_url]], 'running shoes');

        $this->assertStringContainsString($local_url, $result);
        $this->assertStringContainsString('alt="running shoes"', $result);
        $this->assertStringNotContainsString('alt=""', $result);
    }

    public function test_process_adds_alt_to_img_without_alt_attribute(): void
    {
        $content = '<img src="https://example.com/local.jpg">';

        WP_Mock::userFunction('esc_attr')->andReturnArg(0);

        $result = $this->processor->process($content, [], 'keyword alt');

        $this->assertStringContainsString('alt="keyword alt"', $result);
    }

    public function test_process_preserves_existing_alt_text(): void
    {
        $content = '<img src="https://example.com/local.jpg" alt="original alt">';

        WP_Mock::userFunction('esc_attr')->andReturnArg(0);

        $result = $this->processor->process($content, [], 'keyword alt');

        $this->assertStringContainsString('alt="original alt"', $result);
        $this->assertStringNotContainsString('alt="keyword alt"', $result);
    }

    public function test_process_without_alt_text_does_not_modify_img_tags(): void
    {
        $content = '<img src="https://example.com/local.jpg" alt="">';

        $result = $this->processor->process($content, [], '');

        $this->assertStringContainsString('alt=""', $result);
    }
}
