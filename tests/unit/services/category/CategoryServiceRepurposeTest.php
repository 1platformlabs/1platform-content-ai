<?php

namespace ContAI\Tests\Unit\Services\Category;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiCategoryService;

/**
 * The wizard renames the default term in place into the first API category.
 * That makes default_category point at a real category, which the nav menu
 * step used to exclude — so the rename has to leave a marker behind (#48).
 */
class CategoryServiceRepurposeTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_repurposing_the_default_term_records_its_id(): void
    {
        $this->mockCommonTermFunctions();

        WP_Mock::userFunction('get_term_by')
            ->with('name', 'Uncategorized', 'category')
            ->andReturn((object) ['term_id' => 1, 'name' => 'Uncategorized']);

        WP_Mock::userFunction('wp_update_term')->andReturn(['term_id' => 1]);
        WP_Mock::userFunction('wp_insert_term')->andReturn(['term_id' => 2]);

        // The assertion: the marker must be written with the renamed term id.
        WP_Mock::userFunction('update_option')
            ->once()
            ->with(ContaiCategoryService::OPTION_REPURPOSED_DEFAULT, 1);

        $service = new ContaiCategoryService();
        $service->processCategoriesFromResponse(['Finanzas', 'Ahorro']);
    }

    public function test_no_marker_is_written_when_there_is_no_default_term_to_repurpose(): void
    {
        $this->mockCommonTermFunctions();

        WP_Mock::userFunction('get_term_by')
            ->with('name', 'Uncategorized', 'category')
            ->andReturn(false);

        WP_Mock::userFunction('wp_insert_term')->andReturn(['term_id' => 2]);

        WP_Mock::userFunction('update_option')->never();

        $service = new ContaiCategoryService();
        $service->processCategoriesFromResponse(['Finanzas']);
    }

    public function test_no_marker_is_written_when_the_rename_fails(): void
    {
        $this->mockCommonTermFunctions();

        WP_Mock::userFunction('get_term_by')
            ->with('name', 'Uncategorized', 'category')
            ->andReturn((object) ['term_id' => 1, 'name' => 'Uncategorized']);

        // wp_update_term returning a WP_Error means the term was NOT repurposed,
        // so default_category is still a placeholder and must stay excluded.
        WP_Mock::userFunction('wp_update_term')->andReturn('WP_ERROR_SENTINEL');
        WP_Mock::userFunction('wp_insert_term')->andReturn(['term_id' => 2]);

        WP_Mock::userFunction('update_option')->never();

        $service = new ContaiCategoryService();
        $service->processCategoriesFromResponse(['Finanzas']);
    }

    private function mockCommonTermFunctions(): void
    {
        WP_Mock::userFunction('get_terms')->andReturn([]);

        // createCategory()/addToCacheByName() look terms up by slug afterwards;
        // nothing exists yet in these scenarios.
        WP_Mock::userFunction('get_term_by')
            ->with('slug', \Mockery::any(), 'category')
            ->andReturn(false);

        WP_Mock::userFunction('sanitize_title')->andReturnUsing(function ($value) {
            return strtolower(str_replace(' ', '-', $value));
        });

        WP_Mock::userFunction('is_wp_error')->andReturnUsing(function ($value) {
            return 'WP_ERROR_SENTINEL' === $value;
        });
    }
}
