<?php

namespace ContAI\Tests\Unit\Services\Setup;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiCommentsGenerationService;
use ContaiCommentsService;

/**
 * The wizard's comment pass (issues 118 / 119).
 *
 * CommentsServiceTest pins the date rule itself; this pins that this path is
 * WIRED to it. That distinction is the whole defect: the rule was fine in the
 * abstract, and two call sites each rolled their own draw. This is also the
 * path SiteGenerationJob runs, so it is the code that populated the two sites
 * the reports are about.
 */
class CommentsGenerationServiceTest extends TestCase
{
    private $mockCommentsService;

    /** @var array<int, array<string, mixed>> */
    private array $insertedComments = [];

    /** How many times local->GMT conversion was needed. */
    private int $gmtConversions = 0;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->insertedComments = [];
        $this->gmtConversions = 0;
        $this->mockCommentsService = Mockery::mock(ContaiCommentsService::class);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_every_generated_comment_is_dated_at_or_after_its_own_post(): void
    {
        $posts = [
            $this->post(11, '2026-06-01 12:00:00'),
            $this->post(22, '2020-02-03 04:05:00'),
            $this->post(33, gmdate('Y-m-d H:i:s', time() - 60)),
        ];

        $this->mockWordPress($posts);
        $this->mockApiReturning([
            ['full_name' => 'Maria Lopez', 'content' => 'Great comparison, thanks.'],
            ['full_name' => 'Jake Turner', 'content' => 'Bought one after reading this.'],
        ]);

        $result = (new ContaiCommentsGenerationService($this->mockCommentsService))
            ->generateCommentsForRecentPosts(3, 2);

        $this->assertTrue($result['success']);
        $this->assertSame(6, $result['generated_count']);
        $this->assertCount(6, $this->insertedComments, 'Six rows must actually have been written.');

        $postDates = [];
        foreach ($posts as $post) {
            $postDates[$post->ID] = strtotime($post->post_date_gmt . ' +0000');
        }

        foreach ($this->insertedComments as $comment) {
            $commentTs = strtotime($comment['comment_date_gmt'] . ' +0000');

            $this->assertGreaterThanOrEqual(
                $postDates[$comment['comment_post_ID']],
                $commentTs,
                sprintf(
                    'Comment %s predates post #%d (%s).',
                    $comment['comment_date_gmt'],
                    $comment['comment_post_ID'],
                    gmdate('Y-m-d H:i:s', $postDates[$comment['comment_post_ID']])
                )
            );
            $this->assertLessThanOrEqual(time(), $commentTs, 'No comment may be dated in the future.');
        }
    }

    /**
     * The two columns have to describe the same instant. Deriving the GMT one
     * from a value that was already GMT — which is what the replaced code did,
     * by handing gmdate() output to get_gmt_from_date() — shifted every comment
     * by the site offset on any site not running UTC.
     */
    public function test_the_two_date_columns_describe_the_same_instant(): void
    {
        $this->mockWordPress([$this->post(11, '2026-06-01 12:00:00')]);
        $this->mockApiReturning([['full_name' => 'Ana Ruiz', 'content' => 'Useful, thanks.']]);

        (new ContaiCommentsGenerationService($this->mockCommentsService))
            ->generateCommentsForRecentPosts(1, 1);

        $comment = $this->insertedComments[0];

        $this->assertSame(
            strtotime($comment['comment_date_gmt'] . ' +0000') + self::SITE_OFFSET,
            strtotime($comment['comment_date'] . ' +0000'),
            'comment_date must be comment_date_gmt shifted once, in the site direction.'
        );

        $this->assertSame(
            0,
            $this->gmtConversions,
            'A post with a usable GMT column must never be converted back from local. '
            . 'Re-deriving the GMT column from the local one is the exact shape of the '
            . 'original bug and is lossy across a DST boundary.'
        );
    }

    /** Site timezone used by the date stubs: UTC+2, so a double shift is visible. */
    private const SITE_OFFSET = 2 * 3600;

    private function post(int $id, string $postDateGmt): \stdClass
    {
        $post = new \stdClass();
        $post->ID = $id;
        $post->post_title = 'Post ' . $id;
        $post->post_date_gmt = $postDateGmt;
        $post->post_date = gmdate('Y-m-d H:i:s', strtotime($postDateGmt . ' +0000') + self::SITE_OFFSET);

        return $post;
    }

    /**
     * @param array<int, \stdClass> $posts
     */
    private function mockWordPress(array $posts): void
    {
        WP_Mock::userFunction('get_posts')->andReturn($posts);
        WP_Mock::userFunction('get_option')->andReturnUsing(
            static fn (string $key, $default = '') => [
                'contai_site_language' => 'english',
                'contai_site_theme'    => 'eco products',
            ][$key] ?? $default
        );
        WP_Mock::userFunction('get_locale')->andReturn('en_US');
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);
        WP_Mock::userFunction('sanitize_textarea_field')->andReturnArg(0);
        WP_Mock::userFunction('wp_rand')->andReturnUsing(
            static fn (int $min, int $max): int => $min + (int) floor(($max - $min) / 3)
        );
        WP_Mock::userFunction('get_date_from_gmt')->andReturnUsing(
            static fn (string $gmt): string => gmdate('Y-m-d H:i:s', strtotime($gmt . ' +0000') + self::SITE_OFFSET)
        );
        WP_Mock::userFunction('get_gmt_from_date')->andReturnUsing(function (string $local): string {
            $this->gmtConversions++;
            return gmdate('Y-m-d H:i:s', strtotime($local . ' +0000') - self::SITE_OFFSET);
        });

        WP_Mock::userFunction('wp_insert_comment')->andReturnUsing(function (array $comment): int {
            $this->insertedComments[] = $comment;
            return 900 + count($this->insertedComments);
        });
    }

    /**
     * @param array<int, array<string, string>> $comments
     */
    private function mockApiReturning(array $comments): void
    {
        $this->mockCommentsService
            ->shouldReceive('generateComments')
            ->andReturn(['success' => true, 'comments' => $comments, 'error' => null]);
    }
}
