<?php
/**
 * Internal Link ContaiJob
 *
 * ContaiJob for processing internal links in batch mode.
 * Implements ContaiJobInterface for job queue integration.
 *
 * @package WPContentAI
 * @subpackage Services\Jobs
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/../internal-links/InternalLinkProcessor.php';
require_once __DIR__ . '/../internal-links/InternalLinksLogger.php';

use WPContentAI\Services\InternalLinks\ContaiInternalLinkProcessor;
use WPContentAI\Services\InternalLinks\ContaiInternalLinksLogger;

/**
 * Class ContaiInternalLinkJob
 *
 * Handles internal link processing as a background job
 */
class ContaiInternalLinkJob implements ContaiJobInterface
{
    const TYPE = 'internal_links';

    /**
     * @var ContaiInternalLinkProcessor
     */
    private $processor;

    /**
     * @var ContaiInternalLinksLogger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new ContaiInternalLinksLogger();
        $this->processor = new ContaiInternalLinkProcessor($this->logger);
    }

    /**
     * Handle job execution
     *
     * @param array $payload ContaiJob payload with post_id and optionally job_id
     * @return array Result with success status
     */
    public function handle(array $payload)
    {
        $post_id = $this->validatePayload($payload);
        $job_id = $payload['job_id'] ?? $post_id;

        $this->logger->logJobStart($job_id, $payload);

        try {
            $result = $this->processor->processNewPost($post_id, $job_id); 

            if ($result['success']) {
                if (isset($result['total_links']) && $result['total_links'] === 0) {
                    $this->logger->logNoMatchingPosts($job_id, $post_id, 0);
                }
                $this->logger->logJobSuccess($job_id, $payload, $result);
            } else {
                $this->logger->logJobFailure($job_id, $payload, $result['message'] ?? 'Unknown error');
            }

            return [
                'success' => $result['success'],
                'post_id' => $post_id,
                'links_to_new_post' => $result['links_to_new_post'] ?? 0,
                'links_from_new_post' => $result['links_from_new_post'] ?? 0,
                'total_links' => $result['total_links'] ?? 0,
                'message' => $result['message'] ?? 'Internal links processed successfully',
            ];
        } catch (Exception $e) {
            $error_message = $e->getMessage(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            contai_log("ContaiInternalLinkJob error for post {$post_id}: " . $error_message);

            $this->logger->logJobFailure($job_id, $payload, $error_message);

            return [
                'success' => false,
                'post_id' => $post_id,
                'error' => $error_message,
            ];
        }
    }

    /**
     * Get job type identifier
     *
     * @return string
     */
    public function getType()
    {
        return self::TYPE;
    }

    /**
     * Validate job payload
     *
     * @param array $payload
     * @return int Post ID
     * @throws InvalidArgumentException
     */
    private function validatePayload(array $payload): int
    {
        if (!isset($payload['post_id'])) {
            throw new InvalidArgumentException('post_id is required in payload');
        }

        $post_id = (int) $payload['post_id'];

        if ($post_id <= 0) {
            throw new InvalidArgumentException('Invalid post_id in payload');
        }

        $post = get_post($post_id);
        if (!$post) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RuntimeException("Post with ID {$post_id} not found");
        }

        return $post_id;
    }

    /**
     * Create job payload for a post
     *
     * @param int $post_id
     * @return array
     */
    public static function createPayload(int $post_id): array
    {
        return [
            'post_id' => $post_id,
        ];
    }
}
