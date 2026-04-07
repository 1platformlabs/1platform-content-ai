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
}
