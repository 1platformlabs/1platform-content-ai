<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The site wizard has to actually flush rewrite rules after it switches the
 * site to /%postname%/ permalinks (#48).
 *
 * contai_setup_site_config() wrote the permalink_structure option and then set
 * a 'contai_flush_rewrite' flag — a flag whose only other occurrence in the
 * repo is uninstall.php's delete list, i.e. nothing has ever read it. The
 * plugin called flush_rewrite_rules() nowhere at all.
 *
 * Read from WordPress core rather than assumed: WP_Rewrite::wp_rewrite_rules()
 * (class-wp-rewrite.php:1493-1500) self-heals the 'rewrite_rules' OPTION only
 * when it is empty, so a ruleset left over from the previous structure
 * survives; and .htaccess is only ever written through a HARD flush, because
 * save_mod_rewrite_rules() sits behind the $hard branch of
 * WP_Rewrite::flush_rules() (class-wp-rewrite.php:1899-1903). On Apache,
 * without the WordPress block in .htaccess, every pretty permalink 404s — the
 * generated posts and the category archives the main menu points at.
 */
class RewriteFlushTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * The flush must be HARD. A soft flush regenerates the option and leaves
     * .htaccess alone, which is the state the site was already in.
     */
    public function test_performs_a_hard_flush(): void
    {
        $flushes = [];
        WP_Mock::userFunction('flush_rewrite_rules', [
            'return' => function ($hard = null) use (&$flushes) {
                $flushes[] = $hard;
                return null;
            },
        ]);
        WP_Mock::userFunction('update_option', ['return' => true]);

        contai_flush_rewrite_rules_hard();

        $this->assertSame([true], $flushes, 'The wizard must request a HARD flush, so .htaccess is rewritten');
    }

    /**
     * Once flushed, the flag should describe reality. Leaving it permanently
     * true is what made it read as a pending action nobody ever performed.
     */
    public function test_clears_the_pending_flush_flag_afterwards(): void
    {
        $options = [];
        WP_Mock::userFunction('flush_rewrite_rules', ['return' => null]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($key, $value) use (&$options) {
                $options[$key] = $value;
                return true;
            },
        ]);

        contai_flush_rewrite_rules_hard();

        $this->assertArrayHasKey('contai_flush_rewrite', $options);
        $this->assertFalse($options['contai_flush_rewrite']);
    }

    /**
     * Wiring guard: the flush has to be reachable from the wizard step, not
     * merely defined. This is a source assertion — contai_setup_site_config()
     * also calls contai_delete_sample_content(), whose WordPress surface is
     * not worth mocking to prove one call is present — and it is declared as
     * such rather than dressed up as behavioural.
     */
    public function test_setup_site_config_calls_the_flush(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/includes/helpers/site-generation.php');

        $matched = preg_match(
            '/function contai_setup_site_config\(\).*?\n}/s',
            $source,
            $matches
        );

        $this->assertSame(1, $matched, 'contai_setup_site_config() must exist');

        $body = $matches[0];

        $this->assertStringContainsString(
            'contai_flush_rewrite_rules_hard();',
            $body,
            'contai_setup_site_config() must flush rewrite rules after changing permalink_structure (#48)'
        );

        $this->assertStringContainsString(
            "update_option( 'permalink_structure', '/%postname%/' );",
            $body,
            'The permalink structure write must remain'
        );
    }
}
