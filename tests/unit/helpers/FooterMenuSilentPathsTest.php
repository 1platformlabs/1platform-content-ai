<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The two remaining silent exits of contai_create_footer_menu_with_legal_pages()
 * (#48).
 *
 * v2.38.13 instrumented this function's failure paths — menu creation at
 * site-generation.php and the unresolved footer location at the end — but two
 * were left uninstrumented, and both end with the caller appending "Footer menu
 * with legal pages created" to the completed steps:
 *
 *  1. No published page carries the _contai_legal_source meta. The function
 *     returns before the location binding AND before the durable warning, so
 *     the site ends with an empty footer menu bound to nothing.
 *  2. wp_update_nav_menu_item() fails. Read from core rather than assumed:
 *     wp-includes/nav-menu.php inserts the item post and publishes it BEFORE
 *     attaching it to the menu term, and returns the WP_Error from that attach.
 *     So the failure leaves a published nav_menu_item that belongs to no menu —
 *     it renders nowhere, and nothing said so.
 *
 * This issue has reopened for four months because every root cause had to be
 * found by reading code: the wizard applied a change, WordPress ignored it, and
 * there was no trace to read.
 */
class FooterMenuSilentPathsTest extends TestCase
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
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * @param array<int, object> $legalPages What get_posts() returns.
     * @param mixed              $itemResult What wp_update_nav_menu_item() returns.
     */
    private function runWith(array $legalPages, $itemResult = 99): void
    {
        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                if ($name === CONTAI_SITE_WARNINGS_OPTION) {
                    return $this->warnings;
                }
                if ($name === 'contai_wordpress_theme') {
                    return 'astra';
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

        WP_Mock::userFunction('wp_get_nav_menu_object', ['return' => (object) ['term_id' => 11]]);
        WP_Mock::userFunction('is_wp_error')->andReturnUsing(
            static fn ($thing) => $thing instanceof \WP_Error
        );
        WP_Mock::userFunction('get_posts', ['return' => $legalPages]);
        WP_Mock::userFunction('wp_get_nav_menu_items', ['return' => []]);
        WP_Mock::userFunction('wp_update_nav_menu_item', ['return' => $itemResult]);
        WP_Mock::userFunction('get_nav_menu_locations', ['return' => []]);
        WP_Mock::userFunction('get_registered_nav_menus', ['return' => ['footer_menu' => 'Footer']]);
        WP_Mock::userFunction('get_stylesheet', ['return' => 'astra']);
        WP_Mock::userFunction('contai_log', ['return' => null]);
        WP_Mock::userFunction('set_theme_mod', ['return' => true]);

        contai_create_footer_menu_with_legal_pages();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warningsForStep(string $step): array
    {
        return array_values(array_filter(
            $this->warnings,
            static fn ($w) => ($w['step'] ?? '') === $step
        ));
    }

    public function test_records_a_warning_when_there_are_no_legal_pages_to_link(): void
    {
        $this->runWith([]);

        $recorded = $this->warningsForStep('footer legal menu');

        $this->assertCount(1, $recorded, 'An empty, unbound footer menu must leave a trace');
        $this->assertStringContainsString('_contai_legal_source', $recorded[0]['message']);
    }

    public function test_records_a_warning_when_an_item_cannot_be_added_to_the_menu(): void
    {
        $error = \Mockery::mock('WP_Error');
        $error->shouldReceive('get_error_message')->andReturn('could not attach to the menu');

        $this->runWith([(object) ['ID' => 5, 'post_title' => 'Privacy']], $error);

        $recorded = $this->warningsForStep('footer legal menu');

        $this->assertCount(1, $recorded);
        $this->assertStringContainsString('Privacy', $recorded[0]['message']);
        $this->assertStringContainsString('could not attach to the menu', $recorded[0]['message']);
    }

    /**
     * Core returns 0 on a failed wp_insert_post() rather than a WP_Error
     * (wp-includes/nav-menu.php), so the falsy case needs its own cover.
     */
    public function test_records_a_warning_when_no_item_id_comes_back(): void
    {
        $this->runWith([(object) ['ID' => 5, 'post_title' => 'Privacy']], 0);

        $recorded = $this->warningsForStep('footer legal menu');

        $this->assertCount(1, $recorded);
        $this->assertStringContainsString('no item id returned', $recorded[0]['message']);
    }

    /**
     * Discriminating control: the happy path must stay quiet. A warning helper
     * that fires unconditionally would satisfy every assertion above while
     * telling the operator nothing.
     */
    public function test_stays_quiet_when_the_menu_is_built_successfully(): void
    {
        $this->runWith([(object) ['ID' => 5, 'post_title' => 'Privacy']], 99);

        $this->assertSame([], $this->warningsForStep('footer legal menu'));
    }
}
