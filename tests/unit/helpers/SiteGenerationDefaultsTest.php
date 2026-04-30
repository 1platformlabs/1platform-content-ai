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
}
