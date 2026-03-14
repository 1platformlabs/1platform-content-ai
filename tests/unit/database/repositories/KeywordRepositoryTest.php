<?php

namespace ContAI\Tests\Unit\Database\Repositories;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use Database;
use KeywordRepository;
use Keyword;

class KeywordRepositoryTest extends TestCase {

    private $dbMock;
    private $wpdbMock;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->setupDatabaseMock();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function setupDatabaseMock(): void {
        $this->wpdbMock = Mockery::mock('wpdb');
        $this->dbMock = Mockery::mock(Database::class);
        $this->dbMock->shouldReceive('getWpdb')->andReturn($this->wpdbMock);

        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, $this->dbMock);
    }

    private function mockCurrentTime(): void {
        WP_Mock::userFunction('current_time')
            ->andReturn('2025-01-15 10:00:00');
    }

    public function test_create_returns_id_for_valid_keyword(): void {
        $this->mockCurrentTime();

        $keyword = new Keyword([
            'keyword' => 'seo tips',
            'title' => 'Best SEO Tips',
            'volume' => 100,
            'url' => 'https://example.com',
            'status' => 'active',
        ]);

        $this->dbMock->shouldReceive('insert')
            ->once()
            ->with('contai_keywords', Mockery::type('array'), Mockery::type('array'))
            ->andReturn(5);

        $id = (new KeywordRepository())->create($keyword);

        $this->assertSame(5, $id);
        $this->assertSame(5, $keyword->getId());
    }

    public function test_create_returns_null_for_invalid_keyword(): void {
        $keyword = new Keyword([
            'keyword' => '',
            'status' => 'active',
        ]);

        $result = (new KeywordRepository())->create($keyword);

        $this->assertNull($result);
    }

    public function test_create_returns_null_on_db_failure(): void {
        $this->mockCurrentTime();

        $keyword = new Keyword([
            'keyword' => 'test',
            'title' => 'Test',
            'volume' => 100,
            'status' => 'active',
        ]);

        $this->dbMock->shouldReceive('insert')
            ->once()
            ->andReturn(0);

        $this->assertNull((new KeywordRepository())->create($keyword));
    }

    public function test_update_returns_false_for_null_id(): void {
        $keyword = new Keyword(['keyword' => 'test', 'status' => 'active']);

        $this->assertFalse((new KeywordRepository())->update($keyword));
    }

    public function test_update_returns_false_for_invalid_keyword(): void {
        $keyword = new Keyword(['id' => '5', 'keyword' => '', 'status' => 'active']);

        $this->assertFalse((new KeywordRepository())->update($keyword));
    }

    public function test_update_succeeds(): void {
        $this->mockCurrentTime();

        $keyword = new Keyword([
            'id' => '5',
            'keyword' => 'test',
            'title' => 'Test',
            'volume' => 100,
            'status' => 'active',
        ]);

        $this->dbMock->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $this->assertTrue((new KeywordRepository())->update($keyword));
    }

    public function test_delete_removes_keyword(): void {
        $this->dbMock->shouldReceive('delete')
            ->once()
            ->with('contai_keywords', ['id' => 5], ['%d'])
            ->andReturn(1);

        $this->assertTrue((new KeywordRepository())->delete(5));
    }

    public function test_delete_returns_false_when_not_found(): void {
        $this->dbMock->shouldReceive('delete')
            ->once()
            ->andReturn(0);

        $this->assertFalse((new KeywordRepository())->delete(999));
    }

    public function test_soft_delete(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('update')
            ->once()
            ->with(
                'contai_keywords',
                Mockery::on(fn($data) => $data['deleted_at'] === '2025-01-15 10:00:00'),
                ['id' => 5],
                Mockery::type('array'),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->assertTrue((new KeywordRepository())->softDelete(5));
    }

    public function test_restore_clears_deleted_at(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('update')
            ->once()
            ->with(
                'contai_keywords',
                Mockery::on(fn($data) => $data['deleted_at'] === null),
                ['id' => 5],
                Mockery::type('array'),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->assertTrue((new KeywordRepository())->restore(5));
    }

    public function test_find_by_id_returns_keyword(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')
            ->once()
            ->andReturn([
                'id' => '5',
                'keyword' => 'seo tips',
                'title' => 'SEO Tips',
                'volume' => '100',
                'status' => 'active',
            ]);

        $keyword = (new KeywordRepository())->findById(5);

        $this->assertInstanceOf(Keyword::class, $keyword);
        $this->assertSame(5, $keyword->getId());
        $this->assertSame('seo tips', $keyword->getKeyword());
    }

    public function test_find_by_id_returns_null_when_not_found(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')->andReturn(null);

        $this->assertNull((new KeywordRepository())->findById(999));
    }

    public function test_find_by_keyword(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')
            ->once()
            ->andReturn([
                'id' => '1',
                'keyword' => 'seo',
                'title' => 'SEO',
                'volume' => '500',
                'status' => 'active',
            ]);

        $keyword = (new KeywordRepository())->findByKeyword('seo');

        $this->assertInstanceOf(Keyword::class, $keyword);
        $this->assertSame('seo', $keyword->getKeyword());
    }

    public function test_find_by_keyword_returns_null(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getRow')->andReturn(null);

        $this->assertNull((new KeywordRepository())->findByKeyword('nonexistent'));
    }

    public function test_count_returns_integer(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('getVar')->andReturn('42');

        $this->assertSame(42, (new KeywordRepository())->count());
    }

    public function test_count_by_status(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('10');

        $this->assertSame(10, (new KeywordRepository())->countByStatus('active'));
    }

    public function test_exists_returns_true(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('1');

        $this->assertTrue((new KeywordRepository())->exists('seo'));
    }

    public function test_exists_returns_false(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getVar')->andReturn('0');

        $this->assertFalse((new KeywordRepository())->exists('nonexistent'));
    }

    public function test_update_status_with_valid_status(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $this->assertTrue((new KeywordRepository())->updateStatus(5, 'done'));
    }

    public function test_update_status_rejects_invalid_status(): void {
        $this->assertFalse((new KeywordRepository())->updateStatus(5, 'invalid'));
    }

    public function test_bulk_insert(): void {
        $this->mockCurrentTime();

        $keywords = [
            ['keyword' => 'seo', 'title' => 'SEO', 'volume' => 100, 'status' => 'active'],
            ['keyword' => 'wordpress', 'title' => 'WP', 'volume' => 200, 'status' => 'active'],
        ];

        $this->dbMock->shouldReceive('insert')
            ->twice()
            ->andReturn(1, 2);

        $result = (new KeywordRepository())->bulkInsert($keywords);

        $this->assertSame(2, $result);
    }

    public function test_search_uses_like_pattern(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');

        $this->wpdbMock->shouldReceive('esc_like')
            ->with('seo')
            ->andReturn('seo');

        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getResults')->andReturn([]);

        $results = (new KeywordRepository())->search('seo');

        $this->assertIsArray($results);
    }

    public function test_find_all_returns_keywords(): void {
        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('getResults')->andReturn([
            ['id' => '1', 'keyword' => 'seo', 'status' => 'active'],
            ['id' => '2', 'keyword' => 'wordpress', 'status' => 'pending'],
        ]);

        $results = (new KeywordRepository())->findAll(100, 0);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(Keyword::class, $results[0]);
    }

    public function test_reset_active_keywords_by_ids_returns_zero_for_empty(): void {
        $this->assertSame(0, (new KeywordRepository())->resetActiveKeywordsByIds([]));
    }

    public function test_reset_active_keywords_by_ids(): void {
        $this->mockCurrentTime();

        $this->dbMock->shouldReceive('getTableName')->andReturn('wp_contai_keywords');
        $this->dbMock->shouldReceive('prepare')->andReturn('query');
        $this->dbMock->shouldReceive('query')->andReturn(true);

        $result = (new KeywordRepository())->resetActiveKeywordsByIds([1, 2, 3]);

        $this->assertNotSame(0, $result);
    }
}
