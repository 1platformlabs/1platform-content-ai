<?php

if (!defined('ABSPATH')) {
    exit;
}

function contai_encrypt_api_key($key) {
    if ($key) {
        $encryption_key = openssl_digest(wp_salt(), 'SHA256', true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($key, 'aes-256-cbc', $encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
}

function contai_decrypt_api_key($encrypted_key) {
    if (empty($encrypted_key)) {
        return '';
    }

    try {
        $encryption_key = openssl_digest(wp_salt(), 'SHA256', true);
        $data = base64_decode($encrypted_key);

        if ($data === false || strpos($data, '::') === false) {
            // Not in encrypted format — return original value (legacy unencrypted key)
            return $encrypted_key;
        }

        $parts = explode('::', $data, 2);

        if (count($parts) !== 2) {
            contai_log('Content AI Decrypt: invalid encrypted format (unexpected parts)');
            return '';
        }

        list($encrypted, $iv) = $parts;

        if (empty($iv)) {
            contai_log('Content AI Decrypt: invalid encrypted format (empty IV)');
            return '';
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $encryption_key, 0, $iv);

        if ($decrypted === false) {
            contai_log('Content AI Decrypt: openssl_decrypt failed — possible salt change or corrupted data');
            return '';
        }

        return $decrypted;
    } catch (Exception $e) {
        contai_log('Content AI Decrypt error: ' . $e->getMessage());
        return '';
    }
}

function contai_get_decrypted_option($option_name, $default = '') {
    $encrypted_value = get_option($option_name, '');

    if (empty($encrypted_value)) {
        return $default;
    }

    return contai_decrypt_api_key($encrypted_value);
}

/**
 * Log a message only when WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function contai_log( string $message ): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
