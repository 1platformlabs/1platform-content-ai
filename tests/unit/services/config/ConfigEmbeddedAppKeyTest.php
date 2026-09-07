<?php

namespace ContAI\Tests\Unit\Services\Config;

use PHPUnit\Framework\TestCase;
use ContaiConfig;
use ReflectionClass;

/**
 * Guards on the APP keys bundled in ContaiConfig::DEFAULT_CONFIG.
 *
 * The bundled value is public by construction — it ships inside the .zip
 * published to wordpress.org — and that is accepted (see the docblock on
 * DEFAULT_CONFIG). What is NOT accepted is the two states this file pins:
 *
 *  - two environments sharing one value, which is how the QA key ended up
 *    shipping twice and made "rotate the development key" impossible to scope;
 *  - a blank production value, which would break the fresh install that a
 *    wordpress.org plugin has to support with no configuration at all.
 *
 * No key is written down here. The assertions compare and measure; they never
 * name a value.
 */
class ConfigEmbeddedAppKeyTest extends TestCase
{
    /** @return array<string, string> environment => bundled app key */
    private function embeddedKeys(): array
    {
        $defaults = (new ReflectionClass(ContaiConfig::class))->getConstant('DEFAULT_CONFIG');
        $this->assertIsArray($defaults, 'DEFAULT_CONFIG is not readable');

        $keys = [];
        foreach ($defaults as $environment => $config) {
            $keys[$environment] = $config['api']['app_key'] ?? null;
        }

        return $keys;
    }

    public function test_every_environment_has_a_bundled_app_key(): void
    {
        foreach ($this->embeddedKeys() as $environment => $key) {
            $this->assertIsString($key, "{$environment} has no bundled app key");
            $this->assertNotSame('', $key, "{$environment} bundles an empty app key");
        }
    }

    public function test_no_two_environments_share_a_bundled_app_key(): void
    {
        $keys = $this->embeddedKeys();

        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            'two environments bundle the same app key: rotating one cannot be '
            . 'scoped to one environment, and a QA key ends up in production builds'
        );
    }

    public function test_production_bundles_a_real_key_so_a_clean_install_works(): void
    {
        $keys = $this->embeddedKeys();

        $this->assertArrayHasKey('production', $keys);
        // Long and opaque: a placeholder or a blank would leave a fresh
        // wordpress.org install unable to reach the API until somebody edits
        // wp-config.php, which is not the contract of a published plugin.
        $this->assertGreaterThanOrEqual(
            32,
            strlen($keys['production']),
            'the production app key looks like a placeholder — a clean install would not work'
        );
    }

    /**
     * Control: the guard above must not be satisfiable by any string at all.
     * A value that is only distinct is not enough; the distinctness assertion
     * has to be the one doing the work, so prove it can fail.
     */
    public function test_control_the_uniqueness_check_rejects_a_shared_value(): void
    {
        $shared = ['development' => 'same', 'staging' => 'same', 'production' => 'other'];

        $this->assertNotSame(
            count($shared),
            count(array_unique($shared)),
            'the uniqueness assertion would pass on a set that shares a value'
        );
    }
}
