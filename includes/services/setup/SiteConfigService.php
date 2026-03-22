<?php

if (!defined('ABSPATH')) exit;

class ContaiSiteConfigService
{
    public function saveSiteConfiguration(array $config): bool
    {
        $siteTopic = $config['site_topic'] ?? '';
        $siteLanguage = $config['site_language'] ?? 'english';
        $wordpressTheme = $config['wordpress_theme'] ?? 'astra';

        if (empty($siteTopic)) {
            throw new InvalidArgumentException('Site topic is required');
        }

        update_option('contai_site_theme', sanitize_text_field($siteTopic));
        update_option('contai_site_language', sanitize_text_field($siteLanguage));
        update_option('contai_wordpress_theme', sanitize_text_field($wordpressTheme));

        return true;
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
            'site_topic' => get_option('contai_site_theme', ''),
            'site_language' => get_option('contai_site_language', 'english'),
            'wordpress_theme' => get_option('contai_wordpress_theme', 'astra'),
        ];
    }
}
