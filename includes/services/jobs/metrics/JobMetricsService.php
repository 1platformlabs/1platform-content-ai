<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/JobStatus.php';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; safe class property, not user input.
class ContaiJobMetricsService
{
    private ContaiJobRepository $jobRepository;
    private \wpdb $wpdb;
    private string $table;

    public function __construct(?ContaiJobRepository $jobRepository = null)
    {
        global $wpdb;
        $this->jobRepository = $jobRepository ?? new ContaiJobRepository();
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'contai_jobs';
    }

    public function getOverviewMetrics(): array
    {
        return [
            'total_pending' => $this->jobRepository->countByStatus(ContaiJobStatus::PENDING),
            'total_processing' => $this->jobRepository->countByStatus(ContaiJobStatus::PROCESSING),
            'total_done' => $this->jobRepository->countByStatus(ContaiJobStatus::DONE),
            'total_failed' => $this->jobRepository->countByStatus(ContaiJobStatus::FAILED),
            'queue_depth' => $this->calculateQueueDepth(),
            'processing_slots_used' => $this->getProcessingSlotsUsed(),
            'processing_slots_available' => $this->getProcessingSlotsAvailable(),
        ];
    }

    public function getJobTypeBreakdown(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            "SELECT job_type, status, COUNT(*) as count
             FROM {$this->table}
             GROUP BY job_type, status
             ORDER BY job_type, status",
            ARRAY_A
        );

        $breakdown = [];
        foreach ($results as $row) {
            $jobType = $row['job_type'];
            if (!isset($breakdown[$jobType])) {
                $breakdown[$jobType] = [
                    'pending' => 0,
                    'processing' => 0,
                    'done' => 0,
                    'failed' => 0,
                    'total' => 0
                ];
            }
            $breakdown[$jobType][$row['status']] = (int) $row['count'];
            $breakdown[$jobType]['total'] += (int) $row['count'];
        }

        return $breakdown;
    }

    public function getRecentJobs(int $limit = 20): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public function getJobsByStatus(string $status, int $limit = 50): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );
    }

    public function getProcessingJobsWithDetails(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT *,
                 TIMESTAMPDIFF(SECOND, processed_at, NOW()) as seconds_processing,
                 TIMESTAMPDIFF(MINUTE, processed_at, NOW()) as minutes_processing
                 FROM {$this->table}
                 WHERE status = %s
                 ORDER BY processed_at ASC",
                'processing'
            ),
            ARRAY_A
        );
    }

    public function getAverageProcessingTime(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT job_type,
                 AVG(TIMESTAMPDIFF(SECOND, processed_at, updated_at)) as avg_seconds
                 FROM {$this->table}
                 WHERE status = %s
                 AND processed_at IS NOT NULL
                 GROUP BY job_type",
                'done'
            ),
            ARRAY_A
        );

        $averages = [];
        foreach ($results as $row) {
            $averages[$row['job_type']] = (int) $row['avg_seconds'];
        }

        return $averages;
    }

    public function getFailureRateByType(): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT job_type,
                 SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as failed_count,
                 COUNT(*) as total_count
                 FROM {$this->table}
                 GROUP BY job_type",
                'failed'
            ),
            ARRAY_A
        );

        $rates = [];
        foreach ($results as $row) {
            $total = (int) $row['total_count'];
            $failed = (int) $row['failed_count'];
            $rates[$row['job_type']] = [
                'failed' => $failed,
                'total' => $total,
                'rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0
            ];
        }

        return $rates;
    }

    private function calculateQueueDepth(): int
    {
        return $this->jobRepository->countByStatus(ContaiJobStatus::PENDING);
    }

    private function getProcessingSlotsUsed(): int
    {
        return $this->jobRepository->countByStatus(ContaiJobStatus::PROCESSING);
    }

    private function getProcessingSlotsAvailable(): int
    {
        $maxSlots = 5;
        $used = $this->getProcessingSlotsUsed();
        return max(0, $maxSlots - $used);
    }
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
