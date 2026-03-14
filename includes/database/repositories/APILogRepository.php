<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';

class ContaiAPILogRepository {

    private ContaiDatabase $db;
    private string $table_name;

    public function __construct() {
        $this->db = ContaiDatabase::getInstance();
        $this->table_name = $this->db->getTableName('contai_api_logs');
    }

    public function getAll(int $limit = 50, int $offset = 0): array {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }

    public function getErrors(int $limit = 50, int $offset = 0): array {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_name} WHERE error IS NOT NULL OR response_code >= 400 ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }

    public function getByMethod(string $method, int $limit = 50): array {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_name} WHERE method = %s ORDER BY created_at DESC LIMIT %d",
            $method,
            $limit
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }

    public function count(): int {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$this->table_name} WHERE %d = %d",
            1,
            1
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        return (int) $wpdb->get_var( $query );
    }

    public function countErrors(): int {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$this->table_name} WHERE (error IS NOT NULL OR response_code >= %d)",
            400
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        return (int) $wpdb->get_var( $query );
    }

    public function deleteOlderThan(int $days): int {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        return $wpdb->query($query);
    }

    public function deleteAll(): int {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE FROM {$this->table_name} WHERE %d = %d",
            1,
            1
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        return $wpdb->query( $query );
    }

    public function getLatestErrors(int $limit = 10): array {
        $wpdb = $this->db->getWpdb();

        $query = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$this->table_name} WHERE error IS NOT NULL OR response_code >= 400 ORDER BY created_at DESC LIMIT %d",
            $limit
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }
}
