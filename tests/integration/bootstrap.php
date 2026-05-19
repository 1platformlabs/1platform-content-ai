<?php
/**
 * Integration test bootstrap (real WordPress + MySQL).
 *
 * This bootstrap is OPT-IN — the default `phpunit.xml.dist` uses
 * `tests/bootstrap.php` (WP_Mock) for both suites, so the integration
 * tests run as multi-class lifecycle tests against mocks out of the box.
 *
 * To run against a real WordPress test install:
 *
 *   1. Install wp-phpunit (already in composer require-dev) and download
 *      a WordPress test core, e.g. via tools/install-wp-tests.sh.
 *   2. Export the WP_TESTS_DIR, WP_TESTS_DB_* env vars (see ./README.md).
 *   3. Run:
 *        vendor/bin/phpunit \
 *          --bootstrap=tests/integration/bootstrap.php \
 *          --testsuite=integration
 *
 * When WP_TESTS_DIR is unset or wp-phpunit cannot be located, this script
 * falls back to the WP_Mock bootstrap so the suite is still runnable.
 */

$wpTestsDir = getenv('WP_TESTS_DIR');

if (empty($wpTestsDir)) {
    $vendored = dirname(__DIR__, 2) . '/vendor/wp-phpunit/wp-phpunit';
    if (is_dir($vendored)) {
        $wpTestsDir = $vendored;
    }
}

if (empty($wpTestsDir) || !file_exists($wpTestsDir . '/includes/functions.php')) {
    fwrite(
        STDERR,
        "[integration] WP_TESTS_DIR not configured. Falling back to WP_Mock bootstrap.\n"
    );

    if (!defined('CONTAI_INTEGRATION_FALLBACK')) {
        define('CONTAI_INTEGRATION_FALLBACK', true);
    }

    require_once __DIR__ . '/../bootstrap.php';
    return;
}

require_once $wpTestsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/1platform-content-ai.php';
});

require $wpTestsDir . '/includes/bootstrap.php';
