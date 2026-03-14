<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

class SecurityHelperTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_verify_request_passes_with_valid_nonce_and_capability(): void {
        $_POST['_wpnonce'] = 'valid_nonce_value';

        WP_Mock::userFunction('sanitize_key')
            ->with('valid_nonce_value')
            ->andReturn('valid_nonce_value');

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid_nonce_value', 'test_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);

        contai_verify_request('test_action');

        $this->assertTrue(true);

        unset($_POST['_wpnonce']);
    }

    public function test_verify_request_dies_on_missing_nonce(): void {
        unset($_POST['_wpnonce']);

        WP_Mock::userFunction('esc_html__')->andReturnArg(0);

        WP_Mock::userFunction('wp_die')
            ->once()
            ->andReturnUsing(function () {
                throw new \RuntimeException('wp_die: nonce missing');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: nonce missing');

        contai_verify_request('test_action');
    }

    public function test_verify_request_dies_on_invalid_nonce(): void {
        $_POST['_wpnonce'] = 'bad_nonce';

        WP_Mock::userFunction('sanitize_key')
            ->with('bad_nonce')
            ->andReturn('bad_nonce');

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('bad_nonce', 'test_action')
            ->andReturn(false);

        WP_Mock::userFunction('esc_html__')->andReturnArg(0);

        WP_Mock::userFunction('wp_die')
            ->once()
            ->andReturnUsing(function () {
                throw new \RuntimeException('wp_die: bad nonce');
            });

        $this->expectException(\RuntimeException::class);

        try {
            contai_verify_request('test_action');
        } finally {
            unset($_POST['_wpnonce']);
        }
    }

    public function test_verify_request_dies_on_insufficient_capability(): void {
        $_POST['_wpnonce'] = 'valid_nonce';

        WP_Mock::userFunction('sanitize_key')
            ->with('valid_nonce')
            ->andReturn('valid_nonce');

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid_nonce', 'test_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(false);

        WP_Mock::userFunction('esc_html__')->andReturnArg(0);

        WP_Mock::userFunction('wp_die')
            ->once()
            ->andReturnUsing(function () {
                throw new \RuntimeException('wp_die: no permission');
            });

        $this->expectException(\RuntimeException::class);

        try {
            contai_verify_request('test_action');
        } finally {
            unset($_POST['_wpnonce']);
        }
    }

    public function test_verify_request_uses_custom_nonce_field(): void {
        $_POST['custom_nonce'] = 'nonce_value';

        WP_Mock::userFunction('sanitize_key')
            ->with('nonce_value')
            ->andReturn('nonce_value');

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('nonce_value', 'test_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('manage_options')
            ->andReturn(true);

        contai_verify_request('test_action', 'custom_nonce');

        $this->assertTrue(true);

        unset($_POST['custom_nonce']);
    }

    public function test_verify_request_uses_custom_capability(): void {
        $_POST['_wpnonce'] = 'valid_nonce';

        WP_Mock::userFunction('sanitize_key')
            ->with('valid_nonce')
            ->andReturn('valid_nonce');

        WP_Mock::userFunction('wp_verify_nonce')
            ->with('valid_nonce', 'test_action')
            ->andReturn(1);

        WP_Mock::userFunction('current_user_can')
            ->with('edit_posts')
            ->andReturn(true);

        contai_verify_request('test_action', '_wpnonce', 'edit_posts');

        $this->assertTrue(true);

        unset($_POST['_wpnonce']);
    }
}
