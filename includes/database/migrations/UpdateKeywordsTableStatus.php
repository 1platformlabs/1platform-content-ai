<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiUpdateKeywordsTableStatus {

    private ContaiDatabase $db;
    private string $tableName = 'contai_keywords';

    public function __construct() {
        $this->db = ContaiDatabase::getInstance();
    }

    public function up(): bool {
        if (!$this->db->tableExists($this->tableName)) {
            return false;
        }

        $table = $this->db->getTableName($this->tableName);

        $sql = "ALTER TABLE {$table}
                MODIFY COLUMN status ENUM('active', 'inactive', 'pending', 'processing', 'done')
                NOT NULL DEFAULT 'pending'";

        return $this->db->query($sql);
    }
}
