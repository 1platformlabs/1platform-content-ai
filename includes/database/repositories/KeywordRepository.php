<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Keyword.php';

class ContaiKeywordRepository {

    private ContaiDatabase $db;
    private string $table = 'contai_keywords';

    public function __construct() {
        $this->db = ContaiDatabase::getInstance();
    }

    public function create(ContaiKeyword $keyword): ?int {
        if (!$keyword->isValid()) {
            return null;
        }

        $data = $keyword->toDbArray();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $format = ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s'];

        $id = $this->db->insert($this->table, $data, $format);

        if ($id > 0) {
            $keyword->setId($id);
            return $id;
        }

        return null;
    }

    public function update(ContaiKeyword $keyword): bool {
        if ($keyword->getId() === null || !$keyword->isValid()) {
            return false;
        }

        $data = $keyword->toDbArray();
        $data['updated_at'] = current_time('mysql');
        unset($data['id']);

        $where = ['id' => $keyword->getId()];
        $format = ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s'];
        $whereFormat = ['%d'];

        return $this->db->update($this->table, $data, $where, $format, $whereFormat) > 0;
    }

    public function delete(int $id): bool {
        $where = ['id' => $id];
        $whereFormat = ['%d'];
        return $this->db->delete($this->table, $where, $whereFormat) > 0;
    }

    public function softDelete(int $id): bool {
        $data = [
            'deleted_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        $where = ['id' => $id];
        $format = ['%s', '%s'];
        $whereFormat = ['%d'];

        return $this->db->update($this->table, $data, $where, $format, $whereFormat) > 0;
    }

    public function restore(int $id): bool {
        $data = [
            'deleted_at' => null,
            'updated_at' => current_time('mysql'),
        ];
        $where = ['id' => $id];
        $format = ['%s', '%s'];
        $whereFormat = ['%d'];

        return $this->db->update($this->table, $data, $where, $format, $whereFormat) > 0;
    }

    public function findById(int $id): ?ContaiKeyword {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            $id
        );

        $result = $this->db->getRow($query, ARRAY_A);

        if ($result) {
            return new ContaiKeyword($result);
        }

        return null;
    }

    public function findByKeyword(string $keyword): ?ContaiKeyword {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table} WHERE keyword = %s AND deleted_at IS NULL LIMIT 1",
            $keyword
        );

        $result = $this->db->getRow($query, ARRAY_A);

        if ($result) {
            return new ContaiKeyword($result);
        }

        return null;
    }

    public function findByPostId(int $post_id): array {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND deleted_at IS NULL",
            $post_id
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function findAll(int $limit = 100, int $offset = 0): array {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table} WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function findByStatus(string $status, int $limit = 100, int $offset = 0): array {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND deleted_at IS NULL ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $status,
            $limit,
            $offset
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function search(string $search, int $limit = 100, int $offset = 0): array {
        $table = $this->db->getTableName($this->table);
        $searchTerm = '%' . $this->db->getWpdb()->esc_like($search) . '%';

        $query = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE (keyword LIKE %s OR title LIKE %s)
             AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $searchTerm,
            $searchTerm,
            $limit,
            $offset
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function count(): int {
        $table = $this->db->getTableName($this->table);
        $query = "SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL";
        return (int) $this->db->getVar($query);
    }

    public function countByStatus(string $status): int {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND deleted_at IS NULL",
            $status
        );
        return (int) $this->db->getVar($query);
    }

    public function exists(string $keyword): bool {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE keyword = %s AND deleted_at IS NULL",
            $keyword
        );
        return (int) $this->db->getVar($query) > 0;
    }

    public function bulkInsert(array $keywords): int {
        $inserted = 0;

        foreach ($keywords as $keywordData) {
            $keyword = new ContaiKeyword($keywordData);
            if ($this->create($keyword)) {
                $inserted++;
            }
        }

        return $inserted;
    }

    public function updateStatus(int $id, string $status): bool {
        if (!in_array($status, [ContaiKeyword::STATUS_ACTIVE, ContaiKeyword::STATUS_DONE, ContaiKeyword::STATUS_INACTIVE, ContaiKeyword::STATUS_PENDING, ContaiKeyword::STATUS_PROCESSING, ContaiKeyword::STATUS_FAILED], true)) {
            return false;
        }

        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];
        $where = ['id' => $id];
        $format = ['%s', '%s'];
        $whereFormat = ['%d'];

        return $this->db->update($this->table, $data, $where, $format, $whereFormat) > 0;
    }

    public function getTopByVolume(int $limit = 10): array {
        $table = $this->db->getTableName($this->table);
        $query = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE deleted_at IS NULL
             ORDER BY volume DESC
             LIMIT %d",
            $limit
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function findWithFilters(
        ?string $search = null,
        ?string $status = null,
        string $orderBy = 'created_at',
        string $order = 'DESC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $table = $this->db->getTableName($this->table);
        $where = ['deleted_at IS NULL'];
        $params = [];

        if ($search !== null && $search !== '') {
            $searchTerm = '%' . $this->db->getWpdb()->esc_like($search) . '%';
            $where[] = '(keyword LIKE %s OR title LIKE %s)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $allowedOrderBy = ['keyword', 'title', 'volume', 'status', 'created_at', 'updated_at'];
        $orderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'created_at';

        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $whereClause = implode(' AND ', $where);

        $orderByClause = "{$orderBy} {$order}";

        if ($orderBy === 'volume') {
            $orderByClause = "volume {$order}, FIELD(status, 'pending', 'active', 'inactive')";
        } elseif ($orderBy !== 'status') {
            $orderByClause = "{$orderBy} {$order}, FIELD(status, 'pending', 'active', 'inactive')";
        }

        $params[] = $limit;
        $params[] = $offset;

        $query = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE {$whereClause}
             ORDER BY {$orderByClause}
             LIMIT %d OFFSET %d",
            ...$params
        );

        $results = $this->db->getResults($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiKeyword($row);
        }, $results);
    }

    public function countWithFilters(?string $search = null, ?string $status = null): int {
        $table = $this->db->getTableName($this->table);
        $where = ['deleted_at IS NULL'];
        $params = [];

        if ($search !== null && $search !== '') {
            $searchTerm = '%' . $this->db->getWpdb()->esc_like($search) . '%';
            $where[] = '(keyword LIKE %s OR title LIKE %s)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        if (empty($params)) {
            $query = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
        } else {
            $query = $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}",
                ...$params
            );
        }

        return (int) $this->db->getVar($query);
    }

    public function resetActiveKeywordsByIds(array $keywordIds): int {
        if (empty($keywordIds)) {
            return 0;
        }

        $table = $this->db->getTableName($this->table);
        $placeholders = implode(',', array_fill(0, count($keywordIds), '%d'));

        $params = array_merge(
            [ContaiKeyword::STATUS_PENDING, current_time('mysql')],
            $keywordIds,
            [ContaiKeyword::STATUS_ACTIVE]
        );

        $sql = $this->db->prepare(
            "UPDATE {$table}
             SET status = %s, updated_at = %s
             WHERE id IN ({$placeholders})
             AND status = %s",
            ...$params
        );

        return $this->db->query($sql);
    }
}
