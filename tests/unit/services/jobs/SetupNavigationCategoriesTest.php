<?php

namespace ContAI\Tests\Unit\Services\Jobs;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSiteGenerationJob;
use ContaiJobRepository;
use ContaiDatabase;
use ContaiConfig;
use ContaiCategoryService;

/**
 * setupNavigation() must not drop the category the wizard built out of the
 * default term (#48).
 *
 * These are behavioural: they drive setupNavigation() end to end through the
 * real ContaiMainMenuManager and assert on the nav menu items that come out,
 * so a regression in either the predicate or its wiring fails here.
 */
class SetupNavigationCategoriesTest extends TestCase
{
    private ContaiSiteGenerationJob $job;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $dbRef = new \ReflectionClass(ContaiDatabase::class);
        $instanceProp = $dbRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $this->job = new ContaiSiteGenerationJob();

        $repository = Mockery::mock(ContaiJobRepository::class);
        $ref = new \ReflectionClass($this->job);
        $prop = $ref->getProperty('jobRepository');
        $prop->setAccessible(true);
        $prop->setValue($this->job, $repository);

        // ContaiMainMenuManager is constructed inside setupNavigation() with no
        // arguments, so it resolves ContaiConfig::getInstance(). Swap the
        // singleton for a mock (same reflection trick the suite uses for
        // ContaiDatabase) instead of letting it read real options.
        $config = Mockery::mock(ContaiConfig::class);
        $config->shouldReceive('getMaxMenuCategories')->andReturn(10)->byDefault();
        $configRef = new \ReflectionClass(ContaiConfig::class);
        $configInstance = $configRef->getProperty('instance');
        $configInstance->setAccessible(true);
        $configInstance->setValue(null, $config);
    }

    public function tearDown(): void
    {
        $configRef = new \ReflectionClass(ContaiConfig::class);
        $configInstance = $configRef->getProperty('instance');
        $configInstance->setAccessible(true);
        $configInstance->setValue(null, null);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── The regression ───────────────────────────────────────────────

    public function test_category_built_from_the_default_term_reaches_the_menu(): void
    {
        // Term 1 is default_category, renamed by the wizard into "Finanzas".
        $repurposed = (object) ['term_id' => 1, 'count' => 5, 'name' => 'Finanzas'];
        $regular    = (object) ['term_id' => 3, 'count' => 2, 'name' => 'Ahorro'];

        $this->mockNavMenuEnvironment([$repurposed, $regular], 1, 1);

        $addedCategoryIds = [];
        $this->captureCategoryMenuItems($addedCategoryIds);

        $this->invokeSetupNavigation();

        $this->assertContains(
            1,
            $addedCategoryIds,
            'The category the wizard built out of the default term must be added to the menu (#48): excluding it by default_category id silently dropped a real category from every generated site'
        );
        $this->assertContains(3, $addedCategoryIds, 'Regular categories must still be added');
    }

    public function test_untouched_empty_default_category_stays_out_of_the_menu(): void
    {
        // No repurpose marker and no posts: this is a real placeholder.
        $placeholder = (object) ['term_id' => 1, 'count' => 0, 'name' => 'Uncategorized'];
        $regular     = (object) ['term_id' => 3, 'count' => 2, 'name' => 'Ahorro'];

        $this->mockNavMenuEnvironment([$placeholder, $regular], 1, 0);

        $addedCategoryIds = [];
        $this->captureCategoryMenuItems($addedCategoryIds);

        $this->invokeSetupNavigation();

        $this->assertNotContains(
            1,
            $addedCategoryIds,
            'An untouched, empty placeholder must not be added to the menu'
        );
        $this->assertContains(3, $addedCategoryIds, 'Regular categories must still be added');
    }

    // ── Harness ──────────────────────────────────────────────────────

    /**
     * @param object[] $categories
     */
    private function mockNavMenuEnvironment(array $categories, int $defaultCategoryId, int $repurposedId): void
    {
        // Honour the 'exclude' argument the way WordPress does. A mock that
        // ignores it makes these tests blind to the very bug under test: the
        // old blanket 'exclude' => [get_option('default_category')] would be a
        // no-op here and the mutant restoring it would survive.
        WP_Mock::userFunction('get_categories')->andReturnUsing(function ($args = []) use ($categories) {
            $excluded = array_map('intval', (array) ($args['exclude'] ?? []));

            return array_values(array_filter($categories, function ($category) use ($excluded) {
                return !in_array((int) $category->term_id, $excluded, true);
            }));
        });

        WP_Mock::userFunction('get_option')
            ->with('default_category')
            ->andReturn($defaultCategoryId);

        WP_Mock::userFunction('get_option')
            ->with(ContaiCategoryService::OPTION_REPURPOSED_DEFAULT, 0)
            ->andReturn($repurposedId);

        WP_Mock::userFunction('get_option')
            ->with('contai_site_language', 'spanish')
            ->andReturn('spanish');

        // site-generation.php IS loaded here, so the manager takes the static
        // theme-map branch of assignMenuToPrimaryLocation() rather than the
        // runtime fallback.
        WP_Mock::userFunction('get_option')
            ->with('contai_wordpress_theme', 'astra')
            ->andReturn('astra');

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnUsing(function ($value) {
                return $value;
            });

        WP_Mock::userFunction('sanitize_title')
            ->andReturnUsing(function ($value) {
                return strtolower(str_replace(' ', '-', $value));
            });

        // Menu resolution + primary location binding.
        WP_Mock::userFunction('wp_get_nav_menu_object')->andReturn((object) ['term_id' => 7]);
        WP_Mock::userFunction('get_nav_menu_locations')->andReturn([]);
        WP_Mock::userFunction('get_registered_nav_menus')->andReturn(['primary' => 'Primary Menu']);
        WP_Mock::userFunction('set_theme_mod')->andReturn(true);

        // Empty menu to start with.
        WP_Mock::userFunction('wp_get_nav_menu_items')->andReturn(false);
        WP_Mock::userFunction('home_url')->andReturn('https://example.test/');
        WP_Mock::userFunction('trailingslashit')->andReturnUsing(function ($value) {
            return rtrim($value, '/') . '/';
        });

        // Category term resolution, keyed by the slug the manager derives.
        WP_Mock::userFunction('get_term_by')->andReturnUsing(function ($field, $value) use ($categories) {
            foreach ($categories as $category) {
                $slug = strtolower(str_replace(' ', '-', $category->name));

                if (('slug' === $field && $value === $slug) || ('name' === $field && $value === $category->name)) {
                    return (object) [
                        'term_id' => $category->term_id,
                        'name'    => $category->name,
                        'slug'    => $slug,
                    ];
                }
            }

            return false;
        });

        WP_Mock::userFunction('is_wp_error')->andReturn(false);
    }

    /**
     * Collects the term ids of every taxonomy menu item the run creates.
     *
     * Takes the sink by reference: returning the array would hand the caller a
     * COPY, leaving it permanently empty and turning every assertion below into
     * a false negative indistinguishable from the bug under test.
     *
     * @param array<int, int> $added Filled in as the run proceeds.
     */
    private function captureCategoryMenuItems(array &$added): void
    {
        WP_Mock::userFunction('wp_update_nav_menu_item')
            ->andReturnUsing(function ($menu_id, $item_id, $data) use (&$added) {
                if (($data['menu-item-type'] ?? '') === 'taxonomy') {
                    $added[] = (int) $data['menu-item-object-id'];
                }

                return 99;
            });
    }

    private function invokeSetupNavigation(): void
    {
        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('setupNavigation');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }
}
