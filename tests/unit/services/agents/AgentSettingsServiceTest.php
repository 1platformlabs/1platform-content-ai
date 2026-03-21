<?php

namespace ContAI\Tests\Unit\Services\Agents;

use WP_Mock;
use PHPUnit\Framework\TestCase;

class AgentSettingsServiceTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── getPublishStatus ─────────────────────────────────────────

    public function test_get_publish_status_defaults_to_publish(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'contai_agents_publish_status', 'publish' )
            ->andReturn( 'publish' );

        $this->assertSame( 'publish', \ContaiAgentSettingsService::getPublishStatus() );
    }

    // ── setPublishStatus ─────────────────────────────────────────

    public function test_set_publish_status_rejects_invalid_values(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'update_option' )
            ->once()
            ->with( 'contai_agents_publish_status', 'draft' );

        \ContaiAgentSettingsService::setPublishStatus( 'invalid_status' );
        $this->assertSame( 1, 1 ); // WP_Mock verifies the expectation
    }

    public function test_set_publish_status_accepts_valid_values(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );

        foreach ( array( 'draft', 'publish', 'pending' ) as $status ) {
            WP_Mock::userFunction( 'update_option' )
                ->once()
                ->with( 'contai_agents_publish_status', $status );

            \ContaiAgentSettingsService::setPublishStatus( $status );
        }
        $this->assertSame( 3, 3 ); // WP_Mock verifies the expectations
    }

    // ── isAutoConsumeEnabled ─────────────────────────────────────

    public function test_auto_consume_defaults_to_true(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'contai_agents_auto_consume', true )
            ->andReturn( true );

        $this->assertTrue( \ContaiAgentSettingsService::isAutoConsumeEnabled() );
    }

    // ── getPollingInterval ───────────────────────────────────────

    public function test_polling_interval_defaults_to_60(): void {
        WP_Mock::userFunction( 'get_option' )
            ->with( 'contai_agents_polling_interval', 60 )
            ->andReturn( 60 );

        $this->assertSame( 60, \ContaiAgentSettingsService::getPollingInterval() );
    }

    // ── getAllSettings ────────────────────────────────────────────

    public function test_get_all_settings_returns_all_keys(): void {
        WP_Mock::userFunction( 'get_option' )->andReturnUsing( function( $key, $default ) {
            return $default;
        } );

        $settings = \ContaiAgentSettingsService::getAllSettings();

        $this->assertArrayHasKey( 'publish_status', $settings );
        $this->assertArrayHasKey( 'auto_consume', $settings );
        $this->assertArrayHasKey( 'polling_interval', $settings );
        $this->assertArrayHasKey( 'last_poll_time', $settings );
    }

    // ── updateSettings ───────────────────────────────────────────

    public function test_update_settings_ignores_unknown_keys(): void {
        // update_option should NOT be called for unknown keys
        WP_Mock::userFunction( 'update_option' )->never();
        WP_Mock::userFunction( 'sanitize_text_field' )->never();

        \ContaiAgentSettingsService::updateSettings( array( 'unknown_key' => 'value' ) );

        $this->assertTrue( true ); // No exception = pass
    }
}
