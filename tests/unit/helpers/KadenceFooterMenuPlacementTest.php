<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Cover for the Kadence half of the footer builder placement in GitHub issue #48.
 *
 * Same shape as Astra, Neve and Blocksy, one theme over. The wizard binds
 * Kadence's `footer` nav location and stops; Kadence renders its footer from the
 * `footer_items` theme mod (kadence 1.5.2:
 * inc/components/custom_footer/component.php:79 via
 * inc/components/options/component.php:4806), whose default carries only the
 * `footer-html` copyright element in `bottom_1`. With the `footer-navigation`
 * element absent from that layout the bound menu renders nowhere.
 *
 * Measured on a live es_ES install (WordPress 7.0.2, Kadence 1.5.2) with the
 * legal menu bound to `footer`, counting on the DOM with <style>/<script>
 * stripped:
 *
 *   layout default (theme's own)       -> 0 legal links, DOM 18 288 B
 *   `footer-navigation` in bottom_1    -> 2 links, DOM 19 322 B, HTTP 200
 *                                         inside .footer-navigation-wrap
 *                                         > <ul id="footer-menu">
 */
class KadenceFooterMenuPlacementTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var mixed Whatever was written to Kadence's footer items theme mod. */
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

    // ── contai_kadence_footer_items_with_nav() — pure ──────────────

    public function test_seeds_kadences_default_layout_and_places_the_element(): void
    {
        $items = contai_kadence_footer_items_with_nav(null);

        $this->assertNotNull($items, 'an absent layout must be written, not skipped');
        $this->assertSame(
            ['footer-html', 'footer-navigation'],
            $items['bottom']['bottom_1'],
            "the theme's own copyright element must survive and ours is appended"
        );
    }

    /**
     * This is the trap the whole placement turns on. footer-row.php renders
     * exactly kadence()->option('footer_bottom_columns') columns
     * (template-parts/footer/footer-row.php:15,31-45) and that default is the
     * string '1' (inc/components/options/component.php:2283), so bottom_2..5 are
     * outside the rendered range and anything written there is invisible — the
     * same silent no-op that made an earlier Astra placement land in `below_2`.
     */
    public function test_the_element_lands_in_the_only_column_that_renders_by_default(): void
    {
        $items = contai_kadence_footer_items_with_nav(null);

        $this->assertSame('bottom_1', CONTAI_KADENCE_FOOTER_NAV_COLUMN);
        $this->assertContains('footer-navigation', $items['bottom']['bottom_1']);

        foreach (['bottom_2', 'bottom_3', 'bottom_4', 'bottom_5'] as $unrendered) {
            $this->assertNotContains(
                'footer-navigation',
                $items['bottom'][$unrendered],
                "{$unrendered} is outside the default rendered column range"
            );
        }
    }

    public function test_an_owners_existing_elements_survive_and_unrelated_rows_are_untouched(): void
    {
        $current = [
            'top'    => ['top_1' => ['footer-widget1'], 'top_2' => []],
            'middle' => ['middle_1' => ['footer-widget2']],
            'bottom' => ['bottom_1' => ['footer-html', 'footer-social']],
        ];

        $items = contai_kadence_footer_items_with_nav($current);

        $this->assertSame(
            ['footer-html', 'footer-social', 'footer-navigation'],
            $items['bottom']['bottom_1']
        );
        $this->assertSame(['footer-widget1'], $items['top']['top_1'], 'unrelated rows must be untouched');
        $this->assertSame(['footer-widget2'], $items['middle']['middle_1']);
    }

    public function test_a_partial_layout_is_completed_before_being_persisted(): void
    {
        $items = contai_kadence_footer_items_with_nav(['bottom' => ['bottom_1' => []]]);

        foreach (['top', 'middle', 'bottom'] as $row) {
            $this->assertArrayHasKey($row, $items, "{$row} must be restored");
            foreach (['1', '2', '3', '4', '5'] as $n) {
                $this->assertArrayHasKey("{$row}_{$n}", $items[$row], "{$row}_{$n} must be restored");
            }
        }
    }

    public function test_a_layout_that_already_has_the_element_is_left_alone(): void
    {
        $current = [
            'top'    => ['top_1' => ['footer-navigation']],
            'bottom' => ['bottom_1' => ['footer-html']],
        ];

        $this->assertNull(
            contai_kadence_footer_items_with_nav($current),
            'an element the owner already placed must not be duplicated or relocated'
        );
    }

    public function test_the_element_id_is_the_template_kadence_resolves(): void
    {
        // kadence 1.5.2: inc/components/custom_footer/component.php:79-86
        //   get_template_part( 'template-parts/footer/' . $item )
        // so the id IS the template filename: footer-navigation.php.
        $this->assertSame('footer-navigation', CONTAI_KADENCE_FOOTER_NAV_ITEM);
        $this->assertSame('footer_items', CONTAI_KADENCE_FOOTER_ITEMS_MOD);
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
                return $name === CONTAI_KADENCE_FOOTER_ITEMS_MOD ? $currentMod : $default;
            },
        ]);
        WP_Mock::userFunction('set_theme_mod', [
            'return' => function ($name, $value) {
                if ($name === CONTAI_KADENCE_FOOTER_ITEMS_MOD) {
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

    public function test_kadence_gets_the_element_placed_in_its_own_theme_mod(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('kadence', 'footer');

        $this->assertSame(1, $this->writes, 'the layout must actually be persisted');
        $this->assertIsArray($this->written, 'Kadence reads this mod as an array, not a JSON string');
        $this->assertSame(
            ['footer-html', 'footer-navigation'],
            $this->written['bottom']['bottom_1']
        );
    }

    public function test_kadence_is_not_rewritten_when_the_element_is_already_placed(): void
    {
        $this->mockThemeMods(['bottom' => ['bottom_1' => ['footer-html', 'footer-navigation']]]);

        contai_theme_ensure_footer_menu_renders('kadence', 'footer');

        $this->assertSame(0, $this->writes);
    }

    /**
     * The dispatcher must not hand Kadence to the Astra branch: that branch writes
     * nothing and records "this plugin does not manage theme 'kadence'", which is
     * the exact diagnostic this change exists to stop emitting.
     */
    public function test_kadence_no_longer_falls_through_to_the_unmanaged_diagnostic(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('kadence', 'footer');

        $this->assertSame([], $this->renderWarnings());
    }

    /**
     * A Kadence payload must never be written into another theme's mod — the
     * dispatcher routing by slug is the whole guarantee.
     */
    public function test_no_other_theme_receives_a_kadence_layout(): void
    {
        foreach (['blocksy', 'neve', 'generatepress', 'sydney', 'colormag'] as $theme) {
            $this->written = null;
            $this->writes  = 0;
            $this->mockThemeMods(null);

            contai_theme_ensure_footer_menu_renders($theme, 'footer');

            $this->assertSame(0, $this->writes, "{$theme} must not have a Kadence layout written into it");
        }
    }
}
