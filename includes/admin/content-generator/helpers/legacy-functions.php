<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/legal-pages-helper.php';

function contai_get_legal_info(): array {
    return ContaiLegalPagesHelper::get_legal_info();
}

function contai_generate_cookies_banner(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Legacy function; nonce verified by caller.
    ContaiLegalPagesHelper::save_cookie_settings($_POST);
}
