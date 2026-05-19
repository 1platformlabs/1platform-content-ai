<?php

namespace ContAI\Tests\Unit\Cron;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * Cron registration tests for the Action Scheduler dual-runner migration.
 *
 * Action Scheduler is the primary runner; WP-Cron stays as fallback for
 * sites where Action Scheduler is unavailable or has been disabled.
 *
 * Note on test isolation: `as_*` global functions cannot be undeclared once
 * defined. The "fallback when AS missing" cases run first; once any other
 * test declares the AS stubs via `WP_Mock::userFunction()` they persist for
 * the rest of the process.
 */
class JobProcessorCronTest extends TestCase
{
    /**
     * Tracks whether AS stubs have been declared in this PHP process.
     */
    private static bool $asStubsDeclared = false;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── AS-missing fallback path ─────────────────────────────────────────

    public function test_register_falls_back_to_wp_cron_only_when_action_scheduler_missing(): void
    {
        if (self::$asStubsDeclared) {
            $this->markTestSkipped('Action Scheduler stubs already declared earlier in this process.');
        }

        WP_Mock::userFunction('wp_next_scheduled')
            ->with('contai_process_job_queue')
            ->andReturn(false);

        WP_Mock::userFunction('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'contai_every_minute', 'contai_process_job_queue');

        contai_register_job_processor_cron();

        $this->assertTrue(true);
    }

    public function test_unregister_clears_wp_cron_when_action_scheduler_missing(): void
    {
        if (self::$asStubsDeclared) {
            $this->markTestSkipped('Action Scheduler stubs already declared earlier in this process.');
        }

        WP_Mock::userFunction('wp_clear_scheduled_hook')
            ->once()
            ->with('contai_process_job_queue');

        contai_unregister_job_processor_cron();

        $this->assertTrue(true);
    }

    // ── AS-available path ────────────────────────────────────────────────

    public function test_register_registers_both_action_scheduler_and_wp_cron(): void
    {
        $this->markActionSchedulerAvailable();

        WP_Mock::userFunction('as_next_scheduled_action')
            ->with('contai_process_job_queue')
            ->andReturn(false);

        WP_Mock::userFunction('as_schedule_recurring_action')
            ->once()
            ->with(
                \Mockery::type('int'),
                60,
                'contai_process_job_queue',
                [],
                'contai'
            );

        WP_Mock::userFunction('wp_next_scheduled')
            ->with('contai_process_job_queue')
            ->andReturn(false);

        WP_Mock::userFunction('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'contai_every_minute', 'contai_process_job_queue');

        contai_register_job_processor_cron();

        $this->assertTrue(true);
    }

    public function test_register_skips_both_when_already_scheduled(): void
    {
        $this->markActionSchedulerAvailable();

        WP_Mock::userFunction('as_next_scheduled_action')
            ->with('contai_process_job_queue')
            ->andReturn(1234567890);

        WP_Mock::userFunction('as_schedule_recurring_action')->never();

        WP_Mock::userFunction('wp_next_scheduled')
            ->with('contai_process_job_queue')
            ->andReturn(1234567890);

        WP_Mock::userFunction('wp_schedule_event')->never();

        contai_register_job_processor_cron();

        $this->assertTrue(true);
    }

    public function test_unregister_unschedules_both_action_scheduler_and_wp_cron(): void
    {
        $this->markActionSchedulerAvailable();

        WP_Mock::userFunction('as_unschedule_all_actions')
            ->once()
            ->with('contai_process_job_queue');

        WP_Mock::userFunction('wp_clear_scheduled_hook')
            ->once()
            ->with('contai_process_job_queue');

        contai_unregister_job_processor_cron();

        $this->assertTrue(true);
    }

    /**
     * Once any test calls `WP_Mock::userFunction('as_*')`, WP_Mock declares
     * the function globally and it stays for the rest of the process. Track
     * that state so the "missing AS" tests above know to bail out instead of
     * mis-asserting an unreachable branch.
     */
    private function markActionSchedulerAvailable(): void
    {
        self::$asStubsDeclared = true;
    }
}
