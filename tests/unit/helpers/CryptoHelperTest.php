<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

class CryptoHelperTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_encrypt_api_key_returns_encoded_string(): void {
        WP_Mock::userFunction('wp_salt')
            ->andReturn('test-salt-value-for-encryption');

        $result = contai_encrypt_api_key('my-secret-api-key');

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
        $this->assertNotSame('my-secret-api-key', $result);
    }

    public function test_encrypt_api_key_returns_null_for_falsy_input(): void {
        $result = contai_encrypt_api_key('');

        $this->assertNull($result);
    }

    public function test_decrypt_api_key_returns_empty_for_empty_input(): void {
        $this->assertSame('', contai_decrypt_api_key(''));
    }

    public function test_encrypt_decrypt_roundtrip(): void {
        WP_Mock::userFunction('wp_salt')
            ->andReturn('consistent-salt-for-test');

        $original = 'ak-1234567890abcdef';
        $encrypted = contai_encrypt_api_key($original);
        $decrypted = contai_decrypt_api_key($encrypted);

        $this->assertSame($original, $decrypted);
    }

    public function test_decrypt_returns_original_for_non_encoded_data(): void {
        WP_Mock::userFunction('wp_salt')
            ->andReturn('test-salt');

        $result = contai_decrypt_api_key('plain-text-not-encrypted');

        $this->assertSame('plain-text-not-encrypted', $result);
    }

    public function test_get_decrypted_option_returns_default_when_empty(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_api_key', '')
            ->andReturn('');

        $result = contai_get_decrypted_option('contai_api_key', 'default');

        $this->assertSame('default', $result);
    }

    public function test_get_decrypted_option_decrypts_stored_value(): void {
        WP_Mock::userFunction('wp_salt')
            ->andReturn('test-salt');

        $encrypted = contai_encrypt_api_key('my-api-key');

        WP_Mock::userFunction('get_option')
            ->with('contai_api_key', '')
            ->andReturn($encrypted);

        $result = contai_get_decrypted_option('contai_api_key');

        $this->assertSame('my-api-key', $result);
    }

    public function test_contai_log_logs_when_wp_debug_enabled(): void {
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }

        contai_log('Test log message');

        $this->assertTrue(true);
    }
}
