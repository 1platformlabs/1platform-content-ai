<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * CONTAI_VERSION comes from the plugin header, and nowhere else (issue #209 b).
 *
 * It was a second literal next to the header and the two drifted — header
 * 3.2.1, constant 2.40.0 — so contai_maybe_upgrade() saw "no update" on every
 * release after 2.40.0. The release pipeline now stamps only the header
 * (issue #208), which is safe only while the constant keeps reading it.
 */
class PluginHeaderVersionTest extends TestCase
{
    private const PLUGIN_FILE = __DIR__ . '/../../../1platform-content-ai.php';

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/plugin-version.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_it_returns_the_version_wordpress_reads_from_the_header(): void
    {
        WP_Mock::userFunction('get_file_data', [
            'times'  => 1,
            'args'   => [self::PLUGIN_FILE, ['Version' => 'Version']],
            'return' => ['Version' => ' 3.2.1 '],
        ]);

        $this->assertSame('3.2.1', contai_plugin_header_version(self::PLUGIN_FILE));
    }

    /**
     * @dataProvider unreadableHeaders
     */
    public function test_an_unreadable_header_is_a_version_that_never_triggers_an_upgrade(array $headers): void
    {
        WP_Mock::userFunction('get_file_data', ['return' => $headers]);

        $this->assertSame('0.0.0', contai_plugin_header_version(self::PLUGIN_FILE));
    }

    public static function unreadableHeaders(): array
    {
        return [
            'missing header' => [[]],
            'empty header'   => [['Version' => '']],
            'not X.Y.Z'      => [['Version' => '3.2']],
            'trailing text'  => [['Version' => '3.2.1-beta']],
        ];
    }

    public function test_the_main_file_defines_contai_version_from_its_own_header(): void
    {
        $source = file_get_contents(self::PLUGIN_FILE);

        $this->assertMatchesRegularExpression('/^[ \t\/*#@]*Version:\s*\d+\.\d+\.\d+\s*$/m', $source);
        $this->assertStringContainsString(
            "define('CONTAI_VERSION', contai_plugin_header_version(__FILE__));",
            $source
        );
        $this->assertLessThan(
            strpos($source, "define('CONTAI_VERSION'"),
            strpos($source, "require_once __DIR__ . '/includes/helpers/plugin-version.php';"),
            'the helper has to be loaded before the constant is defined'
        );
    }

    public function test_no_shipped_file_defines_contai_version_as_a_literal(): void
    {
        $root  = dirname(__DIR__, 3);
        $files = array_merge(
            [$root . '/1platform-content-ai.php'],
            iterator_to_array(new \RegexIterator(
                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/includes')),
                '/\.php$/'
            ), false)
        );

        $literal = [];
        foreach ($files as $file) {
            $path = is_string($file) ? $file : $file->getPathname();
            if (preg_match('/define\(\s*[\'"]CONTAI_VERSION[\'"]\s*,\s*[\'"]/', (string) file_get_contents($path))) {
                $literal[] = substr($path, strlen($root) + 1);
            }
        }

        $this->assertSame([], $literal, 'a literal CONTAI_VERSION drifts from the header the pipeline stamps');
    }
}
