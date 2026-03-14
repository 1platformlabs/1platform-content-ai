<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiCreateAPILogsTable {

    private ContaiDatabase $db;
    private string $tableName = 'contai_api_logs';

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
            method VARCHAR(100) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            request_body LONGTEXT DEFAULT NULL,
            response_code INT(11) DEFAULT NULL,
            response_body LONGTEXT DEFAULT NULL,
            duration DECIMAL(10,4) DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_method (method),
            KEY idx_response_code (response_code),
            KEY idx_created_at (created_at),
            KEY idx_url (url(255))
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
