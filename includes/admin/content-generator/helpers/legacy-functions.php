<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/legal-pages-helper.php';

function contai_get_legal_info(): array {
    return ContaiLegalPagesHelper::get_legal_info();
}

function contai_generate_cookies_banner(): void {
    // Pass empty array in cron/async context; save_cookie_settings uses defaults when fields are absent.
    ContaiLegalPagesHelper::save_cookie_settings( array() );
}
