<?php

namespace ContAI\Tests\Unit\Services\Agents;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use Mockery;

class PublishContentActionHandlerTest extends TestCase {

    private $post_creator;
    private $handler;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->post_creator = Mockery::mock('ContaiWordPressPostCreator');
        $this->handler = new \ContaiPublishContentActionHandler( $this->post_creator );
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── canHandle ────────────────────────────────────────────────

    public function test_can_handle_returns_true_for_publish_content(): void {
        $this->assertTrue( $this->handler->canHandle( 'publish_content' ) );
    }

    public function test_can_handle_returns_false_for_other_types(): void {
        $this->assertFalse( $this->handler->canHandle( 'send_email' ) );
        $this->assertFalse( $this->handler->canHandle( '' ) );
    }

    // ── Required field validation ────────────────────────────────

    public function test_handle_fails_when_title_is_missing(): void {
        $action = array( 'payload' => array( 'body' => '<p>content</p>' ) );
        $result = $this->handler->handle( $action, array() );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'title', $result['error'] );
    }

    public function test_handle_fails_when_body_is_missing(): void {
        $action = array( 'payload' => array( 'title' => 'Test Post' ) );
        $result = $this->handler->handle( $action, array() );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'body', $result['error'] );
    }

    public function test_handle_fails_when_payload_is_empty(): void {
        $action = array( 'payload' => array() );
        $result = $this->handler->handle( $action, array() );

        $this->assertFalse( $result['success'] );
    }

    public function test_handle_fails_when_payload_key_is_missing(): void {
        $action = array();
        $result = $this->handler->handle( $action, array() );

        $this->assertFalse( $result['success'] );
    }

    // ── Duplicate check ──────────────────────────────────────────

    public function test_handle_fails_on_duplicate_title(): void {
        $existing = new \stdClass();
        $existing->ID = 42;

        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_posts' )->once()->andReturn( array( $existing ) );

        $action = array( 'payload' => array( 'title' => 'Existing Post', 'body' => '<p>test</p>' ) );
        $result = $this->handler->handle( $action, array() );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'already exists', $result['error'] );
    }

    // ── post_date timezone fix ───────────────────────────────────

    public function test_post_date_uses_get_date_from_gmt(): void {
        $utc_date   = '2026-06-15 14:00:00';
        $local_date = '2026-06-15 08:00:00'; // simulated UTC-6

        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
        WP_Mock::userFunction( 'get_date_from_gmt' )
            ->once()
            ->with( $utc_date )
            ->andReturn( $local_date );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

        $captured_post_data = null;
        WP_Mock::userFunction( 'wp_insert_post' )
            ->once()
            ->andReturnUsing( function( $data ) use ( &$captured_post_data ) {
                $captured_post_data = $data;
                return 100;
            } );

        WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
        $this->post_creator->shouldReceive( 'getPermalink' )->andReturn( 'https://example.com/test' );

        $action = array(
            'payload' => array(
                'title'        => 'Timezone Test',
                'body'         => '<p>content</p>',
                'publish_date' => '2026-06-15T14:00:00Z',
            ),
        );

        $result = $this->handler->handle( $action, array( 'publish_status' => 'publish' ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( $utc_date, $captured_post_data['post_date_gmt'] );
        $this->assertSame( $local_date, $captured_post_data['post_date'] );
    }

    public function test_post_date_omitted_when_no_publish_date(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

        $captured_post_data = null;
        WP_Mock::userFunction( 'wp_insert_post' )
            ->once()
            ->andReturnUsing( function( $data ) use ( &$captured_post_data ) {
                $captured_post_data = $data;
                return 101;
            } );

        $this->post_creator->shouldReceive( 'getPermalink' )->andReturn( 'https://example.com/test' );

        $action = array(
            'payload' => array(
                'title' => 'No Date Test',
                'body'  => '<p>content</p>',
            ),
        );

        $result = $this->handler->handle( $action, array( 'publish_status' => 'draft' ) );

        $this->assertTrue( $result['success'] );
        $this->assertArrayNotHasKey( 'post_date', $captured_post_data );
        $this->assertArrayNotHasKey( 'post_date_gmt', $captured_post_data );
    }

    // ── validateDate edge cases ──────────────────────────────────

    public function test_invalid_date_is_ignored(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

        $captured = null;
        WP_Mock::userFunction( 'wp_insert_post' )
            ->once()
            ->andReturnUsing( function( $data ) use ( &$captured ) {
                $captured = $data;
                return 102;
            } );

        $this->post_creator->shouldReceive( 'getPermalink' )->andReturn( 'https://example.com/test' );

        $action = array(
            'payload' => array(
                'title'        => 'Bad Date',
                'body'         => '<p>content</p>',
                'publish_date' => 'not-a-date',
            ),
        );

        $result = $this->handler->handle( $action, array( 'publish_status' => 'draft' ) );

        $this->assertTrue( $result['success'] );
        $this->assertArrayNotHasKey( 'post_date_gmt', $captured );
    }

    // ── Post status from settings ────────────────────────────────

    public function test_post_status_comes_from_settings(): void {
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
        WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );

        $captured = null;
        WP_Mock::userFunction( 'wp_insert_post' )
            ->once()
            ->andReturnUsing( function( $data ) use ( &$captured ) {
                $captured = $data;
                return 103;
            } );

        $this->post_creator->shouldReceive( 'getPermalink' )->andReturn( 'https://example.com/test' );

        $action = array(
            'payload' => array( 'title' => 'Draft Test', 'body' => '<p>draft</p>' ),
        );

        $result = $this->handler->handle( $action, array( 'publish_status' => 'draft' ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'draft', $captured['post_status'] );
        $this->assertSame( 'draft', $result['post_status'] );
    }
}
