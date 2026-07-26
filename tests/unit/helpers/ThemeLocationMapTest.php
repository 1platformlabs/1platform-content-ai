<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the three hand-maintained theme maps in
 * site-generation.php: CONTAI_THEME_SIDEBAR_MAP, CONTAI_THEME_NAV_LOCATION_MAP
 * and the footer map inside contai_assign_legal_menu_to_footer().
 *
 * Same failure mode as the theme-mod keys corrected in v2.38.9 (#48), one layer
 * up: WordPress silently drops a nav_menu_locations entry for a location the
 * active theme never registered, and register_sidebar()/dynamic_sidebar() do
 * the same for an unknown sidebar ID. No error, no log, no effect — so a wrong
 * map entry looks exactly like a working one until somebody opens the site.
 *
 * Worse, a theme whose primary nav location has no menu assigned falls back to
 * wp_page_menu(), which lists the published PAGES — i.e. the generated legal
 * pages. That is verbatim the symptom this issue was reopened with.
 *
 * Every expectation below was verified by downloading the theme from
 * wordpress.org and reading its register_nav_menus()/register_sidebar() call.
 * These are source guards: site-generation.php is not part of the unit-test
 * bootstrap (it pulls in the API client and WordPress upgrader paths), so the
 * constants cannot be resolved under test. Stated plainly rather than dressed
 * up as behavioural cover.
 */
class ThemeLocationMapTest extends TestCase
{
    private string $helperFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->helperFile = dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';
    }

    /**
     * Extract one map literal as CODE.
     *
     * Comments are stripped BEFORE the map is located, not after. Stripping
     * afterwards silently truncates the map: the source comments cite theme
     * files as "component.php:35,86)", and a non-greedy match for the closing
     * ");" stops on that literal, yielding a short block in which the entries
     * under test are simply absent — an assertion failure that reads like a
     * missing map entry when the map is fine. Same shape as the source guard
     * that tripped over its own comment in v2.38.8: an assertion about code has
     * to run on code.
     */
    private function mapBody(string $needle, int $expectedEntries, ?string $file = null): string
    {
        $code = preg_replace('#//[^\n]*#', '', file_get_contents($file ?? $this->helperFile));

        preg_match('/' . preg_quote($needle, '/') . '(.*?)\);/s', $code, $matches);
        $block = $matches[1] ?? '';

        $this->assertNotSame('', $block, "site-generation.php must still define {$needle}");

        // Truncation guard: a short read must fail loudly here rather than turn
        // every "is this entry correct?" assertion into a false negative.
        $this->assertSame(
            $expectedEntries,
            preg_match_all("/'[a-z]+'\s*=>/", $block),
            "Extracted {$needle} does not hold the expected number of theme entries — " .
            'the map literal was probably truncated, not the map wrong'
        );

        return $block;
    }

    private function sidebarMap(): string
    {
        return $this->mapBody("define( 'CONTAI_THEME_SIDEBAR_MAP', array(", 9);
    }

    private function navMap(): string
    {
        return $this->mapBody("define( 'CONTAI_THEME_NAV_LOCATION_MAP', array(", 9);
    }

    /**
     * Six, not nine: generatepress, sydney and colormag register no footer nav
     * location at all in their free build and are deliberately absent.
     */
    private function footerMap(): string
    {
        return $this->mapBody('$theme_footer_map = array(', 6);
    }

    // ── Sidebar IDs ────────────────────────────────────────────────

    /**
     * @dataProvider realSidebarIdProvider
     */
    public function test_sidebar_map_uses_ids_the_theme_registers(string $theme, string $id, string $where): void
    {
        $this->assertMatchesRegularExpression(
            "/'{$theme}'\s*=>\s*'" . preg_quote($id, '/') . "'/",
            $this->sidebarMap(),
            "The '{$theme}' sidebar must be '{$id}' — {$where} (#48)"
        );
    }

    /**
     * All nine entries, each read out of that theme's own register_sidebar()
     * call. Only three were ever verified before; the other six carried the same
     * unaudited provenance as the nav maps that turned out to be 9/27 wrong, so
     * they are pinned here rather than trusted (#48).
     */
    public static function realSidebarIdProvider(): array
    {
        return [
            // astra 4.13.6: inc/widgets.php:94 'id' => 'sidebar-1'
            ['astra', 'sidebar-1', 'inc/widgets.php:94 registers it'],
            // generatepress 3.6.1: inc/general.php:153 seeds the $widgets array
            // that inc/general.php:166 loops into register_sidebar()
            ['generatepress', 'sidebar-1', 'inc/general.php:153 registers it as "Right Sidebar"'],
            // neve 4.2.8: inc/core/front_end.php:529, rendered by sidebar.php:8,14
            ['neve', 'blog-sidebar', 'the theme declares no sidebar-1 anywhere'],
            // blocksy 2.1.49: inc/init.php:475 'id' => 'sidebar-1'
            ['blocksy', 'sidebar-1', 'inc/init.php:475 registers it'],
            // kadence 1.5.1: inc/components/layout/component.php:79
            ['kadence', 'sidebar-primary', 'the theme registers sidebar-primary/sidebar-secondary'],
            // sydney 2.69: functions.php:172 'id' => 'sidebar-1'
            ['sydney', 'sidebar-1', 'functions.php:172 registers it'],
            // oceanwp: functions.php:778 'id' => 'sidebar' — no -1 suffix
            ['oceanwp', 'sidebar', 'functions.php:778 registers a bare "sidebar"'],
            // newsmatic 1.5.0: inc/widgets/widgets.php:18 'id' => 'sidebar-1'
            ['newsmatic', 'sidebar-1', 'inc/widgets/widgets.php:18 registers it'],
            // colormag 4.2.1: inc/widgets/class-colormag-widgets.php:27
            ['colormag', 'colormag_right_sidebar', 'ColorMag prefixes every widget area'],
        ];
    }

    /**
     * @dataProvider deadSidebarIdProvider
     */
    public function test_sidebar_map_drops_ids_the_theme_never_declares(string $theme, string $dead): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/'{$theme}'\s*=>\s*'" . preg_quote($dead, '/') . "'/",
            $this->sidebarMap(),
            "'{$dead}' has zero occurrences in the {$theme} theme, so widgets " .
            'registered against it silently never render (#48)'
        );
    }

    public static function deadSidebarIdProvider(): array
    {
        return [
            ['neve', 'sidebar-1'],
            ['kadence', 'sidebar-1'],
            ['colormag', 'sidebar-right'],
        ];
    }

    // ── Primary nav locations ──────────────────────────────────────

    /**
     * Blocksy registers footer/menu_1/menu_2/menu_mobile (blocksy 2.1.49:
     * inc/init.php:409-412). 'header-menu-1' is not among them, so the wizard
     * assigned nothing and every Blocksy site rendered the wp_page_menu()
     * fallback — a nav bar listing the generated legal pages.
     */
    public function test_blocksy_primary_location_is_registered_by_the_theme(): void
    {
        $map = $this->navMap();

        $this->assertMatchesRegularExpression(
            "/'blocksy'\s*=>\s*'menu_1'/",
            $map,
            "Blocksy's first header menu location is 'menu_1' (#48)"
        );

        $this->assertStringNotContainsString(
            'header-menu-1',
            $map,
            "'header-menu-1' is not registered by Blocksy; assigning it is a silent no-op (#48)"
        );
    }

    /**
     * newsmatic registers menu-1/menu-2/menu-3. Both are real, so this is not a
     * silent no-op — it is the wrong slot: 'menu-1' is the thin top bar
     * (inc/hooks/header-hooks.php:347) while the main header nav reads 'menu-2'
     * (header-hooks.php:186), which is also what every one of the theme's own
     * demo imports assigns.
     */
    public function test_newsmatic_primary_location_is_the_main_header_not_the_top_bar(): void
    {
        $this->assertMatchesRegularExpression(
            "/'newsmatic'\s*=>\s*'menu-2'/",
            $this->navMap(),
            "Newsmatic's main header navigation is 'menu-2'; 'menu-1' is the top bar (#48)"
        );
    }

    // ── Footer nav locations ───────────────────────────────────────

    /**
     * @dataProvider realFooterLocationProvider
     */
    public function test_footer_map_uses_locations_the_theme_registers(string $theme, string $location, string $where): void
    {
        $this->assertMatchesRegularExpression(
            "/'{$theme}'\s*=>\s*'" . preg_quote($location, '/') . "'/",
            $this->footerMap(),
            "The '{$theme}' footer location must be '{$location}' — {$where} (#48)"
        );
    }

    public static function realFooterLocationProvider(): array
    {
        return [
            // kadence 1.5.1: FOOTER_NAV_MENU_SLUG, inc/components/nav_menus/component.php:35,86
            ['kadence', 'footer', "the theme's constant is 'footer'; 'footer_navigation' appears nowhere"],
            // newsmatic 1.5.0: inc/hooks/footer-hooks.php:68-71, guarded by has_nav_menu('menu-3')
            ['newsmatic', 'menu-3', "'footer-menu' is not registered, so no footer nav rendered at all"],
        ];
    }

    /**
     * Three of the nine supported themes register NO footer menu location in
     * their free build, so the honest map entry is no entry: generatepress
     * 3.6.1 registers only 'primary' (functions.php:56-60), sydney 2.69 only
     * primary/mobile (functions.php:56-65), colormag 4.2.1 only
     * primary/menu-secondary (inc/core/class-colormag-after-setup-theme.php:315).
     *
     * Guessing a plausible slug here would recreate precisely the silent no-op
     * this issue is about. With no entry, the pattern fallback runs and, failing
     * that, the diagnostic log names the locations the theme really has.
     *
     * @dataProvider themeWithoutFooterLocationProvider
     */
    public function test_themes_without_a_footer_location_are_absent_from_the_map(string $theme): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/'{$theme}'\s*=>/",
            $this->footerMap(),
            "{$theme} registers no footer nav location; mapping one is a silent no-op (#48)"
        );
    }

    public static function themeWithoutFooterLocationProvider(): array
    {
        return [
            ['generatepress'],
            ['sydney'],
            ['colormag'],
        ];
    }

    /**
     * The footer assignment must go through the shared, unit-tested matcher
     * rather than re-implementing the pattern walk inline — the inline version
     * ranked candidates by registration order, which is what mis-assigned
     * Kadence's footer menu to its secondary header nav.
     */
    public function test_footer_assignment_delegates_to_the_shared_matcher(): void
    {
        $content = file_get_contents($this->helperFile);
        $code    = preg_replace('#//[^\n]*#', '', $content);

        $this->assertStringContainsString(
            'contai_match_footer_nav_location( $registered, $stale )',
            $code,
            'Footer fallback must use the shared matcher (#48)'
        );

        $this->assertStringNotContainsString(
            '$footer_patterns',
            $code,
            'The inline registration-order pattern walk must not survive in site-generation.php (#48)'
        );
    }

    // ── Off-canvas / mobile nav locations ──────────────────────────

    /**
     * Two, not nine: an entry here CHANGES what the theme renders, so it is
     * only added for a theme whose off-canvas element was observed falling back
     * to wp_page_menu() on a live install and observed to stop when bound.
     */
    private function mobileNavMap(): string
    {
        // Lives in nav-location.php, not site-generation.php: MainMenuManager
        // requires nav-location.php directly, so the resolver next to this map
        // is reachable from a unit test instead of being skipped by a
        // function_exists() guard — which is the failure this issue is made of.
        return $this->mapBody(
            "define( 'CONTAI_THEME_MOBILE_NAV_LOCATION_MAP', array(",
            2,
            dirname(__DIR__, 3) . '/includes/helpers/nav-location.php'
        );
    }

    /**
     * @dataProvider realMobileNavLocationProvider
     */
    public function test_mobile_nav_map_uses_locations_the_theme_registers(string $theme, string $location, string $where): void
    {
        $this->assertMatchesRegularExpression(
            "/'{$theme}'\s*=>\s*'" . preg_quote($location, '/') . "'/",
            $this->mobileNavMap(),
            "The '{$theme}' off-canvas menu must be '{$location}' — {$where} (#48)"
        );
    }

    /**
     * Literals written by hand from each theme's own registration, NOT read
     * back out of the constant they pin — a guard whose reference value comes
     * from its own subject cannot fail.
     *
     * Both were measured on a live es_ES install (WordPress 7.0.2, plugin
     * 2.39.0): with the location unbound the home page served three
     * `page_item page-item-N` entries, including the generated legal pages;
     * bound, zero.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function realMobileNavLocationProvider(): array
    {
        return [
            'astra off-canvas'   => ['astra', 'mobile_menu', 'astra 4.13.6 registers mobile_menu ("Off-Canvas Menu"), rendered by #ast-hf-mobile-menu'],
            'blocksy off-canvas' => ['blocksy', 'menu_mobile', 'blocksy 2.1.50 registers menu_mobile, rendered by #offcanvas'],
        ];
    }

    /**
     * The seven themes without an entry are a decision, not an oversight:
     * generatepress/neve/newsmatic/colormag register no off-canvas location at
     * all, and kadence/sydney/oceanwp register one their template leaves empty
     * without falling back to the page listing. Assigning a menu to a location
     * a theme deliberately leaves empty changes what that theme renders, so an
     * unmeasured theme must not appear here.
     *
     * @dataProvider unmappedMobileThemeProvider
     */
    public function test_themes_without_a_measured_fallback_stay_out_of_the_mobile_map(string $theme): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/'{$theme}'\s*=>/",
            $this->mobileNavMap(),
            "'{$theme}' rendered no wp_page_menu() fallback on a live install; adding it would " .
            'change what the theme renders on evidence nobody collected (#48)'
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unmappedMobileThemeProvider(): array
    {
        return [
            'generatepress' => ['generatepress'],
            'neve'          => ['neve'],
            'kadence'       => ['kadence'],
            'sydney'        => ['sydney'],
            'oceanwp'       => ['oceanwp'],
            'newsmatic'     => ['newsmatic'],
            'colormag'      => ['colormag'],
        ];
    }
}
