<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Job.php';
require_once __DIR__ . '/../models/JobStatus.php';

class ContaiJobRepository
{
    private ContaiDatabase $db;
    private string $table = 'contai_jobs';

    public function __construct()
    {
        $this->db = ContaiDatabase::getInstance();
    }

    public function create(ContaiJob $job)
    {
        $data = [
            'job_type' => $job->getJobType(),
            'status' => $job->getStatus(),
            'payload' => is_array($job->getPayload()) ? json_encode($job->getPayload()) : $job->getPayload(),
            'priority' => $job->getPriority(),
            'attempts' => $job->getAttempts(),
            'max_attempts' => $job->getMaxAttempts(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $id = $this->db->insert($this->table, $data);

        if ($id > 0) {
            $job->setId($id);
            return $job;
        }

        return false;
    }

    public function update(ContaiJob $job)
    {
        if ($job->getId() === null) {
            return false;
        }

        $data = [
            'status' => $job->getStatus(),
            'payload' => is_array($job->getPayload()) ? json_encode($job->getPayload()) : $job->getPayload(),
            'priority' => $job->getPriority(),
            'attempts' => $job->getAttempts(),
            'error_message' => $job->getErrorMessage(),
            'processed_at' => $job->getProcessedAt(),
            'updated_at' => current_time('mysql')
        ];

        $where = ['id' => $job->getId()];
        return $this->db->update($this->table, $data, $where) > 0;
    }

    public function findById($id)
    {
        $table = $this->db->getTableName($this->table);
        $sql = $this->db->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        );

        $row = $this->db->getRow($sql, ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByStatus($status, $limit = null, $offset = 0)
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY priority DESC, created_at ASC",
            $status
        );

        if ($limit !== null) {
            $sql .= $this->db->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        $rows = $this->db->getResults($sql, ARRAY_A);
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findPendingJobs($limit = null)
    {
        return $this->findByStatus(ContaiJobStatus::PENDING, $limit);
    }

    public function claimPendingJobs($limit)
    {
        $table = $this->db->getTableName($this->table);
        $processingStatus = ContaiJobStatus::PROCESSING;
        $pendingStatus = ContaiJobStatus::PENDING;
        $now = current_time('mysql');

        $this->db->beginTransaction();

        try {
            $sql = $this->db->prepare(
                "SELECT * FROM {$table}
                 WHERE status = %s
                 ORDER BY priority DESC, created_at ASC
                 LIMIT %d
                 FOR UPDATE",
                $pendingStatus,
                $limit
            );

            $rows = $this->db->getResults($sql, ARRAY_A);

            if (empty($rows)) {
                $this->db->commit();
                return [];
            }

            $ids = array_map(function($row) {
                return (int) $row['id'];
            }, $rows);

            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $updateSql = $this->db->prepare(
                "UPDATE {$table}
                 SET status = %s, processed_at = %s, updated_at = %s
                 WHERE id IN ({$placeholders})",
                $processingStatus,
                $now,
                $now,
                ...$ids
            );

            $this->db->query($updateSql);
            $this->db->commit();

            foreach ($rows as &$row) {
                $row['status'] = $processingStatus;
                $row['processed_at'] = $now;
                $row['updated_at'] = $now;
            }

            return array_map([$this, 'hydrate'], $rows);

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function findProcessingJobs($limit = null)
    {
        return $this->findByStatus(ContaiJobStatus::PROCESSING, $limit);
    }

    public function countByStatus($status)
    {
        $table = $this->db->getTableName($this->table);
        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            $status
        );
        return (int) $this->db->getVar($sql);
    }

    public function countPendingJobs()
    {
        return $this->countByStatus(ContaiJobStatus::PENDING);
    }

    public function countProcessingJobs()
    {
        return $this->countByStatus(ContaiJobStatus::PROCESSING);
    }

    public function getProcessingJobsWithKeywords()
    {
        $jobsTable = $this->db->getTableName($this->table);
        $keywordsTable = $this->db->getTableName('contai_keywords');

        $sql = "SELECT j.*, k.keyword, k.volume, k.title
                FROM {$jobsTable} j
                INNER JOIN {$keywordsTable} k ON JSON_EXTRACT(j.payload, '$.keyword_id') = k.id
                WHERE j.status = %s
                ORDER BY j.created_at ASC";

        $sql = $this->db->prepare($sql, ContaiJobStatus::PROCESSING);
        return $this->db->getResults($sql, ARRAY_A);
    }

    public function getProcessingJobsByType($jobType)
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE status = %s AND job_type = %s
             ORDER BY created_at ASC",
            ContaiJobStatus::PROCESSING,
            $jobType
        );

        return $this->db->getResults($sql, ARRAY_A);
    }

    public function countJobsByTypeAndStatus($jobType, $status)
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE job_type = %s AND status = %s",
            $jobType,
            $status
        );

        return (int) $this->db->getVar($sql);
    }

