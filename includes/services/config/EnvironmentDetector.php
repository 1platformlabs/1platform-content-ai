<?php

if (!defined('ABSPATH')) exit;

class ContaiEnvironmentDetector
{

    private const ENV_DEVELOPMENT = 'development';
    private const ENV_STAGING = 'staging';
    private const ENV_PRODUCTION = 'production';

    private static ?string $detected_environment = null;

    public static function detect(): string
    {
        if (self::$detected_environment !== null) {
            return self::$detected_environment;
        }

        self::$detected_environment = self::detectEnvironment();

        return self::$detected_environment;
    }

    private static function detectEnvironment(): string
    {
        $site_url = defined('WP_SITEURL') ? WP_SITEURL : get_site_url();

        if (strpos($site_url, '.local') !== false) {
            return self::ENV_STAGING;
        }

        return self::ENV_PRODUCTION;
    }

    public static function isDevelopment(): bool
    {
        return self::detect() === self::ENV_DEVELOPMENT;
    }

    public static function isStaging(): bool
    {
        return self::detect() === self::ENV_STAGING;
    }

    public static function isProduction(): bool
    {
        return self::detect() === self::ENV_PRODUCTION;
    }

    public static function reset(): void
    {
        self::$detected_environment = null;
    }
}
