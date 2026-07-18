<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * contai_category_is_unused_default() — the predicate that replaced the blanket
 * 'exclude' => [get_option('default_category')] in setupNavigation() (#48).
 *
 * The bug it fixes: the wizard renames the default term in place into the first
 * API category (CategoryService::replaceUncategorizedWithFirstCategory), while
 * default_category keeps pointing at it — so excluding by that id dropped a
 * real category from the nav menu of every generated site, silently.
 */
class CategoryMenuTest extends TestCase
{
    // ── The regression itself ────────────────────────────────────────

    public function test_repurposed_default_category_is_kept_in_the_menu(): void
    {
        // Term 1 is default_category AND the term the wizard renamed.
        $this->assertFalse(
            contai_category_is_unused_default(1, 4, 1, 1),
            'A default category the wizard repurposed must stay in the menu (#48)'
        );
    }

    public function test_repurposed_default_category_is_kept_even_with_no_posts(): void
    {
        // The recorded flag alone is enough: post assignment may not have run.
        $this->assertFalse(
            contai_category_is_unused_default(1, 0, 1, 1),
            'The repurpose marker must win over the post count (#48)'
        );
    }

    public function test_default_category_with_posts_is_kept_without_the_flag(): void
    {
        // Covers sites generated BEFORE the marker existed: no option, but the
        // term holds posts, which proves it is a real category.
        $this->assertFalse(
            contai_category_is_unused_default(1, 7, 1, 0),
            'A post-bearing default category must stay in the menu even with no marker (#48)'
        );
    }

    // ── The original intent, preserved ───────────────────────────────

    public function test_pristine_empty_default_category_is_excluded(): void
    {
        $this->assertTrue(
            contai_category_is_unused_default(1, 0, 1, 0),
            'An untouched, empty "Uncategorized" placeholder must stay out of the menu'
        );
    }

    // ── Everything else is none of this predicate's business ─────────

    public function test_non_default_category_is_never_excluded(): void
    {
        $this->assertFalse(
            contai_category_is_unused_default(9, 0, 1, 0),
            'A regular empty category must not be excluded by this predicate'
        );
    }

    public function test_absent_default_category_option_excludes_nothing(): void
    {
        // get_option('default_category') can come back 0/'' on a broken install;
        // that must not turn term 0-matching into an exclusion.
        $this->assertFalse(
            contai_category_is_unused_default(1, 0, 0, 0),
            'With no default_category configured, nothing is excluded'
        );
    }

    public function test_marker_pointing_at_a_different_term_does_not_rescue_the_default(): void
    {
        // Stale marker from another term must not keep an empty placeholder in.
        $this->assertTrue(
            contai_category_is_unused_default(1, 0, 1, 5),
            'Only a marker for THIS term proves it was repurposed'
        );
    }
}
