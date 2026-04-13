<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobRecoveryStrategy.php';
require_once __DIR__ . '/../../../helpers/TimestampHelper.php';

class ContaiMarkAsFailedStrategy implements ContaiJobRecoveryStrategy
{
    private int $stuckThresholdMinutes;

    public function __construct(int $stuckThresholdMinutes = 240)
    {
        $this->stuckThresholdMinutes = $stuckThresholdMinutes;
    }

    public function shouldRecover(ContaiJob $job): bool
    {
        if ($job->getStatus() !== ContaiJobStatus::PROCESSING) {
            return false;
        }

        // Always fail jobs that have exhausted all retry attempts
        if ($job->hasReachedMaxAttempts()) {
            return true;
        }

        $processedAt = $job->getProcessedAt();

        if (empty($processedAt)) {
            return false;
        }

        if (!ContaiTimestampHelper::isValidTimestamp($processedAt)) {
            return false;
        }

        $ageInSeconds = ContaiTimestampHelper::getAgeInSeconds($processedAt);
        $thresholdSeconds = $this->stuckThresholdMinutes * 60;

        return $ageInSeconds > $thresholdSeconds;
    }

    public function recover(ContaiJob $job): void
    {
        $job->markAsFailed("ContaiJob stuck in processing for more than {$this->stuckThresholdMinutes} minutes");
    }
}
