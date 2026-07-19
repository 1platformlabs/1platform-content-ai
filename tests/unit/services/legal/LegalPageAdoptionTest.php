<?php

namespace ContAI\Tests\Unit\Services\Legal;

use ContaiLegalPagesGenerator;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Mock;

require_once __DIR__ . '/../../../../includes/services/legal/LegalPagesGenerator.php';
require_once __DIR__ . '/../../../../includes/helpers/site-warnings.php';

/**
 * A legal page that already exists must be adopted, not orphaned (#48).
 *
 * The footer menu selects candidates strictly by the '_contai_legal_source'
 * meta and 'post_status' => 'publish'. processPage() used to walk away from any
 * page it found under the target slug — warning "already exists. Not replaced."
 * — without stamping that meta, so the page could never be linked.
 *
 * That is the common case, not an edge case. get_page_by_path() has no
 * post_status filter, and a stock WordPress install ships a DRAFT page whose
 * slug is 'privacy-policy' (wp-admin/includes/upgrade.php:399, :404). So on an
 * English stock install the FIRST legal page the wizard tried to create was
 * skipped, never linked, and then duplicated as 'privacy-policy-2' by
 * ensureRequiredLegalPages().
 */
class LegalPageAdoptionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{0: int, 1: string, 2: mixed}> */
    private array $metaWrites = [];

    /** @var array<int, array<string, mixed>> */
    private array $postUpdates = [];

    private int $inserts = 0;

    private $apiClient;
    private $legalInfoService;

    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        $this->options = [];
        $this->metaWrites = [];
        $this->postUpdates = [];
        $this->inserts = 0;

        $this->apiClient = Mockery::mock(\ContaiLegalPagesAPIClient::class);
        $this->legalInfoService = Mockery::mock(\ContaiLegalInfoService::class);

        $this->legalInfoService->shouldReceive('getLegalInfo')->andReturn([
            'owner' => 'Owner', 'email' => 'a@b.com', 'address' => 'Addr', 'activity' => 'Act',
        ]);
        $this->legalInfoService->shouldReceive('validateLegalInfo')->andReturn([]);

        foreach (['sanitize_text_field', 'sanitize_email', 'esc_html', 'esc_html__', 'wp_kses_post', '__'] as $passthrough) {
            WP_Mock::userFunction($passthrough, ['return' => function ($v) { return $v; }]);
        }
        WP_Mock::userFunction('sanitize_title', [
            'return' => function ($v) { return strtolower(str_replace(' ', '-', $v)); },
        ]);
        WP_Mock::userFunction('current_time', ['return' => '2026-07-19 12:00:00']);
        WP_Mock::userFunction('is_wp_error', ['return' => false]);

        WP_Mock::userFunction('get_option', [
            'return' => function ($name, $default = false) {
                return $this->options[$name] ?? $default;
            },
        ]);
        WP_Mock::userFunction('update_option', [
            'return' => function ($name, $value) {
                $this->options[$name] = $value;
                return true;
            },
        ]);
        WP_Mock::userFunction('update_post_meta', [
            'return' => function ($id, $key, $value) {
                $this->metaWrites[] = [$id, $key, $value];
                return true;
            },
        ]);
        WP_Mock::userFunction('wp_update_post', [
            'return' => function ($args) {
                $this->postUpdates[] = $args;
                return $args['ID'];
            },
        ]);
        WP_Mock::userFunction('wp_insert_post', [
            'return' => function () {
                $this->inserts++;
                return 900 + $this->inserts;
            },
        ]);
        WP_Mock::userFunction('wp_untrash_post', ['return' => true]);
    }

    public function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function apiReturnsPrivacyPolicy(): void
    {
        $response = Mockery::mock(\ContaiOnePlatformResponse::class);
        $response->shouldReceive('isSuccess')->andReturn(true);
        $response->shouldReceive('getData')->andReturn([
            'pages' => [
                'privacy-policy' => ['title' => 'Privacy Policy', 'content' => '<p>Privacy</p>'],
            ],
            'meta' => ['slug_map' => ['privacy-policy' => 'privacy-policy']],
            'lang' => 'en',
        ]);
        $this->apiClient->shouldReceive('generateLegalPages')->andReturn($response);
    }

    /** @return array<int, string> Meta keys written against the stock draft page. */
    private function metaKeysFor(int $postId): array
    {
        $keys = [];
        foreach ($this->metaWrites as [$id, $key, $_value]) {
            if ($id === $postId) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public function test_the_stock_wordpress_privacy_draft_is_adopted_and_published(): void
    {
        $this->apiReturnsPrivacyPolicy();

        // Core's stock draft, exactly as wp-admin/includes/upgrade.php seeds it.
        $stockDraft = (object) [
            'ID' => 3,
            'post_status' => 'draft',
            'post_title' => 'Privacy Policy',
            'post_name' => 'privacy-policy',
        ];

        WP_Mock::userFunction('get_page_by_path', ['return' => $stockDraft]);
        WP_Mock::userFunction('get_posts', [
            'return' => function ($args) use ($stockDraft) {
                // The ensure step looks up by our own meta and finds the page we
                // just stamped.
                if (($args['meta_key'] ?? '') === '_contai_legal_key') {
                    return ($args['meta_value'] ?? '') === 'privacy-policy'
                        ? [(object) ['ID' => 3, 'post_status' => 'publish']]
                        : [(object) ['ID' => 4, 'post_status' => 'publish']];
                }
                return [];
            },
        ]);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $generator->generate();

        $this->assertContains(
            '_contai_legal_source',
            $this->metaKeysFor(3),
            'Without this meta the footer menu can never link the page (#48)'
        );
        $this->assertContains('_contai_legal_key', $this->metaKeysFor(3));

        $this->assertSame(
            [['ID' => 3, 'post_status' => 'publish']],
            $this->postUpdates,
            'A draft legal page is invisible to the footer menu, which filters on publish (#48)'
        );

        $this->assertSame(
            0,
            $this->inserts,
            "The page already exists — creating another one is what produced 'privacy-policy-2' (#48)"
        );
    }

    /**
     * The duplicate-creation half, which needs a key the API never returned.
     *
     * ensureRequiredLegalPages() looked a required page up ONLY by our own
     * '_contai_legal_key' meta. A page can exist under the target slug without
     * that meta — the stock WordPress 'privacy-policy' draft again — so the
     * lookup missed it and wp_insert_post() ran anyway. wp_unique_post_slug()
     * then produced 'privacy-policy-2' while the original stayed behind, and
     * the only mention was a warning nobody read (#48).
     */
    public function test_a_required_page_is_not_duplicated_when_one_exists_at_its_slug(): void
    {
        // The API returns only privacy-policy, so cookie-policy reaches the
        // ensure step having never been through processPage().
        $this->apiReturnsPrivacyPolicy();

        WP_Mock::userFunction('get_page_by_path', [
            'return' => function ($slug) {
                if ($slug === 'cookie-policy') {
                    // Pre-existing, no _contai_legal_key meta: invisible to the
                    // meta lookup, fatal to the slug.
                    return (object) [
                        'ID' => 42, 'post_status' => 'publish',
                        'post_title' => 'Cookie Policy', 'post_name' => 'cookie-policy',
                    ];
                }
                return (object) [
                    'ID' => 3, 'post_status' => 'publish',
                    'post_title' => 'Privacy Policy', 'post_name' => $slug,
                ];
            },
        ]);
        WP_Mock::userFunction('get_posts', [
            'return' => function ($args) {
                if (($args['meta_key'] ?? '') === '_contai_legal_key') {
                    // Only cookie-policy is missing by meta.
                    return ($args['meta_value'] ?? '') === 'cookie-policy'
                        ? []
                        : [(object) ['ID' => 3, 'post_status' => 'publish']];
                }
                return [];
            },
        ]);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $generator->generate();

        $this->assertSame(
            0,
            $this->inserts,
            "A page already sits at 'cookie-policy'; inserting produced 'cookie-policy-2' (#48)"
        );
        $this->assertContains(
            '_contai_legal_source',
            $this->metaKeysFor(42),
            'The pre-existing page must be adopted so the footer can link it'
        );
    }

    public function test_adopting_an_unpublished_page_leaves_a_durable_record(): void
    {
        $this->apiReturnsPrivacyPolicy();

        WP_Mock::userFunction('get_page_by_path', [
            'return' => (object) [
                'ID' => 3, 'post_status' => 'draft',
                'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy',
            ],
        ]);
        WP_Mock::userFunction('get_posts', [
            'return' => [(object) ['ID' => 3, 'post_status' => 'publish']],
        ]);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $generator->generate();

        $warnings = $this->options[CONTAI_SITE_WARNINGS_OPTION] ?? [];
        $steps = array_column($warnings, 'step');

        $this->assertContains(
            'legal page adopted',
            $steps,
            'Publishing an existing page is a real change and must not be silent'
        );
    }

    /** An already-published page is adopted for linking, but nothing is rewritten. */
    public function test_a_published_page_is_linked_without_being_touched(): void
    {
        $this->apiReturnsPrivacyPolicy();

        WP_Mock::userFunction('get_page_by_path', [
            'return' => (object) [
                'ID' => 7, 'post_status' => 'publish',
                'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy',
            ],
        ]);
        WP_Mock::userFunction('get_posts', [
            'return' => [(object) ['ID' => 7, 'post_status' => 'publish']],
        ]);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $generator->generate();

        $this->assertContains('_contai_legal_source', $this->metaKeysFor(7));
        $this->assertSame([], $this->postUpdates, "The owner's published page must not be rewritten");
        $this->assertSame(0, $this->inserts);
    }

    /**
     * Core renames a trashed post's slug to '<slug>__trashed' and stashes the
     * original in '_wp_desired_post_slug' (wp-includes/post.php:8390-8403),
     * while WP_Query matches 'name' exactly — so the old lookup by the bare
     * slug could never match and the whole trash branch was dead code (#48).
     */
    public function test_a_trashed_page_is_found_by_the_slug_core_actually_stores(): void
    {
        $this->apiReturnsPrivacyPolicy();

        $queries = [];
        WP_Mock::userFunction('get_page_by_path', ['return' => null]);
        WP_Mock::userFunction('get_posts', [
            'return' => function ($args) use (&$queries) {
                $queries[] = $args;
                if (($args['post_status'] ?? '') === 'trash'
                    && ($args['meta_key'] ?? '') === '_wp_desired_post_slug'
                    && ($args['meta_value'] ?? '') === 'privacy-policy') {
                    return [(object) [
                        'ID' => 55, 'post_status' => 'trash',
                        'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy__trashed',
                    ]];
                }
                if (($args['meta_key'] ?? '') === '_contai_legal_key') {
                    return [(object) ['ID' => 55, 'post_status' => 'publish']];
                }
                return [];
            },
        ]);

        $generator = new ContaiLegalPagesGenerator($this->apiClient, $this->legalInfoService);
        $generator->generate();

        $bareSlugTrashQueries = array_filter($queries, static function ($args) {
            return ($args['post_status'] ?? '') === 'trash' && ($args['name'] ?? '') === 'privacy-policy';
        });

        $this->assertSame(
            [],
            $bareSlugTrashQueries,
            "Querying trash by the bare slug can never match: core stored it as 'privacy-policy__trashed' (#48)"
        );

        $this->assertContains('_contai_legal_source', $this->metaKeysFor(55));
        $this->assertSame([['ID' => 55, 'post_status' => 'publish']], $this->postUpdates);
    }
}
