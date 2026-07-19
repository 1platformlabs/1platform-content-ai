<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The nav menu registry is stale for the rest of the request that switches
 * theme, and the guard added in v2.38.7 trusted it anyway (#48).
 *
 * ContaiWebsiteGenerationService::generateCompleteWebsite() calls
 * contai_install_theme() — which ends in switch_theme() — and then, further
 * down the SAME method, contai_create_footer_menu_with_legal_pages().
 *
 * Read from WordPress core rather than assumed:
 *  - get_registered_nav_menus() just returns the $_wp_registered_nav_menus
 *    global (wp-includes/nav-menu.php:149-152), populated only by a theme
 *    calling register_nav_menus() from after_setup_theme.
 *  - switch_theme() (theme.php:757+) updates options and resets template
 *    globals; it cannot load the incoming theme's functions.php, so it never
 *    repopulates that global.
 *  - switch_theme() sets 'theme_switched' to the outgoing stylesheet
 *    (theme.php:840), and only the NEXT request clears it, via
 *    check_theme_switched() on init priority 99 (default-filters.php:367,
 *    theme.php:3510).
 *
 * So mid-wizard the registry is POPULATED and describes the previous theme.
 * That is worse than an empty one, because a populated registry looks
 * authoritative: the incoming theme's correct mapped location gets rejected
 * for not appearing in the outgoing theme's registry, and the fallback then
 * picks a location out of the outgoing theme — which WordPress silently drops.
 * The guard against silent no-ops produced one.
 */
class NavRegistryStaleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/nav-location.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_registry_is_stale_while_theme_switched_is_set(): void
    {
        WP_Mock::userFunction('get_option', [
            'args'   => ['theme_switched'],
            'return' => 'twentytwentyfive',
        ]);

        $this->assertTrue(
            contai_nav_registry_is_stale(),
            "switch_theme() ran in this request, so the registry describes the theme we left"
        );
    }

    /**
     * check_theme_switched() sets the option to false (not deleted) on the
     * next load, so the falsy case has to be the boolean, not just absence.
     */
    public function test_registry_is_fresh_once_core_has_cleared_the_flag(): void
    {
        WP_Mock::userFunction('get_option', [
            'args'   => ['theme_switched'],
            'return' => false,
        ]);

        $this->assertFalse(
            contai_nav_registry_is_stale(),
            'On a normal request the registry genuinely belongs to the active theme'
        );
    }

    /**
     * THE REGRESSION. astra maps to footer_menu. Mid-wizard the registry still
     * holds the outgoing theme's locations, which of course lack footer_menu —
     * so the un-staled predicate rejected a CORRECT map entry.
     */
    public function test_a_stale_registry_does_not_disprove_the_map(): void
    {
        $outgoing_theme_registry = [
            'primary' => 'Primary',
            'footer'  => 'Footer',
        ];

        $this->assertFalse(
            contai_nav_location_is_usable('footer_menu', $outgoing_theme_registry),
            'Sanity: against a registry treated as authoritative, the entry is rejected'
        );

        $this->assertTrue(
            contai_nav_location_is_usable('footer_menu', $outgoing_theme_registry, true),
            'A stale registry can neither confirm nor disprove the map, so the map stands (#48)'
        );
    }

    /**
     * Staleness must not resurrect an empty location. "Cannot tell" is about
     * the registry, not about the map having no entry at all.
     */
    public function test_staleness_does_not_invent_a_location(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable(null, ['primary' => 'Primary'], true),
            'No mapped location means nothing to trust, stale or not'
        );
        $this->assertFalse(
            contai_nav_location_is_usable('', ['primary' => 'Primary'], true),
            'An empty mapped location means nothing to trust, stale or not'
        );
    }

    /**
     * A fresh, populated registry must still be able to reject a wrong entry.
     * If staleness leaked into the normal path it would restore the pre-v2.38.7
     * behaviour of trusting every map entry unconditionally.
     */
    public function test_a_fresh_registry_still_disproves_a_wrong_entry(): void
    {
        $this->assertFalse(
            contai_nav_location_is_usable('footer_menu', ['primary' => 'Primary'], false),
            'With a trustworthy registry the guard must keep working'
        );
    }

    /**
     * The second half of the same bug: with the map entry rejected, the footer
     * fallback matched against the OUTGOING theme's registry and returned one
     * of its locations. Writing that to nav_menu_locations is dropped silently
     * by WordPress — an invisible failure, which is exactly what this issue is.
     * Returning null instead lets the caller record a warning.
     */
    public function test_footer_matcher_refuses_to_match_a_stale_registry(): void
    {
        $outgoing_theme_registry = ['footer' => 'Footer Menu'];

        $this->assertSame(
            'footer',
            contai_match_footer_nav_location($outgoing_theme_registry),
            'Sanity: this registry does contain a matchable footer location'
        );

        $this->assertNull(
            contai_match_footer_nav_location($outgoing_theme_registry, true),
            'Matching the previous theme binds the legal menu to a location the active theme never renders (#48)'
        );
    }

    public function test_footer_matcher_still_works_on_a_fresh_registry(): void
    {
        $this->assertSame(
            'footer',
            contai_match_footer_nav_location(['primary' => 'Primary', 'footer' => 'Footer'], false),
            'The fallback is the only path for the three themes with no footer map entry'
        );
    }
}
