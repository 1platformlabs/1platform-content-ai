<?php

namespace ContAI\Tests\Unit\Services\Comments;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiCommentsService;

/**
 * Dates for generated comments (issues 118 / 119).
 *
 * The property under test is an ordering invariant, not a formatting one: a
 * comment must never carry a timestamp earlier than the post it answers. The
 * draw is randomised, so the tests assert the *window* handed to wp_rand rather
 * than a sampled value — a test that only inspected one sample would pass by
 * luck on the old code roughly half the time.
 */
class CommentsServiceTest extends TestCase
{
    /** Site timezone used by the date stubs: UTC+2, so a swap is observable. */
    private const SITE_OFFSET = 2 * 3600;

    /** @var array<int, array{int, int}> */
    private array $drawWindows = [];

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $this->drawWindows = [];
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ── the window ─────────────────────────────────────────────────

    public function test_draw_window_opens_at_the_post_publication_instant(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        ContaiCommentsService::commentDatesForPost($this->post('2026-03-14 09:15:00'));

        [$min, ] = $this->drawWindows[0];

        $this->assertSame(
            strtotime('2026-03-14 09:15:00 +0000'),
            $min,
            'The earliest a comment may be dated is the post itself.'
        );
    }

    public function test_draw_window_closes_now_so_no_comment_is_dated_in_the_future(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $before = time();
        ContaiCommentsService::commentDatesForPost($this->post('2020-01-01 00:00:00'));
        $after = time();

        [, $max] = $this->drawWindows[0];

        $this->assertGreaterThanOrEqual($before, $max);
        $this->assertLessThanOrEqual($after, $max);
    }

    public function test_earliest_possible_draw_lands_exactly_on_the_post_date(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        [$local, $gmt] = ContaiCommentsService::commentDatesForPost($this->post('2026-03-14 09:15:00'));

        $this->assertSame('2026-03-14 09:15:00', $gmt);
        $this->assertSame(
            '2026-03-14 11:15:00',
            $local,
            'The local column is derived from the GMT one, forward through the site offset.'
        );
    }

    public function test_latest_possible_draw_is_not_in_the_future(): void
    {
        $this->mockDateHelpers();
        WP_Mock::userFunction('wp_rand')->andReturnUsing(function (int $min, int $max): int {
            $this->drawWindows[] = [$min, $max];
            return $max;
        });

        [, $gmt] = ContaiCommentsService::commentDatesForPost($this->post('2026-03-14 09:15:00'));

        $this->assertLessThanOrEqual(time(), strtotime($gmt . ' +0000'));
    }

    /**
     * The regression the two reported sites showed: with the window fixed at
     * "the last 12 months" and posts spread over the same 365 days (see
     * ContaiPostMaintenancePanel::randomize_post_dates), about half of a site's
     * comments predated their article. Sweeping the whole distribution proves
     * the new window cannot produce a single inversion, and the count of
     * inversions the *old* window produces on the same inputs is the control
     * that shows this test can fail at all.
     */
    public function test_no_inversion_across_the_whole_year_while_the_old_window_inverts_constantly(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $now = time();
        $inversionsNew = 0;
        $inversionsOld = 0;
        $daysSwept = 0;

        for ($daysAgo = 0; $daysAgo <= 365; $daysAgo += 5) {
            $postTs = $now - ($daysAgo * DAY_IN_SECONDS);
            $postGmt = gmdate('Y-m-d H:i:s', $postTs);
            $daysSwept++;

            [, $gmt] = ContaiCommentsService::commentDatesForPost($this->post($postGmt));

            if (strtotime($gmt . ' +0000') < $postTs) {
                $inversionsNew++;
            }

            // The replaced rule, verbatim: a draw over the trailing year that
            // never looked at the post. Its floor is one year ago, so every
            // post older than the floor+epsilon can be inverted.
            if (($now - DAY_IN_SECONDS * 365) < $postTs) {
                $inversionsOld++;
            }
        }

        $this->assertSame(74, $daysSwept, 'The sweep must cover the full year the randomiser spans.');
        $this->assertSame(0, $inversionsNew, 'No comment may be dated before its post.');
        $this->assertGreaterThan(
            $daysSwept / 2,
            $inversionsOld,
            'Control: the replaced window could invert on the majority of these posts.'
        );
    }

