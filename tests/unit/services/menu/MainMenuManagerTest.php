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
}
