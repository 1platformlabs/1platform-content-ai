<?php

namespace ContAI\Tests\Unit\Models;

use WP_Mock;
use PHPUnit\Framework\TestCase;
use Keyword;

class KeywordTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_constructor_with_minimal_data(): void {
        $keyword = new Keyword(['keyword' => 'test']);

        $this->assertNull($keyword->getId());
        $this->assertSame('pending', $keyword->getStatus());
        $this->assertSame('test', $keyword->getKeyword());
    }

    public function test_constructor_fills_from_array(): void {
        $data = [
            'id' => '5',
            'keyword' => 'seo tips',
            'original_keyword' => 'SEO Tips',
            'title' => 'Best SEO Tips',
            'original_title' => 'Best SEO Tips 2025',
            'volume' => '1500',
            'url' => 'https://example.com/seo-tips',
            'post_url' => 'https://example.com/seo-tips-post',
            'post_id' => '42',
            'category_id' => '3',
            'status' => 'active',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
            'deleted_at' => null,
        ];

        $keyword = new Keyword($data);

        $this->assertSame(5, $keyword->getId());
        $this->assertSame('seo tips', $keyword->getKeyword());
        $this->assertSame('SEO Tips', $keyword->getOriginalKeyword());
        $this->assertSame('Best SEO Tips', $keyword->getTitle());
        $this->assertSame(1500, $keyword->getVolume());
        $this->assertSame('https://example.com/seo-tips', $keyword->getUrl());
        $this->assertSame(42, $keyword->getPostId());
        $this->assertSame(3, $keyword->getCategoryId());
        $this->assertSame('active', $keyword->getStatus());
    }

    public function test_validate_returns_empty_for_valid_keyword(): void {
        $keyword = new Keyword([
            'keyword' => 'test keyword',
            'volume' => 100,
            'status' => 'active',
        ]);

        $this->assertEmpty($keyword->validate());
        $this->assertTrue($keyword->isValid());
    }

    public function test_validate_returns_error_for_empty_keyword(): void {
        $keyword = new Keyword([
            'keyword' => '',
            'volume' => 100,
            'status' => 'active',
        ]);

        $errors = $keyword->validate();
        $this->assertContains('Keyword is required', $errors);
        $this->assertFalse($keyword->isValid());
    }

    public function test_validate_returns_error_for_negative_volume(): void {
        $keyword = new Keyword([
            'keyword' => 'test',
            'volume' => -1,
            'status' => 'active',
        ]);

        $errors = $keyword->validate();
        $this->assertContains('Volume must be a positive number', $errors);
    }

    public function test_validate_returns_error_for_invalid_status(): void {
        $keyword = new Keyword([
            'keyword' => 'test',
            'volume' => 100,
            'status' => 'invalid_status',
        ]);

        $errors = $keyword->validate();
        $this->assertContains('Invalid status', $errors);
    }

    public function test_set_keyword_sanitizes_input(): void {
        WP_Mock::userFunction('sanitize_text_field')
            ->once()
            ->with('<script>alert("xss")</script>keyword')
            ->andReturn('keyword');

        $keyword = new Keyword();
        $keyword->setKeyword('<script>alert("xss")</script>keyword');

        $this->assertSame('keyword', $keyword->getKeyword());
    }

    public function test_set_title_sanitizes_input(): void {
        WP_Mock::userFunction('sanitize_text_field')
            ->once()
            ->with('Test Title')
            ->andReturn('Test Title');

        $keyword = new Keyword();
        $keyword->setTitle('Test Title');

        $this->assertSame('Test Title', $keyword->getTitle());
    }

    public function test_set_url_sanitizes_input(): void {
        WP_Mock::userFunction('esc_url_raw')
            ->once()
            ->with('https://example.com/test')
            ->andReturn('https://example.com/test');

        $keyword = new Keyword();
        $keyword->setUrl('https://example.com/test');

        $this->assertSame('https://example.com/test', $keyword->getUrl());
    }

    public function test_set_volume_enforces_minimum_zero(): void {
        $keyword = new Keyword();
        $keyword->setVolume(-10);

        $this->assertSame(0, $keyword->getVolume());
    }

    public function test_set_volume_accepts_positive_value(): void {
        $keyword = new Keyword();
        $keyword->setVolume(500);

        $this->assertSame(500, $keyword->getVolume());
    }

    public function test_set_post_id_rejects_zero(): void {
        $keyword = new Keyword();
        $keyword->setPostId(0);

        $this->assertNull($keyword->getPostId());
    }

    public function test_set_post_id_rejects_negative(): void {
        $keyword = new Keyword();
        $keyword->setPostId(-5);

        $this->assertNull($keyword->getPostId());
    }

    public function test_set_post_id_accepts_positive(): void {
        $keyword = new Keyword();
        $keyword->setPostId(42);

        $this->assertSame(42, $keyword->getPostId());
    }

    public function test_set_post_id_accepts_null(): void {
        $keyword = new Keyword();
        $keyword->setPostId(null);

        $this->assertNull($keyword->getPostId());
    }

    public function test_set_category_id_rejects_zero(): void {
        $keyword = new Keyword();
        $keyword->setCategoryId(0);

        $this->assertNull($keyword->getCategoryId());
    }

    public function test_set_status_ignores_invalid_status(): void {
        $keyword = new Keyword(['keyword' => 'test', 'status' => 'active']);
        $keyword->setStatus('nonexistent');

        $this->assertSame('active', $keyword->getStatus());
    }

    /**
     * @dataProvider validStatusProvider
     */
    public function test_set_status_accepts_valid_statuses(string $status): void {
        $keyword = new Keyword(['keyword' => 'test']);
        $keyword->setStatus($status);

        $this->assertSame($status, $keyword->getStatus());
    }

    public function validStatusProvider(): array {
        return [
            'active' => ['active'],
            'inactive' => ['inactive'],
            'pending' => ['pending'],
            'processing' => ['processing'],
            'done' => ['done'],
            'failed' => ['failed'],
        ];
    }

    public function test_is_deleted_returns_true_when_deleted_at_set(): void {
        $keyword = new Keyword(['keyword' => 'test', 'deleted_at' => '2025-01-01 00:00:00']);

        $this->assertTrue($keyword->isDeleted());
    }

    public function test_is_deleted_returns_false_when_deleted_at_null(): void {
        $keyword = new Keyword(['keyword' => 'test', 'deleted_at' => null]);

        $this->assertFalse($keyword->isDeleted());
    }

    public function test_is_done_returns_true_when_done_and_not_deleted(): void {
        $keyword = new Keyword(['keyword' => 'test', 'status' => 'done', 'deleted_at' => null]);

        $this->assertTrue($keyword->isDone());
    }

    public function test_is_done_returns_false_when_done_but_deleted(): void {
        $keyword = new Keyword(['keyword' => 'test', 'status' => 'done', 'deleted_at' => '2025-01-01']);

        $this->assertFalse($keyword->isDone());
    }

    public function test_to_array_returns_all_fields(): void {
        $data = [
            'id' => '1',
            'keyword' => 'test',
            'title' => 'Test Title',
            'volume' => '100',
            'url' => 'https://example.com',
            'status' => 'active',
        ];

        $keyword = new Keyword($data);
        $array = $keyword->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame('test', $array['keyword']);
        $this->assertSame('Test Title', $array['title']);
        $this->assertSame(100, $array['volume']);
        $this->assertArrayHasKey('deleted_at', $array);
    }

    public function test_to_db_array_excludes_id_when_null(): void {
        $keyword = new Keyword(['keyword' => 'test', 'status' => 'active']);
        $dbArray = $keyword->toDbArray();

        $this->assertArrayNotHasKey('id', $dbArray);
    }

    public function test_to_db_array_includes_id_when_set(): void {
        $keyword = new Keyword(['id' => '5', 'keyword' => 'test', 'status' => 'active']);
        $dbArray = $keyword->toDbArray();

        $this->assertArrayHasKey('id', $dbArray);
        $this->assertSame(5, $dbArray['id']);
    }

    public function test_set_original_keyword_with_null(): void {
        WP_Mock::userFunction('sanitize_text_field')->andReturnArg(0);

        $keyword = new Keyword();
        $keyword->setOriginalKeyword(null);

        $this->assertNull($keyword->getOriginalKeyword());
    }

    public function test_set_post_url_with_null(): void {
        WP_Mock::userFunction('esc_url_raw')->andReturnArg(0);

        $keyword = new Keyword();
        $keyword->setPostUrl(null);

        $this->assertNull($keyword->getPostUrl());
    }

    public function test_fill_from_array_handles_missing_keys(): void {
        $keyword = new Keyword(['keyword' => 'placeholder']);
        $keyword->fillFromArray([]);

        $this->assertNull($keyword->getId());
        $this->assertSame('', $keyword->getKeyword());
        $this->assertSame('', $keyword->getTitle());
        $this->assertSame(0, $keyword->getVolume());
        $this->assertSame('', $keyword->getUrl());
        $this->assertSame('pending', $keyword->getStatus());
    }
}
