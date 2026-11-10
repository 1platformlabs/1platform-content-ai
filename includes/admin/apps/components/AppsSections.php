<?php

if (!defined('ABSPATH')) exit;

/**
 * The Tools sections the Apps page routes to: header (title, subtitle, icon)
 * and panel of each one.
 *
 * The marketplace section is labelled "Link Building", never by the
 * marketplace's brand (1platform-api#1043, reverting content-ai#44). An
 * unknown section gets the Tools overview.
 */
class ContaiAppsSections
{
    const DEFAULT_SECTION = 'apps';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function headers(): array
    {
        return [
            'toc' => [
                __('Table of Contents Settings', '1platform-content-ai'),
                __('Configure how the table of contents is displayed on your site', '1platform-content-ai'),
                'dashicons-admin-generic',
            ],
            'internal-links' => [
                __('Internal Links', '1platform-content-ai'),
                __('Configure automatic internal linking and view all links between your posts', '1platform-content-ai'),
                'dashicons-admin-links',
            ],
            'search-console' => [
                __('Search Console', '1platform-content-ai'),
                __('Connect your website to Google Search Console through Content AI', '1platform-content-ai'),
                'dashicons-cloud',
            ],
            'publisuites' => [
                __('Link Building', '1platform-content-ai'),
                __('Connect your website to the marketplace to monetize your content', '1platform-content-ai'),
                'dashicons-money-alt',
            ],
            'ads-manager' => [
                __('Ads Manager', '1platform-content-ai'),
                __('Configure AdSense publisher IDs, ads.txt generation, and custom header code', '1platform-content-ai'),
                'dashicons-megaphone',
            ],
            'analytics' => [
                __('Google Analytics', '1platform-content-ai'),
                __('Connect Google Analytics GA4 to track content performance and site traffic', '1platform-content-ai'),
                'dashicons-chart-area',
            ],
            self::DEFAULT_SECTION => [
                __('Tools', '1platform-content-ai'),
                __('Overview of all available tools and integrations', '1platform-content-ai'),
                'dashicons-list-view',
            ],
        ];
    }

    /**
     * @return array<string, class-string>
     */
    private static function panelClasses(): array
    {
        return [
            'toc'            => ContaiTocSettingsPanel::class,
            'internal-links' => ContaiInternalLinksPanel::class,
            'search-console' => ContaiSearchConsolePanel::class,
            'publisuites'    => ContaiPublisuitesPanel::class,
            'ads-manager'    => ContaiAdsManagerPanel::class,
            'analytics'      => ContaiAnalyticsPanel::class,
        ];
    }

    /**
     * The sections the page routes to; anything else shows the Tools overview.
     *
     * @return string[]
     */
    public static function validSections(): array
    {
        return array_keys(self::panelClasses());
    }

    /**
     * The section to show for a requested one (unknown falls back to Tools).
     */
    public static function resolve(string $requested): string
    {
        return in_array($requested, self::validSections(), true) ? $requested : self::DEFAULT_SECTION;
    }

    /**
     * @return array{0: string, 1: string, 2: string} title, subtitle, dashicon
     */
    public static function header(string $section): array
    {
        $headers = self::headers();
        return $headers[$section] ?? $headers[self::DEFAULT_SECTION];
    }

    /**
     * @return class-string
     */
    public static function panelClass(string $section): string
    {
        return self::panelClasses()[$section] ?? ContaiAppsPanel::class;
    }

    public static function createPanel(string $section): object
    {
        $class = self::panelClass($section);
        return $class === ContaiTocSettingsPanel::class
            ? new $class(new ContaiTocConfiguration())
            : new $class();
    }
}
