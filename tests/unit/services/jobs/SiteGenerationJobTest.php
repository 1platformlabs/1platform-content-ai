<?php

namespace ContAI\Tests\Unit\Services\Jobs;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiSiteGenerationJob;
use ContaiJobRepository;
use ContaiDatabase;

class SiteGenerationJobTest extends TestCase
{
    private ContaiSiteGenerationJob $job;
    private $jobRepository;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // Reset ContaiDatabase singleton so constructor can access $wpdb
        $dbRef = new \ReflectionClass(ContaiDatabase::class);
        $instanceProp = $dbRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, null);

        // Set up global $wpdb mock to satisfy ContaiDatabase constructor
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $this->job = new ContaiSiteGenerationJob();

        // Inject mock repository via reflection
        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
        $ref = new \ReflectionClass($this->job);
        $prop = $ref->getProperty('jobRepository');
        $prop->setAccessible(true);
        $prop->setValue($this->job, $this->jobRepository);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── isReExecution direct tests ────────────────────────────────

    public function test_isReExecution_returns_true_when_option_is_set(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(true);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('isReExecution');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->job));
    }

    public function test_isReExecution_returns_false_when_option_is_not_set(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(false);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('isReExecution');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->job));
    }

    // ── cleanPreviousDataIfReExecution tests ──────────────────────

    public function test_first_execution_does_not_clean_data(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(false);

        // get_categories should NOT be called since we return early
        WP_Mock::userFunction('get_categories')
            ->never();

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('cleanPreviousDataIfReExecution');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }

    public function test_re_execution_deletes_empty_categories(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(true);

        WP_Mock::userFunction('get_option')
            ->with('default_category')
            ->andReturn(1);

        $emptyCategory = (object) [
            'term_id' => 10,
            'count' => 0,
            'name' => 'Empty Cat',
        ];

        WP_Mock::userFunction('get_categories')
            ->once()
            ->andReturn([$emptyCategory]);

        WP_Mock::userFunction('wp_delete_term')
            ->once()
            ->with(10, 'category');

        // Batch options cleanup
        global $wpdb;
        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(0);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('cleanPreviousDataIfReExecution');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }

    public function test_re_execution_deletes_category_when_all_posts_ai_generated(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(true);

        WP_Mock::userFunction('get_option')
            ->with('default_category')
            ->andReturn(1);

        $aiCategory = (object) [
            'term_id' => 20,
            'count' => 3,
            'name' => 'AI Category',
        ];

        WP_Mock::userFunction('get_categories')
            ->once()
            ->andReturn([$aiCategory]);

        // All 3 posts are AI-generated
        WP_Mock::userFunction('get_posts')
            ->once()
            ->andReturn([1, 2, 3]);

        WP_Mock::userFunction('wp_delete_term')
            ->once()
            ->with(20, 'category');

        global $wpdb;
        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(0);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('cleanPreviousDataIfReExecution');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }

    public function test_re_execution_keeps_category_with_mixed_posts(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(true);

        WP_Mock::userFunction('get_option')
            ->with('default_category')
            ->andReturn(1);

        $mixedCategory = (object) [
            'term_id' => 30,
            'count' => 5,
            'name' => 'Mixed Category',
        ];

        WP_Mock::userFunction('get_categories')
            ->once()
            ->andReturn([$mixedCategory]);

        // Only 2 of 5 posts are AI-generated
        WP_Mock::userFunction('get_posts')
            ->once()
            ->andReturn([1, 2]);

        // Category should NOT be deleted
        WP_Mock::userFunction('wp_delete_term')
            ->never();

        global $wpdb;
        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(0);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('cleanPreviousDataIfReExecution');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }

    public function test_re_execution_cleans_batch_options_via_wpdb(): void
    {
        WP_Mock::userFunction('get_option')
            ->with('contai_site_generation_completed', false)
            ->andReturn(true);

        WP_Mock::userFunction('get_option')
            ->with('default_category')
            ->andReturn(1);

        WP_Mock::userFunction('get_categories')
            ->once()
            ->andReturn([]);

        // Verify batch options are cleaned via wpdb DELETE query
        global $wpdb;
        $wpdb->shouldReceive('query')
            ->once()
            ->with(Mockery::on(function ($query) {
                return strpos($query, 'DELETE') !== false
                    && strpos($query, 'contai') !== false
                    && strpos($query, 'batch') !== false;
            }))
            ->andReturn(3);

        $ref = new \ReflectionClass($this->job);
        $method = $ref->getMethod('cleanPreviousDataIfReExecution');
        $method->setAccessible(true);
        $method->invoke($this->job);
    }
}
