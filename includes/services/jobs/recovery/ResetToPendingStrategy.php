<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobRecoveryStrategy.php';
require_once __DIR__ . '/../../../helpers/TimestampHelper.php';

class ContaiResetToPendingStrategy implements ContaiJobRecoveryStrategy
{
    private int $stuckThresholdMinutes;

    public function __construct(int $stuckThresholdMinutes = 30)
    {
        $this->stuckThresholdMinutes = $stuckThresholdMinutes;
    }

    public function shouldRecover(ContaiJob $job): bool
    {
        if ($job->getStatus() !== ContaiJobStatus::PROCESSING) {
            return false;
        }

        $processedAt = $job->getProcessedAt();

        if (empty($processedAt)) {
            return true;
        }

        if (!ContaiTimestampHelper::isValidTimestamp($processedAt)) {
            return true;
        }

        if (ContaiTimestampHelper::isInFuture($processedAt)) {
            return true;
        }

        $ageInSeconds = ContaiTimestampHelper::getAgeInSeconds($processedAt);
        $thresholdSeconds = $this->stuckThresholdMinutes * 60;

        return $ageInSeconds > $thresholdSeconds;
    }

    public function recover(ContaiJob $job): void
    {
        $job->setStatus(ContaiJobStatus::PENDING);
        $job->setProcessedAt(null);
    }
}
