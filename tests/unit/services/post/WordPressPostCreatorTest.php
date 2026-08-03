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

    public function test_create_enables_comments_on_generated_posts(): void
    {
        // #48: generated posts must have comments explicitly enabled instead of
        // relying on the fragile global default_comment_status option.
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
        $creator->create('Test Title', '<p>Body content for the article.</p>');

        $this->assertSame('open', $captured_data['comment_status']);
    }

    // ── post_date timezone (#118/#119) ───────────────────────────

    /**
     * The generation pipeline is the third writer of post_date, and it was the
     * only one deriving the columns in the wrong direction: gmdate() renders
     * GMT, so writing that into the *local* post_date column and then asking
     * get_gmt_from_date() — which reads its argument as local — for the
     * companion pushed both columns off by the site's UTC offset.
     */
    public function test_create_derives_local_post_date_from_gmt(): void
    {
        // Site at UTC-6 (America/Guatemala): the two WordPress helpers are
        // exact inverses, so a wrong direction is visible as a 6h shift.
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnUsing(function ($str) {
            return strip_tags($str);
        });
        WP_Mock::userFunction('get_date_from_gmt')
            ->once()
            ->with('2026-01-15 14:00:00')
            ->andReturn('2026-01-15 08:00:00');
        // The broken direction called this one instead; it must not run at all.
        WP_Mock::userFunction('get_gmt_from_date')->never();
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $captured_data = null;
        WP_Mock::userFunction('wp_insert_post')
            ->once()
            ->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $creator = new ContaiWordPressPostCreator();
        $creator->create('Dated Title', '<p>Body.</p>', null, '2026-01-15T14:00:00Z');

        $this->assertSame('2026-01-15 14:00:00', $captured_data['post_date_gmt']);
        $this->assertSame('2026-01-15 08:00:00', $captured_data['post_date']);
    }

    /**
     * The fallback has to answer in the same clock as the success path:
     * gmdate() is GMT, so current_time() is asked for GMT too. A local
     * fallback would be re-read as GMT by the caller and shifted.
     */
    public function test_create_falls_back_to_the_gmt_clock(): void
    {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_strip_all_tags')->andReturnUsing(function ($str) {
            return strip_tags($str);
        });
        WP_Mock::userFunction('current_time')
            ->once()
            ->with('mysql', true)
            ->andReturn('2026-01-15 14:00:00');
        WP_Mock::userFunction('get_date_from_gmt')
            ->once()
            ->with('2026-01-15 14:00:00')
            ->andReturn('2026-01-15 08:00:00');
        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        $captured_data = null;
        WP_Mock::userFunction('wp_insert_post')
            ->once()
            ->andReturnUsing(function ($data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $creator = new ContaiWordPressPostCreator();
        $creator->create('Undated Title', '<p>Body.</p>', null, 'not a date at all');

        $this->assertSame('2026-01-15 14:00:00', $captured_data['post_date_gmt']);
        $this->assertSame('2026-01-15 08:00:00', $captured_data['post_date']);
    }
}
