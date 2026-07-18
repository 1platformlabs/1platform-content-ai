<?php

namespace ContAI\Tests\Unit\Helpers;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Tests for includes/helpers/theme-settings.php.
 *
 * Same root cause as AstraSettingsTest, for the remaining themes of issue #48:
 * the wizard configured each theme with set_theme_mod() and a guessed key name.
 * Where the theme reads from somewhere else — or under another name — the write
 * is a silent no-op: no error, no log, no effect.
 *
 * GeneratePress 3.6.1 reads every setting through generate_get_option(), backed
 * by the 'generate_settings' option (inc/theme-functions.php:20-33), and never
 * from theme mods. Blocksy has no boolean breadcrumbs setting at all —
 * breadcrumbs are one entry in an ordered '{prefix}_hero_elements' list that
 * ships disabled outside WooCommerce products.
 */
class ThemeSettingsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/theme-settings.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── contai_generatepress_settings_merge() — pure ───────────────

    public function test_generatepress_merge_preserves_unrelated_existing_settings(): void
    {
        $merged = contai_generatepress_settings_merge(
            ['content_layout_setting' => 'one-container', 'post_content' => 'full'],
            ['blog_layout_setting' => 'right-sidebar']
        );

        $this->assertSame('one-container', $merged['content_layout_setting']);
        $this->assertSame('full', $merged['post_content']);
        $this->assertSame('right-sidebar', $merged['blog_layout_setting']);
    }

    /**
     * generate_get_option() only backfills MISSING keys from defaults
     * (wp_parse_args), so a value we drop is unrecoverable — it silently
     * reverts to the theme default and the user's customisation is gone.
     */
    public function test_generatepress_merge_never_drops_existing_keys(): void
    {
        $current = ['a' => 1, 'b' => 2, 'c' => 3];

        $merged = contai_generatepress_settings_merge($current, ['b' => 'changed']);

        $this->assertSame(['a' => 1, 'b' => 'changed', 'c' => 3], $merged);
    }

    public function test_generatepress_merge_treats_non_array_current_value_as_empty(): void
    {
        foreach ([null, false, '', 'corrupted', 0] as $bogus) {
            $merged = contai_generatepress_settings_merge($bogus, ['layout_setting' => 'right-sidebar']);

            $this->assertSame(['layout_setting' => 'right-sidebar'], $merged);
        }
    }

    // ── contai_generatepress_apply_settings() — WordPress-facing ───

    /**
     * The core regression: the payload must land in the 'generate_settings'
     * option. If this ever goes back through set_theme_mod(), GeneratePress
     * reads none of it.
     */
    public function test_generatepress_apply_writes_to_the_generate_settings_option(): void
    {
        WP_Mock::userFunction('get_option')
            ->once()
            ->with('generate_settings', [])
            ->andReturn([]);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('generate_settings', ['blog_layout_setting' => 'right-sidebar']);

        contai_generatepress_apply_settings(['blog_layout_setting' => 'right-sidebar']);

        $this->assertTrue(true);
    }

    public function test_generatepress_apply_merges_into_existing_settings(): void
    {
        $written = null;

        WP_Mock::userFunction('get_option')
            ->once()
            ->with('generate_settings', [])
            ->andReturn(['post_content' => 'full']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('generate_settings', Mockery::on(function ($payload) use (&$written) {
                $written = $payload;

                return true;
            }));

        contai_generatepress_apply_settings(['layout_setting' => 'right-sidebar']);

        $this->assertSame('full', $written['post_content'], 'Unrelated settings must survive');
        $this->assertSame('right-sidebar', $written['layout_setting']);
    }

    public function test_generatepress_apply_is_a_noop_for_an_empty_payload(): void
    {
        WP_Mock::userFunction('get_option')->never();
        WP_Mock::userFunction('update_option')->never();

        contai_generatepress_apply_settings([]);

        $this->assertTrue(true);
    }

    // ── contai_hero_elements_enable() — pure ───────────────────────

    /**
     * Blocksy ships the breadcrumbs entry present but disabled
     * (inc/components/hero/elements.php:65-68 'enabled' => $prefix ===
     * 'product'), so the realistic case is flipping an existing entry.
     */
    public function test_hero_enable_flips_an_existing_disabled_entry(): void
    {
        $result = contai_hero_elements_enable(
            [
                ['id' => 'custom_title', 'enabled' => true],
                ['id' => 'breadcrumbs', 'enabled' => false],
            ],
            'breadcrumbs',
            []
        );

        $this->assertSame('breadcrumbs', $result[1]['id']);
        $this->assertTrue($result[1]['enabled']);
    }

    /**
     * The list is ORDERED and carries the user's arrangement plus elements we
     * know nothing about. Rewriting it wholesale would silently reorder or drop
     * them.
     */
    public function test_hero_enable_preserves_order_and_unknown_elements(): void
    {
        $result = contai_hero_elements_enable(
            [
                ['id' => 'breadcrumbs', 'enabled' => false],
                ['id' => 'custom_title', 'enabled' => true],
                ['id' => 'some_future_element', 'enabled' => false, 'extra' => 'keep me'],
            ],
            'breadcrumbs',
            []
        );

        $this->assertSame(
            ['breadcrumbs', 'custom_title', 'some_future_element'],
            array_column($result, 'id'),
            'Existing order must be preserved'
        );
        $this->assertFalse(
            $result[2]['enabled'],
            'Only the requested element may be enabled'
        );
        $this->assertSame(
            'keep me',
            $result[2]['extra'],
            'Unknown keys on unknown elements must survive untouched'
        );
    }

    public function test_hero_enable_appends_when_the_element_is_absent(): void
    {
        $result = contai_hero_elements_enable(
            [['id' => 'custom_title', 'enabled' => true]],
            'breadcrumbs',
            []
        );

        $this->assertCount(2, $result);
        $this->assertSame('breadcrumbs', $result[1]['id']);
        $this->assertTrue($result[1]['enabled']);
    }

    /**
     * Nothing stored yet is the fresh-install case: the theme is using its own
     * runtime defaults, which we cannot read, so we seed the documented shape
     * with breadcrumbs on.
     */
    public function test_hero_enable_uses_the_fallback_when_nothing_is_stored(): void
    {
        $fallback = [
            ['id' => 'custom_title', 'enabled' => true],
            ['id' => 'custom_description', 'enabled' => true],
        ];

        foreach ([null, false, '', [], 'corrupted'] as $bogus) {
            $result = contai_hero_elements_enable($bogus, 'breadcrumbs', $fallback);

            $this->assertSame(
                ['custom_title', 'custom_description', 'breadcrumbs'],
                array_column($result, 'id'),
                'The fallback list must be seeded and breadcrumbs appended'
            );
            $this->assertTrue($result[2]['enabled']);
        }
    }

    /**
     * A malformed entry (not an array, or missing 'id') must not fatal or be
     * mistaken for the target element.
     */
    public function test_hero_enable_tolerates_malformed_entries(): void
    {
        $result = contai_hero_elements_enable(
            ['not an array', ['no_id_key' => true], ['id' => 'breadcrumbs', 'enabled' => false]],
            'breadcrumbs',
            []
        );

        $this->assertCount(3, $result);
        $this->assertTrue($result[2]['enabled']);
    }

    /**
     * array_values() must renumber, because Blocksy reads the list with
     * foreach and a sparse/string-keyed array would serialize as an object.
     */
    public function test_hero_enable_returns_a_list_with_sequential_keys(): void
    {
        $result = contai_hero_elements_enable(
            [3 => ['id' => 'breadcrumbs', 'enabled' => false], 7 => ['id' => 'custom_title', 'enabled' => true]],
            'breadcrumbs',
            []
        );

        $this->assertSame([0, 1], array_keys($result));
    }
}
