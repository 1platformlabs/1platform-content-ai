<?php
/**
 * Internal Links Queue Manager
 *
 * Manages the queue of internal link processing jobs.
 *
 * @package WPContentAI
 * @subpackage Services\InternalLinks
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../database/models/Job.php';
require_once __DIR__ . '/../../database/models/JobStatus.php';
require_once __DIR__ . '/../../database/Database.php';
require_once __DIR__ . '/../jobs/InternalLinkJob.php';

class ContaiInternalLinksQueueManager
{
    private $jobRepository;
    private $db;

    public function __construct()
    {
        $this->jobRepository = new ContaiJobRepository();
        $this->db = ContaiDatabase::getInstance();
    }

    public function enqueueAllPublishedPosts($limit = 50)
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $post_ids = get_posts($args);

        if (empty($post_ids)) {
            return 0;
        }

        $enqueuedCount = 0;

        foreach ($post_ids as $post_id) {
            if ($this->isPostAlreadyQueued($post_id)) {
                continue;
            }

            $payload = ContaiInternalLinkJob::createPayload($post_id);

            $job = ContaiJob::create(
                ContaiInternalLinkJob::TYPE,
                $payload,
                0
            );

            if ($this->jobRepository->create($job)) {
                $enqueuedCount++;
            }
        }

        return $enqueuedCount;
    }

    public function getPendingCount()
    {
        return $this->getCountByType(ContaiJobStatus::PENDING);
    }

    public function getProcessingCount()
    {
        return $this->getCountByType(ContaiJobStatus::PROCESSING);
    }

    public function getProcessingJobsWithDetails()
    {
        $jobsTable = $this->db->getTableName('contai_jobs');
        $postsTable = $this->db->getWpdb()->posts;

        $sql = "SELECT j.*, p.post_title, p.post_date
                FROM {$jobsTable} j
                LEFT JOIN {$postsTable} p ON JSON_EXTRACT(j.payload, '$.post_id') = p.ID
                WHERE j.status = %s
                AND j.job_type = %s
                ORDER BY j.created_at ASC";

        $sql = $this->db->prepare($sql, ContaiJobStatus::PROCESSING, ContaiInternalLinkJob::TYPE);
        return $this->db->getResults($sql, ARRAY_A);
    }

    public function clearPendingJobs()
    {
        return $this->deleteJobsByStatusAndType(ContaiJobStatus::PENDING);
    }

    public function clearAllJobs()
    {
        $table = $this->db->getTableName('contai_jobs');
        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE job_type = %s AND status IN (%s, %s)",
            ContaiInternalLinkJob::TYPE,
            ContaiJobStatus::PENDING,
            ContaiJobStatus::PROCESSING
        );
        return $this->db->query($sql);
    }

    public function getQueueStatus()
    {
        return [
            'pending' => $this->getPendingCount(),
            'processing' => $this->getProcessingCount(),
            'processing_jobs' => $this->getProcessingJobsWithDetails()
        ];
    }

    private function isPostAlreadyQueued(int $post_id): bool
    {
        $table = $this->db->getTableName('contai_jobs');
        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE job_type = %s
             AND status = %s
             AND JSON_EXTRACT(payload, '$.post_id') = %d
             AND status IN (%s, %s)",
            ContaiInternalLinkJob::TYPE,
            ContaiJobStatus::PENDING,
            $post_id,
            ContaiJobStatus::PENDING,
            ContaiJobStatus::PROCESSING
        );

        return (int) $this->db->getVar($sql) > 0;
    }

    private function getCountByType(string $status): int
    {
        $table = $this->db->getTableName('contai_jobs');
        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND job_type = %s",
            $status,
            ContaiInternalLinkJob::TYPE
        );
        return (int) $this->db->getVar($sql);
    }

    private function deleteJobsByStatusAndType(string $status): int
    {
        $table = $this->db->getTableName('contai_jobs');
        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE status = %s AND job_type = %s",
            $status,
            ContaiInternalLinkJob::TYPE
        );
        return $this->db->query($sql);
    }
}
