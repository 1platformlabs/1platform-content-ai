<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Cover for the wp_footer legal-links fallback (#48).
 *
 * Three of the nine mapped themes (generatepress, sydney, colormag) register no
 * footer nav menu location at all, so neither binding a location nor placing a
 * builder component can ever make the generated legal pages reachable on them.
 * Measured on a live install before this fallback: 0 legal hrefs on all three,
 * 2 or 4 on the other six.
 *
 * The two properties that matter pull in opposite directions and both have to be
 * pinned, because getting either wrong is invisible from the other's test:
 *
 *   - it must RENDER on a theme that renders nothing itself, and
 *   - it must STAY SILENT on the six that already render, or every one of them
 *     gets duplicated legal links.
 */
class FooterLegalFallbackTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/footer-legal-fallback.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── contai_theme_renders_bound_footer_menu() ───────────────────

    /**
     * The allow-list IS the fix's decision. Each entry was measured on a live
     * install; a slug added here without that measurement silently suppresses
     * the fallback for that theme, which is the failure this issue keeps
     * producing.
     */
    public function test_the_six_measured_themes_are_reported_as_rendering_on_their_own(): void
    {
        foreach (['astra', 'oceanwp', 'newsmatic', 'neve', 'blocksy', 'kadence'] as $theme) {
            $this->assertTrue(
                contai_theme_renders_bound_footer_menu($theme),
                "{$theme} was measured rendering the bound footer menu, so the fallback must stay out of its way"
            );
        }
    }

    public function test_the_three_themes_without_a_footer_location_are_not_on_the_allow_list(): void
    {
        foreach (['generatepress', 'sydney', 'colormag'] as $theme) {
            $this->assertFalse(
                contai_theme_renders_bound_footer_menu($theme),
                "{$theme} registers no footer nav location, so it must get the fallback"
            );
        }
    }

    public function test_an_unknown_theme_gets_the_fallback_rather_than_silence(): void
    {
        $this->assertFalse(contai_theme_renders_bound_footer_menu('some-theme-nobody-measured'));
        $this->assertFalse(contai_theme_renders_bound_footer_menu(''));
    }

    // ── wiring ─────────────────────────────────────────────────────

    /**
     * A renderer nobody hooks is this issue's v2.38.7 shape: perfectly correct
     * code that never executes. Calling the function directly in the tests below
     * cannot see that, so the registration is asserted on its own.
     */
    public function test_the_renderer_is_registered_on_wp_footer(): void
    {
        WP_Mock::expectActionAdded('wp_footer', 'contai_render_footer_legal_fallback', 99);

        contai_register_footer_legal_fallback_hooks();

        $this->addToAssertionCount(1);
    }

    /**
     * SOURCE GUARD — declared as such, because it checks FORM, not behaviour.
     *
     * The test above proves the registration function wires the hook at the
     * right priority; it cannot prove the FILE invokes it at load, and the file
     * is required once by the bootstrap so the side effect is long gone by the
     * time any test runs. Dropping that one call leaves every behavioural test
     * in this file green and the fallback never hooked — this issue's v2.38.7
     * shape exactly.
     */
    public function test_source_guard_the_file_registers_its_hooks_at_load(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../includes/helpers/footer-legal-fallback.php');

        $this->assertMatchesRegularExpression(
            '/^contai_register_footer_legal_fallback_hooks\(\);$/m',
            (string) $source,
            'Without a top-level call the renderer is defined and never hooked (#48)'
        );
    }

    // ── contai_footer_legal_menu_id() ──────────────────────────────

    /**
     * @param array<int, object>|false $items
     */
    private function mockMenu($menu, $items, array $legalPageIds = []): void
    {
        WP_Mock::userFunction('wp_get_nav_menu_object', ['return' => $menu]);
        WP_Mock::userFunction('wp_get_nav_menu_items', ['return' => $items]);
        WP_Mock::userFunction('get_post_meta', [
            'return' => function ($postId, $key, $single = false) use ($legalPageIds) {
                if ('_contai_legal_source' !== $key) {
                    return '';
                }

                return in_array((int) $postId, $legalPageIds, true) ? 'contai_api' : '';
            },
        ]);
    }

    private function menuItem(int $objectId, string $type = 'post_type', string $object = 'page'): object
    {
        return (object) ['type' => $type, 'object' => $object, 'object_id' => $objectId];
    }

    public function test_a_site_with_no_footer_menu_renders_nothing(): void
    {
        $this->mockMenu(false, []);

        $this->assertSame(0, contai_footer_legal_menu_id());
    }

    /**
     * wp_get_nav_menu_items() returns false on failure and [] on an empty menu.
     * Both have to end in "render nothing", and the false case has to do so
     * WITHOUT a diagnostic: this callback runs on every front-end request, so
     * `foreach (false)` here would put "foreach() argument must be of type
     * array|object" in the error log of every page view of the site.
     *
     * PHP degrades that to E_WARNING rather than a fatal, so the assertion on
     * the return value alone cannot see it — dropping the guard leaves this test
     * green. The handler is installed around the call under test only, and
     * removed in finally, so it cannot leak into the rest of the suite.
     *
     * A data provider rather than a loop, deliberately: two
     * WP_Mock::userFunction() calls for the same function inside ONE test do not
     * override each other — the first return value keeps winning, so a loop here
     * silently never exercises the second case. Found by this file's own mutant.
     *
     * @dataProvider emptyMenuItemsProvider
     * @param array<int, object>|false $items
     */
    public function test_an_empty_footer_menu_renders_nothing_and_logs_nothing($items): void
    {
        $this->mockMenu((object) ['term_id' => 6], $items);

        set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE | E_DEPRECATED
        );

        try {
            $this->assertSame(0, contai_footer_legal_menu_id());
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{0: array<int, object>|false}> */
    public static function emptyMenuItemsProvider(): array
    {
        return [
            'menu with no items' => [[]],
            'menu items read failed' => [false],
        ];
    }

    /**
     * The wizard names the menu in contai_create_footer_menu_with_legal_pages()
     * and this fallback finds it by name. They share the constant so they cannot
     * drift, and the value is pinned because changing it orphans every menu
     * already generated on a live site.
     */
    public function test_the_menu_name_is_the_one_the_wizard_creates(): void
    {
        $this->assertSame('Footer', CONTAI_FOOTER_MENU_NAME);
    }

    /**
     * This callback runs on every front-end request of every site the plugin is
     * active on. A menu that merely happens to be called "Footer" belongs to the
     * site owner, and appending our own copy of it to their footer would be a
     * defect, not a fix.
     */
    public function test_a_footer_menu_without_generated_legal_pages_is_left_alone(): void
    {
        $this->mockMenu((object) ['term_id' => 6], [$this->menuItem(41), $this->menuItem(42)], []);

        $this->assertSame(0, contai_footer_legal_menu_id());
    }

    public function test_a_footer_menu_carrying_a_generated_legal_page_is_claimed(): void
    {
        $this->mockMenu((object) ['term_id' => 6], [$this->menuItem(41), $this->menuItem(7)], [7]);

        $this->assertSame(6, contai_footer_legal_menu_id());
    }

    /**
     * Custom links and category items carry an object_id that collides with page
     * ids, so the type/object check is load-bearing rather than defensive.
     */
    public function test_a_non_page_item_pointing_at_a_legal_page_id_does_not_claim_the_menu(): void
    {
        $this->mockMenu(
            (object) ['term_id' => 6],
            [$this->menuItem(7, 'taxonomy', 'category'), $this->menuItem(7, 'custom', 'custom')],
            [7]
        );

        $this->assertSame(0, contai_footer_legal_menu_id());
    }

    // ── contai_render_footer_legal_fallback() ──────────────────────

    private function mockRender(
        string $stylesheet,
        string $template,
        $menu,
        $items,
        array $legalPageIds,
        $navReturn
    ): void {
        WP_Mock::userFunction('is_admin', ['return' => false]);
        WP_Mock::userFunction('get_stylesheet', ['return' => $stylesheet]);
        WP_Mock::userFunction('get_template', ['return' => $template]);
        WP_Mock::userFunction('__', ['return' => function ($text) { return $text; }]);
        WP_Mock::userFunction('wp_nav_menu', ['return' => $navReturn]);
        $this->mockMenu($menu, $items, $legalPageIds);
    }

    private function captureRender(): string
    {
        ob_start();
        contai_render_footer_legal_fallback();

        return (string) ob_get_clean();
    }

    public function test_a_theme_without_a_footer_location_gets_the_legal_links_printed(): void
    {
        $this->mockRender(
            'generatepress',
            'generatepress',
            (object) ['term_id' => 6],
            [$this->menuItem(7)],
            [7],
            '<nav id="contai-legal-footer"><ul><li><a href="/legal">Aviso legal</a></li></ul></nav>'
        );

        $out = $this->captureRender();

        $this->assertStringContainsString('contai-legal-footer', $out);
        $this->assertStringContainsString('Aviso legal', $out);
        $this->assertStringContainsString('<style id="contai-legal-footer-css">', $out);
    }

    /**
     * The anti-duplication branch. Measured: astra/oceanwp/newsmatic/neve/
     * blocksy/kadence render the bound menu themselves, and their DOM has to
     * come back byte-identical with this fallback loaded.
     */
    public function test_a_theme_that_renders_the_menu_itself_gets_nothing_appended(): void
    {
        $this->mockRender(
            'astra',
            'astra',
            (object) ['term_id' => 6],
            [$this->menuItem(7)],
            [7],
            '<nav>should never be reached</nav>'
        );

        $this->assertSame('', $this->captureRender());
    }

    /**
     * A child theme keeps its parent's footer templates. Checking only
     * get_stylesheet() would duplicate the links on every child theme of the six
     * measured ones.
     */
    public function test_a_child_of_a_measured_theme_gets_nothing_appended(): void
    {
        $this->mockRender(
            'astra-child',
            'astra',
            (object) ['term_id' => 6],
            [$this->menuItem(7)],
            [7],
            '<nav>should never be reached</nav>'
        );

        $this->assertSame('', $this->captureRender());
    }

    public function test_a_site_the_wizard_never_generated_gets_nothing_appended(): void
    {
        $this->mockRender('generatepress', 'generatepress', false, [], [], '<nav>unused</nav>');

        $this->assertSame('', $this->captureRender());
    }

    /**
     * wp_nav_menu() returns false when the menu resolves to nothing. A nav
     * landmark with no links inside is worse for a screen reader than no
     * landmark at all, so the container must not be emitted on its own.
     *
     * @dataProvider emptyNavReturnProvider
     * @param string|false $navReturn
     */
    public function test_an_empty_menu_render_emits_no_landmark_and_no_style($navReturn): void
    {
        $this->mockRender(
            'colormag',
            'colormag',
            (object) ['term_id' => 6],
            [$this->menuItem(7)],
            [7],
            $navReturn
        );

        $this->assertSame('', $this->captureRender());
    }

    /** @return array<string, array{0: string|false}> */
    public static function emptyNavReturnProvider(): array
    {
        return [
            'wp_nav_menu returned false' => [false],
            'wp_nav_menu returned an empty string' => [''],
            'wp_nav_menu returned whitespace' => ["   \n"],
        ];
    }

    public function test_nothing_is_printed_in_the_admin(): void
    {
        WP_Mock::userFunction('is_admin', ['return' => true]);
        WP_Mock::userFunction('get_stylesheet', ['return' => 'colormag']);
        WP_Mock::userFunction('get_template', ['return' => 'colormag']);
        WP_Mock::userFunction('wp_nav_menu', ['return' => '<nav>should never be reached</nav>']);
        $this->mockMenu((object) ['term_id' => 6], [$this->menuItem(7)], [7]);

        $this->assertSame('', $this->captureRender());
    }
}
