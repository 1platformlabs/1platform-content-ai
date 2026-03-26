<?php

namespace ContAI\Tests\Unit\Database;

use WP_Mock;
use Mockery;
use PHPUnit\Framework\TestCase;
use ContaiMigrationRunner;

class MigrationRunnerTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    private function createMigration(bool $up_result = true, bool $has_down = true, bool $down_result = true): object {
        $migration = Mockery::mock();
        $migration->shouldReceive('up')->andReturn($up_result)->byDefault();

        if ($has_down) {
            $migration->shouldReceive('down')->andReturn($down_result)->byDefault();
        }

        return $migration;
    }

    // ── No pending migrations ────────────────────────────────────

    public function test_run_returns_success_when_no_migrations_registered(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $runner = new ContaiMigrationRunner();
        $result = $runner->run();

        $this->assertTrue($result['success']);
        $this->assertSame('No pending migrations', $result['message']);
        $this->assertSame(0, $result['version']);
        $this->assertEmpty($result['applied']);
    }

    public function test_run_skips_already_applied_migrations(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(3);

        $migration1 = $this->createMigration();
        $migration1->shouldNotReceive('up');
        $migration2 = $this->createMigration();
        $migration2->shouldNotReceive('up');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);

        $result = $runner->run();

        $this->assertTrue($result['success']);
        $this->assertSame('No pending migrations', $result['message']);
    }

    // ── Successful migrations ────────────────────────────────────

    public function test_run_applies_all_pending_migrations_in_order(): void {
        $call_order = [];

        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $migration1 = Mockery::mock();
        $migration1->shouldReceive('up')->once()->andReturnUsing(function () use (&$call_order) {
            $call_order[] = 1;
            return true;
        });

        $migration2 = Mockery::mock();
        $migration2->shouldReceive('up')->once()->andReturnUsing(function () use (&$call_order) {
            $call_order[] = 2;
            return true;
        });

        $migration3 = Mockery::mock();
        $migration3->shouldReceive('up')->once()->andReturnUsing(function () use (&$call_order) {
            $call_order[] = 3;
            return true;
        });

        WP_Mock::userFunction('update_option')->times(3);
        WP_Mock::userFunction('delete_option')
            ->with('contai_migration_error')
            ->once();

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);
        $runner->register(3, $migration3);

        $result = $runner->run();

        $this->assertTrue($result['success']);
        $this->assertSame([1, 2, 3], $result['applied']);
        $this->assertSame([1, 2, 3], $call_order);
    }

    public function test_run_updates_version_after_each_migration(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $versions_set = [];

        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$versions_set) {
                if ($key === 'contai_db_version') {
                    $versions_set[] = $value;
                }
                return true;
            });

        WP_Mock::userFunction('delete_option')
            ->with('contai_migration_error');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $this->createMigration());
        $runner->register(2, $this->createMigration());

        $runner->run();

        $this->assertSame([1, 2], $versions_set);
    }

    public function test_run_only_applies_migrations_after_current_version(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(2);

        $migration1 = $this->createMigration();
        $migration1->shouldNotReceive('up');

        $migration2 = $this->createMigration();
        $migration2->shouldNotReceive('up');

        $migration3 = Mockery::mock();
        $migration3->shouldReceive('up')->once()->andReturn(true);

        WP_Mock::userFunction('update_option')->times(1);
        WP_Mock::userFunction('delete_option')
            ->with('contai_migration_error')
            ->once();

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);
        $runner->register(3, $migration3);

        $result = $runner->run();

        $this->assertTrue($result['success']);
        $this->assertSame([3], $result['applied']);
    }

    // ── Failed migrations with rollback ──────────────────────────

    public function test_run_rolls_back_applied_migrations_on_failure(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $tracker = new \stdClass();
        $tracker->down_called = [];

        $migration1 = new class($tracker) {
            private object $tracker;
            public function __construct(object $t) { $this->tracker = $t; }
            public function up(): bool { return true; }
            public function down(): bool { $this->tracker->down_called[] = 1; return true; }
        };

        $migration2 = new class($tracker) {
            private object $tracker;
            public function __construct(object $t) { $this->tracker = $t; }
            public function up(): bool { return true; }
            public function down(): bool { $this->tracker->down_called[] = 2; return true; }
        };

        $migration3 = new class {
            public function up(): bool { return false; }
            public function down(): bool { return true; }
        };

        WP_Mock::userFunction('update_option');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);
        $runner->register(3, $migration3);

        $result = $runner->run();

        $this->assertFalse($result['success']);
        $this->assertSame(3, $result['failed_at']);
        $this->assertEmpty($result['applied']);
        $this->assertSame([2, 1], $tracker->down_called);
    }

    public function test_run_rolls_back_in_reverse_order(): void {
        $tracker = new \stdClass();
        $tracker->order = [];

        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $migration1 = new class($tracker) {
            private object $tracker;
            public function __construct(object $t) { $this->tracker = $t; }
            public function up(): bool { return true; }
            public function down(): bool { $this->tracker->order[] = 1; return true; }
        };

        $migration2 = new class($tracker) {
            private object $tracker;
            public function __construct(object $t) { $this->tracker = $t; }
            public function up(): bool { return true; }
            public function down(): bool { $this->tracker->order[] = 2; return true; }
        };

        $migration3 = new class {
            public function up(): bool { return false; }
        };

        WP_Mock::userFunction('update_option');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);
        $runner->register(3, $migration3);

        $runner->run();

        $this->assertSame([2, 1], $tracker->order);
    }

    public function test_run_stores_error_on_failure(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $migration1 = Mockery::mock();
        $migration1->shouldReceive('up')->once()->andReturn(false);

        $stored_error = null;
        WP_Mock::userFunction('update_option')
            ->withArgs(function ($key, $value) use (&$stored_error) {
                if ($key === 'contai_migration_error') {
                    $stored_error = $value;
                }
                return true;
            });

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);

        $runner->run();

        $this->assertNotNull($stored_error);
        $this->assertStringContainsString('v1', $stored_error);
        $this->assertStringContainsString('failed', $stored_error);
    }

    public function test_run_handles_exception_in_up_as_failure(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $migration1 = Mockery::mock();
        $migration1->shouldReceive('up')->once()->andThrow(new \RuntimeException('DB connection lost'));

        WP_Mock::userFunction('update_option');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);

        $result = $runner->run();

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['failed_at']);
    }

    public function test_run_continues_rollback_if_down_throws_exception(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $tracker = new \stdClass();
        $tracker->order = [];

        $migration1 = new class($tracker) {
            private object $tracker;
            public function __construct(object $t) { $this->tracker = $t; }
            public function up(): bool { return true; }
            public function down(): bool { $this->tracker->order[] = 1; return true; }
        };

        $migration2 = new class {
            public function up(): bool { return true; }
            public function down(): bool { throw new \RuntimeException('Cannot drop'); }
        };

        $migration3 = new class {
            public function up(): bool { return false; }
        };

        WP_Mock::userFunction('update_option');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);
        $runner->register(3, $migration3);

        $result = $runner->run();

        $this->assertFalse($result['success']);
        // migration1's down() should still be called even though migration2's down() threw
        $this->assertSame([1], $tracker->order);
    }

    public function test_run_skips_rollback_for_migration_without_down_method(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        // Migration without down() method
        $migration1 = new class {
            public function up(): bool { return true; }
        };

        $migration2 = new class {
            public function up(): bool { return false; }
        };

        WP_Mock::userFunction('update_option');

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $migration1);
        $runner->register(2, $migration2);

        $result = $runner->run();

        $this->assertFalse($result['success']);
    }

    // ── Version tracking ─────────────────────────────────────────

    public function test_get_current_version_returns_stored_value(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(5);

        $runner = new ContaiMigrationRunner();

        $this->assertSame(5, $runner->getCurrentVersion());
    }

    public function test_get_current_version_returns_zero_when_not_set(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        $runner = new ContaiMigrationRunner();

        $this->assertSame(0, $runner->getCurrentVersion());
    }

    // ── Error state methods ──────────────────────────────────────

    public function test_get_error_returns_stored_error(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_migration_error', '')
            ->andReturn('Migration failed at v3');

        $this->assertSame('Migration failed at v3', ContaiMigrationRunner::getError());
    }

    public function test_get_error_returns_null_when_no_error(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_migration_error', '')
            ->andReturn('');

        $this->assertNull(ContaiMigrationRunner::getError());
    }

    public function test_has_error_returns_true_when_error_exists(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_migration_error', '')
            ->andReturn('Something failed');

        $this->assertTrue(ContaiMigrationRunner::hasError());
    }

    public function test_has_error_returns_false_when_no_error(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_migration_error', '')
            ->andReturn('');

        $this->assertFalse(ContaiMigrationRunner::hasError());
    }

    public function test_clear_stored_error_deletes_option(): void {
        WP_Mock::userFunction('delete_option')
            ->with('contai_migration_error')
            ->once();

        ContaiMigrationRunner::clearStoredError();

        // If we get here without exception, the assertion passed via WP_Mock expectation
        $this->assertTrue(true);
    }

    // ── Successful run clears previous error ─────────────────────

    public function test_successful_run_clears_previous_error(): void {
        WP_Mock::userFunction('get_option')
            ->with('contai_db_version', 0)
            ->andReturn(0);

        WP_Mock::userFunction('update_option');

        WP_Mock::userFunction('delete_option')
            ->with('contai_migration_error')
            ->once();

        $runner = new ContaiMigrationRunner();
        $runner->register(1, $this->createMigration());

        $result = $runner->run();

        $this->assertTrue($result['success']);
    }

    // ── Registration ─────────────────────────────────────────────

    public function test_get_migrations_returns_sorted_by_version(): void {
        $m1 = $this->createMigration();
        $m3 = $this->createMigration();
        $m2 = $this->createMigration();

        $runner = new ContaiMigrationRunner();
        $runner->register(3, $m3);
        $runner->register(1, $m1);
        $runner->register(2, $m2);

        $migrations = $runner->getMigrations();

        $this->assertSame([1, 2, 3], array_keys($migrations));
    }
}
