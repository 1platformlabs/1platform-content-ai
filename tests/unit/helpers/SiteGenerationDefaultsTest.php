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
            'contai_nav_location_is_usable( $target, $registered, $stale )',
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

        // The diagnostic used to be a bare error_log(). It now goes through the
        // shared recorder, which ALSO error_log()s but additionally persists to
        // an option, so an unbound footer menu can be diagnosed off a live
        // install with `wp option get contai_site_generation_warnings` instead
        // of shell access to the PHP error log (#48).
        $this->assertStringContainsString(
            'contai_record_site_warning(',
            $body,
            'The diagnostic must remain reachable so the remaining per-theme scope ' .
            'of #48 can be diagnosed from a live install'
        );

        $this->assertStringContainsString(
            'no footer location found for theme',
            $body,
            'The diagnostic must still name the theme it failed for'
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

    /**
     * Returns the executable code of one theme's case block, with // comments
     * stripped — the guards below are about what runs, and the blocks
     * legitimately name the dead keys in their explanatory comments.
     */
    private function themeCase(string $theme): string
    {
        $content = file_get_contents($this->helperFile);

        preg_match("/case '{$theme}':(.*?)break;/s", $content, $matches);
        $block = $matches[1] ?? '';

        $this->assertNotSame('', $block, "contai_apply_theme_defaults() must have a '{$theme}' case");

        return preg_replace('#//[^\n]*#', '', $block);
    }

    /**
     * The remaining per-theme scope of GitHub issue #48.
     *
     * Every key below was written by the wizard and read by NOBODY: verified
     * against the theme sources downloaded from wordpress.org, each of these
     * strings has ZERO occurrences anywhere in the theme it targets. They were
     * silent no-ops — no error, no log — which is why breadcrumbs and sidebars
     * never appeared and the issue kept reopening.
     *
     * This is the control that discriminates: the replacement keys asserted in
     * the tests below all have multiple read sites in their theme, while these
     * have none at all.
     *
     * @dataProvider deadKeyProvider
     */
    public function test_dead_theme_keys_are_not_written(string $theme, string $deadKey, string $why): void
    {
        $this->assertStringNotContainsString(
            "'{$deadKey}'",
            $this->themeCase($theme),
            "'{$deadKey}' has zero occurrences in the {$theme} theme — {$why} (#48)"
        );
    }

    public static function deadKeyProvider(): array
    {
        return [
            'newsmatic breadcrumbs' => [
                'newsmatic',
                'newsmatic_breadcrumb_option',
                "the only matches are the section id 'newsmatic_breadcrumb_options_section'; " .
                "the setting is 'site_breadcrumb_option'",
            ],
            'oceanwp blog layout' => [
                'oceanwp',
                'ocean_blog_layout',
                "the archive layout key is 'ocean_blog_archives_layout'",
            ],
            'generatepress container' => [
                'generatepress',
                'content_layout_setting',
                'it is a container-style setting, not a sidebar one, it only accepts ' .
                "'separate-containers'/'one-container', and GeneratePress reads no theme mods at all",
            ],
            'colormag layout' => [
                'colormag',
                'colormag_site_layout',
                'it is a pre-3.0 container-width key that now survives only inside a migration ' .
                'teardown, where an unexpected value corrupts colormag_container_layout',
            ],
            'colormag breadcrumbs' => [
                'colormag',
                'colormag_breadcrumb_display',
                "the gate is 'colormag_breadcrumb_enable'",
            ],
            'neve breadcrumbs' => [
                'neve',
                'neve_breadcrumbs',
                'it is not a setting; Neve free has no stored breadcrumbs toggle',
            ],
            'blocksy single sidebar' => [
                'blocksy',
                'single_has_sidebar',
                "singular views use the structure picker 'single_blog_post_structure'",
            ],
            'blocksy breadcrumbs' => [
                'blocksy',
                'breadcrumb_visibility',
                'breadcrumbs are an entry in the hero elements list',
            ],
            'kadence archive layout' => [
                'kadence',
                'archive_layout',
                "the bare key is never read; the blog archive uses 'post_archive_layout'",
            ],
            'kadence breadcrumbs' => [
                'kadence',
                'breadcrumb_enable',
                "breadcrumbs are the array sub-option 'post_title_element_breadcrumb'",
            ],
            'sydney sidebar' => [
                'sydney',
                'sidebar_position',
                'the mod names are built at runtime as sidebar_single_{post_type}_position',
            ],
            'sydney breadcrumbs' => [
                'sydney',
                'enable_breadcrumbs',
                'Sydney free has no breadcrumbs feature at all — it is a PRO module',
            ],
        ];
    }

    /**
     * The replacement keys, each verified to have read sites in its theme.
     *
     * @dataProvider realKeyProvider
     */
    public function test_real_theme_keys_are_written(string $theme, string $expected): void
    {
        $this->assertStringContainsString(
            $expected,
            $this->themeCase($theme),
            "The '{$theme}' case must write the key the theme actually reads (#48)"
        );
    }

    public static function realKeyProvider(): array
    {
        return [
            // inc/extras/helpers.php:102, default true (inc/theme-starter.php:118)
            ['newsmatic', "set_theme_mod( 'site_breadcrumb_option', true );"],
            // inc/helpers.php:505 oceanwp_post_layout()
            ['oceanwp', "set_theme_mod( 'ocean_blog_archives_layout', 'right-sidebar' );"],
            // inc/helpers.php:2375 oceanwp_has_breadcrumbs()
            ['oceanwp', "set_theme_mod( 'ocean_breadcrumbs', true );"],
            // must go through the option array, never theme mods
            ['generatepress', 'contai_generatepress_apply_settings('],
            ['generatepress', "'blog_layout_setting'   => 'right-sidebar',"],
            // ColorMag's vocabulary uses underscores, not hyphens
            ['colormag', "set_theme_mod( 'colormag_blog_sidebar_layout', 'right_sidebar' );"],
            ['colormag', "set_theme_mod( 'colormag_global_sidebar_layout', 'right_sidebar' );"],
            // header.php:526, compared loosely against 1, default 0 = OFF
            ['colormag', "set_theme_mod( 'colormag_breadcrumb_enable', 1 );"],
            // defaults to full-width, so pages had no sidebar
            ['neve', "set_theme_mod( 'neve_other_pages_sidebar_layout', 'right' );"],
            // inc/sidebar.php:259-266 — a yes/no switch plus a separate position mod
            ['blocksy', "set_theme_mod( 'blog_has_sidebar', 'yes' );"],
            ['blocksy', "set_theme_mod( 'blog_sidebar_position', 'right' );"],
            // 'type-1' = right sidebar (inc/sidebar.php:284-294)
            ['blocksy', "set_theme_mod( 'single_blog_post_structure', 'type-1' );"],
            ['blocksy', 'contai_hero_elements_enable('],
            // inc/components/layout/component.php:838-840
            ['kadence', "set_theme_mod( 'post_archive_layout', 'right' );"],
            ['kadence', "'post_title_element_breadcrumb',"],
            // inc/extras.php:427 and :437
            ['sydney', "set_theme_mod( 'sidebar_archives_position', 'sidebar-right' );"],
            ['sydney', "set_theme_mod( 'sidebar_single_post_position', 'sidebar-right' );"],
        ];
    }

    /**
     * 'neve_default_sidebar_layout' is inert under Neve's defaults (advanced
     * layout options are ON, so per-context keys win), and it is a sentinel in
     * Neve's new-user detection (inc/core/migration_flags.php:99-100): writing
     * it makes Neve treat a brand-new site as a pre-v4 upgrade and silently
     * swap in legacy defaults for blog cards, typography and element order.
     */
    public function test_neve_migration_sentinel_is_not_written(): void
    {
        $this->assertStringNotContainsString(
            "'neve_default_sidebar_layout'",
            $this->themeCase('neve'),
            'Writing this mod trips Neve\'s is_new_user() sentinel and downgrades a fresh ' .
            'site to legacy v3 defaults, while having no layout effect of its own (#48)'
        );
    }

    /**
     * GeneratePress reads nothing from theme mods — every setting resolves
     * through generate_get_option() against the 'generate_settings' option
     * (inc/theme-functions.php:20-33). A set_theme_mod() call in this case
     * block is by definition a silent no-op.
     */
    public function test_generatepress_case_writes_no_theme_mods(): void
    {
        $this->assertStringNotContainsString(
            'set_theme_mod(',
            $this->themeCase('generatepress'),
            'GeneratePress reads no setting from theme mods — such a call is a silent no-op (#48)'
        );
    }
}
