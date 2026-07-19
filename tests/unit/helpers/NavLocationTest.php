<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for contai_nav_location_is_usable() in includes/helpers/nav-location.php.
 *
 * Regression cover for GitHub issue #48: the site wizard resolved nav menu
 * locations from hand-maintained theme maps and wrote them to
 * nav_menu_locations without ever checking the active theme registers them.
 * WordPress silently ignores entries for unregistered locations, so a wrong or
 * stale map entry failed silently AND made the call sites' pattern-matching
 * fallback + diagnostic log unreachable.
 *
 * The predicate must reject a mapped location only when a POPULATED registry
 * proves it absent — an empty registry is the cron/async case the static maps
 * exist for, and must keep trusting the map.
 */
class NavLocationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/nav-location.php';
    }

    public function test_returns_true_when_registry_lists_the_location(): void
    {
        $this->assertTrue(
            contai_nav_location_is_usable('primary', ['primary' => 'Primary Menu']),
            'A mapped location the theme registers must be usable'
        );
    }

    /**
     * The core bug: astra is mapped to footer_menu, but if the active theme
     * does not register it the assignment is a silent no-op. The predicate
     * must say "not usable" so the caller falls through to pattern matching
     * and, failing that, logs the diagnostic naming the real locations.
     */
    public function test_returns_false_when_populated_registry_lacks_the_location(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable('footer_menu', ['primary' => 'Primary', 'footer-nav' => 'Footer Nav']),
            'A mapped location absent from a populated registry must be rejected (#48)'
        );
    }

    /**
     * The cron/async case the static maps were introduced for. An empty
     * registry means "cannot tell", NOT "location is absent" — rejecting here
     * would regress the wizard to assigning no menu at all.
     */
    public function test_returns_true_when_registry_is_empty(): void
    {
        $this->assertTrue(
            contai_nav_location_is_usable('primary', []),
            'An empty registry cannot disprove the static map (cron/async context)'
        );
    }

    public function test_returns_true_when_registry_is_not_an_array(): void
    {
        $this->assertTrue(
            contai_nav_location_is_usable('primary', false),
            'An unavailable registry cannot disprove the static map'
        );
    }

    public function test_returns_false_for_null_location(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable(null, ['primary' => 'Primary Menu']),
            'No mapped location means nothing to trust'
        );
    }

    public function test_returns_false_for_empty_location(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable('', ['primary' => 'Primary Menu']),
            'An empty mapped location means nothing to trust'
        );
    }

    /**
     * array_key_exists semantics, not isset(): a registered location whose
     * description is null must still count as registered.
     */
    public function test_treats_null_description_as_registered(): void
    {
        $this->assertTrue(
            contai_nav_location_is_usable('primary', ['primary' => null]),
            'A registered location with a null description is still registered'
        );
    }

    public function test_location_matching_is_case_sensitive(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable('Primary', ['primary' => 'Primary Menu']),
            'Nav menu location slugs are case-sensitive in WordPress'
        );
    }

    // ── contai_match_footer_nav_location() ─────────────────────────

    /**
     * The ranking bug (#48). Kadence registers its locations in the order
     * primary, secondary, mobile, footer (kadence 1.5.1:
     * inc/components/nav_menus/component.php:83-86). 'secondary' is a weak
     * footer pattern, so walking the registry once and taking the first
     * location that matched ANY pattern put the legal-pages menu into the
     * theme's SECONDARY HEADER nav while a genuine 'footer' location sat two
     * entries later.
     *
     * Three themes now rely on this fallback as their only path, so its
     * ordering has to be strength-major, not registration-major.
     */
    public function test_prefers_a_real_footer_location_over_an_earlier_secondary(): void
    {
        $kadence = [
            'primary'   => 'Primary',
            'secondary' => 'Secondary',
            'mobile'    => 'Mobile',
            'footer'    => 'Footer',
        ];

        $this->assertSame(
            'footer',
            contai_match_footer_nav_location($kadence),
            'A genuine footer location must beat an earlier "secondary" header menu (#48)'
        );
    }

    public function test_prefers_footer_over_bottom_when_both_exist(): void
    {
        $this->assertSame(
            'site-footer',
            contai_match_footer_nav_location([
                'bottom-bar'  => 'Bottom Bar',
                'site-footer' => 'Site Footer',
            ]),
            'Patterns are ranked strongest first'
        );
    }

    /**
     * 'secondary' still has to work — it is the last resort for themes whose
     * only non-header location is named that way.
     */
    public function test_falls_back_to_secondary_when_nothing_stronger_exists(): void
    {
        $this->assertSame(
            'menu-secondary',
            contai_match_footer_nav_location([
                'primary'        => 'Primary Menu',
                'menu-secondary' => 'Secondary Menu',
            ])
        );
    }

    /**
     * GeneratePress 3.6.1 registers exactly one location (functions.php:56-60).
     * There is nothing to assign, and saying so lets the caller log the
     * diagnostic instead of silently writing a dead entry.
     */
    public function test_returns_null_when_the_theme_has_only_a_primary_location(): void
    {
        $this->assertNull(
            contai_match_footer_nav_location(['primary' => 'Primary Menu']),
            'GeneratePress free has no footer nav location (#48)'
        );
    }

    public function test_excludes_header_locations_even_when_they_match_a_pattern(): void
    {
        $this->assertNull(
            contai_match_footer_nav_location([
                'header-bottom' => 'Header Bottom Row',
                'top-footer'    => 'Top Footer Bar',
            ]),
            'An excluded term anywhere in the slug or label disqualifies the location'
        );
    }

    public function test_matches_on_the_human_label_when_the_slug_is_opaque(): void
    {
        $this->assertSame(
            'menu-3',
            contai_match_footer_nav_location([
                'menu-2' => 'Main Header',
                'menu-3' => 'Bottom Footer',
            ]),
            'Newsmatic-style numeric slugs are only distinguishable by their label'
        );
    }

    public function test_returns_null_for_an_empty_or_unavailable_registry(): void
    {
        $this->assertNull(contai_match_footer_nav_location([]));
        $this->assertNull(contai_match_footer_nav_location(false));
    }

    /**
     * get_registered_nav_menus() values are strings in practice, but a theme
     * filtering the registry can leave a null through, and strtolower(null) is
     * deprecated in PHP 8.1+ — which on a site running WP_DEBUG floods the log
     * from inside the site wizard.
     *
     * A plain assertion on the return value does NOT discriminate here: a
     * deprecation is not a failure, so the test passes with or without the
     * cast. Promote it to an exception for the duration of the call, otherwise
     * this is a test that looks like a guard and guards nothing.
     */
    public function test_tolerates_a_null_description(): void
    {
        set_error_handler(
            static function (int $errno, string $message): bool {
                throw new \ErrorException($message, 0, $errno);
            },
            E_DEPRECATED | E_WARNING
        );

        try {
            $result = contai_match_footer_nav_location(['primary' => null, 'footer' => null]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('footer', $result);
    }

    // ── plugin-owned locations are never footer candidates (#48) ────────

    /**
     * The plugin registers 'contai-footer-bottom' itself, on init
     * (includes/admin/content-generator/helpers/cookie-notice-helper.php).
     * That slug is the ONLY occurrence in the repo: nothing ever assigns a
     * menu to it and no template renders it.
     *
     * It nonetheless contains 'footer' — the strongest pattern this matcher
     * has — and matches none of the exclusions, so it was selectable. It wins
     * whenever the active theme registers no location containing 'footer',
     * which is exactly the three themes (generatepress, sydney, colormag)
     * deliberately dropped from the static footer map so that they depend on
     * this fallback. The legal menu would then be written to a location
     * nothing renders: a silent no-op, the failure mode of #48.
     */
    public function test_never_selects_a_location_the_plugin_registers_itself(): void
    {
        // GeneratePress' real registry (only 'primary'), plus the plugin's own
        // location as init would have appended it.
        $registered = [
            'primary'              => 'Primary Menu',
            'contai-footer-bottom' => 'Content AI Footer Bottom',
        ];

        $this->assertNull(
            contai_match_footer_nav_location($registered),
            'A plugin-registered location is not rendered by the theme, so it must never be chosen'
        );
    }

    /**
     * The exclusion must not swallow a genuine theme footer location that
     * merely coexists with the plugin's one — otherwise the fix would trade
     * one silent no-op for another.
     */
    public function test_still_selects_the_real_theme_footer_alongside_the_plugin_location(): void
    {
        $registered = [
            'contai-footer-bottom' => 'Content AI Footer Bottom',
            'primary'              => 'Primary Menu',
            'footer'               => 'Footer Menu',
        ];

        $this->assertSame('footer', contai_match_footer_nav_location($registered));
    }

    /**
     * The guard is prefix-anchored, not a substring test: a theme location
     * that merely contains the string elsewhere stays eligible.
     */
    public function test_exclusion_is_anchored_to_the_prefix(): void
    {
        $registered = [
            'primary'             => 'Primary Menu',
            'theme-contai-footer' => 'Footer Menu',
        ];

        $this->assertSame('theme-contai-footer', contai_match_footer_nav_location($registered));
    }
}
