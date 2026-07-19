<?php

namespace ContAI\Tests\Unit\Helpers;

use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

require_once __DIR__ . '/../../../includes/helpers/nav-menu-claim.php';

/**
 * The wizard writes the right nav location and WordPress takes it back (#48).
 *
 * switch_theme() snapshots the OUTGOING theme's assignments into
 * 'theme_switch_menu_locations' (theme.php:785-786). On the NEXT request
 * check_theme_switched() fires 'after_switch_theme', where _wp_menus_changed()
 * (default-filters.php:371) feeds that snapshot to wp_map_nav_menu_locations(),
 * which overwrites any same-slug location with the old theme's value
 * (nav-menu.php:1249-1254) and writes the result back with set_theme_mod().
 *
 * These tests exercise the re-assert hook end to end rather than asserting that
 * a call appears in the source: this issue's v2.38.7 root cause was code that
 * was present and unreachable, so presence proves nothing.
 */
class NavMenuClaimTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed>|null */
    private ?array $writtenLocations = null;

    private string $stylesheet = 'astra';

    /** @var array<string, string> */
    private array $registered = [];

    /** @var array<string, int> */
    private array $locations = [];

    private bool $menuExists = true;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->options = [];
        $this->writtenLocations = null;
        $this->stylesheet = 'astra';
        $this->registered = ['primary' => 'Primary'];
        $this->locations = [];
        $this->menuExists = true;

        // Every stub reads mutable state instead of being redefined mid-test:
        // WP_Mock keeps the FIRST definition, so a second userFunction() call
        // for the same name silently does nothing — a stub that cannot change
        // would have made three of these tests pass for the wrong reason.
        WP_Mock::userFunction('get_stylesheet', [
            'return' => function () {
                return $this->stylesheet;
            },
        ]);
        WP_Mock::userFunction('get_registered_nav_menus', [
            'return' => function () {
                return $this->registered;
            },
        ]);
        WP_Mock::userFunction('get_nav_menu_locations', [
            'return' => function () {
                return $this->locations;
            },
        ]);
        WP_Mock::userFunction('wp_get_nav_menu_object', [
            'return' => function ($id) {
                return $this->menuExists ? (object) ['term_id' => $id] : false;
            },
        ]);

        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                return $this->options[$name] ?? $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) {
                $this->options[$name] = $value;
                return true;
            },
        ]);
        WP_Mock::userFunction('set_theme_mod', [
            'return' => function ($name, $value) {
                if ($name === 'nav_menu_locations') {
                    $this->writtenLocations = $value;
                }
                return true;
            },
        ]);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Simulate what core does between the wizard's request and ours.
     *
     * @param array<string, int> $oldThemeLocations Assignments the previous theme had.
     * @param array<string, int> $wizardLocations   What the wizard wrote.
     * @return array<string, int> What nav_menu_locations holds after _wp_menus_changed().
     */
    private function coreRemap(array $oldThemeLocations, array $wizardLocations): array
    {
        // wp_map_nav_menu_locations(), same-slug branch (nav-menu.php:1249-1254).
        foreach ($oldThemeLocations as $location => $menuId) {
            $wizardLocations[$location] = $menuId;
        }
        return $wizardLocations;
    }

    public function test_the_generated_menu_survives_cores_post_switch_remap(): void
    {
        $this->stylesheet = 'astra';
        $this->registered = ['primary' => 'Primary'];

        // Wizard request: assign our menu 12 to 'primary' and claim it.
        $this->locations = [];
        contai_assign_nav_menu_location('primary', 12);
        $this->assertSame(['primary' => 12], $this->writtenLocations);

        // Next request: core hands 'primary' back to the previous theme's menu 99.
        $afterCore = $this->coreRemap(['primary' => 99], ['primary' => 12]);
        $this->assertSame(['primary' => 99], $afterCore, 'Precondition: core really does overwrite');

        $this->locations = $afterCore;
        $this->writtenLocations = null;

        contai_reassert_nav_menu_locations();

        $this->assertSame(
            ['primary' => 12],
            $this->writtenLocations,
            'The generated menu must be re-assigned after core remaps it to the previous theme (#48)'
        );
    }

    public function test_nothing_is_written_when_core_left_our_assignment_alone(): void
    {
        $this->stylesheet = 'astra';
        $this->registered = ['primary' => 'Primary'];
        $this->locations = [];

        contai_assign_nav_menu_location('primary', 12);

        $this->locations = ['primary' => 12];
        $this->writtenLocations = null;

        contai_reassert_nav_menu_locations();

        $this->assertNull($this->writtenLocations, 'No theme mod write when there is nothing to restore');
    }

    public function test_a_claim_for_another_theme_is_not_replayed(): void
    {
        $this->registered = ['primary' => 'Primary'];
        $this->locations = [];

        $this->stylesheet = 'astra';
        contai_assign_nav_menu_location('primary', 12);

        // The site owner has since chosen a different theme.
        $this->stylesheet = 'kadence';
        $this->locations = ['primary' => 99];
        $this->writtenLocations = null;

        contai_reassert_nav_menu_locations();

        $this->assertNull($this->writtenLocations, "A claim recorded for Astra must not be forced onto the owner's theme");
    }

    public function test_a_location_the_active_theme_does_not_register_is_skipped(): void
    {
        $this->stylesheet = 'astra';
        $this->locations = [];
        $this->registered = ['primary' => 'Primary'];

        contai_assign_nav_menu_location('footer_nav', 12);

        $this->locations = [];
        $this->writtenLocations = null;

        contai_reassert_nav_menu_locations();

        $this->assertNull(
            $this->writtenLocations,
            'WordPress silently drops unregistered locations, so re-asserting one is pointless noise'
        );
    }

    public function test_a_menu_the_owner_deleted_is_not_resurrected(): void
    {
        $this->stylesheet = 'astra';
        $this->registered = ['primary' => 'Primary'];
        $this->locations = [];

        contai_assign_nav_menu_location('primary', 12);

        $this->menuExists = false;
        $this->locations = ['primary' => 99];
        $this->writtenLocations = null;

        contai_reassert_nav_menu_locations();

        $this->assertNull($this->writtenLocations, 'A claim on a deleted menu must not be re-applied');
    }

    /**
     * The function being correct is worthless if nothing calls it.
     *
     * A re-assert that is never hooked is indistinguishable, from every other
     * test in this file, from one that works — and "present but unreachable" is
     * precisely how this issue's v2.38.7 regression shipped. The priority is
     * part of the assertion: at 10 or lower this would run BEFORE
     * _wp_menus_changed() and be overwritten again.
     */
    public function test_the_reassert_is_wired_after_cores_remapper(): void
    {
        WP_Mock::expectActionAdded('after_switch_theme', 'contai_reassert_nav_menu_locations', 11);

        contai_register_nav_menu_claim_hooks();

        $this->addToAssertionCount(1);
    }

    /**
     * SOURCE GUARD — declared as such, because it checks FORM, not behaviour.
     *
     * The test above proves the registration function wires the hook at the
     * right priority; it cannot prove the file INVOKES that function at load,
     * because the file is required once by the bootstrap and a unit test has no
     * way to observe a load-time side effect after the fact. Deleting the call
     * survives every behavioural test in this file, so this asserts the call
     * exists in the source.
     *
     * A source guard cannot distinguish "present" from "reachable" — that is
     * what test_the_generated_menu_survives_cores_post_switch_remap() and the
     * early-return mutant are for. This one only stops the wiring from being
     * dropped.
     */
    public function test_source_guard_the_file_registers_its_hooks_at_load(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../includes/helpers/nav-menu-claim.php');

        $this->assertMatchesRegularExpression(
            '/^contai_register_nav_menu_claim_hooks\(\);$/m',
            $source,
            'Without a top-level call the re-assert is defined and never hooked (#48)'
        );
    }

    public function test_the_claim_records_the_stylesheet_it_was_made_for(): void
    {
        $this->stylesheet = 'generatepress';
        $this->registered = ['primary' => 'Primary'];
        $this->locations = [];

        contai_assign_nav_menu_location('primary', 7);

        $this->assertSame(
            ['stylesheet' => 'generatepress', 'locations' => ['primary' => 7]],
            $this->options[CONTAI_NAV_MENU_CLAIM_OPTION] ?? null
        );
    }
}
