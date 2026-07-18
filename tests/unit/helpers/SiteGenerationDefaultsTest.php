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

    /**
     * Validates GitHub issue #48 (footer legal links missing on 7/7 sites).
     *
     * contai_create_footer_menu_with_legal_pages() resolves the footer nav
     * location from a static theme map and used to write it to
     * nav_menu_locations and `return` unconditionally. WordPress silently
     * ignores entries for locations the active theme does not register, so a
     * wrong/stale map entry produced a silent no-op — and the early return
     * also made the pattern-matching fallback below it, and its diagnostic
     * "no footer location found" log, unreachable for all nine mapped themes.
     *
     * The decision itself is behaviourally covered by NavLocationTest; this
     * guard pins the call site to the predicate so the early return cannot
     * regress to being unconditional.
     */
    public function test_footer_menu_validates_static_location_before_assigning(): void
    {
        $content = file_get_contents($this->helperFile);

        preg_match(
            '/function contai_create_footer_menu_with_legal_pages\(\).*?\n}/s',
            $content,
            $matches
        );
        $body = $matches[0] ?? '';

        $this->assertNotSame('', $body, 'contai_create_footer_menu_with_legal_pages() must exist');

        $this->assertStringContainsString(
            'contai_nav_location_is_usable( $target, $registered )',
            $body,
            'The footer location must be validated against the registered nav menus ' .
            'before short-circuiting, or an unregistered mapped location fails silently (#48)'
        );

        $this->assertStringNotContainsString(
            "if ( \$target ) {\n\t\t\$locations[ \$target ] = \$menu_id;",
            $body,
            'The unvalidated early return must not come back — it makes the pattern-match ' .
            'fallback and the diagnostic warning dead code (#48)'
        );

        $this->assertStringContainsString(
            'WARNING: No footer location found for theme',
            $body,
            'The diagnostic log must remain reachable so the remaining per-theme scope ' .
            'of #48 can be diagnosed from a live install'
        );
    }

    /**
     * Validates the breadcrumbs scope of GitHub issue #48.
     *
     * The 'astra' case configured the theme with set_theme_mod(), which writes
     * to theme_mods_astra. Astra reads none of its settings from theme mods —
     * astra_get_option() resolves against the astra-settings option (Astra
     * 4.13.6: common-functions.php:558 -> class-astra-theme-options.php:933
     * get_option( ASTRA_THEME_SETTINGS ); functions.php:19 defines that as
     * 'astra-settings'). Its only get_theme_mod() calls are for core's
     * 'custom_logo'. So breadcrumbs never turned on and the three sidebar
     * layouts never applied, silently.
     *
     * The key names were wrong as well: Astra reads 'breadcrumb-position',
     * not 'ast-breadcrumbs-position'.
     *
     * NOTE — this is a SOURCE GUARD, not a behavioural test: site-generation.php
     * is not in tests/bootstrap.php and cannot be loaded here. The write path
     * itself is behaviourally covered by AstraSettingsTest; this only pins the
     * call site so it cannot regress to theme mods.
     */
    public function test_astra_case_writes_settings_to_the_astra_settings_option(): void
    {
        $content = file_get_contents($this->helperFile);

        preg_match("/case 'astra':(.*?)break;/s", $content, $matches);
        $astraBlock = $matches[1] ?? '';

        $this->assertNotSame('', $astraBlock, "contai_apply_theme_defaults() must still have an 'astra' case");

        // Strip // comments: the guards below are about executable code, and
        // the block's own explanatory comments legitimately name the API we
        // are banning.
        $astraCode = preg_replace('#//[^\n]*#', '', $astraBlock);

        $this->assertStringContainsString(
            'contai_astra_apply_settings(',
            $astraCode,
            "The 'astra' case must write through contai_astra_apply_settings() so values land " .
            'in the astra-settings option Astra actually reads (#48)'
        );

        $this->assertStringNotContainsString(
            'set_theme_mod(',
            $astraCode,
            'Astra reads no setting from theme mods — such a call here is a silent no-op (#48)'
        );

        $this->assertMatchesRegularExpression(
            "/'breadcrumb-position'\s*=>\s*'astra_entry_top',/",
            $astraCode,
            "Astra's breadcrumb key is 'breadcrumb-position' (defaulting to 'none' = hidden); " .
            "'ast-breadcrumbs-position' does not exist anywhere in the theme (#48)"
        );

        $this->assertStringNotContainsString(
            'ast-breadcrumbs-',
            $astraCode,
            'The ast-breadcrumbs-* keys match nothing in Astra and must not come back (#48)'
        );
    }
}
