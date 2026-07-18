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
}
