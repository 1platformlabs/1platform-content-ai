<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin version, read from the `Version:` header of the main plugin file.
 *
 * CONTAI_VERSION used to be a second, hand-typed literal next to that header,
 * and the two drifted: the header said 3.2.1 while CONTAI_VERSION stayed at
 * 2.40.0 (issue #209). That constant gates contai_maybe_upgrade(), so every
 * update after 2.40.0 skipped its migrations, cron re-registration and
 * transient flush, and the version reported to the platform was wrong. The
 * release pipeline stamps the header into the package it ships; reading it
 * here makes the header the only place a version can live.
 *
 * Uses get_file_data(), the reader WordPress itself applies to plugin headers
 * (first 8 KB, same grammar), which is loaded before any plugin.
 *
 * @param string $plugin_file Absolute path to the main plugin file.
 * @return string The X.Y.Z version, or '0.0.0' when the header is unreadable —
 *                never a value contai_maybe_upgrade() could keep retrying on.
 */
function contai_plugin_header_version(string $plugin_file): string {
    if (!function_exists('get_file_data')) {
        return '0.0.0';
    }

    $headers = get_file_data($plugin_file, ['Version' => 'Version']);
    $version = isset($headers['Version']) ? trim((string) $headers['Version']) : '';

    return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : '0.0.0';
}