    // ── degenerate post dates ──────────────────────────────────────

    public function test_a_future_dated_post_collapses_the_window_instead_of_inverting_it(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $future = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        ContaiCommentsService::commentDatesForPost($this->post($future));

        [$min, $max] = $this->drawWindows[0];

        $this->assertSame($min, $max, 'A future post must not open a backwards window.');
        $this->assertSame(strtotime($future . ' +0000'), $min);
    }

    public function test_a_zero_post_date_gmt_falls_back_to_the_local_column(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $post = $this->post('0000-00-00 00:00:00');
        $post->post_date = '2026-02-01 08:00:00';

        ContaiCommentsService::commentDatesForPost($post);

        [$min, ] = $this->drawWindows[0];

        $this->assertSame(
            strtotime('2026-02-01 08:00:00 +0000') - self::SITE_OFFSET,
            $min,
            'A zero GMT column is not a date; the local column has to be converted.'
        );
    }

    /**
     * The empty-string case is not symmetric with the others and is the reason
     * the guard cannot be "not false": strtotime(' +0000') returns *now*, so an
     * unguarded empty GMT column would anchor the post on the current time and
     * throw away the real date sitting in the local column.
     */
    public function test_an_empty_post_date_gmt_uses_the_local_column_not_now(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $post = $this->post('');
        $post->post_date = '2026-02-01 08:00:00';

        ContaiCommentsService::commentDatesForPost($post);

        [$min, ] = $this->drawWindows[0];

        $this->assertSame(
            strtotime('2026-02-01 08:00:00 +0000') - self::SITE_OFFSET,
            $min,
            'A blank GMT column must not be read as "now" — the post has a real date.'
        );
    }

    public function test_an_unreadable_post_date_anchors_on_now(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $post = $this->post('');
        $post->post_date = '';

        $before = time();
        ContaiCommentsService::commentDatesForPost($post);
        $after = time();

        [$min, ] = $this->drawWindows[0];

        $this->assertGreaterThanOrEqual($before, $min);
        $this->assertLessThanOrEqual($after, $min);
    }

    public function test_a_garbage_post_date_anchors_on_now_rather_than_epoch(): void
    {
        $this->mockDateHelpers();
        $this->captureDrawReturningMin();

        $post = $this->post('not a date at all');
        $post->post_date = 'also not a date';

        $before = time();
        ContaiCommentsService::commentDatesForPost($post);
        $after = time();

        [$min, ] = $this->drawWindows[0];

        $this->assertGreaterThanOrEqual($before, $min, 'An unparseable date must not fall back to 1970.');
        $this->assertLessThanOrEqual($after, $min);
    }

    // ── the writer inventory ───────────────────────────────────────

    /**
     * The rule lives in one place; what this asserts is that every site that
     * writes a comment row actually goes through it. Two independent copies of
     * the old randomiser is how the defect survived, so a third writer showing
     * up must break this test rather than silently inherit nothing.
     */
    public function test_every_comment_writer_takes_its_dates_from_the_shared_rule(): void
    {
        $writers = $this->commentWriterSources();

        $this->assertSame(
            [
                'includes/admin/content-generator/panels/generate-comments.php',
                'includes/services/setup/CommentsGenerationService.php',
            ],
            array_keys($writers),
            'A new wp_insert_comment() caller must be audited against issues 118/119.'
        );

        foreach ($writers as $path => $source) {
            $this->assertStringContainsString(
                'ContaiCommentsService::commentDatesForPost(',
                $source,
                $path . ' must anchor its comment dates on the post.'
            );
            $this->assertSame(
                0,
                preg_match_all($this->unanchoredDrawPattern(), $source),
                $path . ' still draws a comment date without looking at the post.'
            );
        }
    }

