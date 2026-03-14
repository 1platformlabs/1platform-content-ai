<?php

if (!defined('ABSPATH')) exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class ContaiLicenseStatusChecker
{
    private const STATUS_ACTIVE = 'active';

    public function hasActiveLicense(?array $profile): bool
    {
        if (!$profile) {
            return false;
        }

        return $this->hasActiveStatus($profile);
    }

    private function hasActiveStatus(array $profile): bool
    {
        return isset($profile['status']) && $profile['status'] === self::STATUS_ACTIVE;
    }

    public function getStatus(?array $profile): string
    {
        if ($this->hasActiveLicense($profile)) {
            return self::STATUS_ACTIVE;
        }

        return 'inactive';
    }
}
