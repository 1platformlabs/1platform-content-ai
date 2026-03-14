<?php

namespace ContAI\Tests\Unit\Database;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use Database;

class DatabaseTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->resetDatabaseSingleton();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function resetDatabaseSingleton(): void {
        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function createMockWpdb(): object {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        return $wpdb;
    }

    private function setupGlobalWpdb(object $wpdb): void {
        $GLOBALS['wpdb'] = $wpdb;
    }

    private function getInstanceWithMock(): array {
        $wpdb = $this->createMockWpdb();
        $this->setupGlobalWpdb($wpdb);
        $db = Database::getInstance();
        return [$db, $wpdb];
    }

    public function test_get_instance_returns_singleton(): void {
        [$db1] = $this->getInstanceWithMock();
        $db2 = Database::getInstance();

        $this->assertSame($db1, $db2);
    }

    public function test_get_prefix_returns_wpdb_prefix(): void {
        [$db] = $this->getInstanceWithMock();

        $this->assertSame('wp_', $db->getPrefix());
    }

    public function test_get_table_name_prepends_prefix(): void {
        [$db] = $this->getInstanceWithMock();

        $this->assertSame('wp_contai_jobs', $db->getTableName('contai_jobs'));
    }

    public function test_get_wpdb_returns_wpdb_instance(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $this->assertSame($wpdb, $db->getWpdb());
    }

    public function test_insert_returns_insert_id_on_success(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->insert_id = 42;
        $wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_contai_jobs', ['status' => 'pending'], [])
            ->andReturn(1);

        $id = $db->insert('contai_jobs', ['status' => 'pending']);

        $this->assertSame(42, $id);
    }

    public function test_insert_returns_zero_on_failure(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        WP_Mock::userFunction('contai_log')->andReturn(null);

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        $id = $db->insert('contai_jobs', ['status' => 'pending']);

        $this->assertSame(0, $id);
    }

    public function test_update_delegates_to_wpdb(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('update')
            ->once()
            ->with('wp_contai_jobs', ['status' => 'done'], ['id' => 1], [], [])
            ->andReturn(1);

        $result = $db->update('contai_jobs', ['status' => 'done'], ['id' => 1]);

        $this->assertSame(1, $result);
    }

    public function test_delete_delegates_to_wpdb(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_contai_jobs', ['id' => 1], [])
            ->andReturn(1);

        $result = $db->delete('contai_jobs', ['id' => 1]);

        $this->assertSame(1, $result);
    }

    public function test_query_returns_true_on_success(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('query')
            ->once()
            ->with('UPDATE wp_contai_jobs SET status = "done"')
            ->andReturn(1);

        $this->assertTrue($db->query('UPDATE wp_contai_jobs SET status = "done"'));
    }

    public function test_query_returns_false_on_failure(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(false);

        $this->assertFalse($db->query('INVALID SQL'));
    }

    public function test_get_results_returns_array(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $expected = [['id' => 1], ['id' => 2]];
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($expected);

        $this->assertSame($expected, $db->getResults('SELECT * FROM wp_contai_jobs'));
    }

    public function test_get_results_returns_empty_array_on_null(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(null);

        $this->assertSame([], $db->getResults('SELECT * FROM wp_contai_jobs'));
    }

    public function test_get_row_delegates_to_wpdb(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $expected = ['id' => 1, 'status' => 'pending'];
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($expected);

        $this->assertSame($expected, $db->getRow('SELECT * FROM wp_contai_jobs WHERE id = 1'));
    }

    public function test_get_row_returns_null_when_not_found(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $this->assertNull($db->getRow('SELECT * FROM wp_contai_jobs WHERE id = 999'));
    }

    public function test_get_var_delegates_to_wpdb(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('5');

        $this->assertSame('5', $db->getVar('SELECT COUNT(*) FROM wp_contai_jobs'));
    }

    public function test_prepare_delegates_to_wpdb(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->with('SELECT * FROM wp_contai_jobs WHERE id = %d', 1)
            ->andReturn('SELECT * FROM wp_contai_jobs WHERE id = 1');

        $result = $db->prepare('SELECT * FROM wp_contai_jobs WHERE id = %d', 1);

        $this->assertSame('SELECT * FROM wp_contai_jobs WHERE id = 1', $result);
    }

    public function test_table_exists_returns_true(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn("SHOW TABLES LIKE 'wp_contai_jobs'");

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('wp_contai_jobs');

        $this->assertTrue($db->tableExists('contai_jobs'));
    }

    public function test_table_exists_returns_false(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn("SHOW TABLES LIKE 'wp_contai_missing'");

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(null);

        $this->assertFalse($db->tableExists('contai_missing'));
    }

    public function test_get_last_error(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();
        $wpdb->last_error = 'Duplicate entry';

        $this->assertSame('Duplicate entry', $db->getLastError());
    }

    public function test_get_charset_collate(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('get_charset_collate')
            ->once()
            ->andReturn('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->assertSame(
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $db->getCharsetCollate()
        );
    }

    public function test_begin_transaction(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);

        $this->assertTrue($db->beginTransaction());
    }

    public function test_commit(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(true);

        $this->assertTrue($db->commit());
    }

    public function test_rollback(): void {
        [$db, $wpdb] = $this->getInstanceWithMock();

        $wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(true);

        $this->assertTrue($db->rollback());
    }

    public function test_wakeup_throws_exception(): void {
        [$db] = $this->getInstanceWithMock();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $db->__wakeup();
    }
}
