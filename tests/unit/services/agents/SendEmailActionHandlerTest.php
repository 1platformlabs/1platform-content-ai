<?php

namespace ContAI\Tests\Unit\Services\Agents;

use WP_Mock;
use PHPUnit\Framework\TestCase;

class SendEmailActionHandlerTest extends TestCase {

    private $handler;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->handler = new \ContaiSendEmailActionHandler();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // ── canHandle ────────────────────────────────────────────────

    public function test_can_handle_returns_true_for_send_email(): void {
        $this->assertTrue( $this->handler->canHandle( 'send_email' ) );
    }

    public function test_can_handle_returns_false_for_other_types(): void {
        $this->assertFalse( $this->handler->canHandle( 'publish_content' ) );
        $this->assertFalse( $this->handler->canHandle( '' ) );
    }

    // ── Validation ───────────────────────────────────────────────

    public function test_handle_fails_with_invalid_email(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturn( '' );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( false );

        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'invalid', 'subject' => 'Test', 'body' => 'Hi',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'email', $result['error'] );
    }

    public function test_handle_fails_with_missing_subject(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturn( 'test@example.com' );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturn( '' );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );

        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'test@example.com', 'subject' => '', 'body' => 'Hi',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'subject', $result['error'] );
    }

    public function test_handle_fails_with_missing_body(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturn( 'test@example.com' );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );

        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'test@example.com', 'subject' => 'Test', 'body' => '',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'body', $result['error'] );
    }

    // ── Rate limiting ────────────────────────────────────────────

    public function test_handle_fails_when_rate_limited(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturn( 'test@example.com' );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );
        WP_Mock::userFunction( 'get_transient' )
            ->with( \ContaiSendEmailActionHandler::RATE_LIMIT_OPTION )
            ->andReturn( \ContaiSendEmailActionHandler::RATE_LIMIT_MAX );
        WP_Mock::userFunction( 'contai_log' )->andReturn( null );

        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'test@example.com', 'subject' => 'Test', 'body' => '<p>Hi</p>',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'rate limit', $result['error'] );
    }

    public function test_handle_allows_when_under_rate_limit(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturn( 'test@example.com' );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_transient' )
            ->with( \ContaiSendEmailActionHandler::RATE_LIMIT_OPTION )
            ->andReturn( 5 );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( true );
        WP_Mock::userFunction( 'contai_log' )->andReturn( null );


        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'test@example.com', 'subject' => 'Test', 'body' => '<p>Hello</p>',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'test@example.com', $result['to'] );
    }

    // ── Successful send ──────────────────────────────────────────

    public function test_handle_sends_email_successfully(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( 0 );
        WP_Mock::userFunction( 'set_transient' )->andReturn( true );
        WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( true );
        WP_Mock::userFunction( 'contai_log' )->andReturn( null );


        $action = array( 'payload' => array( 'metadata' => array(
            'to'      => 'admin@example.com',
            'subject' => 'Agent Report',
            'body'    => '<p>Report content</p>',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'admin@example.com', $result['to'] );
        $this->assertSame( '[1Platform] Agent Report', $result['subject'] );
    }

    // ── wp_mail failure ──────────────────────────────────────────

    public function test_handle_returns_failure_when_wp_mail_fails(): void {
        WP_Mock::userFunction( 'sanitize_email' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'is_email' )->andReturn( true );
        WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( function( $v ) { return $v; } );
        WP_Mock::userFunction( 'get_transient' )->andReturn( 0 );
        WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( false );
        WP_Mock::userFunction( 'contai_log' )->andReturn( null );


        $action = array( 'payload' => array( 'metadata' => array(
            'to' => 'fail@example.com', 'subject' => 'Test', 'body' => '<p>Hi</p>',
        ) ) );

        $result = $this->handler->handle( $action, array() );
        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'wp_mail()', $result['error'] );
    }
}
