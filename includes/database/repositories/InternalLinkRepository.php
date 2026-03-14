<?php
/**
 * Internal Link Repository
 *
 * Handles database operations for internal links with optimized indexed queries.
 * Follows Repository pattern for data abstraction.
 *
 * @package WPContentAI
 * @subpackage ContaiDatabase\Repositories
 */

namespace WPContentAI\ContaiDatabase\Repositories;

use WPContentAI\ContaiDatabase\Models\ContaiInternalLink;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ContaiInternalLinkRepository
 *
 * Repository for internal link database operations
 */
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All queries use $wpdb->prepare() with proper placeholders. Table name ($this->table_name) is a safe class property from $wpdb->prefix.
class ContaiInternalLinkRepository {
    /**
     * @var \wpdb WordPress database instance
     */
    private $wpdb;

    /**
     * @var string Table name
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'contai_internal_links';
    }

    /**
     * Create a new internal link
     *
     * @param ContaiInternalLink $link
     * @return int|false Link ID or false on failure
     */
    public function create(ContaiInternalLink $link) {
        $link->validate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert(
            $this->table_name,
            [
                'source_post_id' => $link->getSourcePostId(),
                'target_post_id' => $link->getTargetPostId(),
                'keyword_id' => $link->getKeywordId(),
                'status' => $link->getStatus(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            return false;
        }

        $link->setId($this->wpdb->insert_id);
        return $this->wpdb->insert_id;
    }

    /**
     * Find link by ID
     *
     * @param int $id
     * @return ContaiInternalLink|null
     */
    public function findById(int $id): ?ContaiInternalLink {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $result ? new ContaiInternalLink($result) : null;
    }

    /**
     * Update an internal link
     *
     * @param ContaiInternalLink $link
     * @return bool
     */
    public function update(ContaiInternalLink $link): bool {
        if (!$link->getId()) {
            return false;
        }

        $link->validate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->update(
            $this->table_name,
            [
                'source_post_id' => $link->getSourcePostId(),
                'target_post_id' => $link->getTargetPostId(),
                'keyword_id' => $link->getKeywordId(),
                'status' => $link->getStatus(),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $link->getId()],
            ['%d', '%d', '%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a link by ID
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->delete(
            $this->table_name,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Check if a link already exists
     *
     * @param int $source_post_id
     * @param int $target_post_id
     * @param int $keyword_id
     * @return bool
     */
    public function linkExists(int $source_post_id, int $target_post_id, int $keyword_id): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE source_post_id = %d
                AND target_post_id = %d
                AND keyword_id = %d",
                $source_post_id,
                $target_post_id,
                $keyword_id
            )
        );

        return (int)$result > 0;
    }

    /**
     * Count links by source post
     *
     * @param int $source_post_id
     * @param string $status Optional status filter
     * @return int
     */
    public function countBySourcePost(int $source_post_id, string $status = ContaiInternalLink::STATUS_ACTIVE): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE source_post_id = %d
                AND status = %s",
                $source_post_id,
                $status
            )
        );

        return (int)$result;
    }

