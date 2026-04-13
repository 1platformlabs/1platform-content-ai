<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobRecoveryStrategy.php';
require_once __DIR__ . '/../../../helpers/TimestampHelper.php';
require_once __DIR__ . '/../../billing/CreditGuard.php';

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

        // Don't reset if max attempts reached — let MarkAsFailedStrategy handle it
        if ($job->hasReachedMaxAttempts()) {
            return false;
        }

        // Never re-queue jobs that failed due to insufficient credits
        $errorMessage = $job->getErrorMessage() ?? '';
        if (ContaiCreditGuard::isInsufficientCreditsError($errorMessage)) {
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
        $job->incrementAttempts();
        $job->setStatus(ContaiJobStatus::PENDING);
        $job->setProcessedAt(null);
    }
}
