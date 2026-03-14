<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiCreateJobsTable
{
    private ContaiDatabase $db;
    private string $tableName = 'contai_jobs';

    public function __construct()
    {
        $this->db = ContaiDatabase::getInstance();
    }

    public function up(): bool
    {
        if ($this->db->tableExists($this->tableName)) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);
        $charsetCollate = $this->db->getCharsetCollate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(100) NOT NULL,
            status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
            payload TEXT NOT NULL,
            priority INT(11) NOT NULL DEFAULT 0,
            attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_job_type (job_type),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_created_at (created_at),
            KEY idx_status_priority (status, priority DESC)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        return $this->db->tableExists($this->tableName);
    }

    public function down(): bool
    {
        if (!$this->db->tableExists($this->tableName)) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);
        $sql = "DROP TABLE IF EXISTS {$table};";

        return $this->db->query($sql);
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }
}
