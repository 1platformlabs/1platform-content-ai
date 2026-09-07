<?php

namespace ContAI\Tests\Unit\Services\Config;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use ContaiConfig;

/**
 * MAH-08 — the APP key resolves from external config, with the bundled value as
 * a fallback so a fresh WordPress.org install works with no configuration.
 *
 * The precedence is tested through resolveAppKey()'s injectable reader, so no
 * real (permanent) PHP constant has to be defined — a constant defined in one
 * test would leak into every test after it.
 */
class ConfigAppKeyTest extends TestCase
{
    /** A reader backed by a plain map, standing in for constants/env vars. */
    private function reader(array $sources): callable
    {
        return static fn (string $name): ?string => $sources[$name] ?? null;
    }

    public function test_falls_back_to_the_embedded_value_when_nothing_external_is_set(): void
    {
        $key = ContaiConfig::resolveAppKey('production', 'embedded-key', $this->reader([]));

        $this->assertSame('embedded-key', $key);
    }

    public function test_uses_the_generic_external_source_over_the_embedded_value(): void
    {
        $key = ContaiConfig::resolveAppKey(
            'production',
            'embedded-key',
            $this->reader(['CONTAI_APP_KEY' => 'rotated-key'])
        );

        $this->assertSame('rotated-key', $key);
    }

    public function test_the_environment_specific_source_wins_over_the_generic_one(): void
    {
        $key = ContaiConfig::resolveAppKey(
            'production',
            'embedded-key',
            $this->reader([
                'CONTAI_APP_KEY' => 'generic-key',
                'CONTAI_APP_KEY_PRODUCTION' => 'prod-specific-key',
            ])
        );

        $this->assertSame('prod-specific-key', $key);
    }

    public function test_each_environment_resolves_its_own_key_independently(): void
    {
        $sources = [
            'CONTAI_APP_KEY_DEVELOPMENT' => 'dev-key',
            'CONTAI_APP_KEY_STAGING'     => 'staging-key',
            'CONTAI_APP_KEY_PRODUCTION'  => 'prod-key',
        ];

        $this->assertSame('dev-key', ContaiConfig::resolveAppKey('development', 'e', $this->reader($sources)));
        $this->assertSame('staging-key', ContaiConfig::resolveAppKey('staging', 'e', $this->reader($sources)));
        $this->assertSame('prod-key', ContaiConfig::resolveAppKey('production', 'e', $this->reader($sources)));
    }

    public function test_a_staging_override_does_not_leak_into_production(): void
    {
        $reader = $this->reader(['CONTAI_APP_KEY_STAGING' => 'staging-only']);

        $this->assertSame('embedded', ContaiConfig::resolveAppKey('production', 'embedded', $reader));
    }

    public function test_an_empty_external_value_is_ignored_not_used_as_the_key(): void
    {
        // A constant defined as '' must not blank out the key — that would
        // authenticate as no tenant. Treat it as "not set" and fall back.
        $key = ContaiConfig::resolveAppKey(
            'production',
            'embedded-key',
            $this->reader(['CONTAI_APP_KEY_PRODUCTION' => '', 'CONTAI_APP_KEY' => ''])
        );

        $this->assertSame('embedded-key', $key);
    }

    public function test_a_blank_environment_specific_value_falls_through_to_the_generic_one(): void
    {
        $key = ContaiConfig::resolveAppKey(
            'production',
            'embedded-key',
            $this->reader(['CONTAI_APP_KEY_PRODUCTION' => '', 'CONTAI_APP_KEY' => 'generic-key'])
        );

        $this->assertSame('generic-key', $key);
    }

    public function test_the_default_reader_reads_a_real_environment_variable(): void
    {
        // One end-to-end check that the production reader (constants + getenv),
        // not just the injected fake, actually resolves an override.
        putenv('CONTAI_APP_KEY_PRODUCTION=env-provided-key');
        try {
            $key = ContaiConfig::resolveAppKey('production', 'embedded-key');
            $this->assertSame('env-provided-key', $key);
        } finally {
            putenv('CONTAI_APP_KEY_PRODUCTION');
        }
    }

    public function test_the_default_reader_falls_back_when_no_env_var_is_set(): void
    {
        putenv('CONTAI_APP_KEY_PRODUCTION');
        putenv('CONTAI_APP_KEY');

        $key = ContaiConfig::resolveAppKey('production', 'embedded-key');

        $this->assertSame('embedded-key', $key);
    }

    /**
     * The tests above call resolveAppKey() directly, in isolation. This one
     * goes through the real singleton flow instead — constructor ->
     * loadConfig() -> loadCustomConfig() -> getApiKey() — because that is the
     * only path that writes the resolved key into $custom_config and the only
     * one every real caller (OnePlatformAuthService, validate()) actually
     * uses. Without it, an override that resolveAppKey() computes correctly
     * could still fail to reach a caller if the merge step regressed.
     */
    public function test_getApiKey_reflects_an_external_override_through_the_public_flow(): void
    {
        WP_Mock::setUp();
        ContaiConfig::reset();

        // '.local' selects the 'development' environment (EnvironmentDetector).
        WP_Mock::userFunction('get_site_url')->andReturn('https://example.local');
        WP_Mock::userFunction('get_option')
            ->with('contai_logging_enabled')
            ->andReturn(false);
        WP_Mock::userFunction('get_option')
            ->with('contai_api_base_url', '')
            ->andReturn('');

        putenv('CONTAI_APP_KEY_DEVELOPMENT=overridden-via-env');

        try {
            $key = ContaiConfig::getInstance()->getApiKey();

            $this->assertSame('overridden-via-env', $key);
        } finally {
            putenv('CONTAI_APP_KEY_DEVELOPMENT');
            ContaiConfig::reset();
            WP_Mock::tearDown();
        }
    }
}
