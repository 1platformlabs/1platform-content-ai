<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for contai_apply_theme_defaults() in site-generation.php.
 *
 * Validates GitHub issue #112: site wizard must set posts_per_page to 15
 * (raised from WordPress default 10) so generated sites show more entries
 * per page in blog/archive views.
 */
class SiteGenerationDefaultsTest extends TestCase
{
    private string $helperFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->helperFile = dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';
    }

    public function test_apply_theme_defaults_sets_posts_per_page_to_15(): void
    {
        $content = file_get_contents($this->helperFile);

        $this->assertStringContainsString(
            "update_option( 'posts_per_page', 15 );",
            $content,
            'contai_apply_theme_defaults() must set posts_per_page to 15 (#112)'
        );

        $this->assertStringNotContainsString(
            "update_option( 'posts_per_page', 10 );",
            $content,
            'Legacy posts_per_page=10 must not remain in site-generation.php (#112)'
        );
    }

    /**
     * Validates GitHub issue #46: Neve-themed sites showed no sidebar (and thus no
     * "Sobre mí"/About Me widget) on the blog/category archive — only on single posts.
     *
     * Root cause: Neve's "Advanced Options" (enabled by default) route the archive
     * layout through `neve_blog_archive_sidebar_layout`, which defaults to full-width
     * independently of `neve_default_sidebar_layout`. The 'neve' case previously set
     * the sitewide default and the single-post mod, but never the archive-specific one.
     */
    public function test_apply_theme_defaults_sets_neve_archive_sidebar_layout(): void
    {
        $content = file_get_contents($this->helperFile);

        $this->assertMatchesRegularExpression(
            "/case 'neve':.*?break;/s",
            $content,
            "contai_apply_theme_defaults() must still have a 'neve' case (#46)"
        );

        preg_match("/case 'neve':(.*?)break;/s", $content, $matches);
        $neveBlock = $matches[1] ?? '';

        $this->assertStringContainsString(
            "set_theme_mod( 'neve_blog_archive_sidebar_layout', 'right' );",
            $neveBlock,
            "The 'neve' case must set neve_blog_archive_sidebar_layout, or the blog/category " .
            'archive silently renders full-width with no sidebar widgets (#46)'
        );

        $this->assertStringContainsString(
            "set_theme_mod( 'neve_single_post_sidebar_layout', 'right' );",
            $neveBlock,
            'Regression guard: the existing single-post sidebar mod must remain set'
        );
    }
}
