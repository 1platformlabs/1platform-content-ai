<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * An API-supplied theme slug the plugin does not know produced two silent
 * failures at once (#48).
 *
 * 'contai_wordpress_theme' comes from the API
 * (ContaiSiteConfigService writes it from the site config payload) and is only
 * ever passed through sanitize_text_field(), which validates its characters,
 * not its membership in the theme maps. An unmapped slug then gets:
 *
 *  - no theme configuration, because contai_apply_theme_defaults()'s switch had
 *    no default case, and
 *  - no primary nav menu location, because contai_get_primary_nav_location()
 *    returns null for a slug it does not know — which leaves the theme falling
 *    back to wp_page_menu(), i.e. a menu listing the generated legal pages.
 *    That is the symptom originally reported on this issue.
 *
 * Neither is repaired by guessing: the hand-verified maps exist precisely
 * because a plausible-looking slug is the silent no-op this whole issue is
 * about. What must not happen is that it stays invisible.
 */
class UnmappedThemeTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';

        $this->warnings = [];

        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                return $name === CONTAI_SITE_WARNINGS_OPTION ? $this->warnings : $default;
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
        WP_Mock::userFunction('set_theme_mod', ['return' => true]);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @return array<int, string> Messages of the recorded theme-defaults warnings.
     */
    private function themeWarnings(): array
    {
        $out = [];
        foreach ($this->warnings as $warning) {
            if (($warning['step'] ?? '') === 'theme defaults') {
                $out[] = $warning['message'];
            }
        }

        return $out;
    }

    public function test_an_unmapped_theme_is_reported(): void
    {
        contai_apply_theme_defaults('some-theme-the-api-invented');

        $messages = $this->themeWarnings();

        $this->assertCount(1, $messages, 'An unmapped theme must not be configured in silence (#48)');
        $this->assertStringContainsString(
            'some-theme-the-api-invented',
            $messages[0],
            'The warning must name the offending slug'
        );
        $this->assertStringContainsString(
            'astra',
            $messages[0],
            'The warning must list the slugs that ARE mapped, so the fix is obvious'
        );
    }

    /**
     * The control that discriminates. If this warning fired for every theme it
     * would be noise, and the option would fill with it until the FIFO evicted
     * the warnings that matter.
     */
    public function test_a_mapped_theme_reports_nothing(): void
    {
        contai_apply_theme_defaults('newsmatic');

        $this->assertSame(
            [],
            $this->themeWarnings(),
            'A theme the plugin knows how to configure must record no warning'
        );
    }

    /**
     * The map the warning is checked against must be the one the nav location
     * resolver actually consults, or the two can drift apart and the warning
     * starts lying in whichever direction the drift went.
     */
    public function test_unmapped_means_absent_from_the_nav_location_map(): void
    {
        $this->assertArrayNotHasKey('some-theme-the-api-invented', CONTAI_THEME_NAV_LOCATION_MAP);
        $this->assertArrayHasKey('newsmatic', CONTAI_THEME_NAV_LOCATION_MAP);
    }
}
