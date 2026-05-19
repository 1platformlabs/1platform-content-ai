<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/JobRecoveryStrategy.php';
require_once __DIR__ . '/ResetToPendingStrategy.php';
require_once __DIR__ . '/MarkAsFailedStrategy.php';

class ContaiJobRecoveryService
{
    private array $strategies;

    public function __construct(?array $strategies = null)
    {
        if ($strategies === null) {
            $this->strategies = $this->getDefaultStrategies();
        } else {
            $this->strategies = $strategies;
        }
    }

    public function recoverStuckJobs(array $jobs): array
    {
        $recovered = [];

        foreach ($jobs as $job) {
            if ($this->attemptRecovery($job)) {
                $recovered[] = $job;
            }
        }

        return $recovered;
    }

    private function attemptRecovery(ContaiJob $job): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldRecover($job)) {
                $strategy->recover($job);
                return true;
            }
        }

        return false;
    }

    private function getDefaultStrategies(): array
    {
        $resetMinutes = (int) apply_filters('contai_recovery_reset_threshold_minutes', 5);
        $failMinutes = (int) apply_filters('contai_recovery_fail_threshold_minutes', 30);

        return [
            new ContaiResetToPendingStrategy($resetMinutes),
            new ContaiMarkAsFailedStrategy($failMinutes),
        ];
    }
}
