<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Cover for the Neve half of the footer builder placement in GitHub issue #48.
 *
 * Same shape as Astra, one theme over. The wizard binds Neve's `footer` nav
 * location and stops; Neve renders its footer from a builder whose layout lives
 * in the `hfg_footer_layout_v2` theme mod
 * (neve 4.2.9: header-footer-grid/functions-template.php:271), and whose default
 * — the value neve_hfg_footer_settings()['builder'] returns — has every slot of
 * every row of both devices EMPTY. With the footer-menu component absent from
 * that layout the bound menu renders nowhere.
 *
 * Measured on a live es_ES install (WordPress 7.0.2, Neve 4.2.9) with the legal
 * menu bound to `footer`, counting on the DOM with <style>/<script> stripped:
 *
 *   layout default (theme's own)     -> 0 legal links, DOM 12 133 B
 *   footer-menu in bottom.left       -> 2 links each, DOM 14 458 B, HTTP 200
 *   footer-menu in main.left         -> 2 links each, DOM 15 512 B, HTTP 200
 *   footer-menu in top.left          -> 2 links each, DOM 15 500 B, HTTP 200
 *
 * DOM size is carried through those measurements on purpose: a malformed payload
 * does not render nothing, it takes the site down (see the well-formedness test
 * below), and only the size column separates the two.
 */
class NeveFooterMenuPlacementTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var mixed Whatever was written to Neve's footer layout theme mod. */
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

    // ── contai_neve_footer_builder_with_menu() — pure ──────────────

    public function test_seeds_neves_default_layout_and_places_the_component(): void
    {
        $layout = contai_neve_footer_builder_with_menu(null);

        $this->assertNotNull($layout, 'an absent layout must be written, not skipped');
        $this->assertSame(
            [['id' => 'footer-menu']],
            $layout['desktop']['bottom']['left']
        );
    }

    /**
     * Neve emits a separate markup tree per device. Placing the component on
     * desktop only leaves phones exactly as broken as before — which is the same
     * failure the mobile half of this issue was about (v2.39.1).
     */
    public function test_the_component_is_placed_on_both_devices(): void
    {
        $layout = contai_neve_footer_builder_with_menu(null);

        foreach (['desktop', 'mobile'] as $device) {
            $this->assertSame(
                [['id' => 'footer-menu']],
                $layout[$device]['bottom']['left'],
                "{$device} must carry the component"
            );
        }
    }

    /**
     * Neve stores the layout as a JSON string, not an array — reading it as an
     * array would silently seed a fresh layout over the site owner's saved one.
     */
    public function test_a_json_string_layout_is_decoded_and_extended_not_replaced(): void
    {
        $current = json_encode([
            'desktop' => [
                'top'    => ['left' => [], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
                'main'   => ['left' => [['id' => 'footer-widgets']], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
                'bottom' => ['left' => [['id' => 'footer_copyright']], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
            ],
            'mobile'  => [
                'top'    => ['left' => [], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
                'main'   => ['left' => [], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
                'bottom' => ['left' => [], 'c-left' => [], 'center' => [], 'c-right' => [], 'right' => []],
            ],
        ]);

        $layout = contai_neve_footer_builder_with_menu($current);

        $this->assertSame(
            [['id' => 'footer_copyright'], ['id' => 'footer-menu']],
            $layout['desktop']['bottom']['left'],
            "the owner's existing components must survive and ours is appended"
        );
        $this->assertSame(
            [['id' => 'footer-widgets']],
            $layout['desktop']['main']['left'],
            'unrelated rows must be untouched'
        );
    }

    public function test_a_layout_that_already_has_the_component_is_left_alone(): void
    {
        $current = json_encode([
            'desktop' => ['main' => ['center' => [['id' => 'footer-menu']]]],
            'mobile'  => ['main' => ['center' => []]],
        ]);

        $this->assertNull(
            contai_neve_footer_builder_with_menu($current),
            'a component the owner already placed must not be duplicated or relocated'
        );
    }

    public function test_a_partial_layout_is_completed_before_being_persisted(): void
    {
        $layout = contai_neve_footer_builder_with_menu(json_encode(['desktop' => ['bottom' => ['left' => []]]]));

        foreach (['desktop', 'mobile'] as $device) {
            foreach (['top', 'main', 'bottom'] as $row) {
                $this->assertArrayHasKey($row, $layout[$device], "{$device}.{$row} must be restored");
            }
        }
    }

    // ── the invariant that decides whether the site stays up ───────

    /**
     * Neve walks the layout with
     *   array_combine( wp_list_pluck( $slot, 'id' ), array_fill( 0, count( $slot ), true ) )
     * (header-footer-grid/Core/Builder/Abstract_Builder.php:898). An entry without
     * an `id` key makes wp_list_pluck() return fewer elements than the slot holds,
     * array_combine() raises a ValueError, and because the walk runs from wp_head
     * the result is HTTP 500 on EVERY page — measured during this investigation
     * with a hand-built payload (DOM 12 KB -> 792 B).
     */
    public function test_what_we_persist_satisfies_neves_component_walk(): void
    {
        $layout = contai_neve_footer_builder_with_menu(null);

        $this->assertTrue(
            contai_neve_footer_slot_entries_are_well_formed($layout),
            'every slot entry must carry an id or Neve fatals on every page'
        );
    }

    public function test_the_well_formedness_check_rejects_the_payload_that_fataled(): void
    {
        $broken = contai_neve_footer_builder_with_menu(null);
        // The exact shape that took the front end down: a slot entry that is not
        // a keyed component.
        $broken['desktop']['bottom']['left'][] = 'footer-menu';

        $this->assertFalse(
            contai_neve_footer_slot_entries_are_well_formed($broken),
            'the guard must be able to fail, or it is decorative'
        );
    }

    public function test_the_component_id_is_the_one_neve_declares(): void
    {
        // neve 4.2.9: header-footer-grid/Core/Components/NavFooter.php:27
        //   const COMPONENT_ID = 'footer-menu';
        $this->assertSame('footer-menu', CONTAI_NEVE_FOOTER_MENU_COMPONENT);
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
                return $name === CONTAI_NEVE_FOOTER_LAYOUT_MOD ? $currentMod : $default;
            },
        ]);
        WP_Mock::userFunction('set_theme_mod', [
            'return' => function ($name, $value) {
                if ($name === CONTAI_NEVE_FOOTER_LAYOUT_MOD) {
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

    public function test_neve_gets_the_component_placed_in_its_own_theme_mod(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('neve', 'footer');

        $this->assertSame(1, $this->writes, 'the layout must actually be persisted');
        $this->assertIsString($this->written, 'Neve reads this mod as a JSON string');

        $decoded = json_decode($this->written, true);
        $this->assertSame([['id' => 'footer-menu']], $decoded['desktop']['bottom']['left']);
        $this->assertSame([], $this->renderWarnings(), 'a theme we handle needs no warning');
    }

    public function test_neve_is_not_rewritten_when_the_component_is_already_placed(): void
    {
        $this->mockThemeMods(json_encode([
            'desktop' => ['bottom' => ['left' => [['id' => 'footer-menu']]]],
            'mobile'  => ['bottom' => ['left' => []]],
        ]));

        contai_theme_ensure_footer_menu_renders('neve', 'footer');

        $this->assertSame(0, $this->writes);
    }

    /**
     * The dispatcher must not hand Neve to the Astra branch: that branch writes
     * nothing and records "this plugin does not manage theme 'neve'", which is the
     * exact diagnostic this change exists to stop emitting.
     */
    public function test_neve_no_longer_falls_through_to_the_unmanaged_diagnostic(): void
    {
        $this->mockThemeMods(null);

        contai_theme_ensure_footer_menu_renders('neve', 'footer');

        $this->assertSame([], $this->renderWarnings());
    }

    /**
     * Every theme whose footer layout has NOT been measured still gets the
     * diagnostic and never a guessed payload.
     */
    public function test_the_still_unmeasured_themes_are_reported_not_guessed(): void
    {
        // blocksy and kadence dropped off this list once their footer builders
        // were measured on a live install (v2.39.4) — they now have their own
        // placement helpers and must NOT be handed a Neve layout either.
        foreach (['oceanwp', 'generatepress', 'sydney', 'newsmatic', 'colormag'] as $theme) {
            $this->warnings = [];
            $this->writes   = 0;
            $this->mockThemeMods(null);

            contai_theme_ensure_footer_menu_renders($theme, 'footer');

            $this->assertSame(0, $this->writes, "{$theme} must not have a Neve layout written into it");
            $this->assertCount(1, $this->renderWarnings(), "{$theme} must leave a trace");
        }
    }
}
