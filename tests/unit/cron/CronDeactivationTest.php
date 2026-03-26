<?php

namespace ContAI\Tests\Unit\Cron;

use WP_Mock;
use PHPUnit\Framework\TestCase;

class CronDeactivationTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── Job Processor Cron ───────────────────────────────────────

    public function test_unregister_job_processor_clears_scheduled_hook(): void {
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
            ->once()
            ->with( 'contai_process_job_queue' );

        contai_unregister_job_processor_cron();

        $this->assertTrue( true );
    }

    // ── Agent Actions Cron ───────────────────────────────────────

    public function test_unregister_agent_actions_clears_scheduled_hook(): void {
        WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
            ->once()
            ->with( 'contai_agent_actions_poll' );

        contai_unregister_agent_actions_cron();

        $this->assertTrue( true );
    }

    // ── Registration ─────────────────────────────────────────────

    public function test_register_job_processor_schedules_when_not_existing(): void {
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'contai_process_job_queue' )
            ->andReturn( false );

        WP_Mock::userFunction( 'wp_schedule_event' )
            ->once()
            ->with( \Mockery::type( 'int' ), 'contai_every_minute', 'contai_process_job_queue' );

        contai_register_job_processor_cron();

        $this->assertTrue( true );
    }

    public function test_register_job_processor_skips_when_already_scheduled(): void {
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'contai_process_job_queue' )
            ->andReturn( 1234567890 );

        WP_Mock::userFunction( 'wp_schedule_event' )->never();

        contai_register_job_processor_cron();

        $this->assertTrue( true );
    }

    public function test_register_agent_actions_schedules_when_not_existing(): void {
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'contai_agent_actions_poll' )
            ->andReturn( false );

        WP_Mock::userFunction( 'wp_schedule_event' )
            ->once()
            ->with( \Mockery::type( 'int' ), 'contai_every_minute', 'contai_agent_actions_poll' );

        contai_register_agent_actions_cron();

        $this->assertTrue( true );
    }

    public function test_register_agent_actions_skips_when_already_scheduled(): void {
        WP_Mock::userFunction( 'wp_next_scheduled' )
            ->with( 'contai_agent_actions_poll' )
            ->andReturn( 1234567890 );

        WP_Mock::userFunction( 'wp_schedule_event' )->never();

        contai_register_agent_actions_cron();

        $this->assertTrue( true );
    }
}
