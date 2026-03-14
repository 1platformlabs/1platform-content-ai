<?php

if (!defined('ABSPATH')) exit;

class ContaiRateLimiter {

    private const TRANSIENT_PREFIX = 'contai_rate_limit_';
    private const TRANSIENT_TIMEOUT_PREFIX = '_transient_timeout_';
    private const DEFAULT_MAX_REQUESTS = 60;
    private const DEFAULT_TIME_WINDOW = 60;
    private const MAX_WAIT_SECONDS = 5;
    private const INITIAL_REQUEST_COUNT = 1;

    private string $identifier;
    private int $max_requests;
    private int $time_window;
    private string $transient_key;

    public function __construct(string $identifier, int $max_requests = self::DEFAULT_MAX_REQUESTS, int $time_window = self::DEFAULT_TIME_WINDOW) {
        $this->identifier = $identifier;
        $this->max_requests = $max_requests;
        $this->time_window = $time_window;
        $this->transient_key = $this->buildTransientKey();
    }

    private function buildTransientKey(): string {
        return self::TRANSIENT_PREFIX . md5($this->identifier);
    }

    public function allow(): bool {
        $current_count = $this->getCurrentRequestCount();

        if ($this->isFirstRequest($current_count)) {
            $this->initializeCounter();
            return true;
        }

        if ($this->hasExceededLimit($current_count)) {
            return false;
        }

        $this->incrementCounter($current_count);
        return true;
    }

    private function getCurrentRequestCount() {
        return get_transient($this->transient_key);
    }

    private function isFirstRequest($count): bool {
        return $count === false;
    }

    private function hasExceededLimit(int $count): bool {
        return $count >= $this->max_requests;
    }

    private function initializeCounter(): void {
        $this->setRequestCount(self::INITIAL_REQUEST_COUNT);
    }

    private function incrementCounter(int $current_count): void {
        $this->setRequestCount($current_count + 1);
    }

    private function setRequestCount(int $count): void {
        set_transient($this->transient_key, $count, $this->time_window);
    }

    public function getRemainingRequests(): int {
        $current_count = $this->getCurrentRequestCount();

        if ($this->isFirstRequest($current_count)) {
            return $this->max_requests;
        }

        return $this->calculateRemaining((int) $current_count);
    }

    private function calculateRemaining(int $current_count): int {
        return max(0, $this->max_requests - $current_count);
    }

    public function getResetTime(): int {
        $timeout = $this->getTransientTimeout();

        if ($timeout === false) {
            return $this->calculateDefaultResetTime();
        }

        return (int) $timeout;
    }

    private function getTransientTimeout() {
        $timeout_key = self::TRANSIENT_TIMEOUT_PREFIX . $this->transient_key;
        return get_option($timeout_key);
    }

    private function calculateDefaultResetTime(): int {
        return time() + $this->time_window;
    }

    public function reset(): void {
        delete_transient($this->transient_key);
    }

    public function waitIfNeeded(): bool {
        if ($this->allow()) {
            return true;
        }

        $wait_seconds = $this->calculateWaitSeconds();

        if ($this->shouldWait($wait_seconds)) {
            return $this->waitAndRetry($wait_seconds);
        }

        return false;
    }

    private function calculateWaitSeconds(): int {
        $reset_time = $this->getResetTime();
        return $reset_time - time();
    }

    private function shouldWait(int $wait_seconds): bool {
        return $wait_seconds > 0 && $wait_seconds <= self::MAX_WAIT_SECONDS;
    }

    private function waitAndRetry(int $wait_seconds): bool {
        sleep($wait_seconds);
        return $this->allow();
    }
}
