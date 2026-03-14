<?php

if (!defined('ABSPATH')) exit;

class ContaiWebsiteStatusChecker
{
    private const STATUS_ACTIVE = 'active';
    private const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public function isVerified(array $config): bool
    {
        return $this->hasVerifiedFlag($config) || $this->hasActiveStatus($config);
    }

    public function isPendingVerification(array $config): bool
    {
        return !$this->isVerified($config);
    }

    private function hasVerifiedFlag(array $config): bool
    {
        return isset($config['verified']) && $config['verified'] === true;
    }

    private function hasActiveStatus(array $config): bool
    {
        return isset($config['status']) && $config['status'] === self::STATUS_ACTIVE;
    }

    public function getStatus(array $config): string
    {
        if ($this->isVerified($config)) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_PENDING_VERIFICATION;
    }
}
