<?php

namespace ContAI\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * contai_apply_theme_defaults() carried a guarded call to a function that has
 * never existed (#48).
 *
 *     if ( function_exists( 'contai_set_newsmatic_reading_defaults' ) ) {
 *         contai_set_newsmatic_reading_defaults();
 *     }
 *
 * `git log --all -S "function contai_set_newsmatic_reading_defaults"` returns
 * no commits: the call was introduced in the initial commit and the definition
 * never accompanied it. function_exists() was therefore always false, so the
 * branch never ran while reading as deliberate coverage of Newsmatic's reading
 * settings.
 *
 * This is a source guard rather than a behavioural test: the branch is
 * unreachable by construction, so there is no behaviour to observe. What is
 * worth pinning is that the call does not come back, and that a
 * function_exists() guard is never again used to reference a function this
 * plugin does not define.
 */
class DeadThemeHookTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/includes/helpers/site-generation.php'
        );
    }

    /**
     * Comments are stripped first. The removal left a comment explaining what
     * used to be here and why, which names the function — asserting over the
     * raw file would fail on its own documentation.
     */
    private function code(): string
    {
        $stripped = '';
        foreach (token_get_all($this->source()) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    public function test_the_dead_newsmatic_call_is_gone(): void
    {
        // Sanity: the stripper must not have eaten the file.
        $this->assertStringContainsString('function contai_apply_theme_defaults', $this->code());

        $this->assertStringNotContainsString(
            'contai_set_newsmatic_reading_defaults',
            $this->code(),
            'A call guarded by function_exists() to a function that is never defined is dead code (#48)'
        );
    }

    /**
     * The guard that discriminates: every function this file gates behind
     * function_exists() must be defined somewhere in the plugin. Without this,
     * the next dead call looks exactly like the one just removed.
     */
    public function test_every_function_exists_guard_names_a_defined_function(): void
    {
        $source = $this->code();

        preg_match_all("/function_exists\(\s*'([a-z0-9_]+)'\s*\)/i", $source, $matches);
        $guarded = array_unique($matches[1]);

        $this->assertNotEmpty($guarded, 'Sanity: this file does use function_exists guards');

        $includes = dirname(__DIR__, 3) . '/includes';
        $defined  = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($includes));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/^\s*function\s+([a-z0-9_]+)\s*\(/im', $contents, $found)) {
                    foreach ($found[1] as $name) {
                        $defined[strtolower($name)] = true;
                    }
                }
            }
        }

        foreach ($guarded as $name) {
            // WordPress core functions are legitimately guarded (the plugin
            // does not define them); only contai_* ones are this plugin's
            // responsibility.
            if (strpos($name, 'contai_') !== 0) {
                continue;
            }

            $this->assertArrayHasKey(
                strtolower($name),
                $defined,
                "function_exists('{$name}') guards a function no file under includes/ defines (#48)"
            );
        }
    }
}
