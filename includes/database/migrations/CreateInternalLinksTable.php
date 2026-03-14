<?php
/**
 * Create Internal Links Table Migration
 *
 * Creates the table for storing internal link relationships between posts.
 * Includes optimized indexes for performance.
 *
 * @package WPContentAI
 * @subpackage ContaiDatabase\Migrations
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiCreateInternalLinksTable {

    private ContaiDatabase $db;
    private string $tableName = 'contai_internal_links';

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
            source_post_id BIGINT(20) UNSIGNED NOT NULL,
            target_post_id BIGINT(20) UNSIGNED NOT NULL,
            keyword_id BIGINT(20) UNSIGNED NOT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_source_post (source_post_id),
            KEY idx_target_post (target_post_id),
            KEY idx_keyword (keyword_id),
            KEY idx_status (status),
            KEY idx_source_target (source_post_id, target_post_id),
            KEY idx_created_at (created_at),
            UNIQUE KEY unique_link (source_post_id, target_post_id, keyword_id)
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
