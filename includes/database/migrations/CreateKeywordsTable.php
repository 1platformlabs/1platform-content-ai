<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiCreateKeywordsTable {

    private ContaiDatabase $db;
    private string $tableName = 'contai_keywords';

    public function __construct() {
        $this->db = ContaiDatabase::getInstance();
    }

    public function up(): bool {
        if ($this->db->tableExists($this->tableName)) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);
        $charsetCollate = $this->db->getCharsetCollate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            original_keyword VARCHAR(255) NULL DEFAULT NULL,
            title VARCHAR(500) NOT NULL DEFAULT '',
            original_title VARCHAR(500) NULL DEFAULT NULL,
            volume INT(11) NOT NULL DEFAULT 0,
            url VARCHAR(2048) NOT NULL DEFAULT '',
            post_url VARCHAR(2048) NULL DEFAULT NULL,
            post_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            category_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            status ENUM('active', 'inactive', 'pending', 'processing', 'done') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_keyword (keyword),
            KEY idx_keyword (keyword),
            KEY idx_status (status),
            KEY idx_volume (volume),
            KEY idx_deleted_at (deleted_at),
            KEY idx_created_at (created_at),
            KEY idx_post_id (post_id),
            KEY idx_category_id (category_id)
        ) {$charsetCollate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        return $this->db->tableExists($this->tableName);
    }

    public function down(): bool {
        if (!$this->db->tableExists($this->tableName)) {
            return true;
        }

        $table = $this->db->getTableName($this->tableName);
        $sql = "DROP TABLE IF EXISTS {$table};";

        return $this->db->query($sql);
    }

    public function getTableName(): string {
        return $this->tableName;
    }
}
