<?php

if (!defined('ABSPATH')) exit;

class ContaiDatabase {

    private static ?ContaiDatabase $instance = null;
    private wpdb $wpdb;
    private string $prefix;

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    public static function getInstance(): ContaiDatabase {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getWpdb(): wpdb {
        return $this->wpdb;
    }

    public function getPrefix(): string {
        return $this->prefix;
    }

    public function getTableName(string $tableName): string {
        return $this->prefix . $tableName;
    }

    public function tableExists(string $tableName): bool {
        $table = $this->getTableName($tableName);
        $query = $this->wpdb->prepare('SHOW TABLES LIKE %s', $table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared on the line above.
        return $this->wpdb->get_var($query) === $table;
    }

    public function insert(string $table, array $data, array $format = []): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert($this->getTableName($table), $data, $format);
        if ($result === false) {
            contai_log("ContaiDatabase insert failed for table {$table}: " . $this->wpdb->last_error);
            return 0;
        }
        return $this->wpdb->insert_id;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $whereFormat = []): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->update(
            $this->getTableName($table),
            $data,
            $where,
            $format,
            $whereFormat
        );
    }

    public function delete(string $table, array $where, array $whereFormat = []): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->delete(
            $this->getTableName($table),
            $where,
            $whereFormat
        );
    }

    public function query(string $query): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared by callers via $this->prepare().
        return $this->wpdb->query($query) !== false;
    }

    public function getResults(string $query, string $output = OBJECT): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared by callers via $this->prepare().
        return $this->wpdb->get_results($query, $output) ?? [];
    }

    public function getRow(string $query, string $output = OBJECT) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared by callers via $this->prepare().
        return $this->wpdb->get_row($query, $output);
    }

    public function getVar(string $query) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared by callers via $this->prepare().
        return $this->wpdb->get_var($query);
    }

    public function prepare(string $query, ...$args): string {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This is the prepare() wrapper; $query is parameterized by callers.
        return $this->wpdb->prepare($query, ...$args);
    }

    public function getLastError(): string {
        return $this->wpdb->last_error;
    }

    public function getCharsetCollate(): string {
        return $this->wpdb->get_charset_collate();
    }

    public function beginTransaction(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query('START TRANSACTION') !== false;
    }

    public function commit(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query('COMMIT') !== false;
    }

    public function rollback(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query('ROLLBACK') !== false;
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