    public function deleteById($id)
    {
        return $this->db->delete($this->table, ['id' => $id]) > 0;
    }

    public function deleteByStatus($status)
    {
        return $this->db->delete($this->table, ['status' => $status]) > 0;
    }

    public function deletePendingJobs()
    {
        return $this->deleteByStatus(ContaiJobStatus::PENDING);
    }

    public function deleteProcessingJobs()
    {
        return $this->deleteByStatus(ContaiJobStatus::PROCESSING);
    }

    public function deleteAllActiveJobs()
    {
        $table = $this->db->getTableName($this->table);
        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE status IN (%s, %s)",
            ContaiJobStatus::PENDING,
            ContaiJobStatus::PROCESSING
        );
        return $this->db->query($sql);
    }

    public function findStuckJobs($minutes = 30)
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE status = %s
             AND processed_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
             ORDER BY processed_at ASC",
            ContaiJobStatus::PROCESSING,
            $minutes
        );

        $rows = $this->db->getResults($sql, ARRAY_A);
        return array_map([$this, 'hydrate'], $rows);
    }

    public function getActiveJobKeywordIds()
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT DISTINCT JSON_EXTRACT(payload, '$.keyword_id') as keyword_id
             FROM {$table}
             WHERE status IN (%s, %s)
             AND JSON_EXTRACT(payload, '$.keyword_id') IS NOT NULL",
            ContaiJobStatus::PENDING,
            ContaiJobStatus::PROCESSING
        );

        $results = $this->db->getResults($sql, ARRAY_A);

        return array_map(function($row) {
            return (int) $row['keyword_id'];
        }, $results);
    }

    /**
     * Check if a pending job exists for a specific post and job type
     *
     * @param string $jobType
     * @param int $postId
     * @return bool
     */
    public function hasPendingJobForPost(string $jobType, int $postId): bool
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE job_type = %s
                AND status = %s
             AND JSON_EXTRACT(payload, '$.post_id') = %d",
            $jobType,
            ContaiJobStatus::PENDING,
            $postId
        );

        return (int) $this->db->getVar($sql) > 0;
    }

    private function hydrate(array $data)
    {
        $job = new ContaiJob();
        $job->setId($data['id']);
        $job->setJobType($data['job_type']);
        $job->setStatus($data['status']);
        $job->setPayload($data['payload']);
        $job->setPriority($data['priority']);
        $job->setMaxAttempts($data['max_attempts']);
        $job->setCreatedAt($data['created_at']);
        $job->setUpdatedAt($data['updated_at']);

        if (isset($data['attempts'])) {
            for ($i = 0; $i < $data['attempts']; $i++) {
                $job->incrementAttempts();
            }
        }

        if (!empty($data['error_message'])) {
            $job->setErrorMessage($data['error_message']);
        }

        if (!empty($data['processed_at'])) {
            $job->setProcessedAt($data['processed_at']);
        }

        return $job;
    }

    public function findActiveSiteGenerationJob()
    {
        $table = $this->db->getTableName($this->table);

        $sql = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE job_type = %s
             AND status IN (%s, %s)
             ORDER BY created_at DESC
             LIMIT 1",
            'site_generation',
            ContaiJobStatus::PENDING,
            ContaiJobStatus::PROCESSING
        );

        $row = $this->db->getRow($sql, ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }
}
