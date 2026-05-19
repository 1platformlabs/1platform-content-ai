<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../../database/models/JobStatus.php';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; safe class property, not user input.
class ContaiQueueHealthService
{
    public const CRON_HOOK = 'contai_process_job_queue';
    public const LAST_TICK_OPTION = 'contai_last_tick_at';

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

    /**
     * Returns a snapshot of the job queue health for diagnostics and the
     * admin "Jobs" page banner.
     */
    public function getSnapshot(): array
    {
        $nextRun = wp_next_scheduled(self::CRON_HOOK);
        $nextRunInt = $nextRun ? (int) $nextRun : 0;

        return [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'cron_event_scheduled' => (bool) $nextRun,
            'next_run_at' => $nextRun ?: null,
            'next_run_overdue_seconds' => max(0, time() - ($nextRunInt ?: time())),
            'pending' => $this->jobRepository->countByStatus(ContaiJobStatus::PENDING),
            'processing' => $this->jobRepository->countByStatus(ContaiJobStatus::PROCESSING),
            'longest_processing_age_seconds' => $this->getLongestProcessingAge(),
            'last_tick_at' => get_option(self::LAST_TICK_OPTION, null) ?: null,
        ];
    }

    /**
     * Age in seconds of the oldest PROCESSING job. Returns 0 when no jobs
     * are currently processing.
     */
    private function getLongestProcessingAge(): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $age = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, MIN(processed_at), NOW())
                 FROM {$this->table}
                 WHERE status = %s AND processed_at IS NOT NULL",
                ContaiJobStatus::PROCESSING
            )
        );

        return $age === null ? 0 : max(0, (int) $age);
    }
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
