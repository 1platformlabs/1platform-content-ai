<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Cover for the Blocksy half of the footer builder placement in GitHub issue #48.
 *
 * Same shape as Astra and Neve, one theme over. The wizard binds Blocksy's
 * `footer` nav location and stops; Blocksy renders its footer from a builder
 * whose layout lives in the `footer_placements` theme mod
 * (blocksy 2.1.50: inc/components/builder/footer-logic.php:229-232), and whose
 * default carries only a `copyright` item in `bottom-row`. With the `menu` item
 * absent from that layout the bound menu renders nowhere.
 *
 * Measured on a live es_ES install (WordPress 7.0.2, Blocksy 2.1.50) with the
 * legal menu bound to `footer`, counting on the DOM with <style>/<script>
 * stripped:
 *
 *   layout default (theme's own)      -> 0 legal links, DOM 13 541 B
 *   `menu` in bottom-row column 0     -> 2 links, DOM 14 141 B, HTTP 200
 *                                        inside <nav id="footer-menu" aria-label="Footer">
 *
 * DOM size is carried through those measurements on purpose: on a builder theme
 * a bad payload does not render nothing, it can take the site down, and only the
 * size column separates "did not work" from "I broke it".
 */
class BlocksyFooterMenuPlacementTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var mixed Whatever was written to Blocksy's footer layout theme mod. */
    private $written = null;

    /** @var int How many times set_theme_mod() was called for that mod. */
    private int $writes = 0;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/theme-footer.php';

        $this->warnings = [];
        $this->written  = null;
        $this->writes   = 0;
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── contai_blocksy_footer_placements_with_menu() — pure ────────

    public function test_seeds_blocksys_default_layout_and_places_the_item(): void
    {
        $layout = contai_blocksy_footer_placements_with_menu(null);

        $this->assertNotNull($layout, 'an absent layout must be written, not skipped');
        $this->assertSame(
            ['copyright', 'menu'],
            $layout['sections'][0]['rows'][2]['columns'][0],
            "the theme's own copyright item must survive and ours is appended"
        );
    }

    /**
     * render_row() renders exactly count($row['columns']) columns
     * (builder-footer-renderer.php:70-74) and the theme default gives bottom-row
     * ONE column, so anything written to a second column sits outside the
     * rendered range and is invisible — the silent no-op that made an earlier
     * Astra placement land in `below_2`.
     */
    public function test_the_item_lands_in_the_column_that_actually_renders(): void
    {
        $layout = contai_blocksy_footer_placements_with_menu(null);
        $row    = $layout['sections'][0]['rows'][2];

        $this->assertSame('bottom-row', $row['id']);
        $this->assertContains(
            'menu',
            $row['columns'][0],
            'column 0 is the only index guaranteed to be inside the rendered range'
        );
    }

    /**
     * get_current_section() returns the section whose id is `type-1`
     * (footer-logic.php:242-271), so writing into any other section is a no-op.
     */
    public function test_the_item_goes_into_the_section_blocksy_actually_renders(): void
    {
        $layout = contai_blocksy_footer_placements_with_menu(null);

        $index = contai_blocksy_rendered_section_index($layout);

        $this->assertSame('type-1', $layout['sections'][$index]['id']);
        $this->assertContains('menu', $layout['sections'][$index]['rows'][2]['columns'][0]);
    }

    public function test_the_rendered_section_is_found_even_when_type_1_is_not_first(): void
    {
        $layout = [
            'sections' => [
                ['id' => 'type-2', 'rows' => []],
                ['id' => 'type-1', 'rows' => []],
            ],
        ];

        $this->assertSame(1, contai_blocksy_rendered_section_index($layout));
    }

    public function test_an_owners_existing_items_survive_and_unrelated_rows_are_untouched(): void
    {
        $current = [
            'current_section' => 'type-1',
            'sections'        => [
                [
                    'id'   => 'type-1',
                    'rows' => [
                        ['id' => 'top-row', 'columns' => [[], []]],
                        ['id' => 'middle-row', 'columns' => [['widget-area-1'], [], []]],
                        ['id' => 'bottom-row', 'columns' => [['copyright', 'socials']]],
                    ],
                ],
            ],
        ];

        $layout = contai_blocksy_footer_placements_with_menu($current);

        $this->assertSame(
            ['copyright', 'socials', 'menu'],
            $layout['sections'][0]['rows'][2]['columns'][0]
        );
        $this->assertSame(
            ['widget-area-1'],
            $layout['sections'][0]['rows'][1]['columns'][0],
            'unrelated rows must be untouched'
        );
    }

    public function test_a_layout_that_already_has_the_item_is_left_alone(): void
    {
        $current = [
            'sections' => [
                [
                    'id'   => 'type-1',
                    'rows' => [
                        ['id' => 'top-row', 'columns' => [['menu']]],
                        ['id' => 'bottom-row', 'columns' => [['copyright']]],
                    ],
                ],
            ],
        ];

        $this->assertNull(
            contai_blocksy_footer_placements_with_menu($current),
            'an item the owner already placed must not be duplicated or relocated'
        );
    }

    public function test_the_item_id_is_the_one_blocksy_derives_from_its_component_directory(): void
    {
        // blocksy 2.1.50: inc/components/customizer-builder.php:294
        //   $id = str_replace('_', '-', basename($single_item));
        // over inc/panel-builder/footer/* — the `menu` directory.
        $this->assertSame('menu', CONTAI_BLOCKSY_FOOTER_MENU_ITEM);
        $this->assertSame('footer_placements', CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD);
    }

    // ── wired through the dispatcher ───────────────────────────────

    /** @param mixed $currentMod */
    private function mockThemeMods($currentMod): void
    {
        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    return $this->warnings;
                }

                return $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    $this->warnings = $value;
                }

                return true;
            },
        ]);
        WP_Mock::userFunction('get_theme_mod', [
            'return' => function ($name, $default = false) use ($currentMod) {
                return $name === CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD ? $currentMod : $default;
            },
        ]);
        WP_Mock::userFunction('set_theme_mod', [
            'return' => function ($name, $value) {
                if ($name === CONTAI_BLOCKSY_FOOTER_LAYOUT_MOD) {
                    $this->written = $value;
                    $this->writes++;
                }

                return true;
            },
        ]);
        WP_Mock::userFunction('wp_json_encode', [
            'return' => function ($value) {
                return json_encode($value);
            },
        ]);
        WP_Mock::userFunction('contai_log', ['return' => null]);
    }

    /** @return array<int, string> Messages of the recorded render warnings. */
    private function renderWarnings(): array
    {
        $out = [];
        foreach ($this->warnings as $warning) {
            if (($warning['step'] ?? '') === 'footer menu render') {
                $out[] = $warning['message'];
            }
        }

        return $out;
    }

    public function test_blocksy_gets_the_item_placed_in_its_own_theme_mod(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('blocksy', 'footer');

        $this->assertSame(1, $this->writes, 'the layout must actually be persisted');
        $this->assertIsArray($this->written, 'Blocksy reads this mod as an array, not a JSON string');
        $this->assertSame(
            ['copyright', 'menu'],
            $this->written['sections'][0]['rows'][2]['columns'][0]
        );
    }

    public function test_blocksy_is_not_rewritten_when_the_item_is_already_placed(): void
    {
        $this->mockThemeMods([
            'sections' => [
                ['id' => 'type-1', 'rows' => [['id' => 'bottom-row', 'columns' => [['menu']]]]],
            ],
        ]);

        contai_theme_ensure_footer_menu_renders('blocksy', 'footer');

        $this->assertSame(0, $this->writes);
    }

    /**
     * The dispatcher must not hand Blocksy to the Astra branch: that branch writes
     * nothing and records "this plugin does not manage theme 'blocksy'", which is
     * the exact diagnostic this change exists to stop emitting.
     */
    public function test_blocksy_no_longer_falls_through_to_the_unmanaged_diagnostic(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('blocksy', 'footer');

        $this->assertSame([], $this->renderWarnings());
    }

    /**
     * A Blocksy payload must never be written into another theme's mod — the
     * dispatcher routing by slug is the whole guarantee.
     */
    public function test_no_other_theme_receives_a_blocksy_layout(): void
    {
        foreach (['kadence', 'neve', 'generatepress', 'sydney', 'colormag'] as $theme) {
            $this->written = null;
            $this->writes  = 0;
            $this->mockThemeMods(null);

            contai_theme_ensure_footer_menu_renders($theme, 'footer');

            $this->assertSame(0, $this->writes, "{$theme} must not have a Blocksy layout written into it");
        }
    }
}
