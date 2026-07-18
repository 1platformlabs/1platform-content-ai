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
    private function mapBody(string $needle, int $expectedEntries): string
    {
        $code = preg_replace('#//[^\n]*#', '', file_get_contents($this->helperFile));

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

    public static function realSidebarIdProvider(): array
    {
        return [
            // neve 4.2.8: sidebar.php:8,14 is_active_sidebar('blog-sidebar')
            ['neve', 'blog-sidebar', 'the theme declares no sidebar-1 anywhere'],
            // kadence 1.5.1: inc/components/layout/component.php:78
            ['kadence', 'sidebar-primary', 'the theme registers sidebar-primary/sidebar-secondary'],
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
            'contai_match_footer_nav_location( $registered )',
            $code,
            'Footer fallback must use the shared matcher (#48)'
        );

        $this->assertStringNotContainsString(
            '$footer_patterns',
            $code,
            'The inline registration-order pattern walk must not survive in site-generation.php (#48)'
        );
    }
}
