<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../services/user-profile/UserProfileService.php';
require_once __DIR__ . '/../services/user-profile/LicenseStatusChecker.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class ContaiLicenseHelper
{
    private static ?ContaiLicenseHelper $instance = null;
    private ContaiUserProfileService $userProfileService;
    private ContaiLicenseStatusChecker $licenseChecker;
    private $cachedUserProfile = null;
    private $cacheChecked = false;

    private function __construct()
    {
        $this->userProfileService = new ContaiUserProfileService();
        $this->licenseChecker = new ContaiLicenseStatusChecker();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getUserProfile()
    {
        if (!$this->cacheChecked) {
            $this->cachedUserProfile = $this->userProfileService->getUserProfile();
            $this->cacheChecked = true;
        }
        return $this->cachedUserProfile;
    }

    public function hasActiveLicense(): bool
    {
        $userProfile = $this->getUserProfile();
        return $this->licenseChecker->hasActiveLicense($userProfile);
    }

    public function clearCache(): void
    {
        $this->cachedUserProfile = null;
        $this->cacheChecked = false;
    }
}

function contai_get_user_profile()
{
    return ContaiLicenseHelper::getInstance()->getUserProfile();
}

function contai_has_active_license(): bool
{
    return ContaiLicenseHelper::getInstance()->hasActiveLicense();
}

function contai_clear_license_cache(): void
{
    ContaiLicenseHelper::getInstance()->clearCache();
}
