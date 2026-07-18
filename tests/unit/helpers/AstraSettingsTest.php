<?php

namespace ContAI\Tests\Unit\Helpers;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Tests for includes/helpers/astra-settings.php.
 *
 * Regression cover for the remaining scope of GitHub issue #48 (breadcrumbs).
 *
 * The site wizard configured Astra with set_theme_mod(), which writes to the
 * theme_mods_astra option. Astra never reads that: every setting it owns is
 * read through astra_get_option(), which resolves against the astra-settings
 * option (Astra 4.13.6, inc/core/common-functions.php:558 ->
 * Astra_Theme_Options::get_options() -> class-astra-theme-options.php:933
 * get_option( ASTRA_THEME_SETTINGS ), and functions.php:19 defines
 * ASTRA_THEME_SETTINGS as 'astra-settings'). Astra's only get_theme_mod()
 * calls in the entire theme are for WordPress core's 'custom_logo'.
 *
 * So the old writes were silent no-ops — breadcrumbs stayed off (Astra defaults
 * 'breadcrumb-position' to 'none') and the sidebar layouts never applied. There
 * was no error and no log, which is why the issue kept reopening.
 *
 * Two further details these tests lock in:
 *  - The key names were wrong too: Astra reads 'breadcrumb-position', not
 *    'ast-breadcrumbs-position'. Fixing only the storage API would still fail.
 *  - Astra stores ALL settings in one serialized array, so writing must merge
 *    rather than overwrite, or unrelated customizer settings are destroyed.
 */
class AstraSettingsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/astra-settings.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── contai_astra_settings_merge() — pure ───────────────────────

    public function test_merge_preserves_unrelated_existing_settings(): void
    {
        $merged = contai_astra_settings_merge(
            ['blog-width' => 'custom', 'site-content-width' => 1200],
            ['breadcrumb-position' => 'astra_entry_top']
        );

        $this->assertSame('custom', $merged['blog-width']);
        $this->assertSame(1200, $merged['site-content-width']);
        $this->assertSame('astra_entry_top', $merged['breadcrumb-position']);
    }

    public function test_merge_overwrites_only_the_given_keys(): void
    {
        $merged = contai_astra_settings_merge(
            ['breadcrumb-position' => 'none', 'breadcrumb-separator' => '\00bb'],
            ['breadcrumb-position' => 'astra_entry_top']
        );

        $this->assertSame('astra_entry_top', $merged['breadcrumb-position']);
        $this->assertSame(
            '\00bb',
            $merged['breadcrumb-separator'],
            'Astra already defaults the separator to a CSS escape for »; we must not clobber it'
        );
    }

    /**
     * get_option() returns the default on a missing option, but a corrupted
     * row can yield a scalar or string. Neither may fatal or be written into.
     */
    public function test_merge_treats_non_array_current_value_as_empty(): void
    {
        foreach ([null, false, '', 'corrupted', 0] as $bogus) {
            $merged = contai_astra_settings_merge($bogus, ['breadcrumb-position' => 'astra_entry_top']);

            $this->assertSame(
                ['breadcrumb-position' => 'astra_entry_top'],
                $merged,
                'A non-array current value must degrade to an empty baseline'
            );
        }
    }

    // ── contai_astra_apply_settings() — WordPress-facing ───────────

    /**
     * The core regression: the payload must land in 'astra-settings'. If this
     * ever goes back to a theme mod, or to any other option name, Astra will
     * not see it and #48 returns.
     */
    public function test_apply_writes_to_the_astra_settings_option(): void
    {
        $writtenOption = null;
        $writtenPayload = null;

        WP_Mock::userFunction('get_option')
            ->with('astra-settings', [])
            ->andReturn([]);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with(Mockery::any(), Mockery::any())
            ->andReturnUsing(function ($option, $payload) use (&$writtenOption, &$writtenPayload) {
                $writtenOption = $option;
                $writtenPayload = $payload;
                return true;
            });

        contai_astra_apply_settings(['breadcrumb-position' => 'astra_entry_top']);

        $this->assertSame(
            'astra-settings',
            $writtenOption,
            'Settings must land in the option Astra reads, or they are a silent no-op (#48)'
        );
        $this->assertSame('astra_entry_top', $writtenPayload['breadcrumb-position']);
    }

    /**
     * 'breadcrumb-position' IS Astra's breadcrumbs on/off control — it defaults
     * to 'none' (hidden). 'astra_entry_top' is a valid choice in both of
     * Astra's position choice sets (classic and header-footer-builder), meaning
     * "Before Title" — see class-astra-breadcrumbs-configs.php:40-55.
     */
    public function test_apply_persists_a_position_that_actually_enables_breadcrumbs(): void
    {
        $written = null;

        WP_Mock::userFunction('get_option')
            ->with('astra-settings', [])
            ->andReturn(['breadcrumb-position' => 'none']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('astra-settings', Mockery::on(function ($payload) use (&$written) {
                $written = $payload;
                return true;
            }));

        contai_astra_apply_settings(['breadcrumb-position' => 'astra_entry_top']);

        $this->assertNotSame('none', $written['breadcrumb-position']);
        $this->assertContains(
            $written['breadcrumb-position'],
            ['astra_masthead_content', 'astra_header_markup_after', 'astra_entry_top', 'astra_header_primary_container_after', 'astra_header_after'],
            'Position must be one of Astra\'s non-"none" choices or breadcrumbs stay hidden'
        );
    }

    public function test_apply_does_not_destroy_existing_customizer_settings(): void
    {
        $written = null;

        WP_Mock::userFunction('get_option')
            ->with('astra-settings', [])
            ->andReturn(['site-content-width' => 1200, 'blog-width' => 'custom']);

        WP_Mock::userFunction('update_option')
            ->once()
            ->with('astra-settings', Mockery::on(function ($payload) use (&$written) {
                $written = $payload;
                return true;
            }));

        contai_astra_apply_settings(['breadcrumb-position' => 'astra_entry_top']);

        $this->assertSame(1200, $written['site-content-width']);
        $this->assertSame('custom', $written['blog-width']);
    }

    /**
     * Verified by Mockery on tearDown(): a never() expectation that fires
     * fails the test there, so no in-body assertion is possible.
     */
    public function test_apply_is_a_noop_for_an_empty_payload(): void
    {
        $this->expectNotToPerformAssertions();

        WP_Mock::userFunction('update_option')->never();

        contai_astra_apply_settings([]);
    }
}