    public function test_the_writer_inventory_detects_an_unanchored_writer(): void
    {
        // Negative control: the exact shape this audit exists to catch. Without
        // it, a pattern that matches nothing anywhere would read as "clean".
        $unanchored = <<<'PHP'
        $comment_date = wp_rand(strtotime('-1 year'), time());
        wp_insert_comment(['comment_date' => $comment_date]);
        PHP;

        $this->assertSame(1, preg_match_all($this->unanchoredDrawPattern(), $unanchored));
        $this->assertStringNotContainsString('ContaiCommentsService::commentDatesForPost(', $unanchored);
    }

    // ── the language of a comment ───────────────

    /**
     * The reported case, in one assertion: an English site whose plugin option
     * still says Spanish. The post carries the language it was generated in;
     * the option carries the language the *wizard* was run in, and those are
     * set by different screens.
     */
    public function test_the_comment_language_comes_from_the_post_not_the_site(): void
    {
        $this->mockSiteLanguage('spanish', 'es_ES');
        $this->mockRecordedLanguage('en');

        $this->assertSame('en', ContaiCommentsService::languageForPost($this->post('2026-03-14 09:15:00')));
    }

    /**
     * The inverse, so the test is not satisfied by a hardcoded 'en': the same
     * code path must answer 'es' for a post generated in Spanish, on a site
     * whose option says English.
     */
    public function test_a_spanish_post_on_an_english_site_is_answered_in_spanish(): void
    {
        $this->mockSiteLanguage('english', 'en_US');
        $this->mockRecordedLanguage('es');

        $this->assertSame('es', ContaiCommentsService::languageForPost($this->post('2026-03-14 09:15:00')));
    }

    public function test_a_post_with_no_recorded_language_falls_back_to_the_site(): void
    {
        $this->mockSiteLanguage('spanish', 'en_US');
        $this->mockRecordedLanguage('');

        $this->assertSame(
            'es',
            ContaiCommentsService::languageForPost($this->post('2026-03-14 09:15:00')),
            'Hand-written and imported posts carry no _content_lang; they keep the old behaviour.'
        );
    }

    public function test_a_regional_tag_is_folded_to_its_primary_subtag(): void
    {
        $this->mockSiteLanguage('spanish', 'es_ES');
        $this->mockRecordedLanguage('pt-BR');

        $this->assertSame('pt', ContaiCommentsService::languageForPost($this->post('2026-03-14 09:15:00')));
    }

    /**
     * Coercion is how a resolver invents a wrong answer: substr('spanish', 0, 2)
     * is 'sp', a code for no language at all. An unrecognised value has to fall
     * back to the site rather than be truncated into something plausible.
     */
    public function test_a_word_form_in_the_meta_is_not_truncated_into_a_wrong_code(): void
    {
        $this->mockSiteLanguage('english', 'en_US');
        $this->mockRecordedLanguage('spanish');

        $lang = ContaiCommentsService::languageForPost($this->post('2026-03-14 09:15:00'));

        $this->assertNotSame('sp', $lang, "'sp' is not a language code.");
        $this->assertSame('en', $lang);
    }

    public function test_a_post_without_an_id_never_queries_the_meta_table(): void
    {
        $this->mockSiteLanguage('spanish', 'es_ES');
        WP_Mock::userFunction('get_post_meta')->never();

        $post = new \stdClass();

        $this->assertSame('es', ContaiCommentsService::languageForPost($post));
    }

