<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../database/repositories/JobRepository.php';
require_once __DIR__ . '/../../database/models/JobStatus.php';
require_once __DIR__ . '/JobInterface.php';
require_once __DIR__ . '/PostGenerationJob.php';
require_once __DIR__ . '/InternalLinkJob.php';
require_once __DIR__ . '/KeywordExtractionJob.php';
require_once __DIR__ . '/SiteGenerationJob.php';
require_once __DIR__ . '/ContentGenerationPollingJob.php';
require_once __DIR__ . '/recovery/JobRecoveryService.php';
require_once __DIR__ . '/../billing/CreditGuard.php';

class ContaiJobProcessor
{
    const MAX_CONCURRENT_JOBS = 5;

    private ContaiJobRepository $jobRepository;
    private ContaiJobRecoveryService $recoveryService;
    private array $jobHandlers = [];

    public function __construct(?ContaiJobRecoveryService $recoveryService = null)
    {
        $this->jobRepository = new ContaiJobRepository();
        $this->recoveryService = $recoveryService ?? new ContaiJobRecoveryService();
        $this->registerJobHandlers();
    }

    public function processQueue()
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        if (!$this->acquireLock()) {
            return 0;
        }

        try {
            $this->cleanupStuckJobs();

            $processingCount = $this->jobRepository->countProcessingJobs();
            $availableSlots = self::MAX_CONCURRENT_JOBS - $processingCount;

            if ($availableSlots <= 0) {
                return 0;
            }

            $claimedJobs = $this->jobRepository->claimPendingJobs($availableSlots);

            if (empty($claimedJobs)) {
                return 0;
            }

            $processedCount = 0;

            foreach ($claimedJobs as $job) {
                $this->processJob($job);
                $processedCount++;
            }

            return $processedCount;
        } finally {
            $this->releaseLock();
        }
    }

    private function processJob(ContaiJob $job)
    {
        $handler = $this->getHandler($job->getJobType());

        if (!$handler) {
            $job->markAsFailed("No handler found for job type: {$job->getJobType()}");
            $this->jobRepository->update($job);
            return;
        }

        try {
            $payload = $job->getPayload();
            $payload['job_id'] = $job->getId();

            $result = $handler->handle($payload);

            if (is_array($result)) {
                if (isset($result['retry']) && $result['retry'] === true) {
                    return;
                }

                if (isset($result['continue']) && $result['continue'] === true) {
                    return;
                }

                if (isset($result['success']) && $result['success'] === true) {
                    $job->markAsCompleted();
                    $this->jobRepository->update($job);
                    return;
                }
            }

            $job->markAsCompleted();
            $this->jobRepository->update($job);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage(); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped

            // Tag 402 errors so recovery strategies can skip them
            if ($this->isInsufficientCreditsException($e)) {
                $errorMessage = ContaiCreditGuard::INSUFFICIENT_CREDITS_PREFIX . $errorMessage;
            }

            contai_log("ContaiJob {$job->getId()} failed: " . $errorMessage); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped

            $job->incrementAttempts();
            $job->markAsFailed($errorMessage); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            $this->jobRepository->update($job);
        }
    }

    private function cleanupStuckJobs(): void
    {
        $processingJobs = $this->jobRepository->findByStatus(ContaiJobStatus::PROCESSING);

        $recoveredJobs = $this->recoveryService->recoverStuckJobs($processingJobs);

        foreach ($recoveredJobs as $job) {
            $this->jobRepository->update($job);
        }
    }

    private function registerJobHandlers()
    {
        $this->jobHandlers[ContaiPostGenerationJob::TYPE] = new ContaiPostGenerationJob();
        $this->jobHandlers[ContaiContentGenerationPollingJob::TYPE] = new ContaiContentGenerationPollingJob();
        $this->jobHandlers[ContaiInternalLinkJob::TYPE] = new ContaiInternalLinkJob();
        $this->jobHandlers[ContaiKeywordExtractionJob::TYPE] = new ContaiKeywordExtractionJob();
        $this->jobHandlers[ContaiSiteGenerationJob::TYPE] = new ContaiSiteGenerationJob();
    }

    private function getHandler($jobType)
    {
        return $this->jobHandlers[$jobType] ?? null;
    }

    private function isInsufficientCreditsException(\Throwable $e): bool {
        $message = strtolower($e->getMessage());

        if (strpos($message, 'insufficient balance') !== false
            || strpos($message, 'insufficient credits') !== false
            || strpos($message, 'payment required') !== false
        ) {
            return true;
        }

        if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 402) {
            return true;
        }

        return false;
    }

    private function acquireLock()
    {
        global $wpdb;
        $lockName = 'contai_job_processor_lock';
        $timeout = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, %d)",
            $lockName,
            $timeout
        ));

        return $result === '1';
    }

    private function releaseLock()
    {
        global $wpdb;
        $lockName = 'contai_job_processor_lock';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "SELECT RELEASE_LOCK(%s)",
            $lockName
        ));
    }
}
