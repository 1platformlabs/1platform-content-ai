<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for contai_get_primary_sidebar_id() (#48).
 *
 * Deliberately drives the real function rather than asserting on its source.
 * The defect this issue keeps reopening with is code that is PRESENT and never
 * reached — a source guard cannot tell the difference, and one such guard
 * already survived an `if (true) { return; }` mutant in this repo (v2.38.13).
 */
class PrimarySidebarResolutionTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';

        $this->warnings = [];
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wp_registered_sidebars']);
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $registeredSidebars
     */
    private function resolve(string $theme, array $registeredSidebars, bool $midThemeSwitch): string
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

        $GLOBALS['wp_registered_sidebars'] = $registeredSidebars;

        return contai_get_primary_sidebar_id();
    }

    public function test_mapped_theme_ignores_the_registry_entirely(): void
    {
        // Kadence is mapped to 'sidebar-primary'. Mid-switch the registry below
        // is the OUTGOING theme's, and must not be allowed to override the map.
        $id = $this->resolve('kadence', ['sidebar-1' => [], 'footer-1' => []], true);

        $this->assertSame('sidebar-primary', $id);
        $this->assertSame([], $this->warnings, 'A mapped theme resolves cleanly, with nothing to report');
    }

    public function test_unmapped_theme_does_not_take_an_id_from_the_outgoing_theme(): void
    {
        // The registry still describes the theme we just left, and it offers a
        // perfectly plausible-looking 'twentyfoo-sidebar'. Taking it would write
        // widgets into an area the ACTIVE theme never registers: the silent
        // no-op this issue is made of.
        $id = $this->resolve('some-api-supplied-theme', ['twentyfoo-sidebar' => []], true);

        $this->assertNotSame('twentyfoo-sidebar', $id, 'Resolved a widget area out of the outgoing theme (#48)');
        $this->assertSame('sidebar-1', $id, 'Falls back to the WordPress convention instead');
    }

    public function test_unmapped_theme_mid_switch_records_a_durable_warning(): void
    {
        $this->resolve('some-api-supplied-theme', ['twentyfoo-sidebar' => []], true);

        $this->assertCount(1, $this->warnings, 'A guessed sidebar id must leave a trace, not fail invisibly (#48)');
        $this->assertSame('sidebar id', $this->warnings[0]['step']);
        $this->assertStringContainsString('some-api-supplied-theme', $this->warnings[0]['message']);
        $this->assertStringContainsString(
            'previous theme',
            $this->warnings[0]['message'],
            'The warning must say WHY the registry was unusable'
        );
    }

    public function test_unmapped_theme_uses_the_registry_when_it_is_trustworthy(): void
    {
        // Not mid-switch: the registry genuinely describes the active theme, so
        // runtime detection is the best answer available and no warning is due.
        $id = $this->resolve('some-api-supplied-theme', ['custom-primary' => [], 'footer-1' => []], false);

        $this->assertSame('custom-primary', $id);
        $this->assertSame([], $this->warnings);
    }

    public function test_unmapped_theme_with_no_registry_warns_and_guesses(): void
    {
        $id = $this->resolve('some-api-supplied-theme', [], false);

        $this->assertSame('sidebar-1', $id);
        $this->assertCount(1, $this->warnings);
        $this->assertStringNotContainsString(
            'previous theme',
            $this->warnings[0]['message'],
            'Not mid-switch, so the registry was simply empty — do not blame a theme switch'
        );
    }
}
