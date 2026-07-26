<?php

namespace ContAI\Tests\Unit\Services\Menu;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiMainMenuManager;
use ContaiConfig;
use ReflectionMethod;

class MainMenuManagerTest extends TestCase
{
    private $config;
    private ContaiMainMenuManager $manager;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->config = Mockery::mock(ContaiConfig::class);
        $this->config->shouldReceive('getMaxMenuCategories')->andReturn(10)->byDefault();

        $this->manager = new ContaiMainMenuManager($this->config);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod(ContaiMainMenuManager::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->manager, ...$args);
    }

    // ── removePageItems ──

    public function test_removePageItems_deletes_page_type_items(): void
    {
        $pageItem = (object) ['ID' => 101, 'type' => 'post_type', 'object' => 'page'];
        $catItem = (object) ['ID' => 102, 'type' => 'taxonomy', 'object' => 'category'];
        $customItem = (object) ['ID' => 103, 'type' => 'custom', 'object' => 'custom'];

        WP_Mock::userFunction('wp_get_nav_menu_items')
            ->once()
            ->with(5)
            ->andReturn([$pageItem, $catItem, $customItem]);

        WP_Mock::userFunction('wp_delete_post')
            ->once()
            ->with(101, true);

        $this->invokePrivate('removePageItems', [5]);
    }

    public function test_removePageItems_does_nothing_when_no_items(): void
    {
        WP_Mock::userFunction('wp_get_nav_menu_items')
            ->once()
            ->with(7)
            ->andReturn(false);

        WP_Mock::userFunction('wp_delete_post')->never();

        $this->invokePrivate('removePageItems', [7]);
    }

    // ── removeOrphanedCategoryItems ──

    public function test_removeOrphanedCategoryItems_deletes_when_category_missing(): void
    {
        $orphanItem = (object) ['ID' => 201, 'type' => 'taxonomy', 'object' => 'category', 'object_id' => 99];

        WP_Mock::userFunction('wp_get_nav_menu_items')
            ->once()
            ->with(9)
            ->andReturn([$orphanItem]);

        WP_Mock::userFunction('get_category')
            ->once()
            ->with(99)
            ->andReturn(false);

        WP_Mock::userFunction('wp_delete_post')
            ->once()
            ->with(201, true);

        $this->invokePrivate('removeOrphanedCategoryItems', [9]);
    }

    public function test_removeOrphanedCategoryItems_deletes_when_wp_error(): void
    {
        $errorItem = (object) ['ID' => 301, 'type' => 'taxonomy', 'object' => 'category', 'object_id' => 77];

        WP_Mock::userFunction('wp_get_nav_menu_items')
            ->once()
            ->with(11)
            ->andReturn([$errorItem]);

        $wpError = Mockery::mock('WP_Error');
        WP_Mock::userFunction('get_category')
            ->once()
            ->with(77)
            ->andReturn($wpError);

        WP_Mock::userFunction('is_wp_error')
            ->with($wpError)
            ->andReturn(true);

        WP_Mock::userFunction('wp_delete_post')
            ->once()
            ->with(301, true);

        $this->invokePrivate('removeOrphanedCategoryItems', [11]);
    }

    public function test_removeOrphanedCategoryItems_keeps_valid_categories(): void
    {
        $validItem = (object) ['ID' => 401, 'type' => 'taxonomy', 'object' => 'category', 'object_id' => 5];

        WP_Mock::userFunction('wp_get_nav_menu_items')
            ->once()
            ->with(13)
            ->andReturn([$validItem]);

        $validCat = (object) ['term_id' => 5, 'slug' => 'tech'];
        WP_Mock::userFunction('get_category')
            ->once()
            ->with(5)
            ->andReturn($validCat);

        WP_Mock::userFunction('is_wp_error')
            ->andReturn(false);

        WP_Mock::userFunction('wp_delete_post')->never();

        $this->invokePrivate('removeOrphanedCategoryItems', [13]);
    }

    // ── updateMainMenuWithCategories: empty-categories path (#48) ──

    /**
     * Regression guard for #48: when no custom categories exist yet, the
     * wizard must STILL create a "Main Navigation" menu and assign it to the
     * theme's primary nav location (with a Home item). If it doesn't, the
     * primary location stays empty and themes fall back to wp_page_menu(),
     * which lists published pages — the generated legal pages — producing the
     * reported "main menu shows only legal pages, no categories" symptom.
     */
    public function test_updateMainMenuWithCategories_creates_and_assigns_primary_menu_with_no_categories(): void
    {
        $menu_id = 42;

        WP_Mock::userFunction('wp_get_nav_menu_object')
            ->with('Main Navigation')
            ->andReturn(false);

        WP_Mock::userFunction('wp_create_nav_menu')
            ->once()
            ->with('Main Navigation')
            ->andReturn($menu_id);

        WP_Mock::userFunction('is_wp_error')->andReturn(false);

        // Plumbing for primary-location resolution (covers both the static-map
        // and runtime-detection branches — get_option returns the default).
        WP_Mock::userFunction('get_option')->andReturnUsing(
            function ($key, $default = false) {
                return $default;
            }
        );
        WP_Mock::userFunction('get_nav_menu_locations')->andReturn([]);
        WP_Mock::userFunction('get_stylesheet')->andReturn('astra');
        WP_Mock::userFunction('get_registered_nav_menus')
            ->andReturn(['primary' => 'Primary Menu']);

        // A menu MUST be assigned to some nav location, carrying our menu id.
        WP_Mock::userFunction('set_theme_mod')
            ->once()
            ->with('nav_menu_locations', Mockery::on(function ($locations) use ($menu_id) {
                return is_array($locations) && in_array($menu_id, $locations, true);
            }));

        // Fresh menu → no existing items on every read.
        WP_Mock::userFunction('wp_get_nav_menu_items')->andReturn(false);
        WP_Mock::userFunction('home_url')->with('/')->andReturn('https://example.com/');

        // A Home item MUST be added even with zero categories.
        WP_Mock::userFunction('wp_update_nav_menu_item')
            ->once()
            ->with($menu_id, 0, Mockery::on(function ($item) {
                return is_array($item)
                    && ($item['menu-item-classes'] ?? null) === 'home-page-link'
                    && !empty($item['menu-item-title']);
            }))
            ->andReturn(500);

        $this->manager->updateMainMenuWithCategories([]);
    }

    /**
     * Regression guard for #48 (re-execution path).
     *
     * The wizard calls switch_theme() during generateWebsite(), and theme mods
     * — which hold nav_menu_locations — are stored per stylesheet. So on a
     * re-execution the "Main Navigation" menu survives while its location
     * binding does not. getOrCreateMenu() used to early-return whenever the
     * menu already existed, so assignMenuToPrimaryLocation() ran on first
     * creation only and the binding was never restored. An unbound primary
     * location makes themes fall back to wp_page_menu(), which lists published
     * pages — the generated legal pages — reproducing the reported "main menu
     * shows only legal pages, no categories" symptom that reopened this issue.
     *
     * Assigning is idempotent, so re-asserting it every run is safe.
     */
    public function test_updateMainMenuWithCategories_rebinds_location_when_menu_already_exists(): void
    {
        $menu_id = 77;

        // Menu survived a previous run …
        WP_Mock::userFunction('wp_get_nav_menu_object')
            ->with('Main Navigation')
            ->andReturn((object) ['term_id' => $menu_id]);

        // … so it must NOT be created again.
        WP_Mock::userFunction('wp_create_nav_menu')->never();

        WP_Mock::userFunction('is_wp_error')->andReturn(false);
        WP_Mock::userFunction('get_option')->andReturnUsing(
            function ($key, $default = false) {
                return $default;
            }
        );

        // … but its binding did not survive the theme switch.
        WP_Mock::userFunction('get_nav_menu_locations')->andReturn([]);
        WP_Mock::userFunction('get_stylesheet')->andReturn('astra');
        WP_Mock::userFunction('get_registered_nav_menus')
            ->andReturn(['primary' => 'Primary Menu']);

        // The binding MUST be re-asserted, carrying the existing menu id.
        WP_Mock::userFunction('set_theme_mod')
            ->once()
            ->with('nav_menu_locations', Mockery::on(function ($locations) use ($menu_id) {
                return is_array($locations) && in_array($menu_id, $locations, true);
            }));

        WP_Mock::userFunction('wp_get_nav_menu_items')->andReturn(false);
        WP_Mock::userFunction('home_url')->with('/')->andReturn('https://example.com/');
        WP_Mock::userFunction('wp_update_nav_menu_item')->andReturn(600);

        $this->manager->updateMainMenuWithCategories([]);
    }

    /**
     * Source guard for #48.
     *
     * assignMenuToPrimaryLocation() trusts CONTAI_THEME_NAV_LOCATION_MAP via
     * contai_get_primary_nav_location(). That function lives in
     * site-generation.php, which the test bootstrap does not load, so the
     * static-map branch cannot be exercised behaviourally from here — the
     * runtime-detection branch is what the tests above cover. The decision
     * logic itself is covered by NavLocationTest; this pins the wiring so the
     * branch cannot regress to assigning an unregistered location and
     * returning, which is what made runtime detection unreachable.
     */
    public function test_primary_location_validates_static_map_before_assigning(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/includes/services/menu/MainMenuManager.php'
        );

        $this->assertStringContainsString(
            'contai_nav_location_is_usable($static_location, $registered_menus, $registry_is_stale)',
            $source,
            'The static nav location must be validated against the registered menus ' .
            'before short-circuiting, or an unregistered location is assigned silently (#48)'
        );

        $this->assertStringNotContainsString(
            'if ($static_location) {',
            $source,
            'The unvalidated early return must not come back — it makes runtime ' .
            'detection unreachable and leaves the primary location unbound (#48)'
        );
    }

    // ── Off-canvas / mobile nav location ───────────────────────────

    /**
     * Model nav_menu_locations as WordPress does: one option that successive
     * assignments accumulate into.
     *
     * A stub returning a fixed [] would make the SECOND assignment invisible —
     * each contai_assign_nav_menu_location() call would start from empty and the
     * test could not tell "bound both" from "bound the last one". That is the
     * mock-ignores-the-semantics failure that let a mutant survive in v2.38.11.
     *
     * @param array<string,int> $locations Captured by reference.
     */
    private function stubNavLocationStorage(array &$locations): void
    {
        WP_Mock::userFunction('get_nav_menu_locations')
            ->andReturnUsing(function () use (&$locations) {
                return $locations;
            });

        WP_Mock::userFunction('set_theme_mod')
            ->andReturnUsing(function ($key, $value) use (&$locations) {
                if ('nav_menu_locations' === $key) {
                    $locations = $value;
                }
            });
    }

    /**
     * @param array<string,string> $registered
     * @param array<string,mixed>  $written    Captured update_option() writes.
     */
    private function stubMenuCreationFor(int $menu_id, array $registered, string $theme = 'astra', array &$written = []): void
    {
        WP_Mock::userFunction('wp_get_nav_menu_object')
            ->with('Main Navigation')
            ->andReturn((object) ['term_id' => $menu_id]);
        WP_Mock::userFunction('is_wp_error')->andReturn(false);
        WP_Mock::userFunction('get_option')->andReturnUsing(
            function ($key, $default = false) use ($theme, &$written) {
                if ('contai_wordpress_theme' === $key) {
                    return $theme;
                }

                return $written[ $key ] ?? $default;
            }
        );
        // contai_record_site_warning() is a REAL function here — site-warnings.php
        // is loaded by MainMenuManager's own requires, and WP_Mock cannot
        // intercept an already-declared function. Asserting on a userFunction()
        // expectation for it would pass vacuously, so the warning is observed
        // where it actually lands: the option it writes.
        WP_Mock::userFunction('update_option')->andReturnUsing(
            function ($key, $value) use (&$written) {
                $written[ $key ] = $value;

                return true;
            }
        );
        WP_Mock::userFunction('get_stylesheet')->andReturn($theme);
        WP_Mock::userFunction('get_registered_nav_menus')->andReturn($registered);
        WP_Mock::userFunction('wp_get_nav_menu_items')->andReturn(false);
        WP_Mock::userFunction('home_url')->with('/')->andReturn('https://example.com/');
        WP_Mock::userFunction('wp_update_nav_menu_item')->andReturn(600);
    }

    /**
     * @param array<string,mixed> $written
     * @return list<string>
     */
    private function warningStepsIn(array $written): array
    {
        $warnings = $written[ CONTAI_SITE_WARNINGS_OPTION ] ?? [];

        return array_column(is_array($warnings) ? $warnings : [], 'step');
    }

    /**
     * Binding the primary location alone is not enough.
     *
     * Astra — this plugin's DEFAULT theme — renders its off-canvas header from a
     * second registered location, 'mobile_menu'. Left unbound, that element
     * falls back to wp_page_menu(), which lists the published PAGES: the
     * generated legal pages. Measured on a live es_ES install (WordPress 7.0.2,
     * plugin 2.39.0): with only 'primary' bound the home page served three
     * `page_item page-item-N` entries inside #ast-hf-mobile-menu, including
     * "Terminos y condiciones"; binding 'mobile_menu' to the same menu took that
     * to zero. Blocksy's #offcanvas behaves identically via 'menu_mobile'.
     *
     * Six rounds of fixes on this issue asked WHICH key the wizard writes. None
     * asked HOW MANY it has to (#48).
     */
    public function test_updateMainMenuWithCategories_also_binds_the_theme_mobile_location(): void
    {
        $menu_id   = 77;
        $locations = [];
        $written   = [];

        $this->stubNavLocationStorage($locations);
        // 'astra' is selected through get_option('contai_wordpress_theme'), the
        // same input production uses, so contai_get_mobile_nav_location() runs
        // for real against CONTAI_THEME_MOBILE_NAV_LOCATION_MAP.
        $this->stubMenuCreationFor($menu_id, [
            'primary'     => 'Primary Menu',
            'mobile_menu' => 'Off-Canvas Menu',
        ], 'astra', $written);

        $this->manager->updateMainMenuWithCategories([]);

        $this->assertSame(
            ['primary' => $menu_id, 'mobile_menu' => $menu_id],
            $locations,
            "The off-canvas location must carry the generated menu too, or the mobile " .
            'header keeps rendering the legal pages via wp_page_menu() (#48)'
        );
        $this->assertNotContains('mobile nav location', $this->warningStepsIn($written));
    }

    /**
     * Blocksy reaches the same defect through a different slug — the map, not a
     * hardcoded location, is what the resolver reads.
     */
    public function test_mobile_binding_uses_the_theme_own_slug(): void
    {
        $menu_id   = 81;
        $locations = [];
        $written   = [];

        $this->stubNavLocationStorage($locations);
        $this->stubMenuCreationFor($menu_id, [
            'menu_1'      => 'Header Menu',
            'menu_mobile' => 'Mobile Menu',
        ], 'blocksy', $written);

        $this->manager->updateMainMenuWithCategories([]);

        $this->assertArrayHasKey('menu_mobile', $locations, "Blocksy's off-canvas slug is menu_mobile (#48)");
        $this->assertSame($menu_id, $locations['menu_mobile']);
    }

    /**
     * Seven of the nine supported themes have no separately-rendered mobile
     * menu. That is the expected case and must stay silent: a warning there
     * would train the reader to ignore the warning that matters.
     */
    public function test_no_mobile_location_mapped_binds_only_primary_and_warns_nothing(): void
    {
        $menu_id   = 78;
        $locations = [];
        $written   = [];

        $this->stubNavLocationStorage($locations);
        // GeneratePress registers no off-canvas location and has no map entry.
        $this->stubMenuCreationFor($menu_id, ['primary' => 'Primary Menu'], 'generatepress', $written);

        $this->manager->updateMainMenuWithCategories([]);

        $this->assertSame(['primary' => $menu_id], $locations);
        $this->assertNotContains(
            'mobile nav location',
            $this->warningStepsIn($written),
            'A theme with no off-canvas menu is the normal case and must stay silent (#48)'
        );
    }

    /**
     * A mapped location the ACTIVE theme does not register is the silent no-op
     * this whole issue is made of: WordPress drops the entry and nothing says
     * so. It must not be written, and it must leave a trace.
     */
    public function test_mobile_location_the_theme_does_not_register_is_not_assigned_but_warns(): void
    {
        $menu_id   = 79;
        $locations = [];
        $written   = [];

        $this->stubNavLocationStorage($locations);
        // Astra is mapped to 'mobile_menu', but this registry — populated and
        // genuinely describing the active theme, because get_option() returns
        // the default for 'theme_switched' so contai_nav_registry_is_stale() is
        // false — does not list it. WordPress would drop the entry silently.
        $this->stubMenuCreationFor($menu_id, ['primary' => 'Primary Menu'], 'astra', $written);

        $this->manager->updateMainMenuWithCategories([]);

        $this->assertSame(['primary' => $menu_id], $locations, 'An unregistered location must not be written');
        $this->assertContains(
            'mobile nav location',
            $this->warningStepsIn($written),
            'A dropped assignment must leave a trace (#48)'
        );
    }
}
