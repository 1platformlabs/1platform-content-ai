<?php
/**
 * Internal Links Logger
 *
 * Logs internal links processing jobs to wp_contai_api_logs for debugging and visibility.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

namespace WPContentAI\Services\InternalLinks;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/Database.php';

class ContaiInternalLinksLogger
{
    private $db;
    private $tableName = 'contai_api_logs';

    public function __construct()
    {
        $this->db = \ContaiDatabase::getInstance();
    }

    public function logJobStart(int $job_id, array $payload): void
    {
        $post_id = $payload['post_id'] ?? 0;
        $post_title = $this->getPostTitle($post_id);

        $this->log(
            'INTERNAL_LINKS_START',
            "internal-links/job/{$job_id}",
            [
                'job_id' => $job_id,
                'post_id' => $post_id,
                'post_title' => $post_title,
                'payload' => $payload,
            ],
            null,
            200,
            "Started processing internal links for post: {$post_title} (ID: {$post_id})"
        );
    }

    public function logJobSuccess(int $job_id, array $payload, array $result): void
    {
        $post_id = $payload['post_id'] ?? 0;
        $post_title = $this->getPostTitle($post_id);

        $links_to = $result['links_to_new_post'] ?? 0;
        $links_from = $result['links_from_new_post'] ?? 0;
        $total = $result['total_links'] ?? 0;

        $description = sprintf(
            "Successfully processed internal links for post: %s (ID: %d). " .
            "Links TO post: %d, Links FROM post: %d, Total: %d",
            $post_title,
            $post_id,
            $links_to,
            $links_from,
            $total
        );

        $this->log(
            'INTERNAL_LINKS_SUCCESS',
            "internal-links/job/{$job_id}",
            [
                'job_id' => $job_id,
                'post_id' => $post_id,
                'post_title' => $post_title,
                'payload' => $payload,
            ],
            [
                'success' => true,
                'links_to_new_post' => $links_to,
                'links_from_new_post' => $links_from,
                'total_links' => $total,
                'message' => $result['message'] ?? 'Success',
            ],
            200,
            $description
        );
    }

    public function logJobFailure(int $job_id, array $payload, string $error): void
    {
        $post_id = $payload['post_id'] ?? 0;
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "Failed to process internal links for post: %s (ID: %d). Error: %s",
            $post_title,
            $post_id,
            $error
        );

        $this->log(
            'INTERNAL_LINKS_FAILURE',
            "internal-links/job/{$job_id}",
            [
                'job_id' => $job_id,
                'post_id' => $post_id,
                'post_title' => $post_title,
                'payload' => $payload,
            ],
            [
                'success' => false,
                'error' => $error,
            ],
            500,
            $description
        );
    }

    public function logKeywordsFound(int $job_id, int $post_id, array $keywords): void
    {
        $keyword_count = count($keywords);
        $keyword_list = array_map(function($kw) {
            return $kw->getKeyword();
        }, $keywords);

        $description = sprintf(
            "Found %d keyword(s) for post ID %d: %s",
            $keyword_count,
            $post_id,
            implode(', ', array_slice($keyword_list, 0, 5))
        );

        if ($keyword_count > 5) {
            $description .= sprintf(' (and %d more)', $keyword_count - 5);
        }

        $this->log(
            'INTERNAL_LINKS_KEYWORDS',
            "internal-links/job/{$job_id}/keywords",
            ['job_id' => $job_id, 'post_id' => $post_id],
            [
                'keyword_count' => $keyword_count,
                'keywords' => $keyword_list,
            ],
            200,
            $description
        );
    }

    public function logLinksCreated(int $job_id, int $source_post_id, int $target_post_id, string $keyword): void
    {
        $source_title = $this->getPostTitle($source_post_id);
        $target_title = $this->getPostTitle($target_post_id);

        $description = sprintf(
            "Created internal link: '%s' → '%s' (keyword: %s)",
            $source_title,
            $target_title,
            $keyword
        );

        $this->log(
            'INTERNAL_LINKS_CREATED',
            "internal-links/job/{$job_id}/link",
            ['job_id' => $job_id],
            [
                'source_post_id' => $source_post_id,
                'source_post_title' => $source_title,
                'target_post_id' => $target_post_id,
                'target_post_title' => $target_title,
                'keyword' => $keyword,
            ],
            201,
            $description
        );
    }

    public function logNoKeywords(int $job_id, int $post_id): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "No keywords found for post: %s (ID: %d). Cannot create internal links.",
            $post_title,
            $post_id
        );

        $this->log(
            'INTERNAL_LINKS_NO_KEYWORDS',
            "internal-links/job/{$job_id}",
            ['job_id' => $job_id, 'post_id' => $post_id],
            ['message' => 'No keywords found for this post'],
            204,
            $description
        );
    }

    public function logNoMatchingPosts(int $job_id, int $post_id, int $category_id): void
    {
        $post_title = $this->getPostTitle($post_id);

        $category_name = 'Unknown Category';
        if ($category_id > 0) {
            $category = get_category($category_id);
            if ($category && !is_wp_error($category)) {
                $category_name = $category->name;
            }
        }

        $description = sprintf(
            "No matching posts found in category '%s' for post: %s (ID: %d)",
            $category_name,
            $post_title,
            $post_id
        );

        $this->log(
            'INTERNAL_LINKS_NO_MATCHES',
            "internal-links/job/{$job_id}",
            ['job_id' => $job_id, 'post_id' => $post_id, 'category_id' => $category_id],
            ['message' => 'No matching posts found in category'],
            204,
            $description
        );
    }

    public function logProcessingStage(int $job_id, int $post_id, string $stage, array $data = []): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "[%s] Post: %s (ID: %d)",
            $stage,
            $post_title,
            $post_id
        );

        $this->log(
            'INTERNAL_LINKS_STAGE',
            "internal-links/job/{$job_id}/stage",
            array_merge(['job_id' => $job_id, 'post_id' => $post_id, 'stage' => $stage], $data),
            ['stage' => $stage, 'data' => $data],
            200,
            $description
        );
    }

    public function logKeywordSearch(int $job_id, int $post_id, int $keyword_count): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "Searching keywords for post: %s (ID: %d). Found %d keyword(s)",
            $post_title,
            $post_id,
            $keyword_count
        );

        $this->log(
            'INTERNAL_LINKS_KEYWORD_SEARCH',
            "internal-links/job/{$job_id}/keyword-search",
            ['job_id' => $job_id, 'post_id' => $post_id],
            ['keyword_count' => $keyword_count],
            200,
            $description
        );
    }

    public function logCategoryDetection(int $job_id, int $post_id, int $category_id, string $category_name): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "Detected category '%s' (ID: %d) for post: %s (ID: %d)",
            $category_name,
            $category_id,
            $post_title,
            $post_id
        );

        $this->log(
            'INTERNAL_LINKS_CATEGORY',
            "internal-links/job/{$job_id}/category",
            ['job_id' => $job_id, 'post_id' => $post_id],
            ['category_id' => $category_id, 'category_name' => $category_name],
            200,
            $description
        );
    }

    public function logPostsScanning(int $job_id, int $post_id, int $category_id, int $posts_found, string $direction): void
    {
        $post_title = $this->getPostTitle($post_id);
        $category_name = 'Unknown Category';
        if ($category_id > 0) {
            $category = get_category($category_id);
            if ($category && !is_wp_error($category)) {
                $category_name = $category->name;
            }
        }

        $description = sprintf(
            "Scanning posts in category '%s' for %s linking. Post: %s (ID: %d). Found %d post(s) to process",
            $category_name,
            $direction,
            $post_title,
            $post_id,
            $posts_found
        );

        $this->log(
            'INTERNAL_LINKS_SCANNING',
            "internal-links/job/{$job_id}/scanning",
            ['job_id' => $job_id, 'post_id' => $post_id, 'category_id' => $category_id],
            ['posts_found' => $posts_found, 'direction' => $direction],
            200,
            $description
        );
    }

    public function logKeywordMatches(int $job_id, int $source_post_id, int $target_post_id, string $keyword, int $matches_found): void
    {
        $source_title = $this->getPostTitle($source_post_id);
        $target_title = $this->getPostTitle($target_post_id);

        $description = sprintf(
            "ContaiKeyword '%s' found %d match(es) in post '%s' (ID: %d) for linking to '%s' (ID: %d)",
            $keyword,
            $matches_found,
            $source_title,
            $source_post_id,
            $target_title,
            $target_post_id
        );

        $this->log(
            'INTERNAL_LINKS_MATCHES',
            "internal-links/job/{$job_id}/matches",
            ['job_id' => $job_id, 'source_post_id' => $source_post_id, 'target_post_id' => $target_post_id],
            ['keyword' => $keyword, 'matches_found' => $matches_found],
            200,
            $description
        );
    }

    public function logContentUpdate(int $job_id, int $post_id, int $links_injected, bool $success): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "%s content update for post: %s (ID: %d). Links injected: %d",
            $success ? 'Successful' : 'Failed',
            $post_title,
            $post_id,
            $links_injected
        );

        $this->log(
            'INTERNAL_LINKS_CONTENT_UPDATE',
            "internal-links/job/{$job_id}/content-update",
            ['job_id' => $job_id, 'post_id' => $post_id],
            ['links_injected' => $links_injected, 'success' => $success],
            $success ? 200 : 500,
            $description
        );
    }

    public function logLinkLimitReached(int $job_id, int $post_id, string $limit_type, int $current, int $max): void
    {
        $post_title = $this->getPostTitle($post_id);

        $description = sprintf(
            "Link limit reached for post: %s (ID: %d). Type: %s, Current: %d, Max: %d",
            $post_title,
            $post_id,
            $limit_type,
            $current,
            $max
        );

        $this->log(
            'INTERNAL_LINKS_LIMIT',
            "internal-links/job/{$job_id}/limit",
            ['job_id' => $job_id, 'post_id' => $post_id],
            ['limit_type' => $limit_type, 'current' => $current, 'max' => $max],
            200,
            $description
        );
    }

    private function log(
        string $method,
        string $url,
        array $request_body,
        ?array $response_body,
        int $response_code,
        string $error = null
    ): void {
        $table = $this->db->getTableName($this->tableName);

        $data = [
            'method' => $method,
            'url' => $url,
            'request_body' => json_encode($request_body, JSON_PRETTY_PRINT),
            'response_body' => $response_body ? json_encode($response_body, JSON_PRETTY_PRINT) : null,
            'response_code' => $response_code,
            'error' => $error,
            'duration' => null,
            'created_at' => current_time('mysql'),
        ];

        $result = $this->db->insert($this->tableName, $data);

        if ($result === 0) {
            contai_log("ContaiInternalLinksLogger: Failed to insert log - Method: {$method}, URL: {$url}, DB Error: " . $this->db->getLastError());
        }
    }

    private function getPostTitle(int $post_id): string
    {
        $title = get_the_title($post_id);

        if (!$title || is_wp_error($title)) {
            return "Post {$post_id}";
        }

        return $title;
    }
}
