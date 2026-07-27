<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for contai_create_footer_menu_with_legal_pages() (#48).
 *
 * The neighbouring guard in SiteGenerationDefaultsTest asserts the diagnostic
 * CALL is present in the source. That is not enough, and this issue is the
 * proof: the v2.38.7 root cause was an unconditional early return that left the
 * pattern-match fallback and the diagnostic sitting in the file, perfectly
 * visible to any source guard, and never executed. Presence is not
 * reachability, so the reachability has to be exercised.
 */
class FooterMenuReachabilityTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var array<string, int> Locations written via set_theme_mod(). */
    private array $assignedLocations = [];

    /** @var array<string, mixed> Whatever landed in Astra's own settings option. */
    private array $astraSettings = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';

        $this->warnings          = [];
        $this->assignedLocations = [];
        $this->astraSettings     = [];
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $registered Registry get_registered_nav_menus() returns.
     */
    private function runWith(string $theme, array $registered, bool $midThemeSwitch): void
    {
        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) use ($theme, $midThemeSwitch) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    return $this->warnings;
                }
                if ($name === 'contai_wordpress_theme') {
                    return $theme;
                }
                if ($name === 'theme_switched') {
                    return $midThemeSwitch ? 'the-previous-theme' : false;
                }
                if ($name === CONTAI_ASTRA_SETTINGS_OPTION) {
                    return $this->astraSettings;
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
                    $this->astraSettings = $value;
                }
                return true;
            },
        ]);

        WP_Mock::userFunction('wp_get_nav_menu_object', ['return' => (object) ['term_id' => 11]]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('get_posts', ['return' => [(object) ['ID' => 5, 'post_title' => 'Privacy']]]);
        WP_Mock::userFunction('wp_get_nav_menu_items', ['return' => []]);
        WP_Mock::userFunction('wp_update_nav_menu_item', ['return' => 99]);
        WP_Mock::userFunction('get_nav_menu_locations', ['return' => []]);
        WP_Mock::userFunction('get_registered_nav_menus', ['return' => $registered]);
        WP_Mock::userFunction('get_stylesheet', ['return' => 'astra']);
        WP_Mock::userFunction('contai_log', ['return' => null]);
        WP_Mock::userFunction('set_theme_mod', [
            'return' => function ($name, $value) {
                if ($name === 'nav_menu_locations') {
                    $this->assignedLocations = $value;
                }
                return true;
            },
        ]);

        contai_create_footer_menu_with_legal_pages();
    }

    /** @return array<int, string> Messages of recorded footer warnings. */
    private function footerWarnings(): array
    {
        $out = [];
        foreach ($this->warnings as $warning) {
            if (($warning['step'] ?? '') === 'footer nav location') {
                $out[] = $warning['message'];
            }
        }

        return $out;
    }

    /**
     * THE REACHABILITY TEST. generatepress has no footer map entry and
     * registers no footer location, so nothing can be bound — and before this
     * the failure was invisible.
     */
    public function test_an_unbindable_footer_is_actually_reported(): void
    {
        $this->runWith('generatepress', ['primary' => 'Primary Menu'], false);

        $messages = $this->footerWarnings();

        $this->assertCount(
            1,
            $messages,
            'The diagnostic must EXECUTE, not merely exist in the file (#48)'
        );
        $this->assertStringContainsString('generatepress', $messages[0]);
        $this->assertStringContainsString(
            'primary',
            $messages[0],
            'It must name the locations the theme really has, so the map can be fixed'
        );
        $this->assertSame([], $this->assignedLocations, 'Nothing may be bound when nothing matches');
    }

    /**
     * The control that discriminates: a theme whose mapped location the
     * registry confirms must bind and stay silent. Without this, a function
     * that warned unconditionally would pass the test above.
     */
    public function test_a_bindable_footer_binds_and_reports_nothing(): void
    {
        $this->runWith('astra', ['primary' => 'Primary', 'footer_menu' => 'Footer Menu'], false);

        $this->assertSame([], $this->footerWarnings(), 'A successful binding must not warn');
        $this->assertSame(
            ['footer_menu' => 11],
            $this->assignedLocations,
            "astra's mapped footer_menu must receive the menu"
        );
    }

    /**
     * Mid-wizard the registry still describes the outgoing theme. astra's
     * footer_menu is correct, so it must be bound on the strength of the map
     * rather than rejected for missing from a registry about another theme.
     */
    public function test_mid_theme_switch_the_map_is_trusted_over_a_stale_registry(): void
    {
        $this->runWith('astra', ['primary' => 'Primary', 'footer' => 'Footer'], true);

        $this->assertSame(
            ['footer_menu' => 11],
            $this->assignedLocations,
            'A stale registry must not veto a correct map entry (#48)'
        );
        $this->assertSame([], $this->footerWarnings());
    }

    /**
     * The other half: with no map entry AND a stale registry, the fallback has
     * nothing trustworthy to match against. Binding 'footer' from the outgoing
     * theme would be silently dropped by WordPress, so the correct outcome is
     * to bind nothing and say so.
     */
    public function test_mid_theme_switch_an_unmapped_theme_binds_nothing_and_reports(): void
    {
        $this->runWith('generatepress', ['primary' => 'Primary', 'footer' => 'Footer'], true);

        $this->assertSame(
            [],
            $this->assignedLocations,
            'Matching the outgoing theme produces a binding WordPress silently drops (#48)'
        );

        $messages = $this->footerWarnings();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            'previous theme',
            $messages[0],
            'The warning must distinguish staleness from "this theme has no footer location"'
        );
    }

    /**
     * Binding is only half the job, and this is the half that was missing.
     * Astra renders its footer menu from a builder component; with the slug
     * absent from the layout the bound menu appears nowhere. Reaching the
     * binding is therefore NOT evidence that the links render — the placement
     * has to be exercised from the real entry point too (#48).
     */
    public function test_binding_astra_also_places_the_component_that_renders_it(): void
    {
        $this->runWith('astra', ['primary' => 'Primary', 'footer_menu' => 'Footer Menu'], false);

        $this->assertSame(
            ['footer_menu' => 11],
            $this->assignedLocations,
            'precondition: the location must still be bound'
        );
        $this->assertSame(
            ['copyright', 'menu'],
            $this->astraSettings[CONTAI_ASTRA_FOOTER_ITEMS_KEY]['below']['below_1'],
            'the menu component must be placed in the rendered zone, or nothing shows (#48)'
        );
    }

    /** Same wiring, on the pattern-match branch rather than the static map. */
    public function test_the_pattern_match_branch_also_places_the_component(): void
    {
        $this->runWith('astra', ['primary' => 'Primary', 'theme-contai-footer' => 'Footer'], false);

        $this->assertSame(
            ['theme-contai-footer' => 11],
            $this->assignedLocations,
            'precondition: this must be the fallback branch, not the map branch'
        );
        $this->assertSame(
            ['copyright', 'menu'],
            $this->astraSettings[CONTAI_ASTRA_FOOTER_ITEMS_KEY]['below']['below_1']
        );
    }

    /**
     * The control that discriminates: a theme we have not measured must be
     * REPORTED, never written to. A guessed layout is not a harmless no-op —
     * a hand-built one for Neve fataled the whole front end.
     *
     * Colormag, not Blocksy and not Neve. This test has now outlived two subjects:
     * each time a theme's footer builder gets measured on a live install it starts
     * being written, and pinning a diagnostic for it would pin behaviour production
     * no longer has. Neve moved out of the unmeasured set in v2.39.3, Blocksy and
     * Kadence in v2.39.4 (see NeveFooterMenuPlacementTest,
     * BlocksyFooterMenuPlacementTest, KadenceFooterMenuPlacementTest).
     *
     * Colormag is still in it, and for a stronger reason than the others were: it
     * registers no footer nav location at all (colormag 4.2.1:
     * inc/core/class-colormag-after-setup-theme.php:315-320 registers only
     * `primary` and `menu-secondary`), and a live probe this round measured 0 legal
     * links on its front page. The registered-location fixture below is supplied by
     * the harness, so what this exercises is the dispatcher's unmeasured branch.
     */
    public function test_an_unmeasured_theme_is_reported_instead_of_guessed_at(): void
    {
        $this->runWith('colormag', ['primary' => 'Primary', 'footer' => 'Footer Menu'], false);

        $this->assertSame(['footer' => 11], $this->assignedLocations);
        $this->assertSame([], $this->astraSettings, 'no layout may be invented for an unmeasured theme');

        $render = [];
        foreach ($this->warnings as $warning) {
            if (($warning['step'] ?? '') === 'footer menu render') {
                $render[] = $warning['message'];
            }
        }
        $this->assertCount(1, $render, 'the wizard must leave a trace it cannot guarantee the render');
        $this->assertStringContainsString('colormag', $render[0]);
    }
}
