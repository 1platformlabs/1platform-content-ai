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
            return $encrypted_key;
        }

        $parts = explode('::', $data, 2);

        if (count($parts) !== 2) {
            return $encrypted_key;
        }

        list($encrypted, $iv) = $parts;

        if (empty($iv)) {
            return $encrypted_key;
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $encryption_key, 0, $iv);

        return $decrypted !== false ? $decrypted : $encrypted_key;
    } catch (Exception $e) {
        contai_log('Decryption error: ' . $e->getMessage());
        return $encrypted_key;
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
