<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * The wizard used to destroy pre-existing widget settings (#48).
 *
 * WordPress keeps every instance of a widget type in ONE option keyed by
 * instance id — 'widget_search' => [ 1 => …, 2 => …, '_multiwidget' => 1 ] —
 * shared by every sidebar on the site. contai_add_sidebar_widgets() built a
 * fresh single-instance array with a hardcoded id of 1 and update_option()'d
 * it over the top, so any other instance vanished: other sidebars kept
 * referencing 'search-2', 'recent-posts-3' and so on with no settings behind
 * them.
 *
 * contai_pick_widget_instance_id() is the allocation half of the fix. It must
 * re-use the id the plugin itself wrote last time (so a second wizard run
 * updates its own widget rather than appending another one) and otherwise take
 * the lowest genuinely free id.
 */
class WidgetInstanceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/widget-instance.php';
    }

    public function test_takes_id_1_on_a_stock_install(): void
    {
        $this->assertSame(
            1,
            contai_pick_widget_instance_id(['_multiwidget' => 1], [], 'search'),
            'With nothing installed the first id is free'
        );
        $this->assertSame(
            1,
            contai_pick_widget_instance_id([], [], 'search'),
            'An absent option behaves like an empty one'
        );
        $this->assertSame(
            1,
            contai_pick_widget_instance_id(false, [], 'search'),
            'get_option() can return false; that is not a taken id'
        );
    }

    /**
     * THE REGRESSION. Instance 1 belongs to another sidebar; hardcoding 1
     * overwrote its settings.
     */
    public function test_skips_ids_already_taken_by_other_widgets(): void
    {
        $existing = [1 => ['title' => 'Someone else'], 2 => ['title' => 'Also theirs'], '_multiwidget' => 1];

        $this->assertSame(
            3,
            contai_pick_widget_instance_id($existing, [], 'search'),
            'Allocating an occupied id is what destroyed pre-existing widgets (#48)'
        );
    }

    /**
     * Options round-tripped through the database come back with numeric STRING
     * keys. Treating '1' as free would reintroduce the collision.
     */
    public function test_numeric_string_keys_count_as_taken(): void
    {
        $this->assertSame(
            2,
            contai_pick_widget_instance_id(['1' => ['title' => 'Theirs'], '_multiwidget' => 1], [], 'search')
        );
    }

    public function test_multiwidget_marker_is_not_an_instance(): void
    {
        $this->assertSame(
            1,
            contai_pick_widget_instance_id(['_multiwidget' => 1], [], 'search'),
            "'_multiwidget' is a marker, not a widget instance"
        );
    }

    /**
     * Re-execution has to stay idempotent: the wizard clears its own sidebar
     * and rebuilds it, so without re-use every run would leak a new instance.
     */
    public function test_reuses_the_id_from_the_previous_run(): void
    {
        $existing = [1 => ['title' => 'Theirs'], 4 => ['title' => 'Ours'], '_multiwidget' => 1];

        $this->assertSame(
            4,
            contai_pick_widget_instance_id($existing, ['search-4', 'block-2'], 'search'),
            'A second wizard run must update its own widget, not append another'
        );
    }

    /**
     * Anchoring matters in both directions: 'recent-posts' must not be found
     * inside 'recent-comments-1', and 'search' must not match 'my-search-1'.
     */
    public function test_base_matching_is_anchored(): void
    {
        $this->assertSame(
            1,
            contai_pick_widget_instance_id([], ['recent-comments-7'], 'recent-posts'),
            "'recent-posts' must not match a recent-comments id"
        );
        $this->assertSame(
            1,
            contai_pick_widget_instance_id([], ['my-search-7'], 'search'),
            "'search' must not match a foreign widget whose base ends in it"
        );
        $this->assertSame(
            7,
            contai_pick_widget_instance_id([], ['recent-comments-7'], 'recent-comments'),
            'The exact base must still match'
        );
    }

    public function test_ignores_non_string_entries_in_the_sidebar_list(): void
    {
        $this->assertSame(
            1,
            contai_pick_widget_instance_id([], [null, 42, ['nested']], 'search'),
            'A malformed sidebars_widgets entry must not fatal the wizard'
        );
    }

    /**
     * The allocator above is only half the fix; the other half is that
     * contai_add_sidebar_widgets() must READ each widget option before writing
     * it. Allocating a free id is pointless if the option is then replaced by a
     * freshly built single-instance array.
     *
     * Source guard rather than a behavioural test: contai_add_sidebar_widgets()
     * is bound to WordPress globals and the profile API client chain, and
     * site-generation.php is not in the test bootstrap — the same reason the
     * neighbouring guards in SiteGenerationDefaultsTest are written this way.
     */
    public function test_widget_options_are_read_before_being_written(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/includes/helpers/site-generation.php'
        );

        $start = strpos($source, 'function contai_add_sidebar_widgets()');
        $this->assertNotFalse($start, 'contai_add_sidebar_widgets() must exist');

        $next = strpos($source, "\nfunction ", $start + 1);
        $body = $next === false ? substr($source, $start) : substr($source, $start, $next - $start);

        // Truncation guard: a short read would make every assertion below a
        // false negative that reads exactly like the regression (#48).
        $this->assertStringContainsString(
            "update_option( 'sidebars_widgets'",
            $body,
            'Extraction looks truncated: the body must reach its final write'
        );

        foreach (['widget_search', 'widget_recent-comments', 'widget_recent-posts', 'widget_block'] as $option) {
            $readPos  = strpos($body, "get_option( '{$option}'");
            $writePos = strpos($body, "update_option( '{$option}'");

            $this->assertNotFalse(
                $readPos,
                "'{$option}' holds every instance of that widget type across every sidebar, " .
                'so it must be read and merged, not rebuilt (#48)'
            );
            $this->assertNotFalse($writePos, "'{$option}' must still be written");
            $this->assertLessThan(
                $writePos,
                $readPos,
                "'{$option}' must be read BEFORE it is written"
            );
        }

        $this->assertStringNotContainsString(
            "\$widget_search          = array( '_multiwidget' => 1 );",
            $body,
            'Rebuilding the option from scratch is what destroyed pre-existing widgets (#48)'
        );

        // The allocator can only re-use the previous run's ids if the call site
        // reads the sidebar's current contents BEFORE clearing them. Clearing
        // first loses them, and every re-run then leaks a fresh instance.
        $capturePos = strpos($body, '$previous_sidebar = isset( $sidebars_widgets[ $sidebar_id ] )');
        $clearPos   = strpos($body, '$sidebars_widgets[ $sidebar_id ] = array();');

        $this->assertNotFalse(
            $capturePos,
            'The ids from the previous run must be captured, or re-execution leaks widget instances (#48)'
        );
        $this->assertNotFalse($clearPos, 'The wizard still rebuilds its own sidebar list');
        $this->assertLessThan(
            $clearPos,
            $capturePos,
            'The capture must happen BEFORE the sidebar list is cleared'
        );

        // And the captured list must actually reach the allocator.
        $this->assertStringContainsString(
            "contai_pick_widget_instance_id( \$widget_search, \$previous_sidebar, 'search' )",
            $body,
            'The allocator must receive the previous run\'s ids, not an empty list'
        );
    }
}
