<?php

namespace ContAI\Tests\Unit\Services\Post;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiWordPressPostCreator;

class WordPressPostCreatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_sets_post_excerpt_from_content(): void
    {
        $content = '<p>This is a test article about running shoes that covers everything you need to know.</p>';

        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnUsing(function ($str) {
            return strip_tags($str);
        });

        $captured_data = null;
        WP_Mock::userFunction('wp_insert_post')
            ->once()
            ->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $creator = new ContaiWordPressPostCreator();
        $post_id = $creator->create('Test Title', $content);

        $this->assertSame(1, $post_id);
        $this->assertNotEmpty($captured_data['post_excerpt']);
        $this->assertStringContainsString('running shoes', $captured_data['post_excerpt']);
    }

    public function test_create_truncates_long_excerpt_at_word_boundary(): void
    {
        $content = '<p>' . str_repeat('Word ', 100) . '</p>';

        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnUsing(function ($str) {
            return strip_tags($str);
        });

        $captured_data = null;
        WP_Mock::userFunction('wp_insert_post')
            ->once()
            ->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $creator = new ContaiWordPressPostCreator();
        $creator->create('Long content', $content);

        $this->assertLessThanOrEqual(160, mb_strlen($captured_data['post_excerpt']));
        $this->assertStringEndsWith('...', $captured_data['post_excerpt']);
    }
}
