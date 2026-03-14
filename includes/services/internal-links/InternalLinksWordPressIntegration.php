<?php

/**
 * Internal Links WordPress Integration
 *
 * Handles WordPress hooks and integration for automatic internal linking.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../database/models/Job.php';
require_once __DIR__ . '/../jobs/InternalLinkJob.php';

/**
 * Class ContaiInternalLinksWordPressIntegration
 *
 * Manages WordPress hooks for internal linking
 */
class ContaiInternalLinksWordPressIntegration
{

    /**
     * @var ContaiConfig
     */
    private $config;

    /**
     * @var ContaiJobRepository
     */
    private $job_repository;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = ContaiConfig::getInstance();
        $this->job_repository = new ContaiJobRepository();
    }

    /**
     * Register WordPress hooks
     */
    public function register(): void
    {
        add_action('publish_post', [$this, 'onPostPublished'], 10, 2);
        //add_action('transition_post_status', [$this, 'onPostStatusTransition'], 10, 3);
    }

    /**
     * Handle post published event
     *
     * @param int $post_id
     * @param \WP_Post $post
     */
    public function onPostPublished(int $post_id, $post): void
    {
        if (!$this->shouldProcessPost($post)) {
            return;
        }

        // Check if a pending job already exists for this post
        if ($this->job_repository->hasPendingJobForPost(ContaiInternalLinkJob::TYPE, $post_id)) {
            return;
        }

        $this->queueInternalLinkJob($post_id);
    }

    /**
     * Handle post status transition
     *
     * @param string $new_status
     * @param string $old_status
     * @param \WP_Post $post
     */
    public function onPostStatusTransition(string $new_status, string $old_status, $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        if (!$this->shouldProcessPost($post)) {
            return;
        }

        $this->queueInternalLinkJob($post->ID);
    }

    /**
     * Check if post should be processed
     *
     * @param \WP_Post $post
     * @return bool
     */
    private function shouldProcessPost($post): bool
    {
        if (!$this->config->isInternalLinksEnabled()) {
            return false;
        }

        if ($post->post_type !== 'post') {
            return false;
        }

        if (wp_is_post_revision($post->ID)) {
            return false;
        }

        if (wp_is_post_autosave($post->ID)) {
            return false;
        }

        if (empty($post->post_content)) {
            return false;
        }

        return true;
    }

    /**
     * Queue internal link job for a post
     *
     * @param int $post_id
     */
    private function queueInternalLinkJob(int $post_id): void
    {
        try {
            $payload = ContaiInternalLinkJob::createPayload($post_id);

            $job = ContaiJob::create(
                ContaiInternalLinkJob::TYPE,
                $payload,
                0
            );

            if (!$this->job_repository->create($job)) {
                contai_log("Failed to create ContaiInternalLinkJob for post {$post_id}");
            }
        } catch (Exception $e) {
            contai_log("Failed to queue ContaiInternalLinkJob for post {$post_id}: " . $e->getMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }
}