    /**
     * Count links by target post
     *
     * @param int $target_post_id
     * @param string $status Optional status filter
     * @return int
     */
    public function countByTargetPost(int $target_post_id, string $status = ContaiInternalLink::STATUS_ACTIVE): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE target_post_id = %d
                AND status = %s",
                $target_post_id,
                $status
            )
        );

        return (int)$result;
    }

    /**
     * Count links by keyword
     *
     * @param int $keyword_id
     * @param string $status Optional status filter
     * @return int
     */
    public function countByKeyword(int $keyword_id, string $status = ContaiInternalLink::STATUS_ACTIVE): int {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE keyword_id = %d
                AND status = %s",
                $keyword_id,
                $status
            )
        );

        return (int)$result;
    }

    /**
     * Find links by source post
     *
     * @param int $source_post_id
     * @param string $status Optional status filter
     * @return ContaiInternalLink[]
     */
    public function findBySourcePost(int $source_post_id, string $status = ContaiInternalLink::STATUS_ACTIVE): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->table_name}
                WHERE source_post_id = %d
                AND status = %s
                ORDER BY created_at DESC",
                $source_post_id,
                $status
            ),
            ARRAY_A
        );

        return array_map(function($row) {
            return new ContaiInternalLink($row);
        }, $results ?: []);
    }

    /**
     * Find links by target post
     *
     * @param int $target_post_id
     * @param string $status Optional status filter
     * @return ContaiInternalLink[]
     */
    public function findByTargetPost(int $target_post_id, string $status = ContaiInternalLink::STATUS_ACTIVE): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$this->table_name}
                WHERE target_post_id = %d
                AND status = %s
                ORDER BY created_at DESC",
                $target_post_id,
                $status
            ),
            ARRAY_A
        );

        return array_map(function($row) {
            return new ContaiInternalLink($row);
        }, $results ?: []);
    }

    /**
     * Find posts in same category with pagination
     * Uses indexed joins for performance
     *
     * @param int $category_id
     * @param int $exclude_post_id Post to exclude
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function findPostsInCategory(int $category_id, int $exclude_post_id = 0, int $limit = 10, int $offset = 0): array {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb built-in properties.
        $query = $this->wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_content, p.post_name
            FROM {$this->wpdb->posts} p
            INNER JOIN {$this->wpdb->term_relationships} tr ON p.ID = tr.object_id
            WHERE tr.term_taxonomy_id = %d
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
            AND p.ID != %d
            ORDER BY p.post_date DESC
            LIMIT %d OFFSET %d",
            $category_id,
            $exclude_post_id,
            $limit,
            $offset
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($query, ARRAY_A) ?: [];
    }

    /**
     * Get all links with pagination
     *
     * @param int $limit
     * @param int $offset
     * @param string $status Optional status filter
     * @return array
     */
    public function findAll(int $limit = 20, int $offset = 0, string $status = ''): array {
        if ($status) {
            $query = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class property.
                "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset
            );
        } else {
            $query = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class property.
                "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results($query, ARRAY_A);

        return array_map(function($row) {
            return new ContaiInternalLink($row);
        }, $results ?: []);
    }

    /**
     * Count total links
     *
     * @param string $status Optional status filter
     * @return int
     */
    public function countAll(string $status = ''): int {
        if ($status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class property.
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
                    $status
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class property; no user input to prepare.
            $result = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        }

        return (int)$result;
    }

    /**
     * Get links with post details (for list view)
     * Uses joins to get source/target post titles
     *
     * @param int $limit
     * @param int $offset
     * @param string $status Optional status filter
     * @return array
     */
    public function findAllWithPostDetails(int $limit = 20, int $offset = 0, string $status = ''): array {
        $keywords_table = $this->wpdb->prefix . 'contai_keywords';

        $sql = "SELECT
            il.*,
            sp.post_title as source_post_title,
            tp.post_title as target_post_title,
            k.keyword as keyword_text
            FROM {$this->table_name} il
            INNER JOIN {$this->wpdb->posts} sp ON il.source_post_id = sp.ID
            INNER JOIN {$this->wpdb->posts} tp ON il.target_post_id = tp.ID
            LEFT JOIN {$keywords_table} k ON il.keyword_id = k.id";

        if ($status) {
            $sql .= ' WHERE il.status = %s ORDER BY il.created_at DESC LIMIT %d OFFSET %d';
            $query = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names from $wpdb properties and class property.
                $sql,
                $status,
                $limit,
                $offset
            );
        } else {
            $sql .= ' ORDER BY il.created_at DESC LIMIT %d OFFSET %d';
            $query = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names from $wpdb properties and class property.
                $sql,
                $limit,
                $offset
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($query, ARRAY_A) ?: [];
    }

    /**
     * Delete all links for a post (when post is deleted)
     *
     * @param int $post_id
     * @return bool
     */
    public function deleteByPost(int $post_id): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "DELETE FROM {$this->table_name}
                WHERE source_post_id = %d OR target_post_id = %d",
                $post_id,
                $post_id
            )
        );

        return $result !== false;
    }

    /**
     * Deactivate all links for a post
     *
     * @param int $post_id
     * @return bool
     */
    public function deactivateByPost(int $post_id): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "UPDATE {$this->table_name}
                SET status = %s, updated_at = %s
                WHERE source_post_id = %d OR target_post_id = %d",
                ContaiInternalLink::STATUS_INACTIVE,
                current_time('mysql'),
                $post_id,
                $post_id
            )
        );

        return $result !== false;
    }
}
// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
