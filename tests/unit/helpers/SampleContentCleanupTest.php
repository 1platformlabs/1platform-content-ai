<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's stock-sample-content cleanup has to match the slugs WordPress
 * actually used on this install (#48).
 *
 * Read from WordPress core rather than assumed: core creates the sample post
 * with sanitize_title( _x( 'hello-world', 'Default post slug' ) )
 * (wp-admin/includes/upgrade.php:249) and the sample page with
 * __( 'sample-page' ) (:354) — and looks the post back up through the same
 * translated call (:529). It never hardcodes the English literal on the way
 * back. On an es_ES install those slugs are 'hola-mundo' and 'pagina-ejemplo'
 * (admin-es_ES.po), so the plugin's hardcoded lookups matched nothing and the
 * cleanup was a silent no-op on every non-English site — while the step still
 * reported success.
 *
 * That matters to navigation, which is what this issue is about: the wizard
 * forces the front page to the blog index, where the surviving sample post
 * renders among the generated content, and a surviving published "Sample Page"
 * is listed by the wp_page_menu() fallback — the same listing that surfaces the
 * legal pages as the main navigation, the symptom #48 opened with.
 *
 * The subtlety these tests pin: _x() alone cannot fix it. Those strings live in
 * admin-<locale>.mo, and load_default_textdomain() (wp-includes/l10n.php) only
 * loads that file under is_admin(). The wizard runs from the job queue — the
 * same context that already forces contai_flush_rewrite_rules_hard() to require
 * wp-admin/includes/misc.php by hand — so the file has to be loaded explicitly
 * or the "fix" is one more silent no-op.
 */
class SampleContentCleanupTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        require_once dirname(__DIR__, 3) . '/includes/helpers/site-generation.php';

        if (!defined('WP_LANG_DIR')) {
            define('WP_LANG_DIR', '/tmp/wordpress/wp-content/languages');
        }
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * Mock a Spanish install whose admin translations are present.
     *
     * get_page_by_path() models the slug argument on purpose: it answers only
     * for the translated slug. A mock that returned the same post for any slug
     * would make the whole defect unobservable — the lookup could go back to
     * hardcoded literals and every assertion here would still pass.
     *
     * @param array<int,array{0:string,1:int}> $trashed Collects trash calls.
     */
    private function mockSpanishInstall(array &$trashed, array &$loadedDomains): void
    {
        WP_Mock::userFunction('determine_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('load_textdomain')->andReturnUsing(
            static function ($domain, $mofile, $locale = null) use (&$loadedDomains) {
                $loadedDomains[] = [$domain, $mofile];
                return true;
            }
        );
        WP_Mock::userFunction('_x')->andReturnUsing(
            static fn ($text, $context, $domain = null) => 'hello-world' === $text ? 'hola-mundo' : $text
        );
        WP_Mock::userFunction('__')->andReturnUsing(
            static fn ($text, $domain = null) => 'sample-page' === $text ? 'pagina-ejemplo' : $text
        );
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);

        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static function ($slug, $output = null, $post_type = 'post') {
                if ('hola-mundo' === $slug && 'post' === $post_type) {
                    return (object) ['ID' => 11];
                }
                if ('pagina-ejemplo' === $slug && 'page' === $post_type) {
                    return (object) ['ID' => 22];
                }
                return null;
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$trashed) {
                $trashed[] = $id;
                return (object) ['ID' => $id];
            }
        );
        WP_Mock::userFunction('wp_delete_post')->andReturnUsing(
            static function ($id, $force = false) use (&$trashed) {
                $trashed[] = "FORCE:{$id}";
                return (object) ['ID' => $id];
            }
        );
    }

    /**
     * The defect itself: on a Spanish install the stock content must be found
     * and removed. With hardcoded English slugs nothing is found at all.
     */
    public function test_removes_the_translated_stock_content_on_a_spanish_install(): void
    {
        $trashed = [];
        $loaded  = [];
        $this->mockSpanishInstall($trashed, $loaded);

        contai_delete_sample_content();

        $this->assertSame(
            [11, 22],
            $trashed,
            'The Spanish stock post and page must be removed; hardcoded English slugs match nothing here'
        );
    }

    /**
     * _x() only resolves because the file is loaded explicitly. Pin that the
     * lookup goes to admin-<locale>.mo and NOT to the request's own 'default'
     * domain, which the wizard's context leaves without those strings.
     */
    public function test_loads_core_admin_translations_from_the_locale_file(): void
    {
        $trashed = [];
        $loaded  = [];
        $this->mockSpanishInstall($trashed, $loaded);

        contai_delete_sample_content();

        $this->assertNotEmpty($loaded, 'The core admin translations must be loaded explicitly');
        foreach ($loaded as [$domain, $mofile]) {
            $this->assertNotSame('default', $domain, "Must not mutate the request's own 'default' text domain");
            $this->assertStringEndsWith('/admin-es_ES.mo', $mofile);
        }
    }

    /**
     * Removal must be reversible. This path matched nothing on non-English
     * installs until now, so making it effective must not simultaneously make
     * it irreversible on every one of those sites.
     */
    public function test_trashes_rather_than_force_deleting(): void
    {
        $trashed = [];
        $loaded  = [];
        $this->mockSpanishInstall($trashed, $loaded);

        contai_delete_sample_content();

        foreach ($trashed as $entry) {
            $this->assertIsInt($entry, 'Stock content must be trashed (recoverable), never force-deleted');
        }
    }

    /**
     * A translation is not guaranteed to be slug-safe already. Core stores the
     * sample post under sanitize_title( _x( ... ) )
     * (wp-admin/includes/upgrade.php:249), so a locale whose translation
     * carries capitals, spaces or punctuation is only findable through the
     * sanitized form — the raw string matches nothing.
     */
    public function test_matches_the_sanitized_form_of_a_non_slug_safe_translation(): void
    {
        $trashed = [];

        WP_Mock::userFunction('determine_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('load_textdomain', ['return' => true]);
        WP_Mock::userFunction('_x')->andReturnUsing(
            static fn ($text, $context, $domain = null) => 'hello-world' === $text ? 'Hola Mundo!' : $text
        );
        WP_Mock::userFunction('__')->andReturnUsing(
            static fn ($text, $domain = null) => $text
        );
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(
            static fn ($t) => strtolower(str_replace([' ', '!'], ['-', ''], $t))
        );
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('hola-mundo' === $slug && 'post' === $post_type) ? (object) ['ID' => 31] : null
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$trashed) {
                $trashed[] = $id;
                return (object) ['ID' => $id];
            }
        );

        contai_delete_sample_content();

        $this->assertSame(
            [31],
            $trashed,
            'The sanitized form of the translated slug has to be a candidate; the raw string matches nothing'
        );
    }

    /**
     * The English install must keep working — the literal is still a candidate.
     */
    public function test_still_removes_the_english_stock_content(): void
    {
        $trashed = [];

        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => true]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static function ($slug, $output = null, $post_type = 'post') {
                if ('hello-world' === $slug && 'post' === $post_type) {
                    return (object) ['ID' => 1];
                }
                if ('sample-page' === $slug && 'page' === $post_type) {
                    return (object) ['ID' => 2];
                }
                return null;
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$trashed) {
                $trashed[] = $id;
                return (object) ['ID' => $id];
            }
        );

        contai_delete_sample_content();

        $this->assertSame([1, 2], $trashed);
    }

    /**
     * en_US ships no translation file; loading one would be a wasted parse of
     * a path that does not exist.
     */
    public function test_skips_loading_translations_on_an_english_install(): void
    {
        $loaded = [];
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain')->andReturnUsing(
            static function ($domain, $mofile, $locale = null) use (&$loaded) {
                $loaded[] = $mofile;
                return true;
            }
        );

        $this->assertFalse(contai_load_core_admin_translations());
        $this->assertSame([], $loaded);
    }

    /**
     * A failed removal must leave a durable trace. Every root cause on this
     * issue so far had to be found by reading code, because the wizard's
     * misapplied changes left no record anywhere.
     */
    public function test_records_a_warning_when_the_stock_content_cannot_be_removed(): void
    {
        $warnings = [];

        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => false]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                'hello-world' === $slug ? (object) ['ID' => 7] : null
        );
        WP_Mock::userFunction('wp_trash_post', ['return' => false]);
        WP_Mock::userFunction('get_option', ['return' => []]);
        WP_Mock::userFunction('update_option')->andReturnUsing(
            static function ($key, $value) use (&$warnings) {
                $warnings = $value;
                return true;
            }
        );

        contai_delete_sample_content();

        $this->assertCount(1, $warnings);
        $this->assertSame('sample content', $warnings[0]['step']);
        $this->assertStringContainsString('7', $warnings[0]['message']);
    }
}
