<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Cover for the footer builder placement half of GitHub issue #48.
 *
 * The wizard bound the footer nav location and stopped. On Astra that binding
 * is necessary but NOT sufficient: the footer is a builder, its row template
 * emits one do_action() per component slug found in the layout
 * (astra 4.13.6 template-parts/footer/builder/components.php:152-158) and the
 * menu is rendered only from inside that `case 'menu':` arm. With the slug
 * absent from the layout — Astra's default has only 'copyright'
 * (inc/core/builder/class-astra-builder-options.php:404-425) — the bound menu
 * renders nowhere.
 *
 * Measured on a live es_ES install (WordPress 7.0.2, plugin 2.39.1, Astra
 * 4.13.6) with footer_menu bound to a menu holding both legal pages:
 *
 *   layout default (no 'menu')      -> 0 legal links in the DOM
 *   'menu' in below_2, 1 column     -> 0 legal links in the DOM
 *   'menu' in below_1               -> 1 link each, #astra-footer-menu present
 *
 * The middle row is why the zone is pinned: footer-row.php:56-59 breaks out of
 * the column loop past the row's column count, and the below row defaults to
 * one column (hbb-footer-column = '1', class-astra-builder-options.php:590).
 */
class AstraFooterMenuPlacementTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var array<string, mixed> Whatever was written to the astra-settings option. */
    private array $written = [];

    /** @var int How many times update_option() was called for astra-settings. */
    private int $writes = 0;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/astra-settings.php';

        $this->warnings = [];
        $this->written  = [];
        $this->writes   = 0;
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── contai_astra_footer_items_with_menu() — pure ───────────────

    public function test_seeds_astra_default_layout_and_places_the_menu(): void
    {
        $items = contai_astra_footer_items_with_menu(null);

        $this->assertNotNull($items, 'an absent layout must be written, not skipped');
        $this->assertSame(['copyright', 'menu'], $items['below']['below_1']);
    }

    /**
     * The zone is load-bearing, not cosmetic: below_2 is outside the rendered
     * column range on a default install and renders nothing.
     */
    public function test_the_menu_lands_in_the_zone_the_theme_actually_renders(): void
    {
        $items = contai_astra_footer_items_with_menu(null);

        $this->assertContains('menu', $items['below']['below_1']);
        foreach (['below_2', 'below_3', 'below_4', 'below_5'] as $unrendered) {
            $this->assertNotContains(
                'menu',
                $items['below'][$unrendered],
                "the menu must not be parked in {$unrendered}: past the column count it never renders"
            );
        }
    }

    public function test_the_default_copyright_component_survives(): void
    {
        $items = contai_astra_footer_items_with_menu(null);

        $this->assertContains('copyright', $items['below']['below_1']);
    }

    public function test_an_existing_layout_keeps_every_component_the_owner_placed(): void
    {
        $items = contai_astra_footer_items_with_menu([
            'above'   => ['above_1' => ['widget-1']],
            'primary' => ['primary_1' => ['html-1', 'social-1']],
            'below'   => ['below_1' => ['copyright']],
        ]);

        $this->assertSame(['widget-1'], $items['above']['above_1']);
        $this->assertSame(['html-1', 'social-1'], $items['primary']['primary_1']);
        $this->assertSame(['copyright', 'menu'], $items['below']['below_1']);
    }

    /**
     * Idempotency. The wizard can run more than once and the caller skips the
     * write entirely on null, so a second pass must not stack a second 'menu'.
     */
    public function test_a_layout_that_already_has_the_menu_is_left_alone(): void
    {
        $this->assertNull(contai_astra_footer_items_with_menu([
            'below' => ['below_1' => ['copyright', 'menu']],
        ]));
    }

    /**
     * Placed by the site owner somewhere else — including a zone that does not
     * render. Relocating their component silently is the very move this issue
     * is about, so we do not.
     */
    public function test_the_menu_placed_in_another_row_is_not_duplicated(): void
    {
        $this->assertNull(contai_astra_footer_items_with_menu([
            'primary' => ['primary_2' => ['menu']],
            'below'   => ['below_1' => ['copyright']],
        ]));
        $this->assertNull(contai_astra_footer_items_with_menu([
            'below' => ['below_1' => ['copyright'], 'below_4' => ['menu']],
        ]));
    }

    public function test_a_partial_layout_is_completed_with_the_missing_rows(): void
    {
        $items = contai_astra_footer_items_with_menu(['below' => ['below_1' => ['copyright']]]);

        $this->assertArrayHasKey('above', $items);
        $this->assertArrayHasKey('primary', $items);
        $this->assertSame(['copyright', 'menu'], $items['below']['below_1']);
    }

    /** A corrupted/scalar option must not blow up or be written into. */
    public function test_a_non_array_layout_falls_back_to_the_theme_default(): void
    {
        foreach ([null, false, '', 'corrupted', 42, []] as $garbage) {
            $items = contai_astra_footer_items_with_menu($garbage);
            $this->assertSame(
                ['copyright', 'menu'],
                $items['below']['below_1'],
                'a layout of ' . var_export($garbage, true) . ' must fall back to the documented default'
            );
        }
    }

    // ── contai_astra_ensure_footer_menu_renders() — wired ──────────

    private function mockOptions(array $astraSettings): void
    {
        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) use ($astraSettings) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    return $this->warnings;
                }
                if ($name === CONTAI_ASTRA_SETTINGS_OPTION) {
                    return $astraSettings;
                }

                return $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    $this->warnings = $value;
                }
                if ($name === CONTAI_ASTRA_SETTINGS_OPTION) {
                    $this->written = $value;
                    $this->writes++;
                }

                return true;
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

    public function test_astra_gets_the_component_placed_in_its_own_settings_option(): void
    {
        $this->mockOptions(['blog-width' => 'custom']);

        contai_astra_ensure_footer_menu_renders('astra', 'footer_menu');

        $this->assertSame(1, $this->writes, 'the layout must actually be persisted');
        $this->assertSame(
            ['copyright', 'menu'],
            $this->written[CONTAI_ASTRA_FOOTER_ITEMS_KEY]['below']['below_1']
        );
        $this->assertSame(
            'custom',
            $this->written['blog-width'],
            'unrelated Astra settings live in the same serialized array and must survive'
        );
        $this->assertSame([], $this->renderWarnings(), 'a theme we handle needs no warning');
    }

    public function test_astra_is_not_rewritten_when_the_component_is_already_placed(): void
    {
        $this->mockOptions([
            CONTAI_ASTRA_FOOTER_ITEMS_KEY => ['below' => ['below_1' => ['copyright', 'menu']]],
        ]);

        contai_astra_ensure_footer_menu_renders('astra', 'footer_menu');

        $this->assertSame(0, $this->writes);
    }

    /**
     * The other eight themes get the diagnostic, never a guessed layout. A
     * hand-built payload for Neve's header-footer-grid fataled the entire front
     * end (array_combine() in Abstract_Builder.php:898, neve 4.2.9), so an
     * unmeasured theme is strictly more dangerous than an unbound one.
     */
    public function test_an_unhandled_theme_is_reported_and_never_written_to(): void
    {
        $this->mockOptions([]);

        contai_astra_ensure_footer_menu_renders('neve', 'footer');

        $this->assertSame(0, $this->writes, 'we must not write a layout we have not measured');
        $warnings = $this->renderWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'footer'", $warnings[0]);
        $this->assertStringContainsString("'neve'", $warnings[0]);
    }

    public function test_every_unhandled_theme_the_wizard_can_install_is_reported(): void
    {
        foreach (['neve', 'blocksy', 'kadence', 'oceanwp', 'generatepress', 'sydney', 'newsmatic', 'colormag'] as $theme) {
            $this->warnings = [];
            $this->writes   = 0;
            $this->mockOptions([]);

            contai_astra_ensure_footer_menu_renders($theme, 'footer');

            $this->assertSame(0, $this->writes, "{$theme} must not have its layout guessed at");
            $this->assertCount(1, $this->renderWarnings(), "{$theme} must leave a trace");
        }
    }
}
