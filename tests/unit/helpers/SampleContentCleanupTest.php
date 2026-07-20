<?php

namespace ContAI\Tests\Unit\Helpers;

use WP_Mock;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's stock-sample-content cleanup has to match the slugs WordPress
 * actually used on this install, and must never destroy content (#48).
 *
 * Read from WordPress core rather than assumed: core creates the sample post
 * with sanitize_title( _x( 'hello-world', 'Default post slug' ) ) and
 * __( 'Hello world!' ) (wp-admin/includes/upgrade.php:247-249), and the sample
 * page with __( 'sample-page' ) / __( 'Sample Page' ) (:352-354) — and looks
 * the post back up through the same translated call (:529). It never hardcodes
 * the English literal on the way back. On an es_ES install those slugs are
 * 'hola-mundo' and 'pagina-ejemplo' (admin-es_ES.po), so the plugin's hardcoded
 * lookups matched nothing and the cleanup was a silent no-op on every
 * non-English site — while the step still reported success.
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
     * A Spanish install whose admin translations are present.
     *
     * get_page_by_path() models the slug argument on purpose: it answers only
     * for the translated slug. A stub that returned the same post for any slug
     * would make the whole defect unobservable — the lookup could go back to
     * hardcoded literals and every assertion here would still pass.
     */
    private function mockSpanishInstall(array &$retired, array &$loadedDomains): void
    {
        WP_Mock::userFunction('get_locale', ['return' => 'es_ES']);
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
            static function ($text, $domain = null) {
                $map = [
                    'sample-page'  => 'pagina-ejemplo',
                    'Hello world!' => '¡Hola, mundo!',
                    'Sample Page'  => 'Página de ejemplo',
                ];
                return $map[$text] ?? $text;
            }
        );
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);

        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static function ($slug, $output = null, $post_type = 'post') {
                if ('hola-mundo' === $slug && 'post' === $post_type) {
                    return (object) ['ID' => 11, 'post_type' => 'post', 'post_title' => '¡Hola, mundo!'];
                }
                if ('pagina-ejemplo' === $slug && 'page' === $post_type) {
                    return (object) ['ID' => 22, 'post_type' => 'page', 'post_title' => 'Página de ejemplo'];
                }
                return null;
            }
        );
        // EMPTY_TRASH_DAYS is undefined under the test bootstrap, so production
        // takes the draft path. Both writers are captured so a swap is visible.
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = ['draft', (int) $args['ID'], $args['post_status'] ?? ''];
                return (int) $args['ID'];
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$retired) {
                $retired[] = ['trash', (int) $id, ''];
                return (object) ['ID' => $id];
            }
        );
        WP_Mock::userFunction('wp_delete_post')->andReturnUsing(
            static function ($id, $force = false) use (&$retired) {
                $retired[] = [$force ? 'force-delete' : 'delete', (int) $id, ''];
                return (object) ['ID' => $id];
            }
        );
    }

    /**
     * The defect itself: on a Spanish install the stock content must be found.
     * With hardcoded English slugs nothing is found at all.
     */
    public function test_removes_the_translated_stock_content_on_a_spanish_install(): void
    {
        $retired = [];
        $loaded  = [];
        $this->mockSpanishInstall($retired, $loaded);

        contai_delete_sample_content();

        $this->assertSame(
            [11, 22],
            array_column($retired, 1),
            'The Spanish stock post and page must be retired; hardcoded English slugs match nothing here'
        );
    }

    /**
     * _x() only resolves because the file is loaded explicitly. Pin that the
     * lookup goes to admin-<locale>.mo and NOT to the request's own 'default'
     * domain, which the wizard's context leaves without those strings.
     */
    public function test_loads_core_admin_translations_from_the_locale_file(): void
    {
        $retired = [];
        $loaded  = [];
        $this->mockSpanishInstall($retired, $loaded);

        contai_delete_sample_content();

        $this->assertNotEmpty($loaded, 'The core admin translations must be loaded explicitly');
        foreach ($loaded as [$domain, $mofile]) {
            $this->assertNotSame('default', $domain, "Must not mutate the request's own 'default' text domain");
            $this->assertStringEndsWith('/admin-es_ES.mo', $mofile);
        }
    }

    /**
     * A translation is not guaranteed to be slug-safe already. Core stores the
     * sample post under sanitize_title( _x( ... ) ) (upgrade.php:249), so a
     * locale whose translation carries capitals, spaces or punctuation is only
     * findable through the sanitized form — the raw string matches nothing.
     */
    public function test_matches_the_sanitized_form_of_a_non_slug_safe_translation(): void
    {
        $retired = [];

        WP_Mock::userFunction('get_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('determine_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('load_textdomain', ['return' => true]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('_x')->andReturnUsing(
            static fn ($text, $context, $domain = null) => 'hello-world' === $text ? 'Hola Mundo!' : $text
        );
        WP_Mock::userFunction('__')->andReturnUsing(
            static fn ($text, $domain = null) => 'Hello world!' === $text ? 'Hola Mundo' : $text
        );
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(
            static fn ($t) => strtolower(str_replace([' ', '!'], ['-', ''], $t))
        );
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('hola-mundo' === $slug && 'post' === $post_type)
                    ? (object) ['ID' => 31, 'post_type' => 'post', 'post_title' => 'Hola Mundo']
                    : null
        );
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = (int) $args['ID'];
                return (int) $args['ID'];
            }
        );

        contai_delete_sample_content();

        $this->assertSame(
            [31],
            $retired,
            'The sanitized form of the translated slug has to be a candidate; the raw string matches nothing'
        );
    }

    /**
     * determine_locale() is the locale of THIS REQUEST, not of the install
     * (wp-includes/l10n.php: get_user_locale() under is_admin(), filterable).
     * Core created the sample content once, from the site locale. A site
     * installed in Spanish and later switched to English still has Spanish
     * slugs — resolving only the request locale leaves the bug unfixed there.
     */
    public function test_also_tries_the_site_locale_when_the_request_locale_differs(): void
    {
        $retired = [];
        $loaded  = [];

        WP_Mock::userFunction('get_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('load_textdomain')->andReturnUsing(
            static function ($domain, $mofile, $locale = null) use (&$loaded) {
                $loaded[] = $mofile;
                return true;
            }
        );
        WP_Mock::userFunction('_x')->andReturnUsing(
            static fn ($text, $context, $domain = null) => 'hello-world' === $text ? 'hola-mundo' : $text
        );
        WP_Mock::userFunction('__')->andReturnUsing(
            static fn ($text, $domain = null) => 'Hello world!' === $text ? '¡Hola, mundo!' : $text
        );
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('hola-mundo' === $slug && 'post' === $post_type)
                    ? (object) ['ID' => 41, 'post_type' => 'post', 'post_title' => '¡Hola, mundo!']
                    : null
        );
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = (int) $args['ID'];
                return (int) $args['ID'];
            }
        );

        contai_delete_sample_content();

        $this->assertSame([41], $retired, 'The site locale must be tried, not just the request locale');
        // Once per post type; en_US is short-circuited, so es_ES is all there is.
        $this->assertSame(
            ['/tmp/wordpress/wp-content/languages/admin-es_ES.mo'],
            array_values(array_unique($loaded))
        );
    }

    /**
     * load_textdomain() sets the locale on the shared translation controller
     * (l10n.php), so the request's own locale has to be loaded LAST or the rest
     * of the request reads translations for someone else's language.
     */
    public function test_orders_locales_with_the_request_locale_last(): void
    {
        WP_Mock::userFunction('get_locale', ['return' => 'es_ES']);
        WP_Mock::userFunction('determine_locale', ['return' => 'fr_FR']);

        $this->assertSame(['es_ES', 'fr_FR'], contai_core_locale_candidates());
    }

    /**
     * The English install must keep working — the literal is still a candidate.
     */
    public function test_still_removes_the_english_stock_content(): void
    {
        $retired = [];

        WP_Mock::userFunction('get_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => true]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static function ($slug, $output = null, $post_type = 'post') {
                if ('hello-world' === $slug && 'post' === $post_type) {
                    return (object) ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!'];
                }
                if ('sample-page' === $slug && 'page' === $post_type) {
                    return (object) ['ID' => 2, 'post_type' => 'page', 'post_title' => 'Sample Page'];
                }
                return null;
            }
        );
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = (int) $args['ID'];
                return (int) $args['ID'];
            }
        );

        contai_delete_sample_content();

        $this->assertSame([1, 2], $retired);
    }

    /**
     * en_US ships no translation file; loading one would be a wasted parse of
     * a path that does not exist.
     */
    public function test_skips_loading_translations_on_an_english_install(): void
    {
        $loaded = [];
        WP_Mock::userFunction('load_textdomain')->andReturnUsing(
            static function ($domain, $mofile, $locale = null) use (&$loaded) {
                $loaded[] = $mofile;
                return true;
            }
        );

        $this->assertFalse(contai_load_core_admin_translations('en_US'));
        $this->assertSame([], $loaded);
    }

    /**
     * wp_trash_post() PERMANENTLY DELETES when EMPTY_TRASH_DAYS is 0
     * (wp-includes/post.php:4006-4009), and that is a documented wp-config
     * setting. This path matched nothing on non-English installs until now, so
     * the change that finally makes it effective must not be the change that
     * destroys content there.
     *
     * This asserts what production actually calls. The earlier version of this
     * test only checked that the mocked wp_delete_post was never invoked —
     * which production no longer calls at all, so it could not fail while the
     * real force-delete happened inside wp_trash_post().
     */
    public function test_demotes_to_draft_instead_of_trashing_when_the_trash_is_not_a_trash(): void
    {
        $calls = [];
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$calls) {
                $calls[] = ['wp_update_post', $args['post_status'] ?? ''];
                return (int) $args['ID'];
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$calls) {
                $calls[] = ['wp_trash_post', ''];
                return (object) ['ID' => $id];
            }
        );

        $this->assertTrue(contai_retire_stock_sample(5, 0));

        $this->assertSame([['wp_update_post', 'draft']], $calls);
    }

    /**
     * Where the trash IS a trash, use it — it is the more familiar place for a
     * site owner to find the item.
     */
    public function test_trashes_when_the_trash_actually_retains(): void
    {
        $calls = [];
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$calls) {
                $calls[] = 'wp_update_post';
                return (int) $args['ID'];
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$calls) {
                $calls[] = 'wp_trash_post';
                return (object) ['ID' => $id];
            }
        );

        $this->assertTrue(contai_retire_stock_sample(5, 30));

        $this->assertSame(['wp_trash_post'], $calls);
    }

    /**
     * A slug match is not enough to justify a destructive action. An owner who
     * legitimately has a page at that slug must keep it — and must be told.
     */
    public function test_leaves_an_owner_page_at_the_same_slug_alone(): void
    {
        $warnings = [];
        $retired  = [];

        WP_Mock::userFunction('get_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => false]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('sample-page' === $slug && 'page' === $post_type)
                    ? (object) ['ID' => 9, 'post_type' => 'page', 'post_title' => 'Our Pricing']
                    : null
        );
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = (int) $args['ID'];
                return (int) $args['ID'];
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$retired) {
                $retired[] = (int) $id;
                return (object) ['ID' => $id];
            }
        );
        WP_Mock::userFunction('get_option', ['return' => []]);
        WP_Mock::userFunction('update_option')->andReturnUsing(
            static function ($key, $value) use (&$warnings) {
                $warnings = $value;
                return true;
            }
        );

        contai_delete_sample_content();

        $this->assertSame([], $retired, "An owner's own page must never be retired");
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Our Pricing', $warnings[0]['message']);
    }

    /**
     * get_page_by_path() searches the requested type AND 'attachment', and
     * assigns the id before checking the type (post.php:6215-6222), so an
     * unattached media item at this slug comes back here. Trashing a media item
     * would be a destructive false positive.
     */
    public function test_ignores_an_attachment_returned_for_the_same_slug(): void
    {
        $retired = [];

        WP_Mock::userFunction('get_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => false]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('sample-page' === $slug && 'page' === $post_type)
                    ? (object) ['ID' => 77, 'post_type' => 'attachment', 'post_title' => 'Sample Page']
                    : null
        );
        WP_Mock::userFunction('wp_update_post')->andReturnUsing(
            static function ($args) use (&$retired) {
                $retired[] = (int) $args['ID'];
                return (int) $args['ID'];
            }
        );
        WP_Mock::userFunction('wp_trash_post')->andReturnUsing(
            static function ($id) use (&$retired) {
                $retired[] = (int) $id;
                return (object) ['ID' => $id];
            }
        );

        contai_delete_sample_content();

        $this->assertSame([], $retired, 'An attachment must never be retired by the sample-content cleanup');
    }

    /**
     * A failed removal must leave a durable trace. Every root cause on this
     * issue so far had to be found by reading code, because the wizard's
     * misapplied changes left no record anywhere.
     */
    public function test_records_a_warning_when_the_stock_content_cannot_be_removed(): void
    {
        $warnings = [];

        WP_Mock::userFunction('get_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('determine_locale', ['return' => 'en_US']);
        WP_Mock::userFunction('load_textdomain', ['return' => false]);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);
        WP_Mock::userFunction('sanitize_title')->andReturnUsing(static fn ($t) => $t);
        WP_Mock::userFunction('get_page_by_path')->andReturnUsing(
            static fn ($slug, $output = null, $post_type = 'post') =>
                ('hello-world' === $slug && 'post' === $post_type)
                    ? (object) ['ID' => 7, 'post_type' => 'post', 'post_title' => 'Hello world!']
                    : null
        );
        WP_Mock::userFunction('wp_update_post', ['return' => 0]);
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
