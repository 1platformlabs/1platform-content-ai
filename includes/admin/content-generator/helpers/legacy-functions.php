<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/legal-pages-helper.php';

function contai_get_legal_info(): array {
    return ContaiLegalPagesHelper::get_legal_info();
}

function contai_generate_cookies_banner(): void {
    // Was save_cookie_settings(array()). That method reads its input as a
    // submitted checkbox form, where an absent field means "unchecked", so the
    // wizard step named "Cookie banner configured" wrote
    // contai_cookie_notice_enabled = '0' and TURNED THE BANNER OFF — the
    // renderer treats an absent option as enabled, so the wizard was the only
    // thing on a fresh site that could disable it, and it reported success
    // either way. It also reset contai_consent_mode to 'opt_out' on every run
    // (#48).
    ContaiLegalPagesHelper::apply_cookie_banner_defaults();
}
