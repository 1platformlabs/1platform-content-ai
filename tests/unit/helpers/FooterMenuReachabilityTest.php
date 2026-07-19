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

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';

        $this->warnings          = [];
        $this->assignedLocations = [];
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

        WP_Mock::userFunction('wp_get_nav_menu_object', ['return' => (object) ['term_id' => 11]]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('get_posts', ['return' => [(object) ['ID' => 5, 'post_title' => 'Privacy']]]);
        WP_Mock::userFunction('wp_get_nav_menu_items', ['return' => []]);
        WP_Mock::userFunction('wp_update_nav_menu_item', ['return' => 99]);
        WP_Mock::userFunction('get_nav_menu_locations', ['return' => []]);
        WP_Mock::userFunction('get_registered_nav_menus', ['return' => $registered]);
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
}
