<?php

namespace ContAI\Tests\Unit\Services\Jobs;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiQueueManager;
use ContaiJobRepository;
use ContaiKeywordRepository;
use ContaiKeyword;
use ContaiJob;
use ContaiDatabase;

class QueueManagerTest extends TestCase
{
    private $jobRepository;
    private $keywordRepository;
    private ContaiQueueManager $queueManager;

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

        $this->jobRepository = Mockery::mock(ContaiJobRepository::class);
        $this->keywordRepository = Mockery::mock(ContaiKeywordRepository::class);

        $this->queueManager = new ContaiQueueManager();

        // Inject mocked repositories via reflection
        $ref = new \ReflectionClass($this->queueManager);

        $jobRepoProp = $ref->getProperty('jobRepository');
        $jobRepoProp->setAccessible(true);
        $jobRepoProp->setValue($this->queueManager, $this->jobRepository);

        $kwRepoProp = $ref->getProperty('keywordRepository');
        $kwRepoProp->setAccessible(true);
        $kwRepoProp->setValue($this->queueManager, $this->keywordRepository);
    }

    public function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── Keyword dedup via enqueuePostGeneration ───────────────────

    public function test_enqueue_skips_keyword_with_existing_post_and_marks_done(): void
    {
        $keyword1 = Mockery::mock(ContaiKeyword::class);
        $keyword1->shouldReceive('getId')->andReturn(1);
        $keyword1->shouldReceive('getKeyword')->andReturn('existing topic');
        $keyword1->shouldReceive('getVolume')->andReturn(500);

        $keyword2 = Mockery::mock(ContaiKeyword::class);
        $keyword2->shouldReceive('getId')->andReturn(2);
        $keyword2->shouldReceive('getKeyword')->andReturn('new topic');
        $keyword2->shouldReceive('getVolume')->andReturn(300);

        $this->jobRepository
            ->shouldReceive('getActiveJobKeywordIds')
            ->andReturn([]);

        // First call returns both; second call returns empty (all consumed)
        $this->keywordRepository
            ->shouldReceive('findByStatus')
            ->with('pending', 100)
            ->andReturn([$keyword1, $keyword2], []);

        // keyword1 has an existing post
        WP_Mock::userFunction('get_posts')
            ->andReturnUsing(function ($args) {
                if ($args['meta_value'] === 'existing topic') {
                    return [(object) ['ID' => 10]];
                }
                return [];
            });

        // keyword1 should be marked as done (dedup)
        $this->keywordRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(1, 'done');

        // ContaiJob::create needs current_time
        WP_Mock::userFunction('current_time')
            ->andReturn('2026-04-07 12:00:00');

        // keyword2 has no existing post, so it gets enqueued
        $this->jobRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(true);

        $this->keywordRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(2, 'active');

        // contai_trigger_immediate_job_processing calls these WP functions
        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(true);
        WP_Mock::userFunction('spawn_cron');

        $result = $this->queueManager->enqueuePostGeneration(2);

        $this->assertEquals(1, $result);
    }

    public function test_enqueue_returns_zero_when_all_keywords_have_existing_posts(): void
    {
        $keyword1 = Mockery::mock(ContaiKeyword::class);
        $keyword1->shouldReceive('getId')->andReturn(1);
        $keyword1->shouldReceive('getKeyword')->andReturn('topic a');
        $keyword1->shouldReceive('getVolume')->andReturn(100);

        $keyword2 = Mockery::mock(ContaiKeyword::class);
        $keyword2->shouldReceive('getId')->andReturn(2);
        $keyword2->shouldReceive('getKeyword')->andReturn('topic b');
        $keyword2->shouldReceive('getVolume')->andReturn(200);

        $this->jobRepository
            ->shouldReceive('getActiveJobKeywordIds')
            ->andReturn([]);

        $this->keywordRepository
            ->shouldReceive('findByStatus')
            ->with('pending', 100)
            ->andReturn([$keyword1, $keyword2]);

        // Both keywords have existing posts
        WP_Mock::userFunction('get_posts')
            ->andReturn([(object) ['ID' => 99]]);

        // Both should be marked done
        $this->keywordRepository
            ->shouldReceive('updateStatus')
            ->with(Mockery::anyOf(1, 2), 'done')
            ->twice();

        $result = $this->queueManager->enqueuePostGeneration(2);

        $this->assertEquals(0, $result);
    }

    public function test_enqueue_returns_zero_when_no_pending_keywords(): void
    {
        $this->jobRepository
            ->shouldReceive('getActiveJobKeywordIds')
            ->andReturn([]);

        $this->keywordRepository
            ->shouldReceive('findByStatus')
            ->with('pending', 100)
            ->andReturn([]);

        $result = $this->queueManager->enqueuePostGeneration(1);

        $this->assertEquals(0, $result);
    }

    public function test_enqueue_skips_keywords_with_active_jobs(): void
    {
        $keyword1 = Mockery::mock(ContaiKeyword::class);
        $keyword1->shouldReceive('getId')->andReturn(1);

        $keyword2 = Mockery::mock(ContaiKeyword::class);
        $keyword2->shouldReceive('getId')->andReturn(2);
        $keyword2->shouldReceive('getKeyword')->andReturn('available topic');
        $keyword2->shouldReceive('getVolume')->andReturn(100);

        $this->jobRepository
            ->shouldReceive('getActiveJobKeywordIds')
            ->andReturn([1]); // keyword1 already has an active job

        // First call returns both; second call returns empty (all consumed)
        $this->keywordRepository
            ->shouldReceive('findByStatus')
            ->with('pending', 100)
            ->andReturn([$keyword1, $keyword2], []);

        // keyword2 has no existing post
        WP_Mock::userFunction('get_posts')
            ->andReturn([]);

        WP_Mock::userFunction('current_time')
            ->andReturn('2026-04-07 12:00:00');

        $this->jobRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(true);

        $this->keywordRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(2, 'active');

        WP_Mock::userFunction('wp_next_scheduled')
            ->andReturn(true);
        WP_Mock::userFunction('spawn_cron');

        $result = $this->queueManager->enqueuePostGeneration(2);

        $this->assertEquals(1, $result);
    }

    public function test_enqueue_throws_on_invalid_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queueManager->enqueuePostGeneration(0);
    }
}
