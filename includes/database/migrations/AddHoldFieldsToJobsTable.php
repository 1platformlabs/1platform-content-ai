<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

/**
 * Migration v7 — Add hold_id and credits_released columns to wp_contai_jobs.
 *
 * Backs the Authorize+Capture billing path introduced by the 1Platform API in
 * v2.36.0 of the plugin. Each row may now carry the BalanceHold ObjectId
 * returned by the API as well as the released-credits flag emitted on failure.
 *
 * Idempotent: re-running up() is a no-op once both columns exist.
 */
class ContaiAddHoldFieldsToJobsTable
{
    private ContaiDatabase $db;
    private string $tableName = 'contai_jobs';

    public function __construct()
    {
        $this->db = ContaiDatabase::getInstance();
    }

    public function up(): bool
    {
        // If the base table is missing, ContaiCreateJobsTable hasn't run yet
        // and this migration cannot be applied — bail safely.
        if (!$this->db->tableExists($this->tableName)) {
            return false;
        }

        $hasHoldId = $this->columnExists('hold_id');
        $hasCreditsReleased = $this->columnExists('credits_released');
        $hasHoldIdIndex = $this->indexExists('idx_hold_id');

        if ($hasHoldId && $hasCreditsReleased && $hasHoldIdIndex) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);

        if (!$hasHoldId) {
            $sql = "ALTER TABLE {$table} ADD COLUMN hold_id VARCHAR(64) NULL AFTER error_message";
            if (!$this->db->query($sql)) {
                return false;
            }
        }

        if (!$hasCreditsReleased) {
            $sql = "ALTER TABLE {$table} ADD COLUMN credits_released TINYINT(1) NOT NULL DEFAULT 0 AFTER hold_id";
            if (!$this->db->query($sql)) {
                return false;
            }
        }

        if (!$hasHoldIdIndex) {
            $sql = "ALTER TABLE {$table} ADD KEY idx_hold_id (hold_id)";
            if (!$this->db->query($sql)) {
                return false;
            }
        }

        return $this->columnExists('hold_id') && $this->columnExists('credits_released');
    }

    public function down(): bool
    {
        if (!$this->db->tableExists($this->tableName)) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);
        $success = true;

        if ($this->indexExists('idx_hold_id')) {
            $success = $this->db->query("ALTER TABLE {$table} DROP INDEX idx_hold_id") && $success;
        }

        if ($this->columnExists('credits_released')) {
            $success = $this->db->query("ALTER TABLE {$table} DROP COLUMN credits_released") && $success;
        }

        if ($this->columnExists('hold_id')) {
            $success = $this->db->query("ALTER TABLE {$table} DROP COLUMN hold_id") && $success;
        }

        return $success;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    private function columnExists(string $column): bool
    {
        $table = $this->db->getTableName($this->tableName);
        $sql = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column);
        return $this->db->getVar($sql) !== null;
    }

    private function indexExists(string $index): bool
    {
        $table = $this->db->getTableName($this->tableName);
        $sql = $this->db->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index);
        return $this->db->getRow($sql) !== null;
    }
}
