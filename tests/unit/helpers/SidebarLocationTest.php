<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the sidebar resolution and merge helpers (#48).
 *
 * Two defects of the same family the nav locations had:
 *
 * 1. contai_get_primary_sidebar_id() read $wp_registered_sidebars during the
 *    request that switches theme, so an unmapped theme resolved to a widget
 *    area of the theme just switched AWAY from — silently rendered nowhere.
 * 2. contai_add_sidebar_widgets() blanked the target sidebar before writing,
 *    discarding every widget the site owner had placed there.
 */
class SidebarLocationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/helpers/sidebar-location.php';
    }

    // ── contai_sidebar_id_from_registry ────────────────────────────

    public function test_stale_registry_resolves_to_nothing(): void
    {
        $outgoingTheme = ['sidebar-1' => [], 'footer-1' => []];

        $this->assertNull(
            contai_sidebar_id_from_registry($outgoingTheme, true),
            'Mid-switch the registry describes the OUTGOING theme, so a populated ' .
            'registry proves nothing about the active one (#48)'
        );
    }

    public function test_same_registry_resolves_when_not_stale(): void
    {
        $registry = ['sidebar-1' => [], 'footer-1' => []];

        $this->assertSame(
            'sidebar-1',
            contai_sidebar_id_from_registry($registry, false),
            'Outside a theme switch the registry genuinely describes the active theme'
        );
    }

    public function test_empty_registry_resolves_to_nothing(): void
    {
        $this->assertNull(contai_sidebar_id_from_registry([], false));
        $this->assertNull(contai_sidebar_id_from_registry(null, false));
        $this->assertNull(contai_sidebar_id_from_registry('not an array', false));
    }

    public function test_priority_order_wins_over_registration_order(): void
    {
        // Registration order puts an unrelated area first; the conventional
        // primary ids must still win.
        $registry = ['header-widget' => [], 'sidebar-primary' => [], 'sidebar' => []];

        $this->assertSame(
            'sidebar',
            contai_sidebar_id_from_registry($registry, false),
            "'sidebar' outranks 'sidebar-primary' in the priority list"
        );
    }

    public function test_falls_back_to_first_registered_area(): void
    {
        $registry = ['colormag_right_sidebar' => [], 'colormag_header_sidebar' => []];

        $this->assertSame(
            'colormag_right_sidebar',
            contai_sidebar_id_from_registry($registry, false),
            'A theme naming nothing conventionally still gets its first area'
        );
    }

    public function test_ignores_non_string_keys_in_fallback(): void
    {
        $this->assertNull(
            contai_sidebar_id_from_registry([0 => [], 1 => []], false),
            'A numerically-keyed registry names no sidebar id we could write'
        );
    }

    // ── contai_merge_sidebar_widget_ids ────────────────────────────

    public function test_keeps_widgets_the_owner_placed(): void
    {
        $previous = ['text-3', 'search-1', 'custom_html-9'];
        $wizard   = ['block-1', 'search-1', 'recent-comments-1', 'recent-posts-1'];

        $merged = contai_merge_sidebar_widget_ids($wizard, $previous);

        $this->assertContains('text-3', $merged, "The owner's text widget must survive the wizard (#48)");
        $this->assertContains('custom_html-9', $merged, "The owner's HTML widget must survive the wizard (#48)");
    }

    public function test_wizard_widgets_come_first_and_owner_order_is_kept(): void
    {
        $previous = ['text-3', 'custom_html-9'];
        $wizard   = ['block-1', 'search-1'];

        $this->assertSame(
            ['block-1', 'search-1', 'text-3', 'custom_html-9'],
            contai_merge_sidebar_widget_ids($wizard, $previous)
        );
    }

    public function test_rerun_is_idempotent(): void
    {
        $wizard = ['block-1', 'search-1', 'recent-comments-1', 'recent-posts-1'];

        $first  = contai_merge_sidebar_widget_ids($wizard, []);
        $second = contai_merge_sidebar_widget_ids($wizard, $first);
        $third  = contai_merge_sidebar_widget_ids($wizard, $second);

        $this->assertSame($first, $second, 'A second wizard run must not duplicate its own widgets');
        $this->assertSame($first, $third, 'Nor must a third');
    }

    public function test_rerun_with_owner_widgets_neither_duplicates_nor_drops(): void
    {
        $wizard = ['block-1', 'search-1'];
        $owner  = ['search-1', 'text-3'];

        $first  = contai_merge_sidebar_widget_ids($wizard, $owner);
        $second = contai_merge_sidebar_widget_ids($wizard, $first);

        $this->assertSame(['block-1', 'search-1', 'text-3'], $first);
        $this->assertSame($first, $second);
    }

    public function test_skips_non_string_and_empty_entries(): void
    {
        $merged = contai_merge_sidebar_widget_ids(['search-1'], ['', null, 42, ['nested'], 'text-3']);

        $this->assertSame(['search-1', 'text-3'], $merged);
    }

    public function test_fresh_site_gets_exactly_the_wizard_widgets(): void
    {
        $wizard = ['block-1', 'search-1', 'recent-comments-1', 'recent-posts-1'];

        $this->assertSame($wizard, contai_merge_sidebar_widget_ids($wizard, []));
    }
}