    /**
     * Same argument as the date inventory above: the rule is only worth
     * anything if every writer goes through it. Both writers used to hoist
     * getSiteLang() out of their loop, which is precisely how one site-wide
     * code ended up on posts written in another language.
     */
    public function test_every_comment_writer_takes_its_language_from_the_post(): void
    {
        $writers = $this->commentWriterSources();

        $this->assertSame(
            [
                'includes/admin/content-generator/panels/generate-comments.php',
                'includes/services/setup/CommentsGenerationService.php',
            ],
            array_keys($writers),
            'A new wp_insert_comment() caller must be audited against issues 118/119.'
        );

        foreach ($writers as $path => $source) {
            $this->assertStringContainsString(
                'ContaiCommentsService::languageForPost(',
                $source,
                $path . ' must take the comment language from the post.'
            );
            $this->assertSame(
                0,
                preg_match_all($this->siteWideLanguagePattern(), $source),
                $path . ' still asks the site for a language it should ask the post for.'
            );
        }
    }

    public function test_the_language_inventory_detects_a_site_wide_writer(): void
    {
        // Negative control: the shape this audit exists to catch, i.e. the code
        // that shipped. Without it a pattern matching nothing reads as "clean".
        $siteWide = <<<'PHP'
        $lang = ContaiCommentsService::getSiteLang();
        foreach ($posts as $post) {
            $service->generateComments(3, $lang, $context);
        }
        PHP;

        $this->assertSame(1, preg_match_all($this->siteWideLanguagePattern(), $siteWide));
        $this->assertStringNotContainsString('ContaiCommentsService::languageForPost(', $siteWide);
    }

    // ── helpers ────────────────────────────────────────────────────

    private function unanchoredDrawPattern(): string
    {
        return '/wp_rand\(\s*strtotime\(/';
    }

    private function siteWideLanguagePattern(): string
    {
        return '/ContaiCommentsService::getSiteLang\(/';
    }

    private function mockSiteLanguage(string $option, string $locale): void
    {
        WP_Mock::userFunction('get_option')->andReturnUsing(
            static fn (string $key, $default = '') => $key === 'contai_site_language' ? $option : $default
        );
        WP_Mock::userFunction('get_locale')->andReturn($locale);
    }

    private function mockRecordedLanguage($value): void
    {
        WP_Mock::userFunction('get_post_meta')->andReturnUsing(
            static fn (int $id, string $key, bool $single) => $key === '_content_lang' ? $value : ''
        );
    }

    /**
     * @return array<string, string> repo-relative path => file contents
     */
    private function commentWriterSources(): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/includes', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (strpos($source, 'wp_insert_comment(') === false) {
                continue;
            }

            $found[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $source;
        }

        ksort($found);

        return $found;
    }

    private function post(string $postDateGmt): \stdClass
    {
        $post = new \stdClass();
        $post->ID = 42;
        $post->post_title = 'Best Reusable Water Bottles for 2026';
        $post->post_date_gmt = $postDateGmt;
        $post->post_date = $postDateGmt;

        return $post;
    }

    private function captureDrawReturningMin(): void
    {
        WP_Mock::userFunction('wp_rand')->andReturnUsing(function (int $min, int $max): int {
            $this->drawWindows[] = [$min, $max];
            return $min;
        });
    }

    /**
     * Stubs that behave like the real helpers on a site two hours ahead of UTC,
     * including their failure mode: WordPress reports an unparseable date by
     * returning the epoch, not false (wp-includes/formatting.php:3673-3675,
     * 3695-3697). An identity stub would hide both the direction of each
     * conversion and that epoch, which is exactly where the interesting bugs
     * live.
     */
    private function mockDateHelpers(): void
    {
        WP_Mock::userFunction('get_date_from_gmt')->andReturnUsing(
            static function (string $gmt): string {
                $timestamp = strtotime($gmt . ' +0000');

                return $timestamp === false
                    ? gmdate('Y-m-d H:i:s', 0)
                    : gmdate('Y-m-d H:i:s', $timestamp + self::SITE_OFFSET);
            }
        );
        WP_Mock::userFunction('get_gmt_from_date')->andReturnUsing(
            static function (string $local): string {
                $timestamp = strtotime($local . ' +0000');

                return $timestamp === false
                    ? gmdate('Y-m-d H:i:s', 0)
                    : gmdate('Y-m-d H:i:s', $timestamp - self::SITE_OFFSET);
            }
        );
    }
}
