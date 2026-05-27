<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../jobs/QueueManager.php';

class ContaiPostGenerationSetupService
{
    private ContaiQueueManager $queueManager;

    public function __construct(?ContaiQueueManager $queueManager = null)
    {
        $this->queueManager = $queueManager ?? new ContaiQueueManager();
    }

    public function enqueuePostGeneration(int $count, array $config): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Number of posts must be at least 1');
        }

        $batchId = $this->generateBatchId();
        $config['batch_id'] = $batchId;

        $enqueuedCount = $this->queueManager->enqueuePostGeneration($count, $config);

        // Persist BOTH the originally requested count and what was actually
        // enqueued. `enqueuedCount < requested` happens when there are fewer
        // pending keywords than the wizard asked for — surfaces as a hard
        // failure in getBatchStatus() / SiteGenerationJob::waitForPosts()
        // instead of silently marking the batch "complete" with a short load.
        update_option("contai_batch_{$batchId}_requested", $count);
        update_option("contai_batch_{$batchId}_total", $enqueuedCount);
        update_option("contai_batch_{$batchId}_started_at", current_time('mysql'));

        contai_log(sprintf(
            '[site-gen] batch %s enqueued: requested=%d enqueued=%d',
            $batchId,
            $count,
            $enqueuedCount
        ));

        return [
            'success' => true,
            'batch_id' => $batchId,
            'requested_count' => $count,
            'enqueued_count' => $enqueuedCount,
        ];
    }

    public function getBatchStatus(string $batchId): array
    {
        global $wpdb;

        $total     = (int) get_option("contai_batch_{$batchId}_total", 0);
        // Falls back to $total for batches that started before the
        // requested-count tracking was added. Pre-existing batches behave as
        // before (no shortfall surfaced) instead of being retroactively failed.
        $requested = (int) get_option("contai_batch_{$batchId}_requested", $total);

        $table = $wpdb->prefix . 'contai_jobs';
        $likePattern = '%"batch_id":"' . $wpdb->esc_like($batchId) . '"%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is from $wpdb->prefix, safe.
        $done = (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$table}
             WHERE job_type = 'post_generation'
             AND status = 'done'
             AND payload LIKE %s",
            $likePattern
        ));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $failed = (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$table}
             WHERE job_type = 'post_generation'
             AND status = 'failed'
             AND payload LIKE %s",
            $likePattern
        ));

        $finished = $done + $failed;

        return [
            'batch_id' => $batchId,
            'requested' => $requested,
            'total' => $total,
            'completed' => $done,
            'failed' => $failed,
            'finished' => $finished,
            'is_complete' => $finished >= $total,
            // True when the wizard asked for more posts than could be
            // enqueued (e.g. fewer keywords available than num_posts). Used
            // by SiteGenerationJob to fail loudly instead of marking the
            // generation done with a short load.
            'is_short' => $requested > $total,
            'shortfall' => max(0, $requested - $total),
        ];
    }

    private function generateBatchId(): string
    {
        return 'batch_' . uniqid() . '_' . time();
    }
}
