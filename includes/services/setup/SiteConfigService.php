<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../../providers/WebsiteProvider.php';

class ContaiSiteConfigService
{
    private const LANGUAGE_MAP = [
        'english' => 'en',
        'spanish' => 'es',
    ];

    private ?ContaiWebsiteProvider $websiteProvider;

    public function __construct(?ContaiWebsiteProvider $websiteProvider = null)
    {
        $this->websiteProvider = $websiteProvider;
    }

    public function saveSiteConfiguration(array $config): bool
    {
        $siteTopic = $config['site_topic'] ?? '';
        $siteLanguage = $config['site_language'] ?? 'english';
        $siteCategory = $config['site_category'] ?? '';
        $wordpressTheme = $config['wordpress_theme'] ?? 'astra';

        if (empty($siteTopic)) {
            throw new InvalidArgumentException('Site topic is required');
        }

        update_option('contai_site_topic', sanitize_text_field($siteTopic));
        // Keep legacy option for backward compatibility during migration
        update_option('contai_site_theme', sanitize_text_field($siteTopic));
        update_option('contai_site_language', sanitize_text_field($siteLanguage));
        update_option('contai_wordpress_theme', sanitize_text_field($wordpressTheme));

        if (!empty($siteCategory)) {
            update_option('contai_site_category', sanitize_text_field($siteCategory));
        }

        $this->syncWithApi($siteCategory, $siteLanguage);

        return true;
    }

    private function syncWithApi(string $siteCategory, string $siteLanguage): void
    {
        $langCode = self::LANGUAGE_MAP[$siteLanguage] ?? 'en';

        $data = ['lang' => $langCode];

        if (!empty($siteCategory)) {
            $data['category_id'] = sanitize_text_field($siteCategory);
        }

        try {
            $provider = $this->websiteProvider ?? new ContaiWebsiteProvider();
            $provider->updateWebsite($data);
        } catch (Exception $e) {
            contai_log('SiteConfigService: API sync failed (non-critical): ' . $e->getMessage());
        }
    }

    public function validateSiteConfiguration(array $config): array
    {
        $errors = [];

        if (empty($config['site_topic'])) {
            $errors[] = 'Site topic is required';
        }

        $validLanguages = ['english', 'spanish'];
        if (empty($config['site_language']) || !in_array($config['site_language'], $validLanguages, true)) {
            $errors[] = 'Invalid language selected';
        }

        if (empty($config['wordpress_theme'])) {
            $errors[] = 'WordPress theme is required';
        }

        return $errors;
    }

    public function getSiteConfiguration(): array
    {
        return [
            'site_topic' => get_option('contai_site_topic', get_option('contai_site_theme', '')),
            'site_language' => get_option('contai_site_language', 'english'),
            'site_category' => get_option('contai_site_category', ''),
            'wordpress_theme' => get_option('contai_wordpress_theme', 'astra'),
        ];
    }
}
